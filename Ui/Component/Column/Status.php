<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Ui\Component\Column;

use Magento\Framework\Data\OptionSourceInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;

class Status implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ShoppingCartRequestInterface::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => ShoppingCartRequestInterface::STATUS_APPROVED, 'label' => __('Approved')],
            ['value' => ShoppingCartRequestInterface::STATUS_REJECTED, 'label' => __('Rejected')],
            ['value' => ShoppingCartRequestInterface::STATUS_CANCELLED, 'label' => __('Cancelled')],
            ['value' => ShoppingCartRequestInterface::STATUS_EXPIRED, 'label' => __('Expired (reservation released)')],
            ['value' => ShoppingCartRequestInterface::STATUS_COMPLETED, 'label' => __('Completed')],
        ];
    }
}
