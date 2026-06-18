<?php
namespace Osiyatech\ShoppingCart\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\UrlInterface;
use Magento\Customer\CustomerData\SectionPoolInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Quote\Model\Quote;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;

/**
 * View/restore cart by token (resume_token = customer restores own cart; approval_token = preview for approver).
 * When restoring, activates quote, ensures shipping address for totals, and collects totals so cart summary shows correct amount.
 */
class View extends Action implements HttpGetActionInterface
{
    /** @var RedirectFactory */
    protected $redirectFactory;
    /** @var CheckoutSession */
    protected $checkoutSession;
    /** @var CustomerSession */
    protected $customerSession;
    /** @var QuoteRepository */
    protected $quoteRepository;
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $requestRepository;
    /** @var CustomerRepositoryInterface */
    private $customerRepository;
    /** @var AddressRepositoryInterface */
    private $addressRepository;
    /** @var UrlInterface */
    private $url;
    /** @var SectionPoolInterface */
    private $sectionPool;
    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        Context $context,
        RedirectFactory $redirectFactory,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        QuoteRepository $quoteRepository,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository,
        UrlInterface $url,
        SectionPoolInterface $sectionPool,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->redirectFactory = $redirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->quoteRepository = $quoteRepository;
        $this->requestRepository = $requestRepository;
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
        $this->url = $url;
        $this->sectionPool = $sectionPool;
        $this->storeManager = $storeManager;
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $token = trim((string) $this->getRequest()->getParam('token'));
        if ($token === '') {
            $redirect->setPath('checkout/cart');
            return $redirect;
        }

        // If user is not logged in, send to login and come back to this token URL.
        // Without this, quote restore won't happen and cart summary can show 0 due to stale customer-data/active quote.
        $request = $this->requestRepository->getByResumeToken($token);
        if ($request) {
            // Customer link: if not logged in, force login first so we can safely restore their quote.
            if (!$this->customerSession->isLoggedIn()) {
                $currentUrl = $this->url->getCurrentUrl();
                $loginUrl = $this->url->getUrl('customer/account/login', ['referer' => base64_encode($currentUrl)]);
                $redirect->setUrl($loginUrl);
                return $redirect;
            }
            // Customer link: restore quote to session and redirect to cart
            if ((int) $request->getCustomerId() === (int) $this->customerSession->getCustomerId()) {
                try {
                    $quote = $this->quoteRepository->get($request->getQuoteId());
                    if ($quote->getItemsCount() > 0) {
                        $quoteId = (int) $quote->getId();
                        $this->prepareQuoteWithTotals($quote);
                        $quote = $this->quoteRepository->get($quoteId);
                        $this->checkoutSession->replaceQuote($quote);
                        $this->checkoutSession->setQuoteId($quoteId);
                        $this->checkoutSession->setCartWasUpdated(true);
                        $this->sectionPool->invalidateSections(['cart', 'checkout-data']);
                        $this->checkoutSession->setData('shoppingcart_quote_restored', true);
                        $requestId = (int) $this->getRequest()->getParam('request_id') ?: (int) $request->getEntityId();
                        if ($requestId) {
                            $this->checkoutSession->setData('shoppingcart_generate_cart_request_id', $requestId);
                            $redirect->setPath('checkout/cart', ['_query' => ['shoppingcart_request_id' => $requestId]]);
                        } else {
                            $redirect->setPath('checkout/cart');
                        }
                        return $redirect;
                    }
                } catch (\Throwable $e) {
                    // fall through to cart
                }
            }
        }

