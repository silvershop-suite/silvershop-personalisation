<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Tests;

use SilverShop\Personalisation\Model\PersonalisationField;
use SilverStripe\Dev\SapphireTest;

class PersonalisationFieldTest extends SapphireTest
{
    protected static $fixture_file = 'fixtures/personalisation.yml';

    public function testFieldKeyAndInputNameAreStable(): void
    {
        $field = $this->objFromFixture(PersonalisationField::class, 'engraving');

        $this->assertSame('pf' . $field->ID, $field->fieldKey());
        $this->assertSame('Personalisation_pf' . $field->ID, $field->inputName());
    }

    public function testSanitiseStripsTagsAndControlCharacters(): void
    {
        $field = $this->objFromFixture(PersonalisationField::class, 'engraving');

        $this->assertSame('alert hi', $field->sanitiseValue("<script>alert</script> hi"));
        $this->assertSame('a b', $field->sanitiseValue("a\x00\x07b"));
        $this->assertSame('one two', $field->sanitiseValue("one    two"));
    }

    public function testSanitiseTruncatesFreeTextToMaxLength(): void
    {
        $field = $this->objFromFixture(PersonalisationField::class, 'engraving'); // MaxLength 20

        $value = $field->sanitiseValue(str_repeat('a', 50));

        $this->assertSame(20, mb_strlen($value), 'free text is capped at the configured max length');
    }

    public function testSanitiseFreeTextFallsBackToDefaultMaxLength(): void
    {
        $field = PersonalisationField::create(['FieldType' => 'Text', 'MaxLength' => 0]);

        $value = $field->sanitiseValue(str_repeat('b', 500));

        $this->assertSame((int) PersonalisationField::config()->get('default_max_length'), mb_strlen($value));
    }

    public function testSanitiseSelectAcceptsOnlyConfiguredOptions(): void
    {
        $field = $this->objFromFixture(PersonalisationField::class, 'font'); // Serif / Sans / Script

        $this->assertSame('Serif', $field->sanitiseValue('Serif'));
        $this->assertSame('', $field->sanitiseValue('Comic Sans'), 'an unlisted option is rejected');
    }

    public function testOptionValuesParsesOnePerLine(): void
    {
        $field = $this->objFromFixture(PersonalisationField::class, 'font');

        $this->assertSame(['Serif', 'Sans', 'Script'], $field->optionValues());
        $this->assertSame(['Serif' => 'Serif', 'Sans' => 'Sans', 'Script' => 'Script'], $field->optionMap());
    }
}
