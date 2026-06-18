<?php
/**
 * Suppress Magento default success HTML (thank you, print, continue shopping) for Generate Shopping Cart orders.
 * Layout unsetElement can fail with some themes / merge order; this targets the block class directly.
 */
namespace Osiyatech\ShoppingCart\Plugin\Checkout\Block\Onepage\Success;

use Magento\Checkout\Block\Onepage\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;

class HideDefaultOutputForPoOrder
{
    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param Success $subject
     * @param callable $proceed
     * @return string
     */
    public function aroundToHtml(Success $subject, callable $proceed): string
    {
        if ($this->isShoppingCartGenerateCartOrder()) {
            return '';
        }
        return $proceed();
    }

    private function isShoppingCartGenerateCartOrder(): bool
    {
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if ($orderId <= 0) {
            return false;
        }
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return false;
        }
        $payment = $order->getPayment();
        return $payment && $payment->getMethod() === GenerateCartMethod::PAYMENT_METHOD_CODE;
    }
}
