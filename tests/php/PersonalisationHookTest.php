<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Tests;

use SilverShop\Forms\AddProductForm;
use SilverShop\Model\Buyable;
use SilverShop\Page\Product;
use SilverShop\Page\ProductController;
use SilverShop\Personalisation\Model\PersonalisationField;
use SilverShop\Personalisation\Service\PersonalisationService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;

/**
 * Stubs the core add-to-cart data hook (shipped in a later core release) so the variation/bundle capture path
 * — where the form restricts its saveable fields and the hook must append the personalisation hash — is covered
 * in the clean room against tagged core, without that core release being present.
 */
class PersonalisationHookTest extends SapphireTest
{
    protected static $fixture_file = 'fixtures/personalisation.yml';

    public static $disable_theme = true;

    protected static $use_draft_site = false;

    protected function tearDown(): void
    {
        PersonalisationService::reset();
        parent::tearDown();
    }

    public function testHookAppendsHashToARestrictedSaveableSet(): void
    {
        $mug = $this->objFromFixture(Product::class, 'mug');
        $mug->publishSingle();
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');

        $form = PersonalisationHookStubForm::create(ProductController::create($mug), 'Form');
        // Simulate a bundle line that has already restricted the saveable data to its own field.
        $form->setSaveableFields(['SelectedItemIDs']);

        $data = [
            $engraving->inputName() => 'John',
            'SelectedItemIDs' => '5',
            'Quantity' => 1,
        ];
        $saveable = $form->prepareSaveableData($data, $mug);

        // The hook added the identity hash to the restricted saveable set, so it still reaches the cart filter
        // alongside the bundle's own field.
        $this->assertContains('PersonalisationHash', $form->getSaveableFields());
        $this->assertContains('SelectedItemIDs', $form->getSaveableFields());
        // The returned saveable data (what core passes to the cart) carries both the hash and the bundle field.
        $this->assertArrayHasKey('PersonalisationHash', $saveable);
        $this->assertArrayHasKey('SelectedItemIDs', $saveable);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $saveable['PersonalisationHash']);
        $this->assertNotNull(PersonalisationService::recall((string) $saveable['PersonalisationHash']));
    }
}

/**
 * Minimal stand-in for silvershop/core once it ships the add-to-cart data hook: exposes the saveable fields and
 * fires `updateAddToCartData` before restricting the data, exactly as the core refactor does.
 */
class PersonalisationHookStubForm extends AddProductForm implements TestOnly
{
    public function getSaveableFields(): array
    {
        return $this->saveablefields;
    }

    public function prepareSaveableData(array $data, Buyable $buyable): array
    {
        $this->extend('updateAddToCartData', $data, $buyable);

        if ($this->saveablefields === []) {
            return $data;
        }

        return array_intersect_key($data, array_combine($this->saveablefields, $this->saveablefields));
    }
}
