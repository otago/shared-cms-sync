<?php

namespace Otago\SharedCmsSync\Service;

use Otago\SharedCmsSync\Client\GraphQLClient;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Downloads a whole collection from a remote site, a page at a time, and files
 * one snapshot per item.
 *
 * Where DownloadService asks about records the local site already has, this is
 * for content the local site does not know about until it asks. The answer is
 * also the inventory: an item that stops appearing is an item that has been
 * unpublished or deleted at the far end, and the sync needs to know that as
 * much as it needs to know about a change.
 */
abstract class CollectionDownloadService
{
    use Injectable;
    use Configurable;

    protected GraphQLClient $client;

    /** @var callable|null */
    protected $reporter;

    /**
     * Items per request. Big enough to keep the round trips down, small enough
     * that a slow endpoint still answers inside the timeout.
     *
     * @config
     * @var int
     */
    private static $page_size = 100;

    /**
     * A collection that keeps saying there is another page is a paging bug at
     * one end or the other, and left alone it downloads until something dies.
     *
     * @config
     * @var int
     */
    private static $max_pages = 200;

    /**
     * @param GraphQLClient|null $client
     */
    public function __construct(?GraphQLClient $client = null)
    {
        $this->client = $client ?: Injector::inst()->create(GraphQLClient::class);
    }

    /**
     * @param callable $reporter
     * @return $this
     */
    public function setReporter(callable $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    /**
     * Name of the environment variable holding the endpoint.
     * @return string
     */
    abstract public function getEndpointEnvVar(): string;

    /**
     * The query. It must accept $limit and $offset.
     * @return string
     */
    abstract public function getQuery(): string;

    /**
     * Class name of the CollectionSnapshot subclass storing these items.
     * @return string
     */
    abstract public function getSnapshotClass(): string;

    /**
     * Dotted path from the response root to the list of items, e.g.
     * "data.readPolicyPages.nodes".
     * @return string
     */
    abstract public function getNodesPath(): string;

    /**
     * The remote id of one item.
     * @param array $node
     * @return string|null
     */
    public function getRemoteID(array $node): ?string
    {
        return isset($node['id']) ? (string) $node['id'] : null;
    }

    /**
     * Run the download.
     * @return array{downloaded:int,unchanged:int,removed:int,failed:int}
     */
    public function run(): array
    {
        $tally = ['downloaded' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];

        $endpoint = (string) Environment::getEnv($this->getEndpointEnvVar());
        if (!$endpoint) {
            $this->report('ERROR: ' . $this->getEndpointEnvVar() . ' is not set');

            return $tally;
        }

        $pageSize = (int) $this->config()->get('page_size');
        $maxPages = (int) $this->config()->get('max_pages');

        $seen = [];
        $offset = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $body = $this->client->query($endpoint, $this->getQuery(), [
                    'limit' => $pageSize,
                    'offset' => $offset,
                ]);
            } catch (RuntimeException $e) {
                // Stop rather than carry on. A failed page means an unknown
                // slice of the collection is missing, and treating the rest as
                // the full inventory would unpublish everything in the gap.
                $this->report('FAIL  page at offset ' . $offset . ' — ' . $e->getMessage());
                $tally['failed']++;

                return $tally;
            }

            $nodes = $this->extract($body, $this->getNodesPath());
            if (!is_array($nodes) || $nodes === []) {
                break;
            }

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $remoteId = $this->getRemoteID($node);
                if ($remoteId === null || $remoteId === '') {
                    $this->report('WARN  an item arrived with no id and was ignored');
                    continue;
                }

                $seen[] = $remoteId;

                if ($this->store($remoteId, $node)) {
                    $tally['downloaded']++;
                } else {
                    $tally['unchanged']++;
                }
            }

            if (count($nodes) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        $tally['removed'] = $this->prune($seen);

        return $tally;
    }

    /**
     * Store one item, if it differs from what is already held.
     *
     * @param string $remoteId
     * @param array  $node
     * @return bool true when written
     */
    protected function store(string $remoteId, array $node): bool
    {
        $class = $this->getSnapshotClass();
        $data = json_encode($node);

        $snapshot = $class::get()->filter('RemoteID', $remoteId)->first();

        if ($snapshot) {
            if (json_decode((string) $snapshot->Data, true) == $node) {
                return false;
            }
        } else {
            $snapshot = $class::create();
            $snapshot->RemoteID = $remoteId;
        }

        $snapshot->Data = $data;
        $snapshot->write();

        return true;
    }

    /**
     * Drop snapshots for items the far end no longer lists.
     *
     * Only ever called with a complete inventory — a failed page returns early
     * above, because pruning against a partial list would delete the rest.
     *
     * @param array $seen remote ids present in this run
     * @return int
     */
    protected function prune(array $seen): int
    {
        if ($seen === []) {
            return 0;
        }

        $class = $this->getSnapshotClass();
        $stale = $class::get()->exclude('RemoteID', $seen);
        $count = $stale->count();

        foreach ($stale as $snapshot) {
            $this->report("GONE  remote #{$snapshot->RemoteID} is no longer published");
            $snapshot->delete();
        }

        return $count;
    }

    /**
     * @param string $body
     * @param string $path
     * @return mixed
     */
    protected function extract(string $body, string $path)
    {
        $value = json_decode($body, true);

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
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
