<?php

namespace Otago\SharedCmsSync\Model;

use SilverStripe\ORM\DataObject;

/**
 * The raw response from one remote query, kept exactly as it arrived.
 *
 * Downloading and mapping are separate steps on purpose. The remote site is
 * only reachable while it is up, whereas a mapping is something you get wrong
 * and fix — and fixing it must not mean asking the far end for everything
 * again. With the response on disk the sync can be re-run as often as it takes
 * against the bytes that actually came back.
 *
 * It also gives you the diff. Each download compares against the stored copy
 * and does nothing when the two match, so an unchanged page is not rewritten
 * and republished every night for no reason.
 *
 * Subclass this per dataset and give it a has_one back to whatever the record
 * belongs to.
 */
class Snapshot extends DataObject
{
    private static $table_name = 'Otago_SharedCmsSync_Snapshot';

    private static $db = [
        'Data' => 'Text',
    ];

    private static $summary_fields = [
        'ID' => 'ID',
        'Created' => 'Created',
        'LastEdited' => 'Last Edited',
    ];

    private static $default_sort = 'LastEdited DESC';

    /**
     * The decoded payload, or null if this snapshot never held valid JSON.
     * @return array|null
     */
    public function getDecoded(): ?array
    {
        if (!$this->Data) {
            return null;
        }

        $decoded = json_decode($this->Data, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Reach into the decoded payload by dotted path, e.g.
     * "data.readOneProgrammeInformationPage".
     *
     * @param string $path
     * @return mixed
     */
    public function getPayload(string $path)
    {
        $value = $this->getDecoded();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
