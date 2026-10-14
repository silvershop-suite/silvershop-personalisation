<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Adds the per-line personalisation snapshot to the base {@link \SilverShop\Model\OrderItem}, so it is shared
 * by every line type (plain product, variation, bundle) through the one base table.
 *
 *  - `PersonalisationData` — an immutable JSON snapshot of what the customer entered (key/label/type/value),
 *    frozen at add time and never re-derived from the (mutable) product, so the line always reads as ordered.
 *  - `PersonalisationHash` — a short hex identity of the entered values, listed in the order item's
 *    `required_fields` (via YAML) so core's cart matching splits lines by it and the cart add/remove/quantity
 *    links carry it (base `uniquedata()` already includes every `required_fields` value — a plain line keeps a
 *    NULL hash, which `http_build_query` drops and `MatchObjectFilter` matches as `IS NULL`, so plain lines
 *    still merge; no `uniquedata()` override is needed or wanted).
 *
 * @property ?string $PersonalisationData
 * @property ?string $PersonalisationHash
 * @extends Extension<\SilverShop\Model\OrderItem>
 */
class OrderItemPersonalisationExtension extends Extension
{
    private static array $db = [
        'PersonalisationData' => 'Text',
        'PersonalisationHash' => 'Varchar(32)',
    ];

    /**
     * The entered personalisation as a list of {Label, Value, Type} for templates (cart, checkout,
     * confirmation, account, invoice). Empty when the line is not personalised.
     */
    public function PersonalisationSummary(): ArrayList
    {
        $list = ArrayList::create();

        $json = (string) $this->getOwner()->getField('PersonalisationData');
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

    /**
     * Whether this line carries personalisation (used to show the "made to order / not returnable" notice and
     * the line summary).
     */
    public function getIsPersonalised(): bool
    {
        return (string) $this->getOwner()->getField('PersonalisationHash') !== '';
    }

    /**
     * A compact one-line "Label: Value · Label: Value" rendering for the CMS order-item grid column so the
     * maker sees what to make when packing.
     */
    public function getPersonalisationCMSSummary(): string
    {
        $parts = [];
        foreach ($this->PersonalisationSummary() as $entry) {
            $parts[] = $entry->Label . ': ' . $entry->Value;
        }

        return implode(' · ', $parts);
    }

    /**
     * Replace the raw JSON/hash scaffolded fields with a readable read-only summary when an order item is
     * opened in the CMS.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['PersonalisationData', 'PersonalisationHash']);

        if (!$this->getIsPersonalised()) {
            return;
        }

        $fields->push(
            ReadonlyField::create(
                'PersonalisationReadonly',
                _t(self::class . '.Personalisation', 'Personalisation'),
                $this->getPersonalisationCMSSummary()
            )
        );
    }

    /**
     * Localise the CMS order-item grid column header added via config.
     *
     * @param array<string, string> $labels
     */
    public function updateFieldLabels(array &$labels): void
    {
        $labels['PersonalisationCMSSummary'] = _t(self::class . '.Personalisation', 'Personalisation');
    }
}
