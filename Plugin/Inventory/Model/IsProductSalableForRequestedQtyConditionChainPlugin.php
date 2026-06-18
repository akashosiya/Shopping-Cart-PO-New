<?php
namespace Osiyatech\ShoppingCart\Plugin\Inventory\Model;

use Magento\InventorySales\Model\IsProductSalableForRequestedQtyCondition\IsProductSalableForRequestedQtyConditionChain;
use Magento\InventorySalesApi\Api\Data\ProductSalableResultInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\ProductSalabilityErrorInterfaceFactory;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;

/**
 * Interface-level plugin mat lagao — woh IsCorrectQtyCondition par bhi chalta hai jo ProductSalableResult deta hai.
 * Sirf ConditionChain ke output par cap: requested <= GetProductSalableQty (pending PO pehle se ghata hua).
 */
class IsProductSalableForRequestedQtyConditionChainPlugin
{
    /** @var GetProductSalableQtyInterface */
    private $getProductSalableQty;

    /** @var ProductSalabilityErrorInterfaceFactory */
    private $errorFactory;

    /** @var ProductSalableResultInterfaceFactory */
    private $productSalableResultFactory;

    public function __construct(
        GetProductSalableQtyInterface $getProductSalableQty,
        ProductSalabilityErrorInterfaceFactory $errorFactory,
        ProductSalableResultInterfaceFactory $productSalableResultFactory
    ) {
        $this->getProductSalableQty = $getProductSalableQty;
        $this->errorFactory = $errorFactory;
        $this->productSalableResultFactory = $productSalableResultFactory;
    }

    /**
     * @param IsProductSalableForRequestedQtyConditionChain $subject
     * @param mixed $result ProductSalableResult (chain output)
     * @param string $sku
     * @param int $stockId
     * @param float|int|string $requestedQty
     * @return mixed
     */
    public function afterExecute(
        IsProductSalableForRequestedQtyConditionChain $subject,
        $result,
        string $sku,
        int $stockId,
        $requestedQty
    ) {
        if (!is_object($result) || !method_exists($result, 'isSalable')) {
            return $result;
        }
        if (!$result->isSalable()) {
            return $result;
        }

        $need = (float) $requestedQty;
        if ($need <= 0) {
            return $result;
        }

        $available = (float) $this->getProductSalableQty->execute($sku, $stockId);
        if ($need <= $available + 0.0001) {
            return $result;
        }

        $error = $this->errorFactory->create([
            'code' => 'osiyatech_requested_qty_exceeds_salable',
            'message' => (string) __('The requested qty is not available'),
        ]);

        return $this->productSalableResultFactory->create([
            'errors' => [$error],
        ]);
    }
}
