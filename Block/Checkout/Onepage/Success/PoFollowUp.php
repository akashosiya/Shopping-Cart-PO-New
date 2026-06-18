<?php
namespace Osiyatech\ShoppingCart\Block\Checkout\Onepage\Success;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;
use Osiyatech\ShoppingCart\Model\Payment\GenerateCartMethod;
use Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest\CollectionFactory as RequestCollectionFactory;

/**
 * Extra PO / generate-cart instructions on checkout success for shoppingcart_generatecart orders.
 */
class PoFollowUp extends Template
{
    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var PoConfig */
    private $poConfig;

    /** @var RequestCollectionFactory */
    private $requestCollectionFactory;

    /** @var OrderInterface|null */
    private $order;

    /** @var bool */
    private $orderResolved = false;

    public function __construct(
        Template\Context $context,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        PoConfig $poConfig,
        RequestCollectionFactory $requestCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->poConfig = $poConfig;
        $this->requestCollectionFactory = $requestCollectionFactory;
    }

    protected function _toHtml()
    {
        if (!$this->isGenerateCartOrder()) {
            return '';
        }
        return parent::_toHtml();
    }

    public function isGenerateCartOrder(): bool
    {
        $order = $this->resolveOrder();
        if (!$order || !$order->getEntityId()) {
            return false;
        }
        $payment = $order->getPayment();
        return $payment && $payment->getMethod() === GenerateCartMethod::PAYMENT_METHOD_CODE;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->resolveOrder();
    }

    public function getCartNumber(): string
    {
        $order = $this->resolveOrder();
        if (!$order) {
            return '';
        }
        $request = $this->findPoRequestWithCartNumber($order);
        $num = $request ? (string) $request->getCartNumber() : '';
        return trim($num);
    }

    public function getPoRecipientEmail(): string
    {
        $order = $this->resolveOrder();
        $storeId = $order ? (int) $order->getStoreId() : null;
        $email = $this->poConfig->getPoRecipientEmail($storeId);
        return $email !== '' ? $email : 'hitachi-in.po@prominate.com';
    }

    public function getReservationDays(): int
    {
        $order = $this->resolveOrder();
        $storeId = $order ? (int) $order->getStoreId() : null;
        return $this->poConfig->getGenerateCartReservationDays($storeId);
    }

    public function getAribaCode(): string
    {
        $order = $this->resolveOrder();
        $storeId = $order ? (int) $order->getStoreId() : null;
        return $this->poConfig->getAribaSupplierCode($storeId);
    }

    private function resolveOrder(): ?OrderInterface
    {
        if ($this->orderResolved) {
            return $this->order;
        }
        $this->orderResolved = true;
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if ($orderId <= 0) {
            return null;
        }
        try {
            $this->order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            $this->order = null;
        }
        return $this->order;
    }

    /**
     * Latest PO request for this checkout quote that has a cart number (generate flow / completion).
     */
    private function findPoRequestWithCartNumber(OrderInterface $order): ?ShoppingCartRequestInterface
    {
        $quoteId = (int) $order->getQuoteId();
        if ($quoteId <= 0) {
            return null;
        }
        $collection = $this->requestCollectionFactory->create();
        $collection->addFieldToFilter('quote_id', $quoteId);
        $collection->addFieldToFilter('cart_number', ['notnull' => true]);
        $collection->addFieldToFilter('cart_number', ['neq' => '']);
        $collection->setOrder('entity_id', 'DESC');
        $collection->setPageSize(1);
        if (!$collection->getSize()) {
            return null;
        }
        $item = $collection->getFirstItem();
        return $item->getEntityId() ? $item : null;
    }
}
