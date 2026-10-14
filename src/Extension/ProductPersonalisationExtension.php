<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Extension;

use SilverShop\Forms\AddProductForm;
use SilverShop\Personalisation\Form\PersonalisationAddProductForm;
use SilverShop\Personalisation\Model\PersonalisationField;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\HasManyList;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * Makes a {@link \SilverShop\Page\Product} personalisable: an enable gate plus an ordered set of
 * {@link PersonalisationField} definitions managed on a Root.Personalisation CMS tab (mirroring the core
 * variations tab). Also swaps the add-to-cart form for a plain personalisable product to the capture form, so
 * personalisation works with no core change for the common single-product case.
 *
 * @property bool $PersonalisationEnabled
 * @method HasManyList<PersonalisationField> PersonalisationFields()
 * @extends Extension<\SilverShop\Page\Product>
 */
class ProductPersonalisationExtension extends Extension
{
    private static array $db = [
        'PersonalisationEnabled' => 'Boolean',
    ];

    private static array $has_many = [
        'PersonalisationFields' => PersonalisationField::class,
    ];

    private static array $cascade_deletes = [
        'PersonalisationFields',
    ];

    private static array $cascade_duplicates = [
        'PersonalisationFields',
    ];

    private static array $scaffold_cms_fields_settings = [
        'ignoreRelations' => [
            'PersonalisationFields',
        ],
    ];

    /**
     * Whether this product should present personalisation fields: the gate is on and at least one field is
     * defined.
     */
    public function getPersonalisationActive(): bool
    {
        return (bool) $this->getOwner()->getField('PersonalisationEnabled')
            && $this->getOwner()->PersonalisationFields()->exists();
    }

    /**
     * The personalisation fields to render for this product, in order. Empty when personalisation is off.
     * A single accessor so v2 can merge in reusable groups and category/global defaults by precedence without
     * touching callers.
     *
     * @return SS_List<covariant PersonalisationField>
     */
    public function EffectivePersonalisationFields(): SS_List
    {
        if (!$this->getOwner()->getPersonalisationActive()) {
            return ArrayList::create();
        }

        return $this->getOwner()->PersonalisationFields()->sort('Sort');
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();

        $enable = CheckboxField::create(
            'PersonalisationEnabled',
            _t(self::class . '.Enable', 'Let customers personalise this product')
        )->setDescription(_t(
            self::class . '.EnableDesc',
            'When on, the fields below appear on the product page and are captured on each order line.'
        ));

        if (!$owner->exists()) {
            $fields->addFieldsToTab('Root.Personalisation', [
                $enable,
                LiteralField::create(
                    'PersonalisationSaveFirst',
                    '<p class="message notice">' . _t(
                        self::class . '.SaveFirst',
                        'Save this product first, then add the personalisation fields.'
                    ) . '</p>'
                ),
            ]);

            return;
        }

        $config = GridFieldConfig_RecordEditor::create();
        $config->addComponent(GridFieldOrderableRows::create('Sort'));

        $fields->addFieldsToTab('Root.Personalisation', [
            $enable,
            LiteralField::create(
                'PersonalisationInfo',
                '<p class="message notice">' . _t(
                    self::class . '.Info',
                    'Add one field per thing the customer enters (e.g. "Name to engrave"). Free text has a max '
                    . 'length; a select uses the options you list. Each line a customer personalises differently '
                    . 'becomes its own cart line.'
                ) . '</p>'
            ),
            GridField::create(
                'PersonalisationFields',
                _t(self::class . '.Fields', 'Personalisation fields'),
                $owner->PersonalisationFields(),
                $config
            ),
        ]);
    }

    /**
     * For a plain personalisable product (no variations, not a bundle) swap in the capture form, which combines
     * the entered values into the stored snapshot before adding to the cart — no core change needed. Guarded so
     * it never clobbers the variation or bundle form (those compose via the core add-to-cart data hook instead).
     */
    public function updateFormClass(string &$formClass): void
    {
        if (!$this->getOwner()->getPersonalisationActive()) {
            return;
        }

        if ($formClass !== AddProductForm::class) {
            return;
        }

        $formClass = PersonalisationAddProductForm::class;
    }
}
