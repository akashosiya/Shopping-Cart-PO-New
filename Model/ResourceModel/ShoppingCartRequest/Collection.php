<?php
namespace Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Osiyatech\ShoppingCart\Model\ShoppingCartRequest;
use Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest as ShoppingCartRequestResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(ShoppingCartRequest::class, ShoppingCartRequestResource::class);
    }
}
