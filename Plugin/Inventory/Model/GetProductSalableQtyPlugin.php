<?php
namespace Osiyatech\ShoppingCart\Plugin\Inventory\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Osiyatech\ShoppingCart\Helper\Inventory;

/**
 * MSI salable qty se PO approval reserved qty subtract (pending sab; approved sirf doosre quotes par).
 */
class GetProductSalableQtyPlugin
{
    /** @var Inventory */
    private $inventoryHelper;

    /** @var CheckoutSession */
    private $checkoutSession;

    public function __construct(Inventory $inventoryHelper, CheckoutSession $checkoutSession)
    {
        $this->inventoryHelper = $inventoryHelper;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param GetProductSalableQtyInterface $subject
     * @param float $result
     * @param string $sku
     * @param int $stockId
     * @return float
     */
    public function afterExecute(GetProductSalableQtyInterface $subject, $result, $sku, $stockId)
    {
        $excludeQuoteId = $this->getActiveQuoteIdForSalableAdjust();
        $reserved = $this->inventoryHelper->getApprovalReservedQtyBySkuForSalable((string) $sku, $excludeQuoteId);
        if ($reserved <= 0) {
            return $result;
        }
        return max(0.0, (float) $result - $reserved);
    }

    private function getActiveQuoteIdForSalableAdjust(): ?int
    {
        try {
            $id = (int) $this->checkoutSession->getQuoteId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
