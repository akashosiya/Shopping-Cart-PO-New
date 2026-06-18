<?php
namespace Osiyatech\ShoppingCart\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Api\ShippingRateForEmailProviderInterface;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

class Email extends AbstractHelper
{
    /** @var TransportBuilderFactory */
    protected $transportBuilderFactory;
    /** @var StateInterface */
    protected $inlineTranslation;
    /** @var StoreManagerInterface */
    protected $storeManager;
    /** @var CartRepositoryInterface */
    protected $quoteRepository;
    /** @var ScopeConfigInterface */
    protected $scopeConfig;
    /** @var CustomerRepositoryInterface */
    protected $customerRepository;
    /** @var Config */
    protected $config;
    /** @var ShippingRateForEmailProviderInterface */
    protected $shippingRateForEmailProvider;
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $requestRepository;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    public function __construct(
        Context $context,
        TransportBuilderFactory $transportBuilderFactory,
        StateInterface $inlineTranslation,
        StoreManagerInterface $storeManager,
        CartRepositoryInterface $quoteRepository,
        CustomerRepositoryInterface $customerRepository,
        Config $config,
        ShippingRateForEmailProviderInterface $shippingRateForEmailProvider,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        ProductRepositoryInterface $productRepository
    ) {
        parent::__construct($context);
        $this->transportBuilderFactory = $transportBuilderFactory;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager;
        $this->quoteRepository = $quoteRepository;
        $this->scopeConfig = $context->getScopeConfig();
        $this->customerRepository = $customerRepository;
        $this->config = $config;
        $this->shippingRateForEmailProvider = $shippingRateForEmailProvider;
        $this->requestRepository = $requestRepository;
        $this->productRepository = $productRepository;
    }

    protected function sendEmail($recipientEmail, $recipientName, $templateId, array $templateVars, $storeId)
    {
        $storeId = (int) $storeId;
        if ($storeId <= 0) {
            try {
                $default = $this->storeManager->getDefaultStoreView();
                $storeId = $default ? (int) $default->getId() : (int) $this->storeManager->getStore()->getId();
            } catch (\Throwable $e) {
                $storeId = (int) $this->storeManager->getStore()->getId();
            }
        }
        $sender = $this->config->getEmailSender($storeId);
        $transport = $this->transportBuilderFactory->create()
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars($templateVars)
            ->setFromByScope($sender, $storeId)
            ->addTo($recipientEmail, $recipientName)
            ->getTransport();
        $transport->sendMessage();
    }

