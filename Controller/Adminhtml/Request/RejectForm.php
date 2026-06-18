<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;

class RejectForm extends Action
{
    const ADMIN_RESOURCE = 'Osiyatech_ShoppingCart::cart_po_reject';

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
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Request ID is required.'));
            return $resultRedirect->setPath('shoppingcart/request/index');
        }

        try {
            $request = $this->repository->getById($id);
            if ($request->getStatus() !== ShoppingCartRequestInterface::STATUS_PENDING) {
                $this->messageManager->addErrorMessage(__('Only pending requests can be rejected.'));
                return $resultRedirect->setPath('shoppingcart/request/index');
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Request not found.'));
            return $resultRedirect->setPath('shoppingcart/request/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Osiyatech_ShoppingCart::cart_po');
        $resultPage->getConfig()->getTitle()->prepend(__('Shopping Cart PO Request'));
        $resultPage->getConfig()->getTitle()->prepend(__('Reject'));
        return $resultPage;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
