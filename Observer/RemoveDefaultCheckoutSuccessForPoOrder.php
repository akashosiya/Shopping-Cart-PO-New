<?php
/**
 * After all layout handles are merged, remove Magento default success block for PO (Generate Shopping Cart) orders.
 * (Removing via an early layout handle can be overridden when checkout_onepage_success loads later.)
 */
namespace Osiyatech\ShoppingCart\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;

class RemoveDefaultCheckoutSuccessForPoOrder implements ObserverInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(
        RequestInterface $request,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
    }

    public function execute(Observer $observer): void
    {
        // Strict match only: null/'' must not fall through (null !== 'checkout_onepage_success' is true in PHP).
        $action = (string) $this->request->getFullActionName();
        if ($action !== 'checkout_onepage_success') {
            return;
        }
        $layout = $observer->getEvent()->getData('layout');
        if (!$layout) {
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
        $layout->unsetElement('checkout.success');
        $layout->unsetElement('checkout.registration');
    }
}
