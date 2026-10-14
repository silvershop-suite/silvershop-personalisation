<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Tests;

use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\OrderItem;
use SilverShop\Page\Product;
use SilverShop\Personalisation\Model\PersonalisationField;
use SilverShop\Personalisation\Service\PersonalisationService;
use SilverStripe\Dev\SapphireTest;

/**
 * The crux: a free-text personalisation value becomes part of the cart line's identity, so lines split on
 * different text, merge on identical/empty text, and the snapshot is frozen on the line. Exercises the plain
 * product path (no core hook) end to end through ShoppingCart::add().
 */
class PersonalisationCartTest extends SapphireTest
{
    protected static $fixture_file = 'fixtures/personalisation.yml';

    public static $disable_theme = true;

    protected static $use_draft_site = false;

    protected ShoppingCart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        ShoppingCart::singleton()->clear();
        $this->cart = ShoppingCart::singleton();

        foreach (['mug', 'plainmug'] as $key) {
            $this->objFromFixture(Product::class, $key)->publishSingle();
        }
    }

    protected function tearDown(): void
    {
        $this->cart->clear();
        PersonalisationService::reset();
        parent::tearDown();
    }

    private function mug(): Product
    {
        return $this->objFromFixture(Product::class, 'mug');
    }

    /**
     * Add the mug with the given engraving text through the same combine the plain add-to-cart form runs,
     * then through the real cart, and return the resulting line.
     */
    private function addEngraved(?string $text): ?OrderItem
    {
        $mug = $this->mug();
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');

        $data = ['Quantity' => 1];
        if ($text !== null) {
            $data[$engraving->inputName()] = $text;
        }
        PersonalisationService::applyToData($data, $mug);

        $result = $this->cart->add($mug, 1, $data);

        return $result instanceof OrderItem ? $result : null;
    }

    public function testDifferentTextCreatesSeparateLines(): void
    {
        $this->addEngraved('John');
        $this->addEngraved('Jane');

        $items = $this->cart->current()->Items();
        $this->assertCount(2, $items, 'two different engravings are two lines');
        $this->assertEqualsCanonicalizing(['John', 'Jane'], $this->engravedValues($items));
    }

    public function testIdenticalTextMergesToOneLineWithQuantityTwo(): void
    {
        $this->addEngraved('John');
        $this->addEngraved('John');

        $items = $this->cart->current()->Items();
        $this->assertCount(1, $items, 'identical engraving merges');
        $this->assertSame(2, (int) $items->first()->Quantity);
    }

    public function testEmptyPersonalisationMergesButIsDistinctFromEngravedLine(): void
    {
        $this->addEngraved(null); // plain mug, no personalisation
        $this->addEngraved(null); // merges with the plain line
        $this->addEngraved('John'); // distinct, personalised line

        $items = $this->cart->current()->Items();
        $this->assertCount(2, $items, 'plain merges with plain; engraved is its own line');

        $plain = $items->filter('PersonalisationHash', null)->first();
        $this->assertNotNull($plain);
        $this->assertSame(2, (int) $plain->Quantity, 'the two plain adds merged');
    }

    public function testSnapshotIsStoredOnTheLineAndParsed(): void
    {
        $item = $this->addEngraved('John');

        $this->assertInstanceOf(OrderItem::class, $item);
        $this->assertTrue((bool) $item->getIsPersonalised());

        $reloaded = OrderItem::get()->byID($item->ID);
        $summary = $reloaded->PersonalisationSummary();
        $this->assertCount(1, $summary);
        $this->assertSame('Name to engrave', $summary->first()->Label);
        $this->assertSame('John', $summary->first()->Value);
    }

    public function testSnapshotIsFrozenAndNotReDerivedFromTheProduct(): void
    {
        $item = $this->addEngraved('John');
        $this->assertInstanceOf(OrderItem::class, $item);

        // Rename the field on the product after the line was added.
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');
        $engraving->Label = 'Totally different label';
        $engraving->write();

        // The placed/stored line keeps the label as it was ordered.
        $reloaded = OrderItem::get()->byID($item->ID);
        $reloaded->onPlacement();
        $this->assertSame('Name to engrave', $reloaded->PersonalisationSummary()->first()->Label);
        $this->assertSame('John', $reloaded->PersonalisationSummary()->first()->Value);
    }

    public function testUniqueDataCarriesHashForEngravedLineAndNotForPlain(): void
    {
        $engraved = $this->addEngraved('John');
        $this->assertInstanceOf(OrderItem::class, $engraved);
        $unique = $engraved->uniquedata();
        $this->assertArrayHasKey('PersonalisationHash', $unique);
        $this->assertSame($engraved->PersonalisationHash, $unique['PersonalisationHash']);
        $this->assertNotEmpty($unique['PersonalisationHash']);

        $this->cart->clear();
        $plain = $this->addEngraved(null);
        $this->assertInstanceOf(OrderItem::class, $plain);
        // A plain line keeps a NULL hash, which http_build_query drops and MatchObjectFilter matches as IS NULL.
        $this->assertEmpty((string) $plain->uniquedata()['PersonalisationHash']);
    }

    /**
     * @param iterable<OrderItem> $items
     * @return array<int, string>
     */
    private function engravedValues(iterable $items): array
    {
        $values = [];
        foreach ($items as $item) {
            foreach ($item->PersonalisationSummary() as $entry) {
                $values[] = $entry->Value;
            }
        }

        return $values;
    }
}
