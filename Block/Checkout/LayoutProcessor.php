<?php
namespace Osiyatech\ShoppingCart\Block\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;

class LayoutProcessor implements LayoutProcessorInterface
{
    /** @var PoConfig */
    private $poConfig;

    public function __construct(PoConfig $poConfig)
    {
        $this->poConfig = $poConfig;
    }

    /**
     * @param array $jsLayout
     * @return array
     */
    public function process($jsLayout)
    {
        if (!$this->poConfig->isEnabled()) {
            return $jsLayout;
        }

        // Avoid undefined-index notices (breaks checkout JSON / blank UI on PHP 8+).
        if (!isset(
            $jsLayout['components']['checkout']['children']['steps']['children']['billing-step']['children']['payment']['children']
        )) {
            return $jsLayout;
        }

        $payment = &$jsLayout['components']['checkout']['children']['steps']['children']['billing-step']['children']['payment']['children'];
        if (!isset($payment['renders']['children']) || !is_array($payment['renders']['children'])) {
            return $jsLayout;
        }

        $payment['renders']['children']['shoppingcart'] = [
            'component' => 'Osiyatech_ShoppingCart/js/view/payment-method',
        ];

        return $jsLayout;
    }
}
