<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;

class View extends Action
{
    const ADMIN_RESOURCE = 'Osiyatech_ShoppingCart::cart_po';

    /** @var PageFactory */
    private $resultPageFactory;
    /** @var ShoppingCartRequestRepositoryInterface */
    private $repository;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        ShoppingCartRequestRepositoryInterface $repository
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->repository = $repository;
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        if (!$id) {
            $this->messageManager->addErrorMessage(__('Request ID is required.'));
            return $this->resultRedirectFactory->create()->setPath('shoppingcart/request/index');
        }
        try {
            $this->repository->getById($id);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Request not found.'));
            return $this->resultRedirectFactory->create()->setPath('shoppingcart/request/index');
        }
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Osiyatech_ShoppingCart::cart_po');
        $resultPage->getConfig()->getTitle()->prepend(__('Shopping Cart PO Request'));
        $resultPage->getConfig()->getTitle()->prepend(__('View'));
        return $resultPage;
    }
}
