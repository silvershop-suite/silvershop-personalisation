<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Tests;

use SilverShop\Forms\AddProductForm;
use SilverShop\Page\Product;
use SilverShop\Page\ProductController;
use SilverShop\Personalisation\Form\PersonalisationAddProductForm;
use SilverShop\Personalisation\Model\PersonalisationField;
use SilverShop\Personalisation\Service\PersonalisationService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;

class PersonalisationFormTest extends SapphireTest
{
    protected static $fixture_file = 'fixtures/personalisation.yml';

    public static $disable_theme = true;

    protected static $use_draft_site = false;

    protected function tearDown(): void
    {
        PersonalisationService::reset();
        parent::tearDown();
    }

    private function formFor(string $productKey): AddProductForm
    {
        $product = $this->objFromFixture(Product::class, $productKey);
        $product->publishSingle();
        $controller = ProductController::create($product);

        return $controller->Form();
    }

    public function testPlainPersonalisableProductUsesCaptureFormWithFields(): void
    {
        $form = $this->formFor('mug');
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');
        $font = $this->objFromFixture(PersonalisationField::class, 'font');

        $this->assertInstanceOf(PersonalisationAddProductForm::class, $form);
        $this->assertNotNull($form->Fields()->dataFieldByName($engraving->inputName()));
        $this->assertNotNull($form->Fields()->dataFieldByName($font->inputName()));
    }

    public function testRequiredFieldIsAddedToTheValidator(): void
    {
        $form = $this->formFor('mug');
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving'); // Required
        $font = $this->objFromFixture(PersonalisationField::class, 'font'); // optional

        $validator = $form->getValidator();
        $this->assertInstanceOf(RequiredFieldsValidator::class, $validator);
        $this->assertTrue($validator->fieldIsRequired($engraving->inputName()));
        $this->assertFalse($validator->fieldIsRequired($font->inputName()));
    }

    public function testDisabledProductRendersNoPersonalisationFieldsAndPlainForm(): void
    {
        $form = $this->formFor('plainmug');

        $this->assertNotInstanceOf(PersonalisationAddProductForm::class, $form);
        foreach ($form->Fields() as $field) {
            $this->assertStringStartsNotWith('Personalisation_', (string) $field->getName());
        }
    }

    public function testUpdateAddToCartDataHookInjectsHashAndStashes(): void
    {
        $form = $this->formFor('mug');
        $mug = $this->objFromFixture(Product::class, 'mug');
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');

        $data = [$engraving->inputName() => 'John', 'Quantity' => 1];
        $form->extend('updateAddToCartData', $data, $mug);

        $this->assertArrayHasKey('PersonalisationHash', $data);
        $this->assertNotNull(PersonalisationService::recall((string) $data['PersonalisationHash']));
    }
}
