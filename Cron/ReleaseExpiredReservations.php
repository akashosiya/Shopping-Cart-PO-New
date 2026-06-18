<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Cron;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Helper\Config;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Auto-expire PENDING approval requests and release quantity (cron: see etc/crontab.xml).
 * - Approval: PENDING longer than configured reservation days (from reserved_at, else submitted_at)
 *   → status expired; reserved_items kept for admin history; optional MSI compensation
 * - Generate Cart: PENDING generate_cart older than X days → release reservation only
 * - Sales orders: status waiting_for_po older than configured days → cancel
 */
class ReleaseExpiredReservations
{
    private const ORDER_STATUS_WAITING_FOR_PO = 'waiting_for_po';

    /** @var Config */
    private $config;
    /** @var CollectionFactory */
    private $collectionFactory;
    /** @var ShoppingCartRequestRepositoryInterface */
    private $repository;
    /** @var LoggerInterface */
    private $logger;
    /** @var OrderCollectionFactory */
    private $orderCollectionFactory;
    /** @var OrderManagementInterface */
    private $orderManagement;
    /** @var OrderRepositoryInterface */
    private $orderRepository;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var CartReservationManager */
    private $reservationManager;
    /** @var CartRepositoryInterface */
    private $quoteRepository;
    /** @var DateTime */
    private $dateTime;

    public function __construct(
        Config $config,
        CollectionFactory $collectionFactory,
        ShoppingCartRequestRepositoryInterface $repository,
        LoggerInterface $logger,
        OrderCollectionFactory $orderCollectionFactory,
        OrderManagementInterface $orderManagement,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        CartReservationManager $reservationManager,
        CartRepositoryInterface $quoteRepository,
        DateTime $dateTime
    ) {
        $this->config = $config;
        $this->collectionFactory = $collectionFactory;
        $this->repository = $repository;
        $this->logger = $logger;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderManagement = $orderManagement;
        $this->orderRepository = $orderRepository;
        $this->storeManager = $storeManager;
        $this->reservationManager = $reservationManager;
        $this->quoteRepository = $quoteRepository;
        $this->dateTime = $dateTime;
    }

    public function execute(): void
    {
        $this->releaseExpiredApprovalRequests();
        $this->releaseExpiredGenerateCartRequests();
        $this->cancelExpiredWaitingForPoOrders();
    }

