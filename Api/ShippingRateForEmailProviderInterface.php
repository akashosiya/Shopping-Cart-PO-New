<?php
namespace Osiyatech\ShoppingCart\Api;

use Magento\Quote\Model\Quote;

/**
 * Provide first available shipping rate for a quote (for display in approval email).
 * When quote has no shipping method set, the email helper can use this to show rate info.
 * APS or other shipping modules can implement this to return real rates from their API.
 *
 * Return array with keys: 'method_title' (string), 'amount' (float). Return null if no rate.
 */
interface ShippingRateForEmailProviderInterface
{
    /**
     * @param Quote $quote
     * @return array|null ['method_title' => string, 'amount' => float] or null
     */
    public function getFirstRateForQuote(Quote $quote): ?array;
}
