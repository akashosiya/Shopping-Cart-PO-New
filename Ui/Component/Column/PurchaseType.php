<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Ui\Component\Column;

use Magento\Framework\Data\OptionSourceInterface;

class PurchaseType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'Official', 'label' => __('Official')],
            ['value' => 'Personal', 'label' => __('Personal')],
        ];
    }
}
