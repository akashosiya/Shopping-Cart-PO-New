<?php
namespace Osiyatech\ShoppingCart\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Osiyatech\ShoppingCart\Helper\Email;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;
use Psr\Log\LoggerInterface;

/**
 * Checkout success path (onepage success): cart-ready mail is not sent from GenerateCart controller.
 * Send the same template when order is placed with Generate Shopping Cart payment.
 */
class SendGenerateCartReadyEmail implements ObserverInterface
{
    /** @var Email */
    private $emailHelper;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Email $emailHelper,
        LoggerInterface $logger
    )
    {
        $this->emailHelper = $emailHelper;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof OrderInterface || !$order->getEntityId()) {
            return;
        }
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== GenerateCartMethod::PAYMENT_METHOD_CODE) {
            return;
        }
        $this->emailHelper->sendCartReadyEmailForOrder($order);
    }
}
