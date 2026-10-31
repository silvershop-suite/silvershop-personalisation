<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Tests;

use SilverShop\Page\Product;
use SilverShop\Personalisation\Model\PersonalisationField;
use SilverShop\Personalisation\Service\PersonalisationService;
use SilverStripe\Dev\SapphireTest;

class PersonalisationServiceTest extends SapphireTest
{
    protected static $fixture_file = 'fixtures/personalisation.yml';

    protected function tearDown(): void
    {
        PersonalisationService::reset();
        parent::tearDown();
    }

    private function fields(): array
    {
        return [
            $this->objFromFixture(PersonalisationField::class, 'engraving'),
            $this->objFromFixture(PersonalisationField::class, 'font'),
        ];
    }

    public function testCanonicaliseKeepsEnteredFieldsSortedAndDropsEmpty(): void
    {
        $fields = $this->fields();
        $engraving = $fields[0];

        $canonical = PersonalisationService::canonicalise(
            [$engraving->inputName() => 'John', $fields[1]->inputName() => ''],
            $fields
        );

        $this->assertCount(1, $canonical, 'empty optional field is dropped');
        $this->assertSame($engraving->fieldKey(), $canonical[0]['key']);
        $this->assertSame('Name to engrave', $canonical[0]['label']);
        $this->assertSame('Text', $canonical[0]['type']);
        $this->assertSame('John', $canonical[0]['value']);
    }

    public function testIdentityHashSplitsOnDifferentTextAndMergesOnSame(): void
    {
        $fields = $this->fields();
        $name = $fields[0]->inputName();

        $john = PersonalisationService::identityHash(
            PersonalisationService::canonicalise([$name => 'John'], $fields)
        );
        $johnAgain = PersonalisationService::identityHash(
            PersonalisationService::canonicalise([$name => 'John'], $fields)
        );
        $jane = PersonalisationService::identityHash(
            PersonalisationService::canonicalise([$name => 'Jane'], $fields)
        );

        $this->assertNotNull($john);
        $this->assertSame($john, $johnAgain, 'identical text produces the same hash (lines merge)');
        $this->assertNotSame($john, $jane, 'different text produces a different hash (lines split)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $john);
    }

    public function testEmptyPersonalisationHashesAndJsonAreNull(): void
    {
        $fields = $this->fields();

        $canonical = PersonalisationService::canonicalise(
            [$fields[0]->inputName() => '   ', $fields[1]->inputName() => ''],
            $fields
        );

        $this->assertSame([], $canonical);
        $this->assertNull(PersonalisationService::identityHash($canonical), 'empty => null so plain lines merge');
        $this->assertNull(PersonalisationService::snapshotJson($canonical));
    }

    public function testIdentityHashIgnoresLabelChanges(): void
    {
        $withLabelA = [['key' => 'pf1', 'label' => 'Name', 'type' => 'Text', 'value' => 'John']];
        $withLabelB = [['key' => 'pf1', 'label' => 'Engrave this', 'type' => 'Text', 'value' => 'John']];

        $this->assertSame(
            PersonalisationService::identityHash($withLabelA),
            PersonalisationService::identityHash($withLabelB),
            'a later relabel must not stop two identically-engraved lines from merging'
        );
    }

    public function testSnapshotJsonIsValidUnicodeSafeJson(): void
    {
        $fields = $this->fields();
        $json = PersonalisationService::snapshotJson(
            PersonalisationService::canonicalise([$fields[0]->inputName() => 'Renée ❤'], $fields)
        );

        $this->assertNotNull($json);
        $decoded = json_decode((string) $json, true);
        $this->assertSame('Renée ❤', $decoded[0]['value'], 'unicode survives the snapshot');
    }

    public function testApplyToDataInjectsHashAndStashesSnapshot(): void
    {
        $mug = $this->objFromFixture(Product::class, 'mug');
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');

        $data = [$engraving->inputName() => 'John', 'Quantity' => 1];
        $hash = PersonalisationService::applyToData($data, $mug);

        $this->assertNotNull($hash);
        $this->assertSame($hash, $data['PersonalisationHash'] ?? null);
        $this->assertNotNull(PersonalisationService::recall((string) $hash), 'snapshot is stashed by hash');
    }

    public function testApplyToDataReturnsNullWhenNothingEnteredOrNotPersonalisable(): void
    {
        $mug = $this->objFromFixture(Product::class, 'mug');
        $plain = $this->objFromFixture(Product::class, 'plainmug');
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');

        $empty = ['Quantity' => 1];
        $this->assertNull(PersonalisationService::applyToData($empty, $mug), 'nothing entered => plain line');
        $this->assertArrayNotHasKey('PersonalisationHash', $empty);

        $data = [$engraving->inputName() => 'John'];
        $this->assertNull(PersonalisationService::applyToData($data, $plain), 'not personalisable => no hash');
    }

    /**
     * Security regression: PersonalisationHash feeds the cart's line-matching WHERE (OrderItem.required_fields) and
     * must never be client-controlled, or a submitted value reaches that query unescaped (SQL injection). applyToData
     * must strip any inbound value on every path and only ever emit a server-computed md5.
     */
    public function testApplyToDataStripsClientSuppliedHash(): void
    {
        $mug = $this->objFromFixture(Product::class, 'mug');
        $plain = $this->objFromFixture(Product::class, 'plainmug');
        $engraving = $this->objFromFixture(PersonalisationField::class, 'engraving');
        $injection = "' OR '1'='1";

        // Plain product: the client value must be dropped entirely (line stays plain, nothing hits the filter).
        $data = ['Quantity' => 1, 'PersonalisationHash' => $injection];
        $this->assertNull(PersonalisationService::applyToData($data, $plain), 'not personalisable => no hash');
        $this->assertArrayNotHasKey('PersonalisationHash', $data, 'client hash stripped for a plain product');

        // Personalisable product, nothing entered: still dropped (plain line, able to merge).
        $empty = ['Quantity' => 1, 'PersonalisationHash' => $injection];
        $this->assertNull(PersonalisationService::applyToData($empty, $mug), 'nothing entered => plain line');
        $this->assertArrayNotHasKey('PersonalisationHash', $empty, 'client hash stripped when nothing entered');

        // Personalisable product with input: the client value is replaced by the server-computed md5, never kept.
        $data = [$engraving->inputName() => 'John', 'Quantity' => 1, 'PersonalisationHash' => $injection];
        $hash = PersonalisationService::applyToData($data, $mug);
        $this->assertNotNull($hash);
        $this->assertSame($hash, $data['PersonalisationHash'] ?? null);
        $this->assertNotSame($injection, $data['PersonalisationHash'], 'client hash overwritten, not trusted');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $data['PersonalisationHash']);
    }
}
