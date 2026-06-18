<?php
namespace Osiyatech\ShoppingCart\Model\Inventory;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\StoreManagerInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Build reserved items snapshot from quote and place/release MSI reservations to block salable quantity.
 */
class CartReservationManager
{
    const SALES_EVENT_TYPE_APPROVAL = 'shopping_cart_approval';

    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var LoggerInterface */
    private $logger;
    /** @var ResourceConnection */
    private $resourceConnection;
    /** @var ObjectManagerInterface */
    private $objectManager;
    /** @var mixed PlaceReservationsForSalesEventInterface|null */
    private $placeReservations;
    /** @var mixed ItemToSellInterfaceFactory|null */
    private $itemToSellFactory;
    /** @var mixed SalesChannelInterfaceFactory|null */
    private $salesChannelFactory;
    /** @var mixed SalesEventInterfaceFactory|null */
    private $salesEventFactory;

    public function __construct(
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        ObjectManagerInterface $objectManager,
        $placeReservations = null,
        $itemToSellFactory = null,
        $salesChannelFactory = null,
        $salesEventFactory = null
    ) {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->objectManager = $objectManager;
        $this->placeReservations = $placeReservations;
        $this->itemToSellFactory = $itemToSellFactory;
        $this->salesChannelFactory = $salesChannelFactory;
        $this->salesEventFactory = $salesEventFactory;
    }

