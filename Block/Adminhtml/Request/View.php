<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Block\Adminhtml\Request;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Catalog\Api\ProductRepositoryInterface;

class View extends Template
{
    protected $_template = 'Osiyatech_ShoppingCart::request/view.phtml';

    /** @var ShoppingCartRequestRepositoryInterface */
    private $repository;

    /** @var CustomerRepositoryInterface */
    private $customerRepository;

    /** @var QuoteRepository */
    private $quoteRepository;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $repository,
        CustomerRepositoryInterface $customerRepository,
        QuoteRepository $quoteRepository,
        ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->repository = $repository;
        $this->customerRepository = $customerRepository;
        $this->quoteRepository = $quoteRepository;
        $this->productRepository = $productRepository;
    }

    public function getRequestEntity(): ?\Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface
    {
        $id = (int) $this->getRequest()->getParam('id');
        if (!$id) return null;
        try {
            return $this->repository->getById($id);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get customer full name for the request (or fallback if customer deleted).
     */
    public function getCustomerName(?\Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface $entity): string
    {
        if (!$entity || !$entity->getCustomerId()) {
            return '';
        }
        try {
            $customer = $this->customerRepository->getById($entity->getCustomerId());
            return trim($customer->getFirstname() . ' ' . $customer->getLastname()) ?: (string) $entity->getCustomerId();
        } catch (\Exception $e) {
            return (string) $entity->getCustomerId();
        }
    }

    /**
     * Get customer email for the request (or fallback if customer deleted).
     */
    public function getCustomerEmail(?\Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface $entity): string
    {
        if (!$entity || !$entity->getCustomerId()) {
            return '';
        }
        try {
            $customer = $this->customerRepository->getById($entity->getCustomerId());
            return (string) $customer->getEmail();
        } catch (\Exception $e) {
            return __('Customer #%1 (deleted or invalid)', $entity->getCustomerId())->render();
        }
    }

    public function getApproveUrl(): string
    {
        return $this->getUrl('shoppingcart/request/approve', ['id' => (int) $this->getRequest()->getParam('id')]);
    }

    public function getRejectUrl(): string
    {
        return $this->getUrl('shoppingcart/request/reject', ['id' => (int) $this->getRequest()->getParam('id')]);
    }

    public function getCancelUrl(): string
    {
        return $this->getUrl('shoppingcart/request/cancel', ['id' => (int) $this->getRequest()->getParam('id')]);
    }

    public function canAdminCancel(?ShoppingCartRequestInterface $entity): bool
    {
        if (!$entity) {
            return false;
        }
        $status = $entity->getStatus();
        return $status === ShoppingCartRequestInterface::STATUS_PENDING
            || $status === ShoppingCartRequestInterface::STATUS_APPROVED;
    }

    public function canResendCartReadyEmail(?ShoppingCartRequestInterface $entity): bool
    {
        if (!$entity) {
            return false;
        }
        if ($entity->getRequestType() !== ShoppingCartRequestInterface::REQUEST_TYPE_GENERATE_CART) {
            return false;
        }
        $cartNumber = trim((string) $entity->getCartNumber());

        return $cartNumber !== '' && (int) $entity->getCustomerId() > 0;
    }

    public function getResendCartEmailUrl(): string
    {
        return $this->getUrl('shoppingcart/request/resendCartEmail', ['id' => (int) $this->getRequest()->getParam('id')]);
    }

    /**
     * Cart lines for admin: prefer live quote when it has items; otherwise reserved_items snapshot (kept after approve/reject for history).
     * Each item: ['name' => string, 'sku' => string, 'qty' => float, 'price' => string|null]
     *
     * @param \Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface|null $entity
     * @return array
     */
    public function getCartItemsForDisplay(?\Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface $entity): array
    {
        if (!$entity || !$entity->getQuoteId()) {
            return [];
        }

        try {
            $quote = $this->quoteRepository->get($entity->getQuoteId());
            if ($quote && $quote->getItemsCount()) {
                $items = [];
                foreach ($quote->getAllVisibleItems() as $item) {
                    $items[] = [
                        'name' => (string) $item->getName(),
                        'sku' => (string) $item->getSku(),
                        'qty' => (float) $item->getQty(),
                        'price' => $quote->getStoreId()
                            ? $quote->getStore()->formatPrice($item->getPrice())
                            : null,
                    ];
                }
                return $items;
            }
        } catch (\Exception $e) {
            // Quote not found or inactive; fall back to reserved_items
        }

        $json = $entity->getReservedItems();
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $items = [];
        foreach ($decoded as $row) {
            $sku = isset($row['sku']) ? (string) $row['sku'] : '';
            $qty = isset($row['qty']) ? (float) $row['qty'] : 0;
            if ($sku === '') {
                continue;
            }
            $name = $sku;
            try {
                $product = $this->productRepository->get($sku);
                $name = (string) $product->getName();
            } catch (\Exception $e) {
                // keep SKU as name
            }
            $items[] = [
                'name' => $name,
                'sku' => $sku,
                'qty' => $qty,
                'price' => null,
            ];
        }
        return $items;
    }
}
