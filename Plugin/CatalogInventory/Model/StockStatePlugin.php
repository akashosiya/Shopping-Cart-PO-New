<?php
namespace Osiyatech\ShoppingCart\Plugin\CatalogInventory\Model;

use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Osiyatech\ShoppingCart\Helper\Inventory;

/**
 * Legacy stock (getStockQty, checkQty) se PO approval reserved qty subtract (MSI off wali installs).
 */
class StockStatePlugin
{
    /** @var Inventory */
    private $inventoryHelper;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var CheckoutSession */
    private $checkoutSession;

    public function __construct(
        Inventory $inventoryHelper,
        ProductRepositoryInterface $productRepository,
        CheckoutSession $checkoutSession
    ) {
        $this->inventoryHelper = $inventoryHelper;
        $this->productRepository = $productRepository;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * getStockQty result se PENDING reserved subtract.
     *
     * @param StockStateInterface $subject
     * @param float $result
     * @param int $productId
     * @param int|null $websiteId
     * @return float
     */
    public function afterGetStockQty(StockStateInterface $subject, $result, $productId, $websiteId = null)
    {
        $sku = $this->getSkuByProductId((int) $productId);
        if ($sku === null) {
            return $result;
        }
        $reserved = $this->inventoryHelper->getApprovalReservedQtyBySkuForSalable($sku, $this->getActiveQuoteId());
        if ($reserved <= 0) {
            return $result;
        }
        return max(0.0, (float) $result - $reserved);
    }

    /**
     * checkQty ke liye required qty me reserved add - taaki original check (stock >= qty + reserved) kare.
     *
     * @param StockStateInterface $subject
     * @param int $productId
     * @param float $qty
     * @param int|null $websiteId
     * @return array
     */
    public function beforeCheckQty(StockStateInterface $subject, $productId, $qty, $websiteId = null)
    {
        $sku = $this->getSkuByProductId((int) $productId);
        if ($sku === null) {
            return [$productId, $qty, $websiteId];
        }
        $reserved = $this->inventoryHelper->getApprovalReservedQtyBySkuForSalable($sku, $this->getActiveQuoteId());
        if ($reserved <= 0) {
            return [$productId, $qty, $websiteId];
        }
        return [$productId, (float) $qty + $reserved, $websiteId];
    }

    private function getActiveQuoteId(): ?int
    {
        try {
            $id = (int) $this->checkoutSession->getQuoteId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getSkuByProductId(int $productId): ?string
    {
        try {
            $product = $this->productRepository->getById($productId);
            return $product ? (string) $product->getSku() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