    /**
     * Build snapshot array from quote: [['sku' => 'X', 'qty' => 2], ...]
     *
     * @param CartInterface $quote
     * @return array
     */
    public function buildSnapshotFromQuote(CartInterface $quote): array
    {
        $map = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $sku = $this->getStockSkuFromItem($item);
            $qty = (float) $item->getQty();
            if ($sku === '' || $qty <= 0) continue;
            $map[$sku] = ($map[$sku] ?? 0) + $qty;
        }
        $out = [];
        foreach ($map as $sku => $qty) {
            $out[] = ['sku' => (string) $sku, 'qty' => (float) $qty];
        }
        return $out;
    }

    /**
     * Build snapshot from placed order (checkout with Generate Shopping Cart payment).
     *
     * @return array<int, array{sku: string, qty: float}>
     */
    public function buildSnapshotFromOrder(Order $order): array
    {
        $map = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if (!$item instanceof OrderItem) {
                continue;
            }
            $sku = $this->getStockSkuFromOrderItem($item);
            $qty = (float) $item->getQtyOrdered();
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $map[$sku] = ($map[$sku] ?? 0) + $qty;
        }
        $out = [];
        foreach ($map as $sku => $qty) {
            $out[] = ['sku' => (string) $sku, 'qty' => (float) $qty];
        }
        return $out;
    }

    /**
     * Decode reserved_items JSON to [['sku' => string, 'qty' => float], ...].
     *
     * @param string|null $json
     * @return array<int, array{sku: string, qty: float}>
     */
    public function decodeReservedItemsJson($json): array
    {
        if (!$json || !is_string($json)) {
            return [];
        }
        $snapshot = json_decode($json, true);
        if (!is_array($snapshot)) {
            return [];
        }
        $out = [];
        foreach ($snapshot as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = (string) ($row['sku'] ?? '');
            $qty = (float) ($row['qty'] ?? 0);
            if ($sku !== '' && $qty > 0) {
                $out[] = ['sku' => $sku, 'qty' => $qty];
            }
        }
        return $out;
    }

    /**
     * Purane MSI append rows (metadata shopping_cart_approval::<requestId>) jab msi_reservation_placed DB mein sync na ho.
     */
    public function releaseOrphanApprovalMsi(string $requestId, array $releaseSnapshot, int $storeId): void
    {
        if ($requestId === '' || empty($releaseSnapshot)) {
            return;
        }
        if (!$this->hasNegativeApprovalReservationForRequest($requestId)) {
            return;
        }
        $this->releaseReservationsForApproval($releaseSnapshot, $requestId, $storeId);
        $this->logger->info('ShoppingCart: Cleared orphan MSI approval reservations for request ' . $requestId);
    }

    private function hasNegativeApprovalReservationForRequest(string $requestId): bool
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('inventory_reservation');
            $metadata = self::SALES_EVENT_TYPE_APPROVAL . '::' . $requestId;
            $select = $connection->select()
                ->from($table, 'reservation_id')
                ->where('metadata = ?', $metadata)
                ->where('quantity < 0')
                ->limit(1);

            return (bool) $connection->fetchOne($select);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Approve / reject / expire: inventory_reservation mein negative approval rows hatao (flag par depend nahi).
     */
    public function releaseApprovalReservationIfPlaced(ShoppingCartRequestInterface $request): void
    {
        $snapshot = $this->decodeReservedItemsJson($request->getReservedItems());
        if (empty($snapshot)) {
            return;
        }
        $storeId = 0;
        $quoteId = (int) $request->getQuoteId();
        if ($quoteId > 0) {
            try {
                $quoteRepo = $this->objectManager->get(\Magento\Quote\Api\CartRepositoryInterface::class);
                $storeId = (int) $quoteRepo->get($quoteId)->getStoreId();
            } catch (\Throwable $e) {
                // quote removed — fall through
            }
        }
        if ($storeId <= 0) {
            try {
                $storeId = (int) $this->storeManager->getStore()->getId();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ShoppingCart: Cannot resolve store for MSI release, request ' . $request->getEntityId()
                );
                return;
            }
        }
        $this->releaseOrphanApprovalMsi((string) $request->getEntityId(), $snapshot, $storeId);
    }

    /**
     * Place MSI reservations to block salable quantity for this approval request (negative qty).
     * Tries PlaceReservationsForSalesEvent first; on failure tries AppendReservationsInterface fallback.
     *
     * @param CartInterface $quote
     * @param string $salesEventObjectId e.g. request entity_id
     * @return bool
     */
    public function placeReservationsForApproval(CartInterface $quote, string $salesEventObjectId): bool
    {
        $snapshot = $this->buildSnapshotFromQuote($quote);
        if (empty($snapshot)) {
            $this->logger->warning('ShoppingCart: Cannot place reservations - no items in quote snapshot.');
            return false;
        }
        $storeId = (int) $quote->getStoreId();
        $store = $this->storeManager->getStore($storeId);
        $websiteCode = $store->getWebsite()->getCode();

        // Sirf AppendReservations: custom sales_event type (shopping_cart_approval) pe
        // PlaceReservationsForSalesEvent aksar fail ho jata hai — isliye seedha append.
        return $this->placeReservationsViaAppend($snapshot, $storeId, $websiteCode, $salesEventObjectId);
    }

    /**
     * Release MSI reservations by placing compensatory (positive) quantities.
     * Tries PlaceReservationsForSalesEvent first; on failure tries AppendReservationsInterface fallback.
     *
     * @param array $snapshot [['sku' => 'X', 'qty' => 2], ...] from reserved_items JSON
     * @param string $salesEventObjectId same as used in placeReservationsForApproval
     * @param int $storeId
     * @return bool
     */
    public function releaseReservationsForApproval(array $snapshot, string $salesEventObjectId, int $storeId = 0): bool
    {
        if (empty($snapshot)) {
            return false;
        }
        $storeId = $storeId ?: (int) $this->storeManager->getStore()->getId();
        $store = $this->storeManager->getStore($storeId);
        $websiteCode = $store->getWebsite()->getCode();

        return $this->releaseReservationsViaAppend($snapshot, $storeId, $websiteCode, $salesEventObjectId);
    }

    /**
     * Fallback: place reservations via AppendReservationsInterface (reduces salable qty).
     * Used when PlaceReservationsForSalesEvent is unavailable or fails (e.g. custom event type).
     */
    private function placeReservationsViaAppend(array $snapshot, int $storeId, string $websiteCode, string $salesEventObjectId): bool
    {
        try {
            $getStock = $this->objectManager->get(\Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface::class);
            $appendReservations = $this->objectManager->get(\Magento\InventoryReservationsApi\Api\AppendReservationsInterface::class);
            $salesChannelFactory = $this->resolveSalesChannelFactory();
            $salesChannel = $salesChannelFactory->create();
            $salesChannel->setType('website');
            $salesChannel->setCode($websiteCode);
            $stockId = $getStock->execute($salesChannel);
            $reservationFactory = $this->objectManager->get(\Magento\InventoryReservationsApi\Api\Data\ReservationInterfaceFactory::class);
            $reservations = [];
            $metadata = self::SALES_EVENT_TYPE_APPROVAL . '::' . $salesEventObjectId;
            foreach ($snapshot as $row) {
                $sku = (string) ($row['sku'] ?? '');
                $qty = -(float) ($row['qty'] ?? 0);
                if ($sku === '' || $qty >= 0) continue;
                $reservation = $reservationFactory->create([
                    'reservationId' => null,
                    'stockId' => $stockId,
                    'sku' => $sku,
                    'quantity' => $qty,
                    'metadata' => $metadata,
                ]);
                $reservations[] = $reservation;
            }
            if (empty($reservations)) {
                return false;
            }
            $appendReservations->execute($reservations);
            $this->logger->info('ShoppingCart: MSI reservations placed via AppendReservations for request ' . $salesEventObjectId . ', website ' . $websiteCode);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('ShoppingCart: AppendReservations fallback failed: ' . $e->getMessage(), [
                'request_id' => $salesEventObjectId,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Fallback: release reservations via AppendReservationsInterface (positive qty to restore salable).
     */
    private function releaseReservationsViaAppend(array $snapshot, int $storeId, string $websiteCode, string $salesEventObjectId): bool
    {
        try {
            $getStock = $this->objectManager->get(\Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface::class);
            $appendReservations = $this->objectManager->get(\Magento\InventoryReservationsApi\Api\AppendReservationsInterface::class);
            $salesChannelFactory = $this->resolveSalesChannelFactory();
            $salesChannel = $salesChannelFactory->create();
            $salesChannel->setType('website');
            $salesChannel->setCode($websiteCode);
            $stockId = $getStock->execute($salesChannel);
            $reservationFactory = $this->objectManager->get(\Magento\InventoryReservationsApi\Api\Data\ReservationInterfaceFactory::class);
            $reservations = [];
            $metadata = self::SALES_EVENT_TYPE_APPROVAL . '::' . $salesEventObjectId;
            foreach ($snapshot as $row) {
                $sku = (string) ($row['sku'] ?? '');
                $qty = (float) ($row['qty'] ?? 0);
                if ($sku === '' || $qty <= 0) continue;
                $reservation = $reservationFactory->create([
                    'reservationId' => null,
                    'stockId' => $stockId,
                    'sku' => $sku,
                    'quantity' => $qty,
                    'metadata' => $metadata,
                ]);
                $reservations[] = $reservation;
            }
            if (empty($reservations)) {
                return false;
            }
            $appendReservations->execute($reservations);
            $this->logger->info('ShoppingCart: MSI reservations released via AppendReservations for request ' . $salesEventObjectId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('ShoppingCart: AppendReservations release fallback failed: ' . $e->getMessage());
            return false;
        }
    }

    private function getStockSkuFromItem(Item $item): string
    {
        try {
            $simpleSku = $item->getProductOptionByCode('simple_sku');
            if ($simpleSku) return (string) $simpleSku;
            $simple = $item->getOptionByCode('simple_product');
            if ($simple && $simple->getProduct() && $simple->getProduct()->getSku()) {
                return (string) $simple->getProduct()->getSku();
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return (string) $item->getSku();
    }

    private function getStockSkuFromOrderItem(OrderItem $item): string
    {
        try {
            $simpleSku = $item->getProductOptionByCode('simple_sku');
            if ($simpleSku) {
                return (string) $simpleSku;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return (string) $item->getSku();
    }

    /**
     * Resolve ItemToSellInterfaceFactory; DI sometimes injects array due to config merge.
     */
    private function resolveItemToSellFactory()
    {
        if (is_object($this->itemToSellFactory) && method_exists($this->itemToSellFactory, 'create')) {
            return $this->itemToSellFactory;
        }
        return $this->objectManager->get(\Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory::class);
    }

    /**
     * Resolve SalesChannelInterfaceFactory; DI sometimes injects array due to config merge.
     */
    private function resolveSalesChannelFactory()
    {
        if (is_object($this->salesChannelFactory) && method_exists($this->salesChannelFactory, 'create')) {
            return $this->salesChannelFactory;
        }
        return $this->objectManager->get(\Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory::class);
    }

    /**
     * Resolve SalesEventInterfaceFactory; DI sometimes injects array due to config merge.
     */
    private function resolveSalesEventFactory()
    {
        if (is_object($this->salesEventFactory) && method_exists($this->salesEventFactory, 'create')) {
            return $this->salesEventFactory;
        }
        return $this->objectManager->get(\Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory::class);
    }
}