    /**
     * PENDING approval requests past reservation window → expired, stock unblocked (MSI if used); snapshot kept for display.
     */
    private function releaseExpiredApprovalRequests(): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ShoppingCartRequestInterface::STATUS_PENDING);
        $collection->addFieldToFilter('request_type', ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL);

        foreach ($collection as $request) {
            $storeId = $this->resolveStoreIdForRequest($request);
            $days = $this->config->getApprovalReservationDays($storeId);
            if ($days <= 0) {
                continue;
            }
            if (!$this->isApprovalReservationExpired($request, $days)) {
                continue;
            }
            try {
                $this->reservationManager->releaseApprovalReservationIfPlaced($request);
                $request->setStatus(ShoppingCartRequestInterface::STATUS_EXPIRED);
                $request->setRejectionReason(
                    __('Reservation expired after %1 days (no approval). Stock is available again.', $days)
                );
                $request->setMsiReservationPlaced(0);
                $request->setReservationReleasedAt($this->dateTime->gmtDate());
                $request->setRejectedAt($this->dateTime->gmtDate());
                $request->setRejectedBy(null);
                $this->repository->save($request);
                $this->logger->info(
                    sprintf(
                        'Shopping cart approval request #%s (quote %s): expired after %s days; reservation released.',
                        $request->getEntityId(),
                        $request->getQuoteId(),
                        $days
                    )
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Failed to expire shopping cart approval request id %s: %s',
                        $request->getEntityId(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }
    }

    private function resolveStoreIdForRequest(ShoppingCartRequestInterface $request): int
    {
        $quoteId = (int) $request->getQuoteId();
        if ($quoteId <= 0) {
            return 0;
        }
        try {
            $quote = $this->quoteRepository->get($quoteId);
            return (int) $quote->getStoreId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Expiry anchor: reserved_at (when snapshot / blocking started), else submitted_at (legacy rows).
     */
    private function isApprovalReservationExpired(ShoppingCartRequestInterface $request, int $days): bool
    {
        $startStr = $request->getReservedAt() ?: $request->getSubmittedAt();
        if ($startStr === null || $startStr === '') {
            return false;
        }
        $start = strtotime((string) $startStr);
        if ($start === false) {
            return false;
        }
        $limit = $start + ($days * 86400);
        return time() >= $limit;
    }

    /**
     * PENDING generate_cart requests older than reservation days (no action) → release quantity only.
     */
    private function releaseExpiredGenerateCartRequests(): void
    {
        $days = $this->config->getGenerateCartReservationDays(0);
        if ($days <= 0) {
            return;
        }
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('request_type', ShoppingCartRequestInterface::REQUEST_TYPE_GENERATE_CART);
        $collection->addFieldToFilter('status', ShoppingCartRequestInterface::STATUS_PENDING);
        $collection->addFieldToFilter('reserved_at', ['lt' => $cutoff]);
        $collection->addFieldToFilter('reservation_released_at', ['null' => true]);

        foreach ($collection as $request) {
            try {
                $request->setReservationReleasedAt($this->dateTime->gmtDate());
                $this->repository->save($request);
                $this->logger->info(
                    sprintf(
                        'Generate cart request #%s (quote %s): reservation released after %s days (no action).',
                        $request->getEntityId(),
                        $request->getQuoteId(),
                        $days
                    )
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Failed to release generate cart request id %s: %s', $request->getEntityId(), $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }

    private function cancelExpiredWaitingForPoOrders(): void
    {
        $minDays = $this->getMinWaitingForPoAutoCancelDays();
        if ($minDays <= 0) {
            return;
        }
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$minDays} days"));
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('status', self::ORDER_STATUS_WAITING_FOR_PO);
        $collection->addFieldToFilter('created_at', ['lt' => $cutoff]);

        foreach ($collection as $order) {
            $storeId = (int) $order->getStoreId();
            $days = $this->config->getWaitingForPoAutoCancelDays($storeId);
            if ($days <= 0) {
                continue;
            }
            if (strtotime((string) $order->getCreatedAt()) >= strtotime("-{$days} days")) {
                continue;
            }
            if (!$order->canCancel()) {
                $this->logger->notice(
                    sprintf(
                        'ShoppingCart: Auto-cancel skipped for order %s (cannot cancel).',
                        $order->getIncrementId()
                    )
                );
                continue;
            }
            $orderId = (int) $order->getEntityId();
            try {
                $cancelled = $this->orderManagement->cancel($orderId);
                if (!$cancelled) {
                    $this->logger->notice(
                        sprintf(
                            'ShoppingCart: Auto-cancel returned false for order %s.',
                            $order->getIncrementId()
                        )
                    );
                    continue;
                }
                $freshOrder = $this->orderRepository->get($orderId);
                $freshOrder->addStatusHistoryComment(
                    __(
                        'Automatically cancelled: no PO or admin processing within %1 days (Shopping Cart PO).',
                        $days
                    ),
                    false
                )->setIsCustomerNotified(false);
                $this->orderRepository->save($freshOrder);
                $this->logger->info(
                    sprintf(
                        'ShoppingCart: Auto-cancelled order %s after %s days in waiting for PO.',
                        $order->getIncrementId(),
                        $days
                    )
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'ShoppingCart: Auto-cancel failed for order %s: %s',
                        $order->getIncrementId(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }
    }

    private function getMinWaitingForPoAutoCancelDays(): int
    {
        $min = null;
        foreach ($this->storeManager->getStores() as $store) {
            $d = $this->config->getWaitingForPoAutoCancelDays((int) $store->getId());
            if ($d <= 0) {
                continue;
            }
            $min = $min === null ? $d : min($min, $d);
        }
        if ($min !== null) {
            return (int) $min;
        }
        return $this->config->getWaitingForPoAutoCancelDays(0);
    }
}
