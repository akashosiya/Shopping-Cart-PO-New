<?php
/**
 * For PO (Generate Shopping Cart) payment orders, load a layout handle that removes the default success block.
 */
namespace Osiyatech\ShoppingCart\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;

class CheckoutSuccessApplyPoLayoutHandle implements ObserverInterface
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

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $action = $event->getData('action');
        if (!$action || $action->getRequest()->getFullActionName() !== 'checkout_onepage_success') {
            return;
        }
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if ($orderId <= 0) {
            return;
        }
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return;
        }
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== GenerateCartMethod::PAYMENT_METHOD_CODE) {
            return;
        }
        $layout = $event->getData('layout');
        if ($layout) {
            $layout->getUpdate()->addHandle('shoppingcart_checkout_success_po');
        }
    }
}
