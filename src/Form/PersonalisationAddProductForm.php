<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Form;

use SilverShop\Forms\AddProductForm;
use SilverShop\Model\Buyable;
use SilverShop\Personalisation\Service\PersonalisationService;

/**
 * Add-to-cart form for a plain personalisable product. Selected in by
 * {@see \SilverShop\Personalisation\Extension\ProductPersonalisationExtension::updateFormClass()} when the
 * product is personalisable and has no variations and is not a bundle.
 *
 * Its only job is the pre-add combine that core (before it ships the `updateAddToCartData` hook) has no slot for:
 * fold the entered values into the line's `PersonalisationHash` before `parent::addtocart()` runs the cart's
 * match. It deliberately leaves the saveable-fields set empty so the whole (un-escaped) data array carries the
 * hash — the snapshot JSON itself is written onto the line by the Order afterAdd extension, never through
 * `Convert::raw2sql()`. When the core hook is present, that hook does the combine instead and this is a no-op.
 */
class PersonalisationAddProductForm extends AddProductForm
{
    public function addtocart(array $data, $form)
    {
        $buyable = $this->getBuyable($data);

        if ($buyable instanceof Buyable && !PersonalisationService::captureHookAvailable()) {
            PersonalisationService::applyToData($data, $buyable);
        }

        return parent::addtocart($data, $form);
    }
}
