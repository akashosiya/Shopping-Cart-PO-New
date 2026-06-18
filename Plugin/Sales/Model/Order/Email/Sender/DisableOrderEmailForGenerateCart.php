<?php
/**
 * Generate Shopping Cart checkout places a real order; only the custom cart-ready email should go out.
 */
namespace Osiyatech\ShoppingCart\Plugin\Sales\Model\Order\Email\Sender;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;

class DisableOrderEmailForGenerateCart
{
    /**
     * @param OrderSender $subject
     * @param callable $proceed
     * @param Order $order
     * @param bool $forceSyncMode
     * @return bool
     */
    public function aroundSend(
        OrderSender $subject,
        callable $proceed,
        Order $order,
        $forceSyncMode = false
    ): bool {
        $payment = $order->getPayment();
        if ($payment && $payment->getMethod() === GenerateCartMethod::PAYMENT_METHOD_CODE) {
            return false;
        }

        return $proceed($order, $forceSyncMode);
    }
}
