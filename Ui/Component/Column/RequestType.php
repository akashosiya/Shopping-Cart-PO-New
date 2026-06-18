<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Ui\Component\Column;

use Magento\Framework\Data\OptionSourceInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;

class RequestType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ShoppingCartRequestInterface::REQUEST_TYPE_APPROVAL, 'label' => __('Approval')],
            ['value' => ShoppingCartRequestInterface::REQUEST_TYPE_GENERATE_CART, 'label' => __('Generate Cart')],
        ];
    }
}
