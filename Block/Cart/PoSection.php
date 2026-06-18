<?php
namespace Osiyatech\ShoppingCart\Block\Cart;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Data\Form\FormKey;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;

class PoSection extends Template
{
    /** @var PoConfig */
    protected $poConfig;
    /** @var ShoppingCartRequestRepositoryInterface */
    private $requestRepository;
    /** @var CheckoutSession */
    protected $checkoutSession;
    /** @var CustomerSession */
    protected $customerSession;
    /** @var FormKey */
    protected $formKey;

    public function __construct(
        Context $context,
        PoConfig $poConfig,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->poConfig = $poConfig;
        $this->requestRepository = $requestRepository;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->formKey = $formKey;
    }

    public function getFormKeyValue(): string
    {
        return $this->formKey->getFormKey();
    }

    public function isCartPoAvailable(): bool
    {
        if (!$this->poConfig->isEnabled() || !$this->customerSession->isLoggedIn()) return false;
        $quote = $this->checkoutSession->getQuote();
        return $quote && $quote->getId() && $quote->getItemsCount() > 0;
    }

    public function showApprovalForm(): bool
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) return false;
        $storeId = (int) $quote->getStoreId();
        $quote->collectTotals();
        $threshold = $this->poConfig->getApprovalThreshold($storeId);
        $approvers = $this->poConfig->getApproverList($storeId);
        return $threshold > 0 && (float) $quote->getGrandTotal() >= $threshold && !empty($approvers);
    }

    public function getApprovalThreshold(): float
    {
        $quote = $this->checkoutSession->getQuote();
        return $this->poConfig->getApprovalThreshold($quote ? (int) $quote->getStoreId() : null);
    }

    public function getApproverList(): array
    {
        $quote = $this->checkoutSession->getQuote();
        return $this->poConfig->getApproverList($quote ? (int) $quote->getStoreId() : null);
    }

    public function getPoRecipientEmail(): string
    {
        $quote = $this->checkoutSession->getQuote();
        return $this->poConfig->getPoRecipientEmail($quote ? (int) $quote->getStoreId() : null) ?: 'hitachi-in.po@prominate.com';
    }

    public function getGenerateCartReservationDays(): int
    {
        $quote = $this->checkoutSession->getQuote();
        return $this->poConfig->getGenerateCartReservationDays($quote ? (int) $quote->getStoreId() : null);
    }

    public function getApprovalReservationDays(): int
    {
        $quote = $this->checkoutSession->getQuote();
        return $this->poConfig->getApprovalReservationDays($quote ? (int) $quote->getStoreId() : null);
    }

    public function getGenerateCartSubmitUrl(): string
    {
        return $this->getUrl('shoppingcart/cart/generateCart', ['_secure' => true]);
    }

    public function getSubmitApprovalUrl(): string
    {
        return $this->getUrl('shoppingcart/cart/submitApproval', ['_secure' => true]);
    }

    public function getCheckoutUrl(): string
    {
        return $this->getUrl('checkout', ['_secure' => true]);
    }

    /**
     * Quote grand total for approval threshold check (e.g. show popup when exceeded).
     */
    public function getQuoteGrandTotal(): float
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return 0.0;
        }
        $quote->collectTotals();
        return (float) $quote->getGrandTotal();
    }

    /**
     * Whether to show approval popup on "Proceed to Checkout" (total >= threshold, approvers exist).
     */
	public function isApprovalPopupRequired(): bool
	{
		$quote = $this->checkoutSession->getQuote();
		if (!$quote || !$quote->getId()) {
			return false;
		}

		$storeId = (int) $quote->getStoreId();
		$approvers = $this->poConfig->getApproverList($storeId);

		if ($this->poConfig->isPurchaseTypeCheckoutFlowEnabled($storeId)) {
			return true;
		}

		$threshold = $this->poConfig->getApprovalThreshold($storeId);

		return $threshold > 0 && !empty($approvers);
	}

    /**
     * Whether current quote already has an approved approval request (no popup, go straight to checkout).
     */
    public function hasApprovedRequestForCurrentQuote(): bool
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return false;
        }

        $request = $this->requestRepository->getByQuoteIdAndType(
            (int) $quote->getId(),
            ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL
        );

        // Only skip the popup if the cart has NOT changed since the approval.
        // If customer adds/removes items or changes qty after approval, show popup again.
        if (!$request || $request->getStatus() !== ShoppingCartRequestInterface::STATUS_APPROVED) {
            return false;
        }

        $approvedAt = $request->getApprovedAt();
        $quoteUpdatedAt = $quote->getUpdatedAt();

        if (!$approvedAt || !$quoteUpdatedAt) {
            return false;
        }

        $approvedTs = strtotime((string) $approvedAt);
        $quoteUpdatedTs = strtotime((string) $quoteUpdatedAt);

        if (!$approvedTs || !$quoteUpdatedTs) {
            return false;
        }

        return $quoteUpdatedTs <= $approvedTs;
    }

    /**
     * Whether the cart requires a shipping method (has physical items).
     */
    public function isShippingMethodRequired(): bool
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return false;
        }
        return !$quote->isVirtual();
    }

    /**
     * Whether a shipping method is already selected on the quote.
     */
    public function hasShippingMethodSelected(): bool
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId() || $quote->isVirtual()) {
            return true; // no shipping required or N/A
        }
        $shippingAddress = $quote->getShippingAddress();
        $method = $shippingAddress ? trim((string) $shippingAddress->getShippingMethod()) : '';
        return $method !== '';
    }
	
	public function isPurchaseTypeCheckoutFlowEnabled(): bool
	{
		$quote = $this->checkoutSession->getQuote();
		return $this->poConfig->isPurchaseTypeCheckoutFlowEnabled(
			$quote ? (int) $quote->getStoreId() : null
		);
	}
}
