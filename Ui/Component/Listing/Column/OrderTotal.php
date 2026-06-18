<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Ui\Component\Listing\Column;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class OrderTotal extends Column
{
    /** @var PriceCurrencyInterface */
    protected $priceCurrency;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        PriceCurrencyInterface $priceCurrency,
        array $components = [],
        array $data = []
    ) {
        $this->priceCurrency = $priceCurrency;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        $name = $this->getData('name');
        if (isset($dataSource['data']['items']) && $name) {
            foreach ($dataSource['data']['items'] as &$item) {
                $amount = $item['order_total'] ?? null;
                $currency = $item['order_total_currency'] ?? null;
                if ($amount === null || $amount === '') {
                    $item[$name] = '';
                    continue;
                }
                // Plain text: admin grids escape HTML; format(..., true) wraps in <span class="price">.
                $item[$name] = $currency
                    ? $this->priceCurrency->format(
                        (float) $amount,
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION,
                        null,
                        $currency
                    )
                    : $this->priceCurrency->format(
                        (float) $amount,
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION
                    );
            }
            unset($item);
        }

        return parent::prepareDataSource($dataSource);
    }
}
