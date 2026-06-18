<?php
namespace Osiyatech\ShoppingCart\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\UrlInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;

/**
 * Restore reserved cart by resume_token and redirect to checkout (for "Proceed to Checkout" link in approved email).
 */
class ProceedToCheckout extends Action implements HttpGetActionInterface
{
    /** @var RedirectFactory */
    protected $redirectFactory;
    /** @var UrlInterface */
    protected $url;
    /** @var CheckoutSession */
    protected $checkoutSession;
    /** @var CustomerSession */
    protected $customerSession;
    /** @var QuoteRepository */
    protected $quoteRepository;
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $requestRepository;
    /** @var CustomerRepositoryInterface */
    protected $customerRepository;
    /** @var AddressRepositoryInterface */
    protected $addressRepository;

    public function __construct(
        Context $context,
        RedirectFactory $redirectFactory,
        UrlInterface $url,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        QuoteRepository $quoteRepository,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository
    ) {
        parent::__construct($context);
        $this->redirectFactory = $redirectFactory;
        $this->url = $url;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->quoteRepository = $quoteRepository;
        $this->requestRepository = $requestRepository;
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $token = trim((string) $this->getRequest()->getParam('token'));
        if ($token === '') {
            $redirect->setPath('checkout/cart');
            return $redirect;
        }
        if (!$this->customerSession->isLoggedIn()) {
            $currentUrl = $this->url->getCurrentUrl();
            $loginUrl = $this->url->getUrl('customer/account/login', ['referer' => base64_encode($currentUrl)]);
            $redirect->setUrl($loginUrl);
            return $redirect;
        }

        $request = $this->requestRepository->getByResumeToken($token);
        if (!$request || (int) $request->getCustomerId() !== (int) $this->customerSession->getCustomerId()) {
            $redirect->setPath('checkout/cart');
            return $redirect;
        }

        try {
            $quote = $this->quoteRepository->get($request->getQuoteId());
            if ($quote->getItemsCount() > 0) {
                $quote->setIsActive(true);
                $this->ensureShippingAddressForRates($quote);
                $this->quoteRepository->save($quote);
                $shippingAddress = $quote->getShippingAddress();
                $shippingAddress->setCollectShippingRates(true);
                if (method_exists($shippingAddress, 'collectShippingRates')) {
                    $shippingAddress->collectShippingRates();
                }
                $quote->collectTotals();
                $this->quoteRepository->save($quote);
                // Use freshly loaded quote so session has persisted totals (fixes "back to cart" showing 0)
                $quote = $this->quoteRepository->get($request->getQuoteId());
                $this->checkoutSession->replaceQuote($quote);
                // So that Generate Cart submit updates this approval row instead of creating a new one
                $this->checkoutSession->setData('shoppingcart_generate_cart_request_id', (int) $request->getEntityId());
                return $redirect->setPath('checkout');
            }
        } catch (\Throwable $e) {
            // fall through
        }

        $redirect->setPath('checkout/cart');
        return $redirect;
    }

    /**
     * Ensure quote has a shipping address so checkout can collect shipping rates.
     * If shipping address is empty, assign customer's default shipping address.
     * Explicitly set region_id for table rate / carrier rate collection.
     */
    private function ensureShippingAddressForRates(\Magento\Quote\Model\Quote $quote): void
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
                // customer may have no default address; checkout will ask for one
            }
        }
        $shippingAddress->setCollectShippingRates(true);
    }
}
