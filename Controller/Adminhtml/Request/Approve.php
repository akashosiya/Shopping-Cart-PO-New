<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Helper\Email;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\Model\Auth\Session as AuthSession;

class Approve extends Action
{
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $repository;
    /** @var Email */
    protected $emailHelper;
    /** @var AuthSession */
    protected $authSession;
    /** @var CartReservationManager */
    protected $reservationManager;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $repository,
        Email $emailHelper,
        AuthSession $authSession,
        CartReservationManager $reservationManager
    ) {
        parent::__construct($context);
        $this->repository = $repository;
        $this->emailHelper = $emailHelper;
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
            if ($request->getStatus() !== ShoppingCartRequestInterface::STATUS_PENDING) {
                $this->messageManager->addErrorMessage(__('Only pending requests can be approved.'));
                return $resultRedirect->setPath('shoppingcart/request/index');
            }
            $request->setStatus(ShoppingCartRequestInterface::STATUS_APPROVED);
            $request->setApprovedBy($this->authSession->getUser()->getId());
            $request->setApprovedAt(date('Y-m-d H:i:s'));
            $request->setRejectionReason(null);
            $request->setRejectedBy(null);
            $request->setRejectedAt(null);
            if (!$request->getResumeToken()) {
                $request->setResumeToken(bin2hex(random_bytes(32)));
                $request->setResumeTokenCreatedAt(date('Y-m-d H:i:s'));
            }
            // Release MSI approval rows so checkout can see salable qty; other shoppers stay capped via Inventory helper.
            $this->reservationManager->releaseApprovalReservationIfPlaced($request);
            $request->setMsiReservationPlaced(0);
            $request->setReservationReleasedAt(date('Y-m-d H:i:s'));
            $this->repository->save($request);
            $this->emailHelper->sendApprovedEmail($request);
            $this->messageManager->addSuccessMessage(__('Cart PO Request #%1 has been approved.', $request->getEntityId()));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Request not found.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error approving request: %1', $e->getMessage()));
        }
        return $resultRedirect->setPath('shoppingcart/request/index');
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Osiyatech_ShoppingCart::cart_po_approve');
    }
}
