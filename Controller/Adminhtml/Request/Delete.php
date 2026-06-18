<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Delete extends Action
{
    const ADMIN_RESOURCE = 'Osiyatech_ShoppingCart::cart_po';

    /** @var ShoppingCartRequestRepositoryInterface */
    private $repository;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $repository
    ) {
        parent::__construct($context);
        $this->repository = $repository;
    }

    /**
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('shoppingcart/request/index');

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Request ID is required.'));
            return $resultRedirect;
        }

        try {
            $request = $this->repository->getById($id);
            $request->delete();
            $this->messageManager->addSuccessMessage(__('Cart PO Request #%1 has been deleted.', $id));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Request not found.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not delete request: %1', $e->getMessage()));
        }

        return $resultRedirect;
    }
}
