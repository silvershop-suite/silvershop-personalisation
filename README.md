# SilverShop Personalisation

Per-product personalisation for [SilverShop](https://github.com/silvershop/silvershop-core): engraving, monogram
or "name on it" fields captured on the product page and attached to the **individual order line**. Two of the
same product with different text become **separate lines**, so the maker sees exactly what to make when packing,
and the entered values follow the line all the way through cart → checkout → order confirmation → account →
the CMS order screen → the invoice / packing slip.

Requires **SilverShop core ^6** (Silverstripe CMS 6, PHP 8.3+).

## What it does (v1)

- A shop admin turns personalisation **on per product** and defines **one or more fields** on a
  `Root.Personalisation` CMS tab (drag to reorder).
- Each field is **free text** (with a max length) or a **select** from preset options, with a label, optional
  help text and a required/optional toggle.
- The customer fills the fields on the product page before *Add to cart*. The entries are **captured on that
  cart/order line** and shown everywhere the line appears.
- A **"made to order — not returnable"** notice is available for the product page and the confirmation.

No surcharge, reusable field groups or category/global defaults in v1 — see [Roadmap](#roadmap).

## How line identity works

The crux is making a free-text value part of the line's identity so the cart splits lines by it, reusing
SilverShop's own per-line `required_fields` / `MatchObjectFilter` mechanism (the same machinery
`silvershop-suite/product-bundles` rides):

- A `DataExtension` on the **base** `SilverShop\Model\OrderItem` adds two columns, shared by every line type
  (plain product, variation, bundle):
  - `PersonalisationData` — an **immutable JSON snapshot** of what was entered (key / label / type / value),
    frozen at add time so the line always reads as ordered.
  - `PersonalisationHash` — a short hex hash of the entered **values**, listed in `required_fields` (via YAML),
    so `ShoppingCart::findLineItem()` splits lines by it and the cart add/remove/quantity links carry it.
- Everything is canonicalised with **NULL when empty**, so an empty optional field (or a plain, non-personalised
  line) still merges normally.
- Only the hex hash travels the cart's `Convert::raw2sql()` saveable-data path (raw2sql-safe). The JSON snapshot
  is written straight onto the line by an `Order::afterAdd` extension — a parameterised ORM write — so the free
  text round-trips intact on every database.

### The pre-add combine step

The hash must be in the filter **before** the cart matches the line, and core has no pre-add data hook plus a
single-winner add-to-cart form class. So:

- **Plain products** work with **zero core change**: a `PersonalisationAddProductForm` (swapped in via the
  existing `updateFormClass` hook) folds the entered values into the hash before adding to the cart.
- **Variation / bundle products** need a tiny upstream core hook, `updateAddToCartData(&$data, $buyable)`, fired
  in `AddProductForm`/`VariationForm::addtocart()` (proposed for a future core release). The module **detects the
  hook** (`PersonalisationService::captureHookAvailable()`) and uses it when present. When it is absent, the
  personalisation fields are **not rendered** on variation/bundle products — so a line is never silently
  mis-merged — and personalisation there needs core ≥ the release that adds the hook.

## Installation

```bash
composer require silvershop-suite/personalisation
```

Then `vendor/bin/sake db:build --flush` (or `/dev/build?flush=all`).

## Optional integrations (`suggest`)

- **silvershop-suite/invoicing** — carries the personalisation onto the immutable invoice / packing-slip / credit-memo
  line via the `updateSnapshotLine` hook (guarded; only active when invoicing is installed).
- **silvershop-suite/order-admin** — groups the order screen so the personalised line reads cleanly when packing.
- **silvershop-suite/product-bundles** — personalisation composes with bundle lines (needs the core hook above).
- **silvershop-suite/storefront** — themed product page + cart line summary.

## Templates

The module ships functional, theme-neutral includes (a Fable polish pass for the product-page field block and
the line summary is planned):

- `SilverShop/Personalisation/Includes/PersonalisationLineSummary` — the reusable per-line summary, rendered
  inside an `OrderItem` scope (cart, side-cart, checkout, confirmation, account, invoice).
- `SilverShop/Personalisation/Includes/PersonalisationNotReturnable` — the not-returnable notice (product page
  or order line).

The product-page input fields render automatically through the add-to-cart form's `$Fields` loop.

## Roadmap

**v2:** per-field price surcharge (folded into the line unit price), more field types (number, checkbox, date,
file upload), reusable `PersonalisationGroup` sets + category/global defaults with an effective-fields precedence,
Fluent translatable labels, charset/pattern validation, a per-unit-different-text mode, and an optional
order-level gift-message.

## License

BSD-3-Clause. See [LICENSE](LICENSE).

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
