<?php

namespace Osiyatech\ShoppingCart\Plugin\Checkout\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\Quote;
use Osiyatech\ShoppingCart\Helper\Config as ShoppingCartConfig;
use Osiyatech\ShoppingCart\Helper\QuoteItemSku;
use Psr\Log\LoggerInterface;

/**
 * Cart page qty updates: validate against salable qty (MSI when enabled, else catalog stock).
 * MSI services are resolved at runtime so di:compile works without Inventory modules.
 */
class CartUpdateItemsQtyGuardPlugin
{
    private const MSI_MODULE = 'Magento_InventorySalesApi';

    /** @var QuoteItemSku */
    private $quoteItemSku;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var StockRegistryInterface */
    private $stockRegistry;

    /** @var ShoppingCartConfig */
    private $shoppingCartConfig;

    /** @var LoggerInterface */
    private $logger;

    /** @var ObjectManagerInterface */
    private $objectManager;

    /** @var ModuleManager */
    private $moduleManager;

    public function __construct(
        QuoteItemSku $quoteItemSku,
        ProductRepositoryInterface $productRepository,
        StockRegistryInterface $stockRegistry,
        ShoppingCartConfig $shoppingCartConfig,
        LoggerInterface $logger,
        ObjectManagerInterface $objectManager,
        ModuleManager $moduleManager
    ) {
        $this->quoteItemSku = $quoteItemSku;
        $this->productRepository = $productRepository;
        $this->stockRegistry = $stockRegistry;
        $this->shoppingCartConfig = $shoppingCartConfig;
        $this->logger = $logger;
        $this->objectManager = $objectManager;
        $this->moduleManager = $moduleManager;
    }

    /**
     * @param Cart $subject
     * @param array $data
     * @return array
     * @throws LocalizedException
     */
    public function beforeUpdateItems(Cart $subject, array $data)
    {
        $quote = $subject->getQuote();
        if (!$this->shoppingCartConfig->isSalableQtyGuardEnabled((int) $quote->getStore()->getId())) {
            return [$data];
        }

        try {
            foreach ($data as $itemId => $itemInfo) {
                if (!isset($itemInfo['qty'])) {
                    continue;
                }

                $quoteItem = $quote->getItemById($itemId);
                if (!$quoteItem || !$quoteItem->getProduct()) {
                    continue;
                }

                $requestedQty = (float) $itemInfo['qty'];
                if ($requestedQty <= 0) {
                    continue;
                }

                $product = $quoteItem->getProduct();
                $productName = (string) $product->getName();
                $sku = $this->quoteItemSku->getStockSku($quoteItem);

                $availableQty = $this->getSalableQtyForSku($sku, $quote);

                $otherItemsQty = 0.0;
                foreach ($quote->getAllVisibleItems() as $item) {
                    if ((int) $item->getId() === (int) $itemId) {
                        continue;
                    }

                    if ($this->quoteItemSku->getStockSku($item) === $sku) {
                        $otherItemsQty += (float) $item->getQty();
                    }
                }

                if (($otherItemsQty + $requestedQty) > $availableQty) {
                    throw new LocalizedException(
                        __('The requested qty for "%1" is not available. Available qty is %2.', $productName, $availableQty)
                    );
                }
            }
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ShoppingCart: salable qty guard skipped on cart updateItems (allowing core to validate).',
                ['exception' => $e]
            );
        }

        return [$data];
    }

    private function getSalableQtyForSku(string $sku, Quote $quote): float
    {
        if ($this->moduleManager->isEnabled(self::MSI_MODULE)) {
            try {
                $getAssignedStockIdForWebsite = $this->objectManager->get(
                    'Magento\InventorySalesApi\Api\GetAssignedStockIdForWebsiteInterface'
                );
                $getProductSalableQty = $this->objectManager->get(
                    'Magento\InventorySalesApi\Api\GetProductSalableQtyInterface'
                );
                $websiteId = (int) $quote->getStore()->getWebsiteId();
                $stockId = (int) $getAssignedStockIdForWebsite->execute($websiteId);

                return max(0.0, (float) $getProductSalableQty->execute($sku, $stockId));
            } catch (\Throwable $e) {
                $this->logger->debug(
                    'ShoppingCart: MSI salable qty unavailable, using catalog stock.',
                    ['sku' => $sku, 'exception' => $e]
                );
            }
        }

        try {
            $product = $this->productRepository->get($sku);
            $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());

            return max(0.0, (float) $stockItem->getQty());
        } catch (\Throwable $e2) {
            return \PHP_FLOAT_MAX;
        }
    }
}
