<?php
namespace Osiyatech\ShoppingCart\Plugin\Inventory\Model;

use Magento\InventorySalesApi\Api\Data\IsProductSalableResultInterface;
use Magento\InventorySalesApi\Api\Data\IsProductSalableResultInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\ProductSalabilityErrorInterfaceFactory;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableForRequestedQtyInterface;

/**
 * Core kabhi-kabhi MSI salable check pending-PO-ghati-hui qty ke bina pass kar deta hai.
 * GetProductSalableQty (hamara plugin) pehle hi reserved ghata chuka hota hai — yahan sirf
 * requested <= wahi salable verify karte hain (beforeExecute wala +reserved = double count, hata diya).
 */
class IsProductSalableForRequestedQtyPlugin
{
    /** @var GetProductSalableQtyInterface */
    private $getProductSalableQty;

    /** @var IsProductSalableResultInterfaceFactory */
    private $resultFactory;

    /** @var ProductSalabilityErrorInterfaceFactory */
    private $errorFactory;

    public function __construct(
        GetProductSalableQtyInterface $getProductSalableQty,
        IsProductSalableResultInterfaceFactory $resultFactory,
        ProductSalabilityErrorInterfaceFactory $errorFactory
    ) {
        $this->getProductSalableQty = $getProductSalableQty;
        $this->resultFactory = $resultFactory;
        $this->errorFactory = $errorFactory;
    }

    /**
     * @param IsProductSalableForRequestedQtyInterface $subject
     * @param IsProductSalableResultInterface $result
     * @param string $sku
     * @param int $stockId
     * @param float|int|string $requestedQty
     * @return IsProductSalableResultInterface
     */
    public function afterExecute(
        IsProductSalableForRequestedQtyInterface $subject,
        IsProductSalableResultInterface $result,
        string $sku,
        int $stockId,
        $requestedQty
    ): IsProductSalableResultInterface {
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

        return $this->resultFactory->create([
            'isSalable' => false,
            'errors' => [$error],
        ]);
    }
}
