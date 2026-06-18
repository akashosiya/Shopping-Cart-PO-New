<?php

namespace Osiyatech\ShoppingCart\Helper;

use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * MSI / salable checks ke liye child SKU (configurable → simple).
 */
class QuoteItemSku
{
    public function getStockSku(AbstractItem $item): string
    {
        try {
            $simpleSku = $item->getProductOptionByCode('simple_sku');
            if ($simpleSku) {
                return (string) $simpleSku;
            }
            $simple = $item->getOptionByCode('simple_product');
            if ($simple && $simple->getProduct() && $simple->getProduct()->getSku()) {
                return (string) $simple->getProduct()->getSku();
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return (string) $item->getSku();
    }
}