        $request = $this->requestRepository->getByApprovalToken($token);
        if ($request) {
            // Approver or shared link: redirect to cart preview page (same cart restore if same customer, else preview)
            if (!$this->customerSession->isLoggedIn()) {
                // Approver tokens can be opened without login (preview), but customer restore needs login.
                $redirect->setPath('shoppingcart/cart/preview', ['token' => $token]);
                return $redirect;
            }
            if ((int) $request->getCustomerId() === (int) $this->customerSession->getCustomerId()) {
                try {
                    $quote = $this->quoteRepository->get($request->getQuoteId());
                    if ($quote->getItemsCount() > 0) {
                        $quoteId = (int) $quote->getId();
                        $this->prepareQuoteWithTotals($quote);
                        $quote = $this->quoteRepository->get($quoteId);
                        $this->checkoutSession->replaceQuote($quote);
                        $this->checkoutSession->setQuoteId($quoteId);
                        $this->checkoutSession->setCartWasUpdated(true);
                        $this->sectionPool->invalidateSections(['cart', 'checkout-data']);
                        $this->checkoutSession->setData('shoppingcart_quote_restored', true);
                        $requestId = (int) $this->getRequest()->getParam('request_id') ?: (int) $request->getEntityId();
                        if ($requestId) {
                            $this->checkoutSession->setData('shoppingcart_generate_cart_request_id', $requestId);
                            $redirect->setPath('checkout/cart', ['_query' => ['shoppingcart_request_id' => $requestId]]);
                        } else {
                            $redirect->setPath('checkout/cart');
                        }
                        return $redirect;
                    }
                } catch (\Throwable $e) {
                    // fall through
                }
            }
            $redirect->setPath('shoppingcart/cart/preview', ['token' => $token]);
            return $redirect;
        }

        $redirect->setPath('checkout/cart');
        return $redirect;
    }

    /**
     * Activate quote, ensure shipping address for rate/totals collection, collect totals and save.
     * Ensures cart and checkout show correct subtotal/shipping/order total instead of 0.
     */
    private function prepareQuoteWithTotals(Quote $quote): void
    {
        // Deactivate current session quote (if any) so Magento totals APIs use the restored active quote.
        try {
            $current = $this->checkoutSession->getQuote();
            if ($current && (int)$current->getId() && (int)$current->getId() !== (int)$quote->getId()) {
                $current->setIsActive(false);
                $this->quoteRepository->save($current);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $quote->setStoreId($storeId);
        if ($this->customerSession->isLoggedIn()) {
            $quote->setCustomerId((int)$this->customerSession->getCustomerId());
        }
        $quote->setIsActive(true);

        // Force totals recollection (prevents stale/0 totals).
        $quote->setTotalsCollectedFlag(false);

        $this->ensureShippingAddressForRates($quote);
        $this->quoteRepository->save($quote);

        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true);
            if (method_exists($shippingAddress, 'collectShippingRates')) {
                $shippingAddress->collectShippingRates();
            }
        }
        $quote->collectTotals();
        $this->quoteRepository->save($quote);
    }

    /**
     * Ensure quote has a shipping address so totals and shipping rates can be collected.
     */
    private function ensureShippingAddressForRates(Quote $quote): void
    {
        $shippingAddress = $quote->getShippingAddress();
        if ($quote->isVirtual()) {
            return;
        }
        $needsImport = !$shippingAddress->getCountryId();
        if ($needsImport) {
            try {
                $customer = $this->customerRepository->getById($this->customerSession->getCustomerId());
                $defaultShippingId = $customer->getDefaultShipping();
                if ($defaultShippingId) {
                    $address = $this->addressRepository->getById($defaultShippingId);
                    $shippingAddress->importCustomerAddressData($address);
                    if ($address->getRegionId()) {
                        $shippingAddress->setRegionId($address->getRegionId());
                    }
                    $region = $address->getRegion();
                    if ($region && method_exists($region, 'getRegion')) {
                        $shippingAddress->setRegion($region->getRegion());
                    }
                    if ($region && method_exists($region, 'getCode')) {
                        $shippingAddress->setRegionCode($region->getCode());
                    }
                }
            } catch (\Throwable $e) {
                // customer may have no default address
            }
        }
        $shippingAddress->setCollectShippingRates(true);
    }
}
