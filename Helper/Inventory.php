<?php
namespace Osiyatech\ShoppingCart\Helper;

use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest\CollectionFactory;

/**
 * Reserved qty helper for ShoppingCart module.
 */
class Inventory
{
    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var Config */
    private $config;

    /** @var array [cacheKey => [sku => qty]] cache for current request */
    private $cache = [];

    public function __construct(CollectionFactory $collectionFactory, Config $config)
    {
        $this->collectionFactory = $collectionFactory;
        $this->config = $config;
    }

    /**
     * Purchase-approval requests (pending + approved) se SKU => total reserved qty map.
     * Approved par bhi qty block rehti hai jab tak order place / completed na ho (release observer).
     *
     * @param int|null $quoteId Optional - filter sirf is quote ke requests
     * @return array [sku => float]
     */
    public function getPendingApprovalReservedQtyBySkuForQuote(?int $quoteId = null): array
    {
        $cacheKey = $quoteId !== null ? 'approval_quote_' . $quoteId : 'approval_all';
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $map = [];

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', [
            'in' => [
                ShoppingCartRequestInterface::STATUS_PENDING,
                ShoppingCartRequestInterface::STATUS_APPROVED,
            ],
        ]);
        $collection->addFieldToFilter('request_type', ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL);
        if ($quoteId !== null) {
            $collection->addFieldToFilter('quote_id', (int) $quoteId);
        }
        foreach ($collection as $request) {
            $this->addSnapshotToMap($request->getReservedItems(), $map);
        }

        $this->cache[$cacheKey] = $map;
        return $map;
    }

    /**
     * Parse reserved_items JSON and add quantities into map.
     */
    private function addSnapshotToMap($json, array &$map): void
    {
        if (!$json || !is_string($json)) {
            return;
        }
        $snapshot = json_decode($json, true);
        if (!is_array($snapshot)) {
            return;
        }
        foreach ($snapshot as $row) {
            $sku = (string) ($row['sku'] ?? '');
            $qty = (float) ($row['qty'] ?? 0);
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $map[$sku] = ($map[$sku] ?? 0) + $qty;
        }
    }

    /**
     * Salable adjustment: pending (all quotes) + approved on *other* quotes only.
     * When the active checkout quote matches an approved PO request, that snapshot is skipped so the buyer can check out.
     * Other shoppers still see reduced salable for those approved lines until the order completes.
     *
     * @param string $sku
     * @param int|null $excludeQuoteIdForApproved current session quote id; approved rows on this quote are ignored
     * @return float
     */
    public function getApprovalReservedQtyBySkuForSalable(string $sku, ?int $excludeQuoteIdForApproved): float
    {
        $map = $this->getApprovalReservedQtyMapForSalable($excludeQuoteIdForApproved);
        return (float) ($map[$sku] ?? 0);
    }

    /**
     * @param int|null $excludeQuoteIdForApproved
     * @return array<string, float> sku => qty
     */
    private function getApprovalReservedQtyMapForSalable(?int $excludeQuoteIdForApproved): array
    {
        $cacheKey = 'salable_adj_' . ($excludeQuoteIdForApproved ?? 'none');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        $map = [];

        $pending = $this->collectionFactory->create();
        $pending->addFieldToFilter('status', ShoppingCartRequestInterface::STATUS_PENDING);
        $pending->addFieldToFilter('request_type', ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL);
        foreach ($pending as $request) {
            $this->addSnapshotToMap($request->getReservedItems(), $map);
        }

        $approved = $this->collectionFactory->create();
        $approved->addFieldToFilter('status', ShoppingCartRequestInterface::STATUS_APPROVED);
        $approved->addFieldToFilter('request_type', ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL);
        foreach ($approved as $request) {
            if ($excludeQuoteIdForApproved !== null
                && (int) $request->getQuoteId() === (int) $excludeQuoteIdForApproved
            ) {
                continue;
            }
            $this->addSnapshotToMap($request->getReservedItems(), $map);
        }

        $this->cache[$cacheKey] = $map;
        return $map;
    }

    /**
     * Ek SKU ke liye total pending+approved approval reserved qty (sab quotes, session ignore).
     * Prefer getApprovalReservedQtyBySkuForSalable() on storefront checkout/cart.
     *
     * @param string $sku
     * @return float
     */
    public function getPendingApprovalReservedQtyBySku(string $sku): float
    {
        return $this->getApprovalReservedQtyBySkuForSalable($sku, null);
    }

    /**
     * Backward compatible alias.
     *
     * @param string $sku
     * @return float
     */
    public function getPendingReservedQtyBySku(string $sku): float
    {
        return $this->getPendingApprovalReservedQtyBySku($sku);
    }
}
