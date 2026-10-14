<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Extension;

use SilverShop\Model\OrderItem;
use SilverStripe\Core\Extension;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;

/**
 * Optional (suggest) integration with silvershop/invoicing: copies the order line's personalisation snapshot
 * onto the immutable invoice / packing-slip / credit-memo line at snapshot time, via the module's
 * `updateSnapshotLine` hook, and exposes it for the document templates.
 *
 * @property ?string $Personalisation
 * @extends Extension<\SilverStripe\ORM\DataObject>
 */
class InvoiceLinePersonalisationExtension extends Extension
{
    private static array $db = [
        'Personalisation' => 'Text',
    ];

    /**
     * Freeze the source order item's personalisation onto this document line when it is snapshotted.
     *
     * @param DataObject $source the order item or modifier the line was snapshotted from
     * @param DataObject $order  the order being invoiced
     */
    public function updateSnapshotLine(DataObject $source, DataObject $order): void
    {
        if (!$source instanceof OrderItem) {
            return;
        }

        $json = (string) $source->getField('PersonalisationData');
        if ($json !== '') {
            $this->getOwner()->setField('Personalisation', $json);
        }
    }

    /**
     * The personalisation entered on this line as a list of {Label, Value, Type} for the document templates.
     */
    public function PersonalisationSummary(): ArrayList
    {
        $list = ArrayList::create();

        $json = (string) $this->getOwner()->getField('Personalisation');
        if ($json === '') {
            return $list;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $list;
        }

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $list->push(ArrayData::create([
                'Label' => (string) ($entry['label'] ?? ''),
                'Value' => (string) ($entry['value'] ?? ''),
                'Type' => (string) ($entry['type'] ?? 'Text'),
            ]));
        }

        return $list;
    }

    public function getIsPersonalised(): bool
    {
        return (string) $this->getOwner()->getField('Personalisation') !== '';
    }
}
