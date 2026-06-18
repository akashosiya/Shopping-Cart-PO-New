<?php
namespace Osiyatech\ShoppingCart\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;
use Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest\CollectionFactory;
use Osiyatech\ShoppingCart\Model\ShoppingCartRequestFactory;
use Psr\Log\LoggerInterface;

/**
 * Persist admin grid row when checkout completes with Generate Shopping Cart payment
 * (standard placeOrder path — GenerateCart controller is not used there).
 * Skips when this quote already has a Purchase Approval request (same customer): ≥ threshold flow uses that row only.
 */
class CreateGenerateCartRequestFromOrder implements ObserverInterface
{
    /** @var ShoppingCartRequestRepositoryInterface */
    private $requestRepository;

    /** @var ShoppingCartRequestFactory */
    private $requestFactory;

    /** @var CartReservationManager */
    private $reservationManager;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var DateTime */
    private $dateTime;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShoppingCartRequestRepositoryInterface $requestRepository,
        ShoppingCartRequestFactory $requestFactory,
        CartReservationManager $reservationManager,
        CollectionFactory $collectionFactory,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->requestRepository = $requestRepository;
        $this->requestFactory = $requestFactory;
        $this->reservationManager = $reservationManager;
        $this->collectionFactory = $collectionFactory;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$order->getEntityId()) {
            return;
        }
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== GenerateCartMethod::PAYMENT_METHOD_CODE) {
            return;
        }
        $customerId = (int) $order->getCustomerId();
        if ($customerId <= 0) {
            $this->logger->info(
                'ShoppingCart: Skipping shopping_cart_request for generate-cart guest order.',
                ['order_id' => $order->getEntityId()]
            );
            return;
        }
        $quoteId = (int) $order->getQuoteId();
        // Approval-threshold flow: same quote has an approval request — grid row is the approval record (completed by SetWaitingForPoStatus).
        if ($quoteId > 0) {
            $approvalRequest = $this->requestRepository->getByQuoteIdAndType(
                $quoteId,
                ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL
            );
            if ($approvalRequest
                && $approvalRequest->getEntityId()
                && (int) $approvalRequest->getCustomerId() === $customerId
            ) {
                return;
            }
        }
        $cartNumber = trim((string) $order->getIncrementId());
        if ($cartNumber === '') {
            return;
        }
        $existing = $this->collectionFactory->create();
        $existing->addFieldToFilter('request_type', ShoppingCartRequestInterface::REQUEST_TYPE_GENERATE_CART);
        $existing->addFieldToFilter('customer_id', $customerId);
        $existing->addFieldToFilter('cart_number', $cartNumber);
        $existing->setPageSize(1);
        if ($existing->getSize() > 0) {
            return;
        }
        try {
            $request = $this->requestFactory->create();
            $request->setQuoteId($quoteId > 0 ? $quoteId : null);
            $request->setCustomerId($customerId);
            $request->setRequestType(ShoppingCartRequestInterface::REQUEST_TYPE_GENERATE_CART);
            $request->setStatus(ShoppingCartRequestInterface::STATUS_COMPLETED);
            $request->setCartNumber($cartNumber);
            $request->setSubmittedAt($this->dateTime->gmtDate());
            $request->setMsiReservationPlaced(0);
            $snapshot = $this->reservationManager->buildSnapshotFromOrder($order);
            if (!empty($snapshot)) {
                $request->setReservedItems(json_encode($snapshot));
                $request->setReservedAt($this->dateTime->gmtDate());
            }
            $shipDesc = trim((string) $order->getShippingDescription());
            if ($shipDesc !== '') {
                $request->setShippingMethodName($shipDesc);
                $currency = $order->getStore()->getBaseCurrency();
                $request->setShippingMethodCharges(
                    $currency->format((float) $order->getBaseShippingAmount(), [], false)
                );
            }
            $this->requestRepository->save($request);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ShoppingCart: Could not create shopping_cart_request for generate-cart order: ' . $e->getMessage(),
                ['exception' => $e, 'order_id' => $order->getEntityId()]
            );
        }
    }
}
