<?php

namespace Otago\SharedCmsSync\Service;

use Otago\SharedCmsSync\Client\GraphQLClient;
use Otago\SharedCmsSync\Model\Snapshot;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

/**
 * Fetches one dataset from a remote SilverStripe site and files the answer as a
 * snapshot. It does not touch a single local record — that is the sync's job.
 *
 * Subclasses say what to ask for and where to put it; everything about talking
 * to the far end, noticing that nothing changed, and not letting old snapshots
 * pile up is handled here.
 */
abstract class DownloadService
{
    use Injectable;
    use Configurable;

    protected GraphQLClient $client;

    /** @var callable|null */
    protected $reporter;

    /**
     * @param GraphQLClient|null $client
     */
    public function __construct(?GraphQLClient $client = null)
    {
        $this->client = $client ?: Injector::inst()->create(GraphQLClient::class);
    }

    /**
     * Where to send progress. Left unset, output is dropped — which is what a
     * queued job wants, and what a test wants even more.
     *
     * @param callable $reporter
     * @return $this
     */
    public function setReporter(callable $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    /**
     * Name of the environment variable holding the endpoint to query.
     * @return string
     */
    abstract public function getEndpointEnvVar(): string;

    /**
     * The GraphQL query document.
     * @return string
     */
    abstract public function getQuery(): string;

    /**
     * Class name of the Snapshot subclass that stores these responses.
     * @return string
     */
    abstract public function getSnapshotClass(): string;

    /**
     * The local records this dataset is downloaded for, one query each.
     *
     * Return an empty list for a dataset that is fetched whole rather than per
     * record, and override downloadAll() instead.
     *
     * @return iterable<DataObject>
     */
    abstract public function getRecords(): iterable;

    /**
     * Query variables for one record — usually its id on the remote site.
     *
     * Returning null skips the record, which is the right answer when it has no
     * remote counterpart yet.
     *
     * @param DataObject $record
     * @return array|null
     */
    abstract public function getVariablesFor(DataObject $record): ?array;

    /**
     * Name of the has_one on the snapshot pointing back at the local record.
     * @return string
     */
    public function getSnapshotRelation(): string
    {
        return 'Page';
    }

    /**
     * A human label for a record, used in progress output only.
     * @param DataObject $record
     * @return string
     */
    public function describe(DataObject $record): string
    {
        return (string) ($record->Title ?: get_class($record) . " #{$record->ID}");
    }

    /**
     * Run the download.
     * @return array{downloaded:int,unchanged:int,skipped:int,failed:int}
     */
    public function run(): array
    {
        $tally = ['downloaded' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

        $endpoint = $this->getEndpoint();
        if (!$endpoint) {
            $this->report('ERROR: ' . $this->getEndpointEnvVar() . ' is not set');

            return $tally;
        }

        foreach ($this->getRecords() as $record) {
            $label = $this->describe($record);
            $variables = $this->getVariablesFor($record);

            if ($variables === null) {
                $this->report("SKIP  {$label} — nothing to look up");
                $tally['skipped']++;
                continue;
            }

            try {
                $data = $this->client->query($endpoint, $this->getQuery(), $variables);
            } catch (RuntimeException $e) {
                // One unreachable record must not abandon the rest: a sync that
                // stops at the first failure leaves the site half updated.
                $this->report("FAIL  {$label} — " . $e->getMessage());
                $tally['failed']++;
                continue;
            }

            if ($this->store($record, $data)) {
                $this->report("SAVE  {$label}");
                $tally['downloaded']++;
            } else {
                $tally['unchanged']++;
            }
        }

        return $tally;
    }

    /**
     * File the response against a record, replacing anything already held.
     *
     * @param DataObject $record
     * @param string     $data
     * @return bool true when a new snapshot was written
     */
    protected function store(DataObject $record, string $data): bool
    {
        $class = $this->getSnapshotClass();
        $relation = $this->getSnapshotRelation() . 'ID';

        $existing = $class::get()->filter($relation, $record->ID);

        // Compare decoded, not raw: an endpoint is free to reorder its keys or
        // change its whitespace, and neither means the content changed.
        $current = $existing->first();
        if ($current?->Data && json_decode($current->Data, true) == json_decode($data, true)) {
            return false;
        }

        // Note the ids first. Reading them after the write would include the
        // snapshot just created, and deleting that is the one thing this must
        // never do.
        $supersededIds = $existing->column('ID');

        $snapshot = $class::create();
        $snapshot->$relation = $record->ID;
        $snapshot->Data = $data;
        $snapshot->write();

        foreach ($supersededIds as $id) {
            $class::get()->byID($id)?->delete();
        }

        return true;
    }

    /**
     * @return string
     */
    protected function getEndpoint(): string
    {
        return (string) Environment::getEnv($this->getEndpointEnvVar());
    }

    /**
     * @param string $message
     * @return void
     */
    protected function report(string $message): void
    {
        if ($this->reporter) {
            call_user_func($this->reporter, $message);
        }
    }
}
