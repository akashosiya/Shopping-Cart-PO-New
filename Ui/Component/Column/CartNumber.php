<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Ui\Component\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class CartNumber extends Column
{
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $cartNumber = isset($item[$fieldName]) ? trim((string) $item[$fieldName]) : '';
            $orderId = isset($item['order_id']) ? trim((string) $item['order_id']) : '';

            if ($cartNumber !== '' && $orderId !== '') {
                $item[$fieldName] = sprintf('%s (Order: %s)', $cartNumber, $orderId);
            } elseif ($cartNumber === '' && $orderId !== '') {
                $item[$fieldName] = sprintf('Order: %s', $orderId);
            }
        }

        return $dataSource;
    }
}
