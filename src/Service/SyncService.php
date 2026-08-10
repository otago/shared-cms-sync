<?php

namespace Otago\SharedCmsSync\Service;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Maps stored snapshots onto local records. Never talks to the network.
 *
 * The rule it exists to enforce is that an unchanged record is left alone. A
 * sync that writes and republishes everything it touches fills the version
 * history with entries nobody made, and on a versioned page that history is
 * what an editor uses to see who changed what.
 */
abstract class SyncService
{
    use Injectable;
    use Configurable;

    /** @var callable|null */
    protected $reporter;

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
     * Remote field name => local field name.
     * @return array<string, string>
     */
    abstract public function getFieldMap(): array;

    /**
     * The local records to update.
     * @return iterable<DataObject>
     */
    abstract public function getRecords(): iterable;

    /**
     * Dotted path to the payload inside the snapshot, e.g.
     * "data.readOneProgrammeInformationPage".
     * @return string
     */
    abstract public function getPayloadPath(): string;

    /**
     * The snapshot holding this record's data, or null if none has been
     * downloaded yet.
     *
     * @param DataObject $record
     * @return DataObject|null
     */
    abstract public function getSnapshotFor(DataObject $record): ?DataObject;

    /**
     * A hook for reshaping the payload before it is compared and written —
     * where a value has to be normalised the same way the local record would
     * normalise it, or a comparison reports a change on every run.
     *
     * @param array      $payload
     * @param DataObject $record
     * @return array
     */
    public function preparePayload(array $payload, DataObject $record): array
    {
        return $payload;
    }

    /**
     * @param DataObject $record
     * @return string
     */
    public function describe(DataObject $record): string
    {
        return (string) ($record->Title ?: get_class($record) . " #{$record->ID}");
    }

    /**
     * Run the sync.
     * @return array{synced:int,unchanged:int,skipped:int}
     */
    public function run(): array
    {
        $tally = ['synced' => 0, 'unchanged' => 0, 'skipped' => 0];

        foreach ($this->getRecords() as $record) {
            $outcome = $this->handle($record);
            $tally[$outcome]++;
        }

        return $tally;
    }

    /**
     * @param DataObject $record
     * @return string one of synced, unchanged, skipped
     */
    protected function handle(DataObject $record): string
    {
        $label = $this->describe($record);

        $snapshot = $this->getSnapshotFor($record);
        if (!$snapshot?->Data) {
            return 'skipped';
        }

        $payload = $snapshot->getPayload($this->getPayloadPath());
        if (!is_array($payload)) {
            $this->report("WARN  {$label} — snapshot payload is not usable");

            return 'skipped';
        }

        $payload = $this->preparePayload($payload, $record);

        $changed = false;
        foreach ($this->getFieldMap() as $remote => $local) {
            if (!array_key_exists($remote, $payload)) {
                continue;
            }

            $value = $this->normalise($payload[$remote]);

            // Loose comparison on purpose. GraphQL hands back "1" where the
            // database holds 1, and a strict test would call that a change on
            // every single run.
            if ($record->getField($local) != $value) {
                $record->setField($local, $value);
                $changed = true;
            }
        }

        if (!$changed && $this->isPublished($record)) {
            return 'unchanged';
        }

        $record->write();

        if ($record->hasExtension(Versioned::class)) {
            $record->publishSingle();
        }

        $this->report("SYNC  {$label}");

        return 'synced';
    }

    /**
     * GraphQL booleans arrive as the strings "true" and "false", which a
     * boolean column stores as 1 either way — "false" is a non-empty string.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function normalise($value)
    {
        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        return $value;
    }

    /**
     * Is this record live?
     *
     * Asked of the database rather than isPublished(), which reads a static
     * cache that publishSingle() does not refresh — so a record published
     * moments ago still reports itself as draft, and the next run "changes"
     * nothing but publishes it again.
     *
     * @param DataObject $record
     * @return bool
     */
    protected function isPublished(DataObject $record): bool
    {
        if (!$record->hasExtension(Versioned::class)) {
            return true;
        }

        $schema = DataObject::getSchema();
        $baseTable = $schema->tableName($schema->baseDataClass(get_class($record)));

        return (bool) DB::prepared_query(
            "SELECT COUNT(*) FROM \"{$baseTable}_Live\" WHERE \"ID\" = ?",
            [$record->ID]
        )->value();
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
