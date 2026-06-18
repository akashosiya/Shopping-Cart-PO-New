<?php
namespace Osiyatech\ShoppingCart\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;

class SetWaitingForPoStatus implements ObserverInterface
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShoppingCartRequestRepositoryInterface */
    private $requestRepository;

    /** @var CartReservationManager */
    private $reservationManager;

    /** @var DateTime */
    private $dateTime;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        CartReservationManager $reservationManager,
        DateTime $dateTime
    ) {
        $this->orderRepository = $orderRepository;
        $this->requestRepository = $requestRepository;
        $this->reservationManager = $reservationManager;
        $this->dateTime = $dateTime;
    }

    /**
     * After place order: release Shopping Cart PO approval reservation and mark request completed (any payment).
     * Generate Cart payment: also set order status waiting_for_po.
     */
    public function execute(Observer $observer)
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getEntityId()) {
            return;
        }

        $this->completeApprovedShoppingCartRequestAfterOrder($order);

        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== GenerateCartMethod::PAYMENT_METHOD_CODE) {
            return;
        }

        $order->setState(Order::STATE_NEW);
        $order->setStatus('waiting_for_po');
        $order->addStatusHistoryComment(__('Order is waiting for PO confirmation.'));
        $this->orderRepository->save($order);
    }

    /**
     * When customer completes checkout: ensure MSI rows are gone (idempotent), mark request completed.
     */
    private function completeApprovedShoppingCartRequestAfterOrder(OrderInterface $order): void
    {
        if (!$order->getCustomerId() || !$order->getQuoteId()) {
            return;
        }
        try {
            $request = $this->requestRepository->getApprovedRequestForQuoteCustomer(
                (int) $order->getQuoteId(),
                (int) $order->getCustomerId()
            );
            if ($request && $request->getEntityId()) {
                $this->reservationManager->releaseApprovalReservationIfPlaced($request);
                $request->setMsiReservationPlaced(0);
                $request->setReservationReleasedAt($this->dateTime->gmtDate());
                $request->setStatus(ShoppingCartRequestInterface::STATUS_COMPLETED);
                $this->requestRepository->save($request);
            }
        } catch (\Throwable $e) {
            // Do not break checkout when request row is not found.
        }
    }
}