    /**
     * Send "Your Shopping Cart No. X is Ready!" email.
     */
    public function sendCartReadyEmail($quote, $customer, string $cartNumber, int $storeId): bool
    {
        $to = trim((string) $customer->getEmail());
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->_logger->error('ShoppingCart sendCartReadyEmail: customer has no valid email address.');
            return false;
        }
        try {
            $this->inlineTranslation->suspend();
            $items = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($item->getParentItemId()) continue;
                $items[] = [
                    'name' => $item->getName(),
                    'sku' => $item->getSku(),
                    'qty' => (int) $item->getQty(),
                    'price' => $quote->getStore()->getBaseCurrency()->format($item->getPrice(), [], false),
                ];
            }
            $cartItemsHtml = '';
            $cartTotal = '';
            if (!empty($items)) {
                $store = $quote->getStore();
                $subtotal = (float) $quote->getSubtotal();
                $subtotalFormatted = $store->getBaseCurrency()->format($subtotal, [], false);
                $cartTotal = $store->getBaseCurrency()->format($quote->getGrandTotal(), [], false);
                $cartItemsHtml = '<table style="width:100%; border-collapse:collapse; margin:20px 0;"><thead><tr style="background-color:#f5f5f5;">';
                $cartItemsHtml .= '<th style="padding:10px; text-align:left; border:1px solid #ddd;">Product Name</th><th style="padding:10px; text-align:left; border:1px solid #ddd;">SKU</th>';
                $cartItemsHtml .= '<th style="padding:10px; text-align:center; border:1px solid #ddd;">Qty</th><th style="padding:10px; text-align:right; border:1px solid #ddd;">Price</th></tr></thead><tbody>';
                foreach ($items as $item) {
                    $cartItemsHtml .= '<tr><td style="padding:10px; border:1px solid #ddd;">' . htmlspecialchars($item['name']) . '</td>';
                    $cartItemsHtml .= '<td style="padding:10px; border:1px solid #ddd;">' . htmlspecialchars($item['sku']) . '</td>';
                    $cartItemsHtml .= '<td style="padding:10px; text-align:center; border:1px solid #ddd;">' . $item['qty'] . '</td>';
                    $cartItemsHtml .= '<td style="padding:10px; text-align:right; border:1px solid #ddd;">' . htmlspecialchars($item['price']) . '</td></tr>';
                }
                $cartItemsHtml .= '</tbody><tfoot><tr><td colspan="3" style="padding:10px; text-align:right; border:1px solid #ddd; font-weight:bold; white-space:nowrap;">Subtotal:</td>';
                $cartItemsHtml .= '<td style="padding:10px; text-align:right; border:1px solid #ddd; font-weight:bold;">' . htmlspecialchars($subtotalFormatted) . '</td></tr></tfoot></table>';
            }
            $store = $quote->getStore();
            $shippingAddress = $quote->getShippingAddress();
            $shippingAmount = $shippingAddress ? (float) $shippingAddress->getShippingAmount() : 0;
            $taxAmount = $shippingAddress ? (float) $shippingAddress->getTaxAmount() : 0;
            $cartShipping = $store->getBaseCurrency()->format($shippingAmount, [], false);
            $cartTax = $store->getBaseCurrency()->format($taxAmount, [], false);
            $shippingMethodName = '';
            $shippingMethodCharges = '';
            if ($shippingAddress && $shippingAddress->getShippingMethod()) {
                $shippingMethodName = (string) $shippingAddress->getShippingDescription() ?: $shippingAddress->getShippingMethod();
                $shippingMethodCharges = $cartShipping;
            }
            if ($shippingMethodName === '' && $quote->getItemsCount() > 0) {
                $firstRate = null;
                try {
                    $firstRate = $this->shippingRateForEmailProvider->getFirstRateForQuote($quote);
                } catch (\Throwable $e) {
                    $this->_logger->warning(
                        'ShoppingCart sendCartReadyEmail: shipping rate for email failed: ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                }
                if ($firstRate && !empty($firstRate['method_title'])) {
                    $shippingMethodName = (string) $firstRate['method_title'];
                    $shippingMethodCharges = $quote->getStore()->getBaseCurrency()->format((float) ($firstRate['amount'] ?? 0), [], false);
                } else {
                    $shippingMethodName = (string) __('To be selected at checkout');
                    $shippingMethodCharges = '—';
                }
            }
            $poEmail = $this->config->getPoRecipientEmail($storeId) ?: 'hitachi-in.po@prominate.com';
            $days = $this->config->getGenerateCartReservationDays($storeId) ?: 7;
            $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
            $this->sendEmail($to, $customerName, $this->config->getCartReadyEmailTemplate($storeId), [
                'customer_name' => $customerName,
                'cart_number' => $cartNumber,
                'cart_items_html' => $cartItemsHtml,
                'cart_total' => $cartTotal,
                'cart_shipping' => $cartShipping,
                'cart_tax' => $cartTax,
                'shipping_method_name' => $shippingMethodName,
                'shipping_method_charges' => $shippingMethodCharges,
                'po_recipient_email' => $poEmail,
                'reservation_days' => (string) $days,
            ], $storeId);
            $this->inlineTranslation->resume();
            return true;
        } catch (\Throwable $e) {
            $this->inlineTranslation->resume();
            $this->_logger->error(
                'ShoppingCart sendCartReadyEmail: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }
    }

    /**
     * Same cart-ready template after checkout: "Generate Shopping Cart" payment places a real order;
     * success page uses order increment id — email was never sent from GenerateCart controller in that flow.
     */
    public function sendCartReadyEmailForOrder(OrderInterface $order): bool
    {
        if (!$order instanceof Order) {
            $this->_logger->error('ShoppingCart sendCartReadyEmailForOrder: unsupported order implementation.');
            return false;
        }
        $to = trim((string) $order->getCustomerEmail());
        if ($to === '' && $order->getBillingAddress()) {
            $to = trim((string) $order->getBillingAddress()->getEmail());
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->_logger->error('ShoppingCart sendCartReadyEmailForOrder: no valid recipient email on order.');
            return false;
        }
        $customerName = trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname());
        if ($customerName === '' && $order->getBillingAddress()) {
            $ba = $order->getBillingAddress();
            $customerName = trim((string) $ba->getFirstname() . ' ' . (string) $ba->getLastname());
        }
        if ($customerName === '') {
            $customerName = $to;
        }
        $storeId = (int) $order->getStoreId();
        $cartNumber = trim((string) $order->getIncrementId());
        if ($cartNumber === '') {
            $this->_logger->error('ShoppingCart sendCartReadyEmailForOrder: order has no increment id yet.');
            return false;
        }
        try {
            $this->inlineTranslation->suspend();
            $store = $order->getStore();
            $currency = $store->getBaseCurrency();
            $items = [];
            foreach ($order->getAllVisibleItems() as $item) {
                if ($item->getParentItemId()) {
                    continue;
                }
                $price = (float) $item->getBasePrice();
                $items[] = [
                    'name' => (string) $item->getName(),
                    'sku' => (string) $item->getSku(),
                    'qty' => (int) $item->getQtyOrdered(),
                    'price' => $currency->format($price, [], false),
                ];
            }
            $cartItemsHtml = '';
            $cartTotal = '';
            if (!empty($items)) {
                $subtotal = (float) $order->getBaseSubtotal();
                $subtotalFormatted = $currency->format($subtotal, [], false);
                $cartTotal = $currency->format((float) $order->getBaseGrandTotal(), [], false);
                $cartItemsHtml = '<table style="width:100%; border-collapse:collapse; margin:20px 0;"><thead><tr style="background-color:#f5f5f5;">';
                $cartItemsHtml .= '<th style="padding:10px; text-align:left; border:1px solid #ddd;">Product Name</th><th style="padding:10px; text-align:left; border:1px solid #ddd;">SKU</th>';
                $cartItemsHtml .= '<th style="padding:10px; text-align:center; border:1px solid #ddd;">Qty</th><th style="padding:10px; text-align:right; border:1px solid #ddd;">Price</th></tr></thead><tbody>';
                foreach ($items as $row) {
                    $cartItemsHtml .= '<tr><td style="padding:10px; border:1px solid #ddd;">' . htmlspecialchars($row['name']) . '</td>';
                    $cartItemsHtml .= '<td style="padding:10px; border:1px solid #ddd;">' . htmlspecialchars($row['sku']) . '</td>';
                    $cartItemsHtml .= '<td style="padding:10px; text-align:center; border:1px solid #ddd;">' . $row['qty'] . '</td>';
                    $cartItemsHtml .= '<td style="padding:10px; text-align:right; border:1px solid #ddd;">' . htmlspecialchars($row['price']) . '</td></tr>';
                }
                $cartItemsHtml .= '</tbody><tfoot><tr><td colspan="3" style="padding:10px; text-align:right; border:1px solid #ddd; font-weight:bold; white-space:nowrap;">Subtotal:</td>';
                $cartItemsHtml .= '<td style="padding:10px; text-align:right; border:1px solid #ddd; font-weight:bold;">' . htmlspecialchars($subtotalFormatted) . '</td></tr></tfoot></table>';
            }
            $shippingAmount = (float) $order->getBaseShippingAmount();
            $taxAmount = (float) $order->getBaseTaxAmount();
            $cartShipping = $currency->format($shippingAmount, [], false);
            $cartTax = $currency->format($taxAmount, [], false);
            $shippingMethodName = trim((string) $order->getShippingDescription());
            $shippingMethodCharges = '';
            if ($shippingMethodName !== '') {
                $shippingMethodCharges = $cartShipping;
            }
            if ($shippingMethodName === '' && count($items) > 0) {
                $shippingMethodName = (string) __('To be selected at checkout');
                $shippingMethodCharges = '—';
            }
            $poEmail = $this->config->getPoRecipientEmail($storeId) ?: 'hitachi-in.po@prominate.com';
            $days = $this->config->getGenerateCartReservationDays($storeId) ?: 7;
            $this->sendEmail($to, $customerName, $this->config->getCartReadyEmailTemplate($storeId), [
                'customer_name' => $customerName,
                'cart_number' => $cartNumber,
                'cart_items_html' => $cartItemsHtml,
                'cart_total' => $cartTotal,
                'cart_shipping' => $cartShipping,
                'cart_tax' => $cartTax,
                'shipping_method_name' => $shippingMethodName,
                'shipping_method_charges' => $shippingMethodCharges,
                'po_recipient_email' => $poEmail,
                'reservation_days' => (string) $days,
            ], $storeId);
            $this->inlineTranslation->resume();
            return true;
        } catch (\Throwable $e) {
            $this->inlineTranslation->resume();
            $this->_logger->error(
                'ShoppingCart sendCartReadyEmailForOrder: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }
    }

    /**
     * Resend "Your Shopping Cart is Ready" email to the customer (admin). Uses live quote if it still has items; else reserved_items snapshot.
     */
    public function resendCartReadyEmailForRequest(ShoppingCartRequestInterface $request): bool
    {
        if ($request->getRequestType() !== ShoppingCartRequestInterface::REQUEST_TYPE_GENERATE_CART) {
            return false;
        }
        $cartNumber = trim((string) $request->getCartNumber());
        if ($cartNumber === '' || !$request->getCustomerId()) {
            return false;
        }
        try {
            $customer = $this->customerRepository->getById((int) $request->getCustomerId());
        } catch (\Throwable $e) {
            $this->_logger->error('ShoppingCart resendCartReadyEmailForRequest: ' . $e->getMessage());
            return false;
        }
        $quoteId = (int) $request->getQuoteId();
        if ($quoteId > 0) {
            try {
                $quote = $this->quoteRepository->get($quoteId);
                if ($quote && $quote->getItemsCount() > 0) {
                    return $this->sendCartReadyEmail($quote, $customer, $cartNumber, (int) $quote->getStoreId());
                }
            } catch (\Throwable $e) {
                // inactive quote — fall back to snapshot
            }
        }
        return $this->sendCartReadyEmailFromSnapshot($request, $customer, $cartNumber);
    }

    /**
     * Cart-ready email when quote is gone: build items from reserved_items JSON.
     */
    private function sendCartReadyEmailFromSnapshot(
        ShoppingCartRequestInterface $request,
        CustomerInterface $customer,
        string $cartNumber
    ): bool {
        $to = trim((string) $customer->getEmail());
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->_logger->error('ShoppingCart sendCartReadyEmailFromSnapshot: customer has no valid email address.');
            return false;
        }
        try {
            $this->inlineTranslation->suspend();
            $storeId = (int) $customer->getStoreId();
            if ($storeId <= 0) {
                $default = $this->storeManager->getDefaultStoreView();
                $storeId = $default ? (int) $default->getId() : (int) $this->storeManager->getStore()->getId();
            }
            $dash = '—';
            $itemsHtml = $this->buildCartReadyItemsHtmlFromReservedJson($request->getReservedItems(), $storeId);
            if ($itemsHtml === '') {
                $itemsHtml = '<p>'
                    . htmlspecialchars((string) __('Cart line items are no longer available; use your Shopping Cart Number above for reference.'))
                    . '</p>';
            }
            $shippingMethodName = trim((string) $request->getShippingMethodName());
            $shippingMethodCharges = trim((string) ($request->getShippingMethodCharges() ?? ''));
            if ($shippingMethodName === '') {
                $shippingMethodName = (string) __('To be selected at checkout');
                $shippingMethodCharges = $dash;
            }
            $poEmail = $this->config->getPoRecipientEmail($storeId) ?: 'hitachi-in.po@prominate.com';
            $days = $this->config->getGenerateCartReservationDays($storeId) ?: 7;
            $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
            $this->sendEmail($to, $customerName, $this->config->getCartReadyEmailTemplate($storeId), [
                'customer_name' => $customerName,
                'cart_number' => $cartNumber,
                'cart_items_html' => $itemsHtml,
                'cart_total' => $dash,
                'cart_shipping' => $dash,
                'cart_tax' => $dash,
                'shipping_method_name' => $shippingMethodName,
                'shipping_method_charges' => $shippingMethodCharges !== '' ? $shippingMethodCharges : $dash,
                'po_recipient_email' => $poEmail,
                'reservation_days' => (string) $days,
            ], $storeId);
            $this->inlineTranslation->resume();
            return true;
        } catch (\Throwable $e) {
            $this->inlineTranslation->resume();
            $this->_logger->error(
                'ShoppingCart sendCartReadyEmailFromSnapshot: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }
    }

    /**
     * @param string|null $json
     */
    private function buildCartReadyItemsHtmlFromReservedJson($json, int $storeId): string
    {
        if (!$json || !is_string($json)) {
            return '';
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || empty($decoded)) {
            return '';
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = isset($row['sku']) ? (string) $row['sku'] : '';
            $qty = isset($row['qty']) ? (float) $row['qty'] : 0;
            if ($sku === '') {
                continue;
            }
            $name = $sku;
            try {
                $product = $this->productRepository->get($sku, false, $storeId);
                $name = (string) $product->getName();
            } catch (\Throwable $e) {
                // keep SKU as label
            }
            $rows[] = ['name' => $name, 'sku' => $sku, 'qty' => $qty];
        }
        if (empty($rows)) {
            return '';
        }
        $html = '<table style="width:100%; border-collapse:collapse; margin:20px 0;"><thead><tr style="background-color:#f5f5f5;">';
        $html .= '<th style="padding:10px; text-align:left; border:1px solid #ddd;">Product Name</th><th style="padding:10px; text-align:left; border:1px solid #ddd;">SKU</th>';
        $html .= '<th style="padding:10px; text-align:center; border:1px solid #ddd;">Qty</th><th style="padding:10px; text-align:right; border:1px solid #ddd;">Price</th></tr></thead><tbody>';
        foreach ($rows as $item) {
            $html .= '<tr><td style="padding:10px; border:1px solid #ddd;">' . htmlspecialchars($item['name']) . '</td>';
            $html .= '<td style="padding:10px; border:1px solid #ddd;">' . htmlspecialchars($item['sku']) . '</td>';
            $html .= '<td style="padding:10px; text-align:center; border:1px solid #ddd;">' . (int) $item['qty'] . '</td>';
            $html .= '<td style="padding:10px; text-align:right; border:1px solid #ddd;">—</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Send approval request to selected approver (Purchase Approval form).
     * Uses only the stored approver email (no display name); display name is for frontend dropdown only.
     */
    public function sendApprovalRequestToApprover(ShoppingCartRequestInterface $request): bool
    {
        $approverEmail = $request->getApproverEmail();
        if (!$approverEmail || !filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            $this->inlineTranslation->suspend();
            $storeId = (int) $this->storeManager->getStore()->getId();
            if ($request->getQuoteId()) {
                try {
                    $quote = $this->quoteRepository->get((int) $request->getQuoteId());
                    $storeId = (int) $quote->getStoreId();
                } catch (\Exception $e) {}
            }
            $customer = $this->customerRepository->getById($request->getCustomerId());
            $cartItemsHtml = '';
            $cartShipping = '';
            $cartTax = '';
            $cartTotal = '';
            if ($request->getQuoteId()) {
                try {
                    $quote = $this->quoteRepository->get((int) $request->getQuoteId());
                    $items = [];
                    foreach ($quote->getAllItems() as $item) {
                        if ($item->getParentItemId()) continue;
                        $items[] = ['name' => $item->getName(), 'sku' => $item->getSku(), 'qty' => (int) $item->getQty(), 'price' => $quote->getStore()->getBaseCurrency()->format($item->getPrice(), [], false)];
                    }
                    if (!empty($items)) {
                        $store = $quote->getStore();
                        $subtotal = (float) $quote->getSubtotal();
                        $subtotalFormatted = $store->getBaseCurrency()->format($subtotal, [], false);
                        $cartItemsHtml = '<table style="width:100%; border-collapse:collapse;"><thead><tr style="background:#f5f5f5;"><th style="padding:8px; border:1px solid #ddd;">Product Name</th><th style="padding:8px; border:1px solid #ddd;">SKU</th><th style="padding:8px; text-align:center; border:1px solid #ddd;">Qty</th><th style="padding:8px; text-align:right; border:1px solid #ddd;">Price</th></tr></thead><tbody>';
                        foreach ($items as $i) {
                            $cartItemsHtml .= '<tr><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($i['name']) . '</td><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($i['sku']) . '</td><td style="padding:8px; text-align:center; border:1px solid #ddd;">' . $i['qty'] . '</td><td style="padding:8px; text-align:right; border:1px solid #ddd;">' . htmlspecialchars($i['price']) . '</td></tr>';
                        }
                        $cartItemsHtml .= '</tbody><tfoot><tr><td colspan="3" style="padding:8px; text-align:right; border:1px solid #ddd; font-weight:bold; white-space:nowrap;">Subtotal:</td><td style="padding:8px; text-align:right; border:1px solid #ddd; font-weight:bold;">' . htmlspecialchars($subtotalFormatted) . '</td></tr></tfoot></table>';
                    }
                    $store = $quote->getStore();
                    $shippingAddress = $quote->getShippingAddress();
                    $shippingAmount = $shippingAddress ? (float) $shippingAddress->getShippingAmount() : 0;
                    $taxAmount = $shippingAddress ? (float) $shippingAddress->getTaxAmount() : 0;
                    $cartShipping = $store->getBaseCurrency()->format($shippingAmount, [], false);
                    $cartTax = $store->getBaseCurrency()->format($taxAmount, [], false);
                    $cartTotal = $store->getBaseCurrency()->format($quote->getGrandTotal(), [], false);
                } catch (\Exception $e) {}
            }
            $shippingMethodName = '';
            $shippingMethodCharges = '';
            // Use shipping captured from cart page (set in SubmitApproval) if present
            if ((string) $request->getShippingMethodName() !== '') {
                $shippingMethodName = (string) $request->getShippingMethodName();
                $shippingMethodCharges = (string) ($request->getShippingMethodCharges() ?? '');
            }
            if ($shippingMethodName === '' && $request->getQuoteId()) {
                try {
                    $quote = $this->quoteRepository->get((int) $request->getQuoteId());
                    $shippingAddress = $quote->getShippingAddress();
                    if ($shippingAddress && $shippingAddress->getShippingMethod()) {
                        $shippingMethodName = (string) $shippingAddress->getShippingDescription() ?: $shippingAddress->getShippingMethod();
                        $shippingMethodCharges = $quote->getStore()->getBaseCurrency()->format((float) $shippingAddress->getShippingAmount(), [], false);
                    }
                    // Quote from DB often has no shipping (user submitted approval without selecting); try provider or show fallback
                    if ($shippingMethodName === '' && $quote->getItemsCount() > 0) {
                        try {
                            $firstRate = $this->shippingRateForEmailProvider->getFirstRateForQuote($quote);
                            if ($firstRate && !empty($firstRate['method_title'])) {
                                $shippingMethodName = (string) $firstRate['method_title'];
                                $amount = isset($firstRate['amount']) ? (float) $firstRate['amount'] : 0;
                                $shippingMethodCharges = $quote->getStore()->getBaseCurrency()->format($amount, [], false);
                            } else {
                                $shippingMethodName = (string) __('To be selected at checkout');
                                $shippingMethodCharges = '—';
                            }
                        } catch (\Exception $e) {
                                $shippingMethodName = (string) __('To be selected at checkout');
                                $shippingMethodCharges = '—';
                        }
                    }
                } catch (\Exception $e) {
                    // Quote load or shipping logic failed; fallback set below when quoteId present
                }
            }
            // Ensure approval email always shows a shipping line when we have a quote (even if load/provider failed)
            if ($request->getQuoteId() && $shippingMethodName === '') {
                $shippingMethodName = (string) __('To be selected at checkout');
                $shippingMethodCharges = '—';
            }
            // Save shipping to request (grid + audit); then send email
            try {
                $request->setShippingMethodName($shippingMethodName);
                $request->setShippingMethodCharges($shippingMethodCharges);
                $this->requestRepository->save($request);
            } catch (\Exception $e) {
                $this->_logger->warning('ShoppingCart: Could not save shipping to request: ' . $e->getMessage());
            }
            // Approval email "Order Total" must match shown shipping: quote grand total often excludes shipping
            // at submit time (method shown from cart POST or first-rate estimate), while cart ready uses collected quote.
            if ($request->getQuoteId() && $shippingMethodCharges !== '' && $shippingMethodCharges !== '—') {
                $add = $this->parseNumericFromChargesString($shippingMethodCharges);
                if ($add > 0) {
                    try {
                        $quote = $this->quoteRepository->get((int) $request->getQuoteId());
                        $shippingAddress = $quote->getShippingAddress();
                        $shippingOnQuote = $shippingAddress ? (float) $shippingAddress->getShippingAmount() : 0.0;
                        if ($shippingOnQuote <= 0.00001) {
                            $cartTotal = $quote->getStore()->getBaseCurrency()->format(
                                (float) $quote->getGrandTotal() + $add,
                                [],
                                false
                            );
                        }
                    } catch (\Exception $e) {
                        // keep cart_total from quote block above
                    }
                }
            }
            $store = $this->getFrontendStoreForUrl($storeId);
            $useSecure = (bool) $this->scopeConfig->getValue(
                'web/secure/use_in_frontend',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                (int) $store->getId()
            );
            $approveUrl = $this->buildFrontendUrl(
                $store,
                'shoppingcart/workplace/approve',
                [
                    'id' => $request->getEntityId(),
                    'token' => $request->getApprovalToken()
                ],
                $useSecure
            );
            $rejectUrl = $this->buildFrontendUrl(
                $store,
                'shoppingcart/workplace/rejectForm',
                [
                    'id' => $request->getEntityId(),
                    'token' => $request->getApprovalToken()
                ],
                $useSecure
            );
            $cartViewUrl = $this->buildFrontendUrl(
                $store,
                'shoppingcart/cart/view',
                [
                    'token' => $request->getApprovalToken()
                ],
                $useSecure
            );
            $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
            $this->sendEmail($approverEmail, 'Approver', $this->config->getApprovalRequestEmailTemplate($storeId), [
                'customer_name' => $customerName,
                'purchase_type' => $request->getPurchaseType(),
                'reason_for_purchase' => $request->getReasonForPurchase(),
                'approve_url' => $approveUrl,
                'reject_url' => $rejectUrl,
                'cart_items_html' => $cartItemsHtml,
                'cart_shipping' => $cartShipping,
                'cart_tax' => $cartTax,
                'cart_total' => $cartTotal,
                'cart_view_url' => $cartViewUrl,
                'shipping_method_name' => $shippingMethodName,
                'shipping_method_charges' => $shippingMethodCharges,
            ], $storeId);
            $this->inlineTranslation->resume();
            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->_logger->error('ShoppingCart sendApprovalRequestToApprover: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send "Approval request submitted" confirmation email to customer.
     */
    public function sendSubmittedEmailToCustomer(ShoppingCartRequestInterface $request, int $reservationDays = 7): bool
    {
        try {
            $customer = $this->customerRepository->getById($request->getCustomerId());
            $storeId = (int) $this->storeManager->getStore()->getId();
            if ($request->getQuoteId()) {
                try {
                    $quote = $this->quoteRepository->get((int) $request->getQuoteId());
                    $storeId = (int) $quote->getStoreId();
                } catch (\Exception $e) {}
            }
            $this->inlineTranslation->suspend();
            $name = $customer->getFirstname() . ' ' . $customer->getLastname();
            $store = $this->getFrontendStoreForUrl($storeId);
            $useSecure = (bool) $this->scopeConfig->getValue(
                'web/secure/use_in_frontend',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                (int) $store->getId()
            );
            $cartViewUrl = $request->getResumeToken()
                ? $this->buildFrontendUrl(
                    $store,
                    'shoppingcart/cart/view',
                    [
                        'token' => $request->getResumeToken()
                    ],
                    $useSecure
                )
                : '';
            $this->sendEmail($customer->getEmail(), $name, $this->config->getSubmittedEmailTemplate($storeId), [
                'customer_name' => $name,
                'reservation_days' => $reservationDays,
                'cart_view_url' => $cartViewUrl,
            ], $storeId);
            $this->inlineTranslation->resume();
            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->_logger->error('ShoppingCart sendSubmittedEmailToCustomer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send "Request approved" email to customer.
     */
    public function sendApprovedEmail(ShoppingCartRequestInterface $request): bool
    {
        try {
            $customer = $this->customerRepository->getById($request->getCustomerId());
            $storeId = (int) $this->storeManager->getStore()->getId();
            if ($request->getQuoteId()) {
                try {
                    $quote = $this->quoteRepository->get((int) $request->getQuoteId());
                    $storeId = (int) $quote->getStoreId();
                } catch (\Exception $e) {}
            }
            $this->inlineTranslation->suspend();
            $name = $customer->getFirstname() . ' ' . $customer->getLastname();
            $resumeToken = $request->getResumeToken();
            $store = $this->getFrontendStoreForUrl($storeId);
            $useSecure = (bool) $this->scopeConfig->getValue('web/secure/use_in_frontend', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
            // Link goes to cart first (restore quote + totals/shipping); request_id so Generate Cart updates this same row
            if ($resumeToken) {
                $checkoutUrl = $this->buildFrontendUrl(
                    $store,
                    'shoppingcart/cart/view',
                    [
                        'token' => $resumeToken,
                        'request_id' => (int) $request->getEntityId(),
                    ],
                    $useSecure
                );
            } else {
                $checkoutUrl = $this->buildFrontendUrl($store, 'checkout', [], $useSecure);
            }
            $this->sendEmail($customer->getEmail(), $name, $this->config->getApprovedEmailTemplate($storeId), [
                'customer_name' => $name,
                'entity_id' => $request->getEntityId(),
                'checkout_url' => $checkoutUrl,
            ], $storeId);
            $this->inlineTranslation->resume();
            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->_logger->error('ShoppingCart sendApprovedEmail: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send "Request rejected" email to customer.
     */
    public function sendRejectedEmail(ShoppingCartRequestInterface $request): bool
    {
        try {
            $customer = $this->customerRepository->getById($request->getCustomerId());
            $storeId = (int) $this->storeManager->getStore()->getId();
            if ($request->getQuoteId()) {
                try {
                    $quote = $this->quoteRepository->get((int) $request->getQuoteId());
                    $storeId = (int) $quote->getStoreId();
                } catch (\Exception $e) {}
            }
            $this->inlineTranslation->suspend();
            $name = $customer->getFirstname() . ' ' . $customer->getLastname();
            $this->sendEmail($customer->getEmail(), $name, $this->config->getRejectedEmailTemplate($storeId), [
                'customer_name' => $name,
                'entity_id' => $request->getEntityId(),
                'rejection_reason' => $request->getRejectionReason(),
            ], $storeId);
            $this->inlineTranslation->resume();
            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->_logger->error('ShoppingCart sendRejectedEmail: ' . $e->getMessage());
            return false;
        }
    }

    private function parseNumericFromChargesString(string $charges): float
    {
        $cleaned = preg_replace('/[^\d.,\-]/', '', $charges);
        return (float) str_replace(',', '', $cleaned);
    }

    /**
     * Always resolve a frontend store for URL building.
     * Prevents accidental admin-prefixed URLs when called from admin area.
     */
    private function getFrontendStoreForUrl(int $preferredStoreId)
    {
        try {
            if ($preferredStoreId > 0) {
                return $this->storeManager->getStore($preferredStoreId);
            }
        } catch (\Exception $e) {
            // Fallback below.
        }

        try {
            $defaultStoreView = $this->storeManager->getDefaultStoreView();
            if ($defaultStoreView) {
                return $defaultStoreView;
            }
        } catch (\Exception $e) {
            // Fallback below.
        }

        return $this->storeManager->getStore();
    }

    /**
     * Build frontend-safe URL without admin frontName pollution.
     */
    private function buildFrontendUrl(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $path,
        array $queryParams = [],
        bool $useSecure = true
    ): string {
        $baseUrl = $store->getBaseUrl(
            \Magento\Framework\UrlInterface::URL_TYPE_LINK,
            $useSecure
        );
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }
        return $url;
    }
}
