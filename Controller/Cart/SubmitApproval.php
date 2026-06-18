<?php
namespace Osiyatech\ShoppingCart\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Model\ShoppingCartRequestFactory;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;
use Osiyatech\ShoppingCart\Helper\Email;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest\CollectionFactory as RequestCollectionFactory;
use Psr\Log\LoggerInterface;

class SubmitApproval extends Action
{
    protected $resultJsonFactory;
    protected $checkoutSession;
    protected $customerSession;
    protected $requestRepository;
    protected $requestFactory;
    protected $poConfig;
    protected $emailHelper;
    protected $reservationManager;
    protected $logger;
    /** @var QuoteRepository */
    protected $quoteRepository;
    /** @var QuoteFactory */
    protected $quoteFactory;
    /** @var CustomerRepositoryInterface */
    protected $customerRepository;
    /** @var StoreManagerInterface */
    protected $storeManager;
    /** @var RequestCollectionFactory */
    protected $requestCollectionFactory;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        ShoppingCartRequestFactory $requestFactory,
        PoConfig $poConfig,
        Email $emailHelper,
        CartReservationManager $reservationManager,
        LoggerInterface $logger,
        QuoteRepository $quoteRepository,
        QuoteFactory $quoteFactory,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager,
        RequestCollectionFactory $requestCollectionFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->requestRepository = $requestRepository;
        $this->requestFactory = $requestFactory;
        $this->poConfig = $poConfig;
        $this->emailHelper = $emailHelper;
        $this->reservationManager = $reservationManager;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->quoteFactory = $quoteFactory;
        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
        $this->requestCollectionFactory = $requestCollectionFactory;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        if (!$this->poConfig->isEnabled()) {
            return $result->setData(['success' => false, 'message' => __('Shopping Cart PO is disabled.')]);
        }
        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData(['success' => false, 'message' => __('Please login to submit approval request.')]);
        }
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId() || !$quote->getItemsCount()) {
            $message = $this->getEmptyCartMessage();
            return $result->setData(['success' => false, 'message' => $message]);
        }
        $storeId = (int) $quote->getStoreId();
        $purchaseType = trim((string) $this->getRequest()->getParam('purchase_type'));
        $approverEmail = trim((string) $this->getRequest()->getParam('approver_email'));
        $reasonForPurchase = trim((string) $this->getRequest()->getParam('reason_for_purchase'));
        $purchaseTypeCheckoutFlow = $this->poConfig->isPurchaseTypeCheckoutFlowEnabled($storeId);
        if ($purchaseType === '' || !in_array(strtolower($purchaseType), ['official', 'personal'], true)) {
            return $result->setData(['success' => false, 'message' => __('Please select Purchase Type (Official or Personal).')]);
        }
        if ($reasonForPurchase === '' && strtolower($purchaseType) === 'official') {
            return $result->setData(['success' => false, 'message' => __('Please enter the reason for purchase.')]);
        }
        if (!$purchaseTypeCheckoutFlow) {
            if ($approverEmail === '' || !filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
                return $result->setData(['success' => false, 'message' => __('Please select your approver from the list.')]);
            }
            $approverList = $this->poConfig->getApproverList($storeId);
            $allowedEmails = array_column($approverList, 'value');
            if (!in_array($approverEmail, $allowedEmails, true)) {
                return $result->setData(['success' => false, 'message' => __('Selected approver is not valid.')]);
            }
        } elseif ($approverEmail !== '') {
            if (!filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
                $approverEmail = '';
            } else {
                $approverList = $this->poConfig->getApproverList($storeId);
                $allowedEmails = array_column($approverList, 'value');
                if (!in_array($approverEmail, $allowedEmails, true)) {
                    return $result->setData(['success' => false, 'message' => __('Selected approver is not valid.')]);
                }
            }
        }
        try {
            $existing = $this->requestRepository->getByQuoteIdAndType($quote->getId(), ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL);
            $oldReservedJson = null;

            if ($existing && $existing->getStatus() === ShoppingCartRequestInterface::STATUS_PENDING) {
                $oldReservedJson = $existing->getReservedItems();
                $request = $existing;
                $request->setPurchaseType($purchaseType);
                $request->setApproverEmail($approverEmail);
                $request->setReasonForPurchase($reasonForPurchase);
                $request->setReservedItems(null);
                $request->setReservationReleasedAt(null);
                $request->setMsiReservationPlaced(0);
            } else {
                $request = $this->requestFactory->create();
                $request->setQuoteId($quote->getId());
                $request->setCustomerId($this->customerSession->getCustomerId());
                $request->setRequestType(ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL);
                $request->setPurchaseType($purchaseType);
                $request->setApproverEmail($approverEmail);
                $request->setReasonForPurchase($reasonForPurchase);
                $request->setStatus(ShoppingCartRequestInterface::STATUS_PENDING);
                $request->setSubmittedAt(date('Y-m-d H:i:s'));
                $request->setMsiReservationPlaced(0);
            }
            $request->setApprovalToken(bin2hex(random_bytes(32)));
            $request->setApprovalTokenCreatedAt(date('Y-m-d H:i:s'));
            $request->setResumeToken(bin2hex(random_bytes(32)));
            $request->setResumeTokenCreatedAt(date('Y-m-d H:i:s'));
            $snapshot = $this->reservationManager->buildSnapshotFromQuote($quote);
            $this->requestRepository->save($request);

            $requestId = (string) $request->getEntityId();
            $storeIdForMsi = (int) $quote->getStoreId();

            $releaseSnap = $this->reservationManager->decodeReservedItemsJson($oldReservedJson);
            if (empty($releaseSnap) && !empty($snapshot)) {
                $releaseSnap = $snapshot;
            }
            if (!empty($releaseSnap)) {
                $this->reservationManager->releaseOrphanApprovalMsi($requestId, $releaseSnap, $storeIdForMsi);
            }

            // Sirf DB snapshot + GetProductSalableQty / IsProductSalable plugins se qty block (ek hi baar).
            // MSI AppendReservations + plugins = double count (e.g. 67→27); isliye yahan MSI place band.
            if (!empty($snapshot)) {
                $request->setReservedItems(json_encode($snapshot));
                $request->setReservedAt(date('Y-m-d H:i:s'));
                $request->setMsiReservationPlaced(0);
                $this->requestRepository->save($request);
            }

            // Shipping: first from hidden fields (popup form – user selected on cart page), else from session quote
            $postShippingName = trim((string) $this->getRequest()->getParam('shipping_method_name', ''));
            $postShippingCharges = trim((string) $this->getRequest()->getParam('shipping_method_charges', ''));
            if ($postShippingName !== '') {
                $request->setShippingMethodName($postShippingName);
                $request->setShippingMethodCharges($postShippingCharges !== '' ? $postShippingCharges : '');
                $this->requestRepository->save($request);
            } else {
                try {
                    $quote->collectTotals();
                    $this->quoteRepository->save($quote);
                    $shippingAddress = $quote->getShippingAddress();
                    if ($shippingAddress && $shippingAddress->getShippingMethod()) {
                        $name = (string) ($shippingAddress->getShippingDescription() ?: $shippingAddress->getShippingMethod());
                        $charges = $quote->getStore()->getBaseCurrency()->format((float) $shippingAddress->getShippingAmount(), [], false);
                        $request->setShippingMethodName($name);
                        $request->setShippingMethodCharges($charges);
                        $this->requestRepository->save($request);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('ShoppingCart: Could not capture shipping from quote: ' . $e->getMessage());
                }
            }

            // Empty cart: replace session quote with a new empty quote (reserved quote stays in DB for cart view link)
            $this->replaceSessionWithNewQuote($quote);

            $this->emailHelper->sendApprovalRequestToApprover($request);
            $days = $this->poConfig->getApprovalReservationDays((int) $quote->getStoreId());
            $this->emailHelper->sendSubmittedEmailToCustomer($request, $days);
            
            $message = __('Your approval request has been submitted. Cart items will be reserved for %1 days.', $days);
            return $result->setData([
                'success' => true,
                'message' => $message,
                'po_request_id' => $request->getEntityId(),
                'status' => $request->getStatus(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical($e->getMessage(), ['exception' => $e]);
            return $result->setData(['success' => false, 'message' => __('An error occurred. Please try again.')]);
        }
    }

    /**
     * When cart is empty, return a friendly message if customer just submitted an approval (avoids confusing "Your cart is empty" after success).
     */
    private function getEmptyCartMessage(): \Magento\Framework\Phrase
    {
        if (!$this->customerSession->isLoggedIn()) {
            return __('Your cart is empty.');
        }
        $customerId = (int) $this->customerSession->getCustomerId();
        $collection = $this->requestCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->addFieldToFilter('request_type', ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL);
        $collection->addFieldToFilter('status', ShoppingCartRequestInterface::STATUS_PENDING);
        $collection->addFieldToFilter(
            'submitted_at',
            ['gteq' => date('Y-m-d H:i:s', strtotime('-2 minutes'))]
        );
        $collection->setOrder('submitted_at', 'DESC');
        $collection->setPageSize(1);
        if ($collection->getSize() > 0) {
            return __('Your approval request was already submitted. The cart has been cleared. Please check your email for confirmation.');
        }
        return __('Your cart is empty.');
    }

    /**
     * Replace current session quote with a new empty quote so cart appears empty; reserved quote remains in DB.
     */
    private function replaceSessionWithNewQuote(\Magento\Quote\Model\Quote $oldQuote): void
    {
        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            $storeId = (int) $oldQuote->getStoreId();
            $store = $this->storeManager->getStore($storeId);
            $customer = $this->customerRepository->getById($customerId);
            $newQuote = $this->quoteFactory->create();
            $newQuote->setStore($store);
            $newQuote->assignCustomer($customer);
            $newQuote->setIsActive(true);
            $this->quoteRepository->save($newQuote);
            $this->checkoutSession->replaceQuote($newQuote);
        } catch (\Throwable $e) {
            $this->logger->warning('ShoppingCart: Could not replace session quote: ' . $e->getMessage());
        }
    }
}
