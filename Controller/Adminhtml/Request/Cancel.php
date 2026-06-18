<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\Model\Auth\Session as AuthSession;

class Cancel extends Action
{
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $repository;
    /** @var AuthSession */
    protected $authSession;
    /** @var CartReservationManager */
    protected $reservationManager;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $repository,
        AuthSession $authSession,
        CartReservationManager $reservationManager
    ) {
        parent::__construct($context);
        $this->repository = $repository;
        $this->authSession = $authSession;
        $this->reservationManager = $reservationManager;
    }

    public function execute()
    {
        $id = $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$id) {
            $this->messageManager->addErrorMessage(__('Request ID is required.'));
            return $resultRedirect->setPath('shoppingcart/request/index');
        }
        try {
            $request = $this->repository->getById($id);
            $status = $request->getStatus();
            $canCancel = $status === ShoppingCartRequestInterface::STATUS_PENDING
                || $status === ShoppingCartRequestInterface::STATUS_APPROVED;
            if (!$canCancel) {
                $this->messageManager->addNoticeMessage(
                    __('This request was not cancelled because it is not pending or approved (e.g. already completed). No changes were made.')
                );
                return $resultRedirect->setPath('shoppingcart/request/index');
            }
            $request->setStatus(ShoppingCartRequestInterface::STATUS_CANCELLED);
            $request->setRejectedBy($this->authSession->getUser()->getId());
            $request->setRejectedAt(date('Y-m-d H:i:s'));
            $request->setRejectionReason(
                $status === ShoppingCartRequestInterface::STATUS_APPROVED
                    ? __('Cancelled by admin (approval withdrawn)')
                    : __('Cancelled by admin')
            );
            $request->setApprovedBy(null);
            $request->setApprovedAt(null);
            $this->reservationManager->releaseApprovalReservationIfPlaced($request);
            $request->setMsiReservationPlaced(0);
            $request->setReservationReleasedAt(date('Y-m-d H:i:s'));
            $this->repository->save($request);
            $this->messageManager->addSuccessMessage(__('Cart PO Request #%1 has been cancelled.', $request->getEntityId()));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Request not found.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error cancelling request: %1', $e->getMessage()));
        }
        return $resultRedirect->setPath('shoppingcart/request/index');
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Osiyatech_ShoppingCart::cart_po_reject');
    }
}
