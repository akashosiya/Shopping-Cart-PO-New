<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Ui\Component\Column;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\ResourceModel\Website\CollectionFactory;

class Website implements OptionSourceInterface
{
    /**
     * @var CollectionFactory
     */
    private $websiteCollectionFactory;

    public function __construct(CollectionFactory $websiteCollectionFactory)
    {
        $this->websiteCollectionFactory = $websiteCollectionFactory;
    }

    public function toOptionArray(): array
    {
        $options = [];
        $collection = $this->websiteCollectionFactory->create();
        $collection->setOrder('name', 'ASC');

        foreach ($collection as $website) {
            $options[] = [
                'value' => (string) $website->getId(),
                'label' => $website->getName(),
            ];
        }

        return $options;
    }
}
