<?php

namespace Otago\SharedCmsSync\Admin;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\ArrayData;
use SilverStripe\View\Requirements;

/**
 * The Sync Data screen: runs the configured steps and streams their progress.
 *
 * Which steps exist is configuration, not code, so a site says what it syncs in
 * YAML and gets the same screen. Auckland pulls departments, news, programmes
 * and research stories; the marketing website pulls policies. Neither needs its
 * own admin, its own SSE plumbing or its own copy of this stylesheet.
 *
 * Each step runs in its own short-lived request, because a single request that
 * syncs everything eventually meets php-fpm's request_terminate_timeout and
 * dies with no way to tell how far it got.
 */
class SyncAdmin extends LeftAndMain
{
    private static string $url_segment = 'sync';
    private static string $menu_title = 'Sync Data';
    private static string $menu_icon_class = 'font-icon-sync';
    private static int $menu_priority = -1;

    private static array $allowed_actions = [
        'steps',
        'stream_step',
    ];

    /**
     * Step id => [section, label, done, service, chunked].
     *
     * `service` is resolved through Injector, so a service needing constructor
     * arguments is wired the same way as anything else in the project rather
     * than needing a special case here.
     *
     * `chunked` marks a step that processes a batch and returns the next
     * cursor, for datasets too large to finish inside one request.
     *
     * @config
     * @var array
     */
    private static array $steps = [];

    /**
     * Mode => ordered list of step ids. The screen offers a button per mode.
     *
     * @config
     * @var array
     */
    private static array $modes = [];

    /**
     * Mode => wording for its card. Optional: a mode with nothing here gets its
     * id title-cased, which is serviceable but rarely what you want on screen.
     *
     * @config
     * @var array
     */
    private static array $mode_labels = [
        'download' => [
            'title' => 'Download Data',
            'description' => 'Fetch the latest content from external endpoints and write local snapshots.',
        ],
        'sync' => [
            'title' => 'Sync to Database',
            'description' => 'Process downloaded snapshots and update website content in the database.',
        ],
    ];

    /**
     * Section id => heading shown above that group in the log.
     *
     * @config
     * @var array
     */
    private static array $sections = [
        'download' => 'Downloading Data',
        'sync' => 'Syncing to Database',
    ];

    /**
     * How long a single step may run before PHP gives up on it.
     *
     * @config
     * @var int
     */
    private static int $step_time_limit = 300;

    private string $captureBuffer = '';

    /**
     * @return array
     */
    protected function stepRegistry(): array
    {
        return (array) $this->config()->get('steps');
    }

    /**
     * @return array
     */
    protected function modeRegistry(): array
    {
        return (array) $this->config()->get('modes');
    }

    /**
     * @param string $id
     * @return array|null
     */
    protected function getStep(string $id): ?array
    {
        $steps = $this->stepRegistry();

        return isset($steps[$id]) ? (array) $steps[$id] : null;
    }

