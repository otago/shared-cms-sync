<?php

namespace Otago\SharedCmsSync\Service;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Turns collection snapshots into local records: creating what is new, updating
 * what changed, and retiring what the far end no longer publishes.
 *
 * Retiring means unpublishing, not deleting. A policy withdrawn upstream should
 * stop being findable here, but the page and its history are the only evidence
 * that it ever existed, and an editor may well need to point at it.
 */
abstract class CollectionSyncService
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
     * Class name of the local record this collection becomes.
     * @return string
     */
    abstract public function getRecordClass(): string;

    /**
     * Class name of the CollectionSnapshot subclass holding the downloads.
     * @return string
     */
    abstract public function getSnapshotClass(): string;

    /**
     * Field on the local record holding the remote id.
     * @return string
     */
    public function getRemoteIDField(): string
    {
        return 'RemoteID';
    }

    /**
     * Field holding a fingerprint of the source this record was last built
     * from. Return null to fall back to comparing field by field.
     *
     * This exists because comparing field by field cannot be made to work in
     * general. A site transforms content as it is written — rewriting links,
     * cleaning markup, resolving shortcodes — so what comes back out is not
     * what went in, and the incoming value never matches the stored one. The
     * record is then rewritten and republished on every run, for a difference
     * no one made and no one can see.
     *
     * Fingerprinting the source sidesteps all of it. If the far end is saying
     * exactly what it said last time, there is nothing to do, whatever this
     * site did to the content on the way in.
     *
     * @return string|null
     */
    public function getSourceHashField(): ?string
    {
        return 'SyncedSourceHash';
    }

    /**
     * Somewhere for new records to be created. Return 0 for records that are
     * not pages.
     * @return int
     */
    public function getParentID(): int
    {
        return 0;
    }

    /**
     * A hook for anything the field map cannot express — relations, or a value
     * that has to be assembled from several.
     *
     * @param DataObject $record
     * @param array      $payload
     * @return bool true if this changed the record
     */
    public function applyExtras(DataObject $record, array $payload): bool
    {
        return false;
    }

    /**
     * Reshape the payload before it is compared and written.
     *
     * @param array $payload
     * @return array
     */
    public function preparePayload(array $payload): array
    {
        return $payload;
    }

    /**
     * Run the sync.
     * @return array{created:int,updated:int,unchanged:int,retired:int}
     */
    public function run(): array
    {
        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 0];

        $snapshotClass = $this->getSnapshotClass();
        $recordClass = $this->getRecordClass();
        $idField = $this->getRemoteIDField();

        $liveIds = [];

        foreach ($snapshotClass::get() as $snapshot) {
            $payload = json_decode((string) $snapshot->Data, true);
            if (!is_array($payload)) {
                $this->report("WARN  remote #{$snapshot->RemoteID} holds no usable data");
                continue;
            }

            $payload = $this->preparePayload($payload);
            $liveIds[] = $snapshot->RemoteID;

            $record = $recordClass::get()->filter($idField, $snapshot->RemoteID)->first();
            $isNew = !$record;

            if ($isNew) {
                $record = $recordClass::create();
                $record->$idField = $snapshot->RemoteID;
                if ($this->getParentID() && $record->hasField('ParentID')) {
                    $record->ParentID = $this->getParentID();
                }
            }

            // If the far end is saying exactly what it said last time, and the
            // result of that is still published, there is nothing to do.
            $hashField = $this->getSourceHashField();
            $hash = $hashField ? sha1((string) $snapshot->Data) : null;

            if (
                !$isNew
                && $hash !== null
                && $record->hasField($hashField)
                && $record->getField($hashField) === $hash
                && $this->isPublished($record)
            ) {
                $tally['unchanged']++;
                continue;
            }

            $changed = $isNew;

            foreach ($this->getFieldMap() as $remote => $local) {
                if (!array_key_exists($remote, $payload)) {
                    continue;
                }

                $value = $this->normalise($payload[$remote]);

                if ($record->getField($local) != $value) {
                    $record->setField($local, $value);
                    $changed = true;
                }
            }

            if ($this->applyExtras($record, $payload)) {
                $changed = true;
            }

            if (!$changed && $this->isPublished($record)) {
                $tally['unchanged']++;
                continue;
            }

            if ($hash !== null && $record->hasField($hashField)) {
                $record->setField($hashField, $hash);
            }

            $record->write();

            if ($record->hasExtension(Versioned::class)) {
                $record->publishSingle();
            }

            $this->report(($isNew ? 'NEW   ' : 'SYNC  ') . $this->describe($record));
            $tally[$isNew ? 'created' : 'updated']++;
        }

        $tally['retired'] = $this->retire($liveIds);

        return $tally;
    }

    /**
     * Unpublish records whose snapshot has gone.
     *
     * @param array $liveIds
     * @return int
     */
    protected function retire(array $liveIds): int
    {
        $recordClass = $this->getRecordClass();
        $idField = $this->getRemoteIDField();

        $records = $recordClass::get()->exclude($idField, [null, '']);
        if ($liveIds !== []) {
            $records = $records->exclude($idField, $liveIds);
        }

        $count = 0;

        foreach ($records as $record) {
            if (!$record->hasExtension(Versioned::class)) {
                continue;
            }

            if (!$this->isPublished($record)) {
                continue;
            }

            $record->doUnpublish();
            $this->report('RETIRE ' . $this->describe($record) . ' — withdrawn upstream');
            $count++;
        }

        return $count;
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
     * Asked of the database, not isPublished(), whose static cache
     * publishSingle() does not refresh.
     *
     * @param DataObject $record
     * @return bool
     */
    protected function isPublished(DataObject $record): bool
    {
        if (!$record->hasExtension(Versioned::class) || !$record->ID) {
            return $record->ID ? true : false;
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
