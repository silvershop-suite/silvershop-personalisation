<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Model;

use SilverShop\Page\Product;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * One personalisation field a customer fills in on a product page — e.g. "Name to engrave" (free text) or
 * "Font" (a select from preset options). Defined per product; the entered value is captured on the order line.
 *
 * @property string $Label
 * @property string $HelpText
 * @property string $FieldType
 * @property bool   $Required
 * @property int    $MaxLength
 * @property string $Options
 * @property int    $Sort
 * @property int    $ProductID
 * @method Product Product()
 */
class PersonalisationField extends DataObject
{
    private static string $table_name = 'SilverShop_PersonalisationField';

    private static array $db = [
        'Label' => 'Varchar',
        'HelpText' => 'Varchar(255)',
        'FieldType' => "Enum('Text,Select','Text')",
        'Required' => 'Boolean',
        'MaxLength' => 'Int',
        'Options' => 'Text',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Product' => Product::class,
    ];

    private static string $default_sort = '"Sort" ASC, "ID" ASC';

    private static array $summary_fields = [
        'Label' => 'Label',
        'FieldType' => 'FieldType',
        'Required.Nice' => 'Required',
    ];

    public function summaryFields(): array
    {
        return [
            'Label' => _t(self::class . '.db_Label', 'Label'),
            'FieldType' => _t(self::class . '.db_FieldType', 'Field type'),
            'Required.Nice' => _t(self::class . '.db_Required', 'Required'),
        ];
    }

    private static string $singular_name = 'Personalisation field';

    private static string $plural_name = 'Personalisation fields';

    private static int $default_max_length = 60;

    /**
     * A stable, request-safe key used both as the form input name fragment (`Personalisation[<key>]`) and as
     * the canonical key in the line snapshot. Based on the record ID, which never changes once created.
     */
    public function fieldKey(): string
    {
        return 'pf' . (int) $this->ID;
    }

    /**
     * The flat form input name for this field, e.g. `Personalisation_pf12`. Flat (not bracketed) so a required
     * field is enforceable by the standard RequiredFieldsValidator, which keys on the literal field name.
     */
    public function inputName(): string
    {
        return 'Personalisation_' . $this->fieldKey();
    }

    /**
     * The configured select options, one per line, trimmed and de-duplicated.
     *
     * @return array<int, string>
     */
    public function optionValues(): array
    {
        $raw = (string) $this->Options;
        if (trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Options as a value => label map for a DropdownField.
     *
     * @return array<string, string>
     */
    public function optionMap(): array
    {
        $values = $this->optionValues();

        return $values === [] ? [] : array_combine($values, $values);
    }

    /**
     * The effective maximum character length for a free-text field (0/unset falls back to the configured
     * default so the stored value can never grow unbounded).
     */
    public function effectiveMaxLength(): int
    {
        $max = (int) $this->MaxLength;

        return $max > 0 ? $max : (int) self::config()->get('default_max_length');
    }

    /**
     * Clean and constrain a submitted value for this field: strip tags and control characters, collapse
     * whitespace, enforce the max length for free text, and accept only a configured option for a select.
     * Returns '' when nothing usable remains (so an empty optional field never splits a cart line).
     */
    public function sanitiseValue(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if ($this->FieldType === 'Select') {
            return in_array($value, $this->optionValues(), true) ? $value : '';
        }

        $max = $this->effectiveMaxLength();
        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        return $value;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['ProductID', 'Sort']);

        $fields->dataFieldByName('HelpText')?->setDescription(_t(
            self::class . '.HelpTextDesc',
            'Shown under the field on the product page to guide the customer.'
        ));
        $fields->dataFieldByName('FieldType')?->setDescription(_t(
            self::class . '.FieldTypeDesc',
            'Free text (with a max length) or a select from the options below.'
        ));
        $fields->dataFieldByName('MaxLength')?->setDescription(_t(
            self::class . '.MaxLengthDesc',
            'Maximum characters for a free-text field. Leave 0 to use the default ({default}).',
            ['default' => (int) self::config()->get('default_max_length')]
        ));
        $fields->dataFieldByName('Options')?->setDescription(_t(
            self::class . '.OptionsDesc',
            'One option per line. Only used when the field type is "Select".'
        ));

        return $fields;
    }

    public function canView($member = null): bool
    {
        $product = $this->Product();

        return $product && $product->exists() ? $product->canView($member) : parent::canView($member);
    }

    public function canEdit($member = null): bool
    {
        $product = $this->Product();

        return $product && $product->exists()
            ? $product->canEdit($member)
            : Permission::check('CMS_ACCESS', 'any', $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canEdit($member);
    }
}
