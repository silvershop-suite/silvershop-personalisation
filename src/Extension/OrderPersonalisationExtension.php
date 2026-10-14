<?php

declare(strict_types=1);

namespace SilverShop\Personalisation\Extension;

use SilverShop\Model\Buyable;
use SilverShop\Model\OrderItem;
use SilverShop\Personalisation\Service\PersonalisationService;
use SilverStripe\Core\Extension;

/**
 * Fills the human-readable personalisation snapshot onto a cart line once the cart has created it.
 *
 * Only the hex {@see OrderItem::$PersonalisationHash} travels the cart's saveable-data path (which runs
 * `Convert::raw2sql()` and so would corrupt free text / unicode). The matching JSON snapshot is recalled from
 * the request stash by that hash and written straight onto the order item here — a parameterised ORM write, so
 * the text round-trips intact on every database. Core writes the item immediately after this hook fires.
 *
 * Written once only (when the line is brand new and has no snapshot yet), which keeps a placed line immutable
 * and lets an existing identical line simply merge.
 *
 * @extends Extension<\SilverShop\Model\Order>
 */
class OrderPersonalisationExtension extends Extension
{
    public function afterAdd(OrderItem $item, Buyable $buyable, int $quantity, array $filter): void
    {
        $hash = (string) $item->getField('PersonalisationHash');
        if ($hash === '') {
            return;
        }

        if ((string) $item->getField('PersonalisationData') !== '') {
            return;
        }

        $json = PersonalisationService::recall($hash);
        if ($json !== null && $json !== '') {
            $item->setField('PersonalisationData', $json);
        }
    }
}
