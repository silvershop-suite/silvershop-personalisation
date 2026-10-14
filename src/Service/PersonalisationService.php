<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Service;

use SilverShop\Forms\AddProductForm;
use SilverShop\Model\Buyable;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Personalisation\Extension\ProductPersonalisationExtension;
use SilverShop\Personalisation\Model\PersonalisationField;

/**
 * Turns the raw personalisation inputs submitted on the product page into the two values that drive a
 * personalised order line: a canonical, human-readable snapshot ({@see snapshotJson()}) stored for display,
 * and a short identity hash ({@see identityHash()}) stored in the order item's `required_fields` so the cart
 * splits lines by it.
 *
 * The snapshot is written straight onto the order item (by the Order afterAdd extension) rather than flowing
 * through the cart's `Convert::raw2sql()` saveable-data path, so the free text survives a cart round-trip intact
 * on every database. Only the hex hash travels the saveable/filter path (raw2sql-safe).
 */
class PersonalisationService
{
    /**
     * Request-scoped map of identity hash => canonical snapshot JSON, populated during add-to-cart and read
     * back when the brand-new line is written. One request only; cleared between tests.
     *
     * @var array<string, string>
     */
    private static array $stash = [];

    /**
     * The product that carries the personalisation fields for a buyable: the buyable itself for a plain product
     * or a bundle, or the parent product for a variation. Null when the buyable is not a personalisable product.
     */
    public static function personalisableProduct(Buyable $buyable): ?Product
    {
        $product = null;
        if ($buyable instanceof Product) {
            $product = $buyable;
        } elseif ($buyable instanceof Variation) {
            $product = $buyable->Product();
        }

        if (!$product instanceof Product) {
            return null;
        }

        if (!$product->hasExtension(ProductPersonalisationExtension::class)) {
            return null;
        }

        return $product->getPersonalisationActive() ? $product : null;
    }

    /**
     * Combine the submitted personalisation inputs for a buyable into its line identity: inject the hex
     * `PersonalisationHash` into the add-to-cart data (so the cart splits the line) and stash the snapshot JSON
     * for the order item to pick up. Returns the hash, or null when the product is not personalisable or nothing
     * was entered (leaving the line plain so it can merge). Does not touch the saveable-fields set — the caller
     * decides whether the hash needs adding there (only when that set is already non-empty).
     *
     * @param array<string, mixed> $data
     */
    public static function applyToData(array &$data, Buyable $buyable): ?string
    {
        $product = self::personalisableProduct($buyable);
        if (!$product instanceof Product) {
            return null;
        }

        $canonical = self::canonicalise($data, $product->EffectivePersonalisationFields());
        $hash = self::identityHash($canonical);
        $json = self::snapshotJson($canonical);

        if ($hash === null || $json === null) {
            return null;
        }

        $data['PersonalisationHash'] = $hash;
        self::stash($hash, $json);

        return $hash;
    }

    /**
     * Build the canonical personalisation list from the raw submitted inputs for a product's fields.
     * Empty values are dropped and the result is sorted by field key, so the same entries always produce the
     * same snapshot and hash regardless of input order.
     *
     * @param array<string, mixed> $rawInputs the submitted add-to-cart data (reads each field's `inputName()`)
     * @param iterable<PersonalisationField> $fields the product's effective personalisation fields
     * @return array<int, array{key: string, label: string, type: string, value: string}>
     */
    public static function canonicalise(array $rawInputs, iterable $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $key = $field->fieldKey();
            $raw = $rawInputs[$field->inputName()] ?? null;
            $value = $field->sanitiseValue(is_scalar($raw) ? (string) $raw : '');

            if ($value === '') {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => (string) $field->Label,
                'type' => (string) $field->FieldType,
                'value' => $value,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $out;
    }

    /**
     * The immutable display snapshot: the full canonical list as JSON, or null when there is nothing entered
     * (so an all-empty personalisation leaves the line plain and able to merge).
     *
     * @param array<int, array{key: string, label: string, type: string, value: string}> $canonical
     */
    public static function snapshotJson(array $canonical): ?string
    {
        if ($canonical === []) {
            return null;
        }

        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /**
     * The line-identity hash: md5 of the {key, value} pairs only (not the label/type), so a later admin
     * relabel of a field does not retroactively stop two identically-engraved lines from merging. Null when
     * nothing is entered.
     *
     * @param array<int, array{key: string, label: string, type: string, value: string}> $canonical
     */
    public static function identityHash(array $canonical): ?string
    {
        if ($canonical === []) {
            return null;
        }

        $identity = array_map(
            static fn (array $entry): array => ['key' => $entry['key'], 'value' => $entry['value']],
            $canonical
        );

        $json = json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : md5($json);
    }

    /**
     * Remember the snapshot JSON for a hash so the order item can be filled in once the cart has created the
     * matching line.
     */
    public static function stash(string $hash, string $json): void
    {
        self::$stash[$hash] = $json;
    }

    public static function recall(string $hash): ?string
    {
        return self::$stash[$hash] ?? null;
    }

    /**
     * Clear the request-scoped stash. Primarily for tests.
     */
    public static function reset(): void
    {
        self::$stash = [];
    }

    /**
     * Whether the installed silvershop/core exposes the pre-add data hook that lets personalisation compose
     * with variation and bundle products (the single-winner add-to-cart form class cannot). Plain products
     * never need it (they use {@see \SilverShop\Personalisation\Form\PersonalisationAddProductForm}).
     */
    public static function captureHookAvailable(): bool
    {
        return method_exists(AddProductForm::class, 'prepareSaveableData');
    }
}