    public function getEditForm($id = null, $fields = null): Form
    {
        Requirements::css('otago/shared-cms-sync:client/dist/sync-admin.css');
        Requirements::javascript('otago/shared-cms-sync:client/dist/sync-admin.js');

        $html = (string) $this->renderWith(
            'Otago\\SharedCmsSync\\Admin\\SyncAdmin_UI',
            ['Modes' => $this->getModesForTemplate()]
        );

        $form = Form::create(
            $this,
            'EditForm',
            FieldList::create(LiteralField::create('SyncUI', $html)),
            FieldList::create()
        );
        $form->addExtraClass('cms-edit-form');
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));

        return $form;
    }

    /**
     * The modes, with the datasets each one covers, so the screen describes
     * what a button will actually do rather than naming them in the template
     * where they would go stale.
     *
     * @return ArrayList
     */
    protected function getModesForTemplate(): ArrayList
    {
        $registry = $this->stepRegistry();
        $wording = (array) $this->config()->get('mode_labels');
        $list = ArrayList::create();

        foreach ($this->modeRegistry() as $mode => $stepIds) {
            $labels = [];
            foreach ((array) $stepIds as $stepId) {
                $label = $registry[$stepId]['dataset'] ?? null;
                if ($label && !in_array($label, $labels, true)) {
                    $labels[] = $label;
                }
            }

            $datasetList = ArrayList::create();
            foreach ($labels as $label) {
                $datasetList->push(ArrayData::create(['Value' => $label]));
            }

            $list->push(ArrayData::create([
                'Mode' => $mode,
                'Title' => $wording[$mode]['title'] ?? ucfirst(str_replace('_', ' ', (string) $mode)),
                'Description' => $wording[$mode]['description'] ?? '',
                'Datasets' => implode(' • ', $labels),
                'DatasetList' => $datasetList,
                'Count' => count((array) $stepIds),
            ]));
        }

        return $list;
    }

    /**
     * The ordered steps for a mode, as JSON. The client walks this list and
     * opens a separate request per step.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function steps(HTTPRequest $request): HTTPResponse
    {
        $mode = (string) $request->getVar('mode');
        $modes = $this->modeRegistry();
        $ids = $modes[$mode] ?? reset($modes) ?: [];

        $steps = [];
        foreach ((array) $ids as $stepId) {
            $step = $this->getStep($stepId);
            if (!$step) {
                continue;
            }

            $steps[] = [
                'id' => $stepId,
                'label' => $step['label'] ?? $stepId,
                'section' => $this->sectionLabel($step['section'] ?? ''),
            ];
        }

        $response = HTTPResponse::create(json_encode(['steps' => $steps]));
        $response->addHeader('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Runs one slice of one step, streaming its output as server-sent events.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse|null
     */
    public function stream_step(HTTPRequest $request)
    {
        $stepId = (string) $request->getVar('step');
        $cursor = max(0, (int) $request->getVar('cursor'));
        $step = $this->getStep($stepId);

        $this->startSSE((int) $this->config()->get('step_time_limit'));

        // The event names are the client's protocol, not labels: it advances to
        // the next step on 'complete', and treats a stream that ends without
        // one as a dropped connection.
        if (!$step) {
            $this->sendSSE('error', "Unknown step '{$stepId}'");

            return null;
        }

        if ($cursor === 0) {
            $this->sendSSE('step', ($step['label'] ?? $stepId) . '...');
        }

        try {
            [$next, $elapsed] = $this->runStep(fn () => $this->invoke($step, $cursor));
        } catch (\Throwable $e) {
            // Report and stop this step rather than letting the exception reach
            // the client as a broken stream, which the screen cannot explain.
            $this->sendSSE('error', $e->getMessage());

            return null;
        }

        if (is_int($next)) {
            $this->sendSSE('continue', (string) $next);

            return null;
        }

        $done = $step['done'] ?? 'Done';
        $this->sendSSE('step_done', $done . " ({$elapsed}s)");
        $this->sendSSE('complete', $done);

        return null;
    }

    /**
     * @param array $step
     * @param int   $cursor
     * @return mixed
     */
    protected function invoke(array $step, int $cursor)
    {
        $service = Injector::inst()->get($step['service']);

        // Point the service's reporter at stdout, which runStep() is capturing.
        // Without this a service that reports through the callable says nothing
        // here: a step that could not reach its endpoint, or found nothing to
        // do, finishes in no time and looks exactly like a success.
        if (method_exists($service, 'setReporter')) {
            $service->setReporter(function (string $message): void {
                echo $message . "\n";
            });
        }

        // A chunked service takes the cursor and hands back the next one.
        // Everything else ignores it and finishes in one go.
        return !empty($step['chunked'])
            ? $service->run($cursor)
            : $service->run();
    }

    /**
     * @param string $section
     * @return string
     */
    protected function sectionLabel(string $section): string
    {
        $sections = (array) $this->config()->get('sections');

        return $sections[$section] ?? $section;
    }

    /**
     * Runs the step with output buffering, turning each echoed line into a log
     * event. Services report through a callable, but the ones inherited from
     * Auckland echo, and both need to reach the screen.
     *
     * @param callable $fn
     * @return array
     */
    private function runStep(callable $fn): array
    {
        $start = microtime(true);

        $this->captureBuffer = '';
        ob_start(function (string $chunk): string {
            $this->captureBuffer .= $chunk;
            $out = '';
            while (($pos = strpos($this->captureBuffer, "\n")) !== false) {
                $line = rtrim(substr($this->captureBuffer, 0, $pos));
                $this->captureBuffer = substr($this->captureBuffer, $pos + 1);
                if ($line === '') {
                    continue;
                }
                $level = $this->classifyEchoLine($line);
                $out .= "event: log\n";
                $out .= 'data: ' . json_encode(['message' => $line, 'level' => $level]) . "\n\n";
            }

            return $out;
        }, 1);

        $result = null;
        try {
            $result = $fn();
        } finally {
            if (trim($this->captureBuffer) !== '') {
                echo "\n";
            }
            ob_end_flush();
            @flush();
        }

        return [$result, number_format(microtime(true) - $start, 1)];
    }

    /**
     * @param string $line
     * @return string
     */
    private function classifyEchoLine(string $line): string
    {
        $s = ltrim($line);

        if (preg_match('/^[✔✓]/u', $s)) {
            return 'done';
        }
        if (preg_match('/^✖/u', $s) || str_contains($s, 'ERROR') || str_contains($s, 'FAIL')) {
            return 'error';
        }
        if (str_contains($s, '[WARN]') || str_contains($s, 'WARN')) {
            return 'info';
        }
        if (preg_match('/^→/u', $s)) {
            return 'step';
        }

        return 'info';
    }

    /**
     * @param int $timeLimit
     * @return void
     */
    private function startSSE(int $timeLimit = 300): void
    {
        set_time_limit($timeLimit);
        ignore_user_abort(true);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
    }

    /**
     * @param string $event
     * @param string $message
     * @return void
     */
    private function sendSSE(string $event, string $message): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode(['message' => $message]) . "\n\n";
        flush();
    }
}
