<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Block\Adminhtml\Request;

class RejectForm extends View
{
    protected $_template = 'Osiyatech_ShoppingCart::request/reject_form.phtml';

    public function getBackUrl(): string
    {
        return $this->getUrl('shoppingcart/request/index');
    }
}
