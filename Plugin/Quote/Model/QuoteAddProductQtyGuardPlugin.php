<?php
namespace Osiyatech\ShoppingCart\Plugin\Quote\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\Quote;
use Osiyatech\ShoppingCart\Helper\Config as ShoppingCartConfig;
use Osiyatech\ShoppingCart\Helper\QuoteItemSku;
use Psr\Log\LoggerInterface;

/**
 * Prevent quote qty from exceeding salable qty (MSI when enabled, else catalog stock).
 * MSI services are not constructor-injected so di:compile works if Inventory modules are absent/disabled.
 */
class QuoteAddProductQtyGuardPlugin
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

    /** @var RequestInterface */
    private $httpRequest;

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
        RequestInterface $httpRequest,
        LoggerInterface $logger,
        ObjectManagerInterface $objectManager,
        ModuleManager $moduleManager
    ) {
        $this->quoteItemSku = $quoteItemSku;
        $this->productRepository = $productRepository;
        $this->stockRegistry = $stockRegistry;
        $this->shoppingCartConfig = $shoppingCartConfig;
        $this->httpRequest = $httpRequest;
        $this->logger = $logger;
        $this->objectManager = $objectManager;
        $this->moduleManager = $moduleManager;
    }

    public function beforeAddProduct(Quote $subject, Product $product, $request = null, $processMode = null): array
    {
        if (!$this->shoppingCartConfig->isSalableQtyGuardEnabled((int) $subject->getStore()->getId())) {
            return [$product, $request, $processMode];
        }

        try {
            $requestedQty = $this->extractRequestedQty($request);
            if ($requestedQty <= 0) {
                return [$product, $request, $processMode];
            }

            $sku = $this->resolveSkuForAddProduct($product, $request);
            $currentQty = 0.0;
            foreach ($subject->getAllVisibleItems() as $item) {
                if ($this->quoteItemSku->getStockSku($item) === $sku) {
                    $currentQty += (float) $item->getQty();
                }
            }

            $availableQty = $this->getSalableQtyForSku($sku, $subject);
            if (($currentQty + $requestedQty) > $availableQty) {
                throw new LocalizedException(__('The requested qty is not available'));
            }
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ShoppingCart: salable qty guard skipped on addProduct (allowing core to validate).',
                ['exception' => $e]
            );
        }

        return [$product, $request, $processMode];
    }

    public function beforeUpdateItem(Quote $subject, $itemId, $buyRequest, $params = null): array
    {
        if (!$this->shoppingCartConfig->isSalableQtyGuardEnabled((int) $subject->getStore()->getId())) {
            return [$itemId, $buyRequest, $params];
        }

        try {
            $item = $subject->getItemById((int) $itemId);
            if (!$item || !$item->getProduct()) {
                return [$itemId, $buyRequest, $params];
            }

            $requestedQty = $this->extractRequestedQty($buyRequest);
            if ($requestedQty <= 0) {
                return [$itemId, $buyRequest, $params];
            }

            $sku = $this->quoteItemSku->getStockSku($item);
            $otherItemsQty = 0.0;
            foreach ($subject->getAllVisibleItems() as $quoteItem) {
                if ((int) $quoteItem->getId() === (int) $itemId) {
                    continue;
                }
                if ($this->quoteItemSku->getStockSku($quoteItem) === $sku) {
                    $otherItemsQty += (float) $quoteItem->getQty();
                }
            }

            $availableQty = $this->getSalableQtyForSku($sku, $subject);
            if (($otherItemsQty + $requestedQty) > $availableQty) {
                throw new LocalizedException(__('The requested qty is not available'));
            }
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ShoppingCart: salable qty guard skipped on updateItem (allowing core to validate).',
                ['exception' => $e]
            );
        }

        return [$itemId, $buyRequest, $params];
    }

    /**
     * Configurable parent SKU is usually not MSI-salable; use selected simple SKU.
     *
     * @param null|float|int|array|DataObject $request
     */
    private function resolveSkuForAddProduct(Product $product, $request): string
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return (string) $product->getSku();
        }

        $superAttribute = null;
        if ($request instanceof DataObject) {
            $superAttribute = $request->getData('super_attribute');
        } elseif (is_array($request)) {
            $superAttribute = $request['super_attribute'] ?? null;
        }

        if ((empty($superAttribute) || !is_array($superAttribute)) && method_exists($this->httpRequest, 'getParam')) {
            $fromHttp = $this->httpRequest->getParam('super_attribute');
            if (is_array($fromHttp) && !empty($fromHttp)) {
                $superAttribute = $fromHttp;
            }
        }

        if (empty($superAttribute) || !is_array($superAttribute)) {
            return (string) $product->getSku();
        }

        $child = $product->getTypeInstance()->getProductByAttributes($superAttribute, $product);
        if ($child && $child->getId() && $child->getSku()) {
            return (string) $child->getSku();
        }

        return (string) $product->getSku();
    }

    /**
     * @param null|float|int|array|DataObject $request
     */
    private function extractRequestedQty($request): float
    {
        if ($request === null) {
            return 1.0;
        }
        if (is_numeric($request)) {
            return (float) $request;
        }
        if (is_array($request)) {
            return isset($request['qty']) ? (float) $request['qty'] : 1.0;
        }
        if ($request instanceof DataObject) {
            $qty = $request->getData('qty');
            return $qty !== null ? (float) $qty : 1.0;
        }
        return 1.0;
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
