<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Extension;

use SilverShop\Model\Buyable;
use SilverShop\Page\Product;
use SilverShop\Personalisation\Model\PersonalisationField;
use SilverShop\Personalisation\Service\PersonalisationService;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;

/**
 * Renders a product's personalisation fields on the add-to-cart form and (on core versions that fire it)
 * implements the pre-add `updateAddToCartData` hook that combines the entered values into the line identity for
 * variation and bundle products.
 *
 * Applied to {@link \SilverShop\Forms\AddProductForm}; because VariationForm and BundleAddProductForm both call
 * the parent constructor (which fires `updateAddProductForm`), the one field-rendering method covers plain,
 * variation and bundle forms. Plain products capture via the PersonalisationAddProductForm; variation/bundle
 * products need the core hook, so their fields render only when that hook is available.
 *
 * @extends Extension<\SilverShop\Forms\AddProductForm>
 */
class PersonalisationFormExtension extends Extension
{
    public function updateAddProductForm(): void
    {
        $form = $this->getOwner();
        $product = $this->personalisableProduct();
        if (!$product instanceof Product) {
            return;
        }

        // Variation / bundle lines can only capture personalisation through the core add-to-cart data hook
        // (the single-winner form class is already taken). Without it, render nothing rather than show fields
        // that would silently fail to split the line.
        if (!$this->isPlainProduct($product) && !PersonalisationService::captureHookAvailable()) {
            return;
        }

        $validator = $form->getValidator();

        foreach ($product->EffectivePersonalisationFields() as $field) {
            $form->Fields()->push($this->buildField($field));

            if ($field->Required && $validator instanceof RequiredFieldsValidator) {
                $validator->addRequiredField($field->inputName());
            }
        }
    }

    /**
     * The pre-add combine hook (core >= the release that adds it). Injects the personalisation hash into the
     * data and, when the form already restricts its saveable fields (e.g. a bundle), adds the hash so it still
     * travels to the cart. The snapshot JSON is written onto the line later, by the Order afterAdd extension.
     *
     * @param array<string, mixed> $data
     */
    public function updateAddToCartData(array &$data, Buyable $buyable): void
    {
        $hash = PersonalisationService::applyToData($data, $buyable);
        if ($hash === null) {
            return;
        }

        $form = $this->getOwner();
        if (!method_exists($form, 'getSaveableFields')) {
            return;
        }

        $saveable = $form->getSaveableFields();
        if ($saveable !== []) {
            $form->setSaveableFields(array_values(array_unique(array_merge($saveable, ['PersonalisationHash']))));
        }
    }

    private function buildField(PersonalisationField $field): FormField
    {
        if ($field->FieldType === 'Select') {
            $formField = DropdownField::create($field->inputName(), $field->Label, $field->optionMap());
            if (!$field->Required) {
                $formField->setEmptyString(_t(self::class . '.ChooseEmpty', '(none)'));
            } else {
                $formField->setEmptyString(_t(self::class . '.ChoosePrompt', 'Choose …'));
            }
        } else {
            $formField = TextField::create($field->inputName(), $field->Label);
            $formField->setMaxLength($field->effectiveMaxLength());
        }

        if ((string) $field->HelpText !== '') {
            $formField->setDescription($field->HelpText);
        }

        $formField->addExtraClass('personalisation-field');
        $formField->setAttribute('data-personalisation', '1');

        return $formField;
    }

    private function personalisableProduct(): ?Product
    {
        $controller = $this->getOwner()->getController();
        $record = $controller && method_exists($controller, 'data') ? $controller->data() : null;

        if (!$record instanceof Product) {
            return null;
        }

        if (!$record->hasExtension(ProductPersonalisationExtension::class)) {
            return null;
        }

        return $record->getPersonalisationActive() ? $record : null;
    }

    private function isPlainProduct(Product $product): bool
    {
        $hasVariations = $product->hasMethod('Variations') && $product->Variations()->exists();
        $isBundle = is_a($product, 'SilverShop\\ProductBundles\\Page\\BundleProduct');

        return !$hasVariations && !$isBundle;
    }
}
