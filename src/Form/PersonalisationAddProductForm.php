<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Form;

use SilverShop\Forms\AddProductForm;

/**
 * Add-to-cart form for a plain personalisable product. Selected in by
 * {@see \SilverShop\Personalisation\Extension\ProductPersonalisationExtension::updateFormClass()} when the
 * product is personalisable and has no variations and is not a bundle.
 *
 * A thin marker subclass: the pre-add combine is done by core's `updateAddToCartData` hook (implemented in
 * {@see \SilverShop\Personalisation\Extension\PersonalisationFormExtension}), which folds the entered values into
 * the line's `PersonalisationHash` before `parent::addtocart()` runs the cart match. The snapshot JSON itself is
 * written onto the line by the Order afterAdd extension, never through `Convert::raw2sql()`.
 */
class PersonalisationAddProductForm extends AddProductForm
{
}
