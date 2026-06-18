<?php
namespace Osiyatech\ShoppingCart\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Model\ShoppingCartRequestFactory;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;
use Osiyatech\ShoppingCart\Helper\Email;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class GenerateCart extends Action
{
    const SESSION_KEY = 'generate_cart_success';
    const SESSION_REQUEST_ID_KEY = 'shoppingcart_generate_cart_request_id';

    protected $resultJsonFactory;
    protected $checkoutSession;
    protected $customerSession;
    protected $requestRepository;
    protected $requestFactory;
    protected $poConfig;
    protected $emailHelper;
    protected $reservationManager;
    protected $customerRepository;
    protected $logger;

    /** @var UrlInterface */
    protected $urlBuilder;

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
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger,
        UrlInterface $urlBuilder
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
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        $this->urlBuilder = $urlBuilder;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        if (!$this->poConfig->isEnabled()) {
            return $result->setData(['success' => false, 'message' => __('Shopping Cart PO is disabled.')]);
        }
        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData(['success' => false, 'message' => __('Please login to generate shopping cart.')]);
        }
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId() || !$quote->getItemsCount()) {
            return $result->setData(['success' => false, 'message' => __('Your cart is empty.')]);
        }
        try {
            $requestId = (int) $this->getRequest()->getParam('request_id') ?: (int) $this->checkoutSession->getData(self::SESSION_REQUEST_ID_KEY);
            $request = null;
            if ($requestId > 0) {
                try {
                    $existing = $this->requestRepository->getById($requestId);
                    if ((int) $existing->getCustomerId() === (int) $this->customerSession->getCustomerId()
                        && (int) $existing->getQuoteId() === (int) $quote->getId()
                    ) {
                        $request = $existing;
                    }
                } catch (\Exception $e) {
                    // ignore
                }
                $this->checkoutSession->setData(self::SESSION_REQUEST_ID_KEY, null);
            }

            // Fallback: no request_id in session/link – find approved row for this quote+customer and update it
            if ($request === null) {
                $request = $this->requestRepository->getApprovedRequestForQuoteCustomer(
                    (int) $quote->getId(),
                    (int) $this->customerSession->getCustomerId()
                );
            }

            if ($request) {
                // Update same row (from mail link): set cart number + status completed
                $entityId = (int) $request->getEntityId();
                $quoteId = (int) $quote->getId();
                $randomSuffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $cartNumber = sprintf('CART-%d-%d-%s', $entityId, $quoteId, $randomSuffix);
                $request->setCartNumber($cartNumber);
                $request->setStatus(ShoppingCartRequestInterface::STATUS_COMPLETED);
                $request->setSubmittedAt(date('Y-m-d H:i:s'));
                $snapshot = $this->reservationManager->buildSnapshotFromQuote($quote);
                if (!empty($snapshot)) {
                    $request->setReservedItems(json_encode($snapshot));
                    $request->setReservedAt(date('Y-m-d H:i:s'));
                } else {
                    $request->setReservedItems(null);
                    $request->setReservationReleasedAt(null);
                }
                $this->requestRepository->save($request);
                $storeId = (int) $quote->getStoreId();
                $customer = $this->customerRepository->getById($this->customerSession->getCustomerId());
                $emailSent = $this->emailHelper->sendCartReadyEmail($quote, $customer, $cartNumber, $storeId);
                if (!$emailSent) {
                    $this->logger->warning('ShoppingCart GenerateCart: cart ready email failed to send.', ['cart_number' => $cartNumber]);
                }
                $this->checkoutSession->setData(self::SESSION_KEY, [
                    'cart_number' => $cartNumber,
                    'email_sent' => $emailSent,
                ]);
                return $result->setData([
                    'success' => true,
                    'email_sent' => $emailSent,
                    'message' => $emailSent
                        ? __('Thank you! Your shopping cart details have been emailed to you.')
                        : __('Your shopping cart number was created, but we could not send the email. Please save your cart number or contact support.'),
                    'cart_number' => $cartNumber,
                    'redirect_url' => $this->urlBuilder->getUrl('shoppingcart/cart/generateCartSuccess'),
                ]);
            }

            // No session request_id or invalid: create new row
            $request = $this->requestFactory->create();
            $request->setQuoteId($quote->getId());
            $request->setCustomerId($this->customerSession->getCustomerId());
            $request->setRequestType(ShoppingCartRequestInterface::REQUEST_TYPE_GENERATE_CART);
            $request->setStatus(ShoppingCartRequestInterface::STATUS_PENDING);
            $request->setSubmittedAt(date('Y-m-d H:i:s'));
            $request->setApprovalToken(bin2hex(random_bytes(32)));
            $request->setApprovalTokenCreatedAt(date('Y-m-d H:i:s'));

            $snapshot = $this->reservationManager->buildSnapshotFromQuote($quote);
            if (!empty($snapshot)) {
                $request->setReservedItems(json_encode($snapshot));
                $request->setReservedAt(date('Y-m-d H:i:s'));
            } else {
                $request->setReservedItems(null);
                $request->setReservationReleasedAt(null);
            }
            $this->requestRepository->save($request);

            $entityId = (int) $request->getEntityId();
            $quoteId = (int) $quote->getId();
            $randomSuffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $cartNumber = sprintf('CART-%d-%d-%s', $entityId, $quoteId, $randomSuffix);
            $request->setCartNumber($cartNumber);
            // Keep status PENDING so cron can release quantity after reservation days if no action
            $this->requestRepository->save($request);
            $storeId = (int) $quote->getStoreId();
            $customer = $this->customerRepository->getById($this->customerSession->getCustomerId());
            $emailSent = $this->emailHelper->sendCartReadyEmail($quote, $customer, $cartNumber, $storeId);
            if (!$emailSent) {
                $this->logger->warning('ShoppingCart GenerateCart: cart ready email failed to send.', ['cart_number' => $cartNumber]);
            }
            $this->checkoutSession->setData(self::SESSION_KEY, [
                'cart_number' => $cartNumber,
                'email_sent' => $emailSent,
            ]);
            return $result->setData([
                'success' => true,
                'email_sent' => $emailSent,
                'message' => $emailSent
                    ? __('Thank you! Your shopping cart details have been emailed to you.')
                    : __('Your shopping cart number was created, but we could not send the email. Please save your cart number or contact support.'),
                'cart_number' => $cartNumber,
                'redirect_url' => $this->urlBuilder->getUrl('shoppingcart/cart/generateCartSuccess'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical($e->getMessage(), ['exception' => $e]);
            return $result->setData(['success' => false, 'message' => __('An error occurred. Please try again.')]);
        }
    }

    private function getThankYouHtml(string $cartNumber): string
    {
        $poEmail = $this->poConfig->getPoRecipientEmail() ?: 'hitachi-in.po@prominate.com';
        $days = $this->poConfig->getGenerateCartReservationDays();
        $steps = [
            __('Use the Shopping Cart Number provided to generate a Purchase Order (PO) in your internal system.'),
            __('Email the internally generated PO along with the Shopping Cart copy to %1', $poEmail),
        ];
        $note = __('Since this is a PO-based order, the stock will be reserved for %1 days. If the PO is not emailed to us within this period, the stock will be automatically released.', $days);
        $html = '<div class="po-cart-thankyou message message-success">';
        $html .= '<strong>' . __('Thank you! Your shopping cart details have been emailed to you.') . '</strong>';
        $html .= '<p>' . __('Please follow the steps below to proceed:') . '</p><ol>';
        foreach ($steps as $step) {
            $html .= '<li>' . (string) $step . '</li>';
        }
        $html .= '</ol><p><em>' . (string) $note . '</em></p></div>';
        return $html;
    }
}
