<?php

namespace Otago\SharedCmsSync\Model;

/**
 * A snapshot of one item from a remote collection, keyed by that item's id on
 * the remote site rather than by a local record.
 *
 * The per-record Snapshot assumes the local site already knows what it wants —
 * Auckland lists its own programme pages and asks about each one. A collection
 * is the other way round: the marketing site does not know what policies exist
 * until it asks, and the answer is also how it finds out that one has been
 * added or taken down.
 *
 * So the remote id is the key, and it is what lets the sync tell "this is new"
 * from "this changed" from "this is gone".
 */
class CollectionSnapshot extends Snapshot
{
    private static $table_name = 'Otago_SharedCmsSync_CollectionSnapshot';

    private static $db = [
        'RemoteID' => 'Varchar(64)',
    ];

    private static $indexes = [
        'RemoteID' => true,
    ];

    private static $summary_fields = [
        'RemoteID' => 'Remote ID',
        'Created' => 'Created',
        'LastEdited' => 'Last Edited',
    ];

    public function getTitle()
    {
        return "Remote #{$this->RemoteID}";
    }
}
