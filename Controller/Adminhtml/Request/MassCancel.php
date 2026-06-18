<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Osiyatech\ShoppingCart\Model\ResourceModel\ShoppingCartRequest\CollectionFactory;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Magento\Backend\Model\Auth\Session as AuthSession;

class MassCancel extends Action
{
    /** @var Filter */
    protected $filter;
    /** @var CollectionFactory */
    protected $collectionFactory;
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $repository;
    /** @var AuthSession */
    protected $authSession;
    /** @var CartReservationManager */
    protected $reservationManager;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        ShoppingCartRequestRepositoryInterface $repository,
        AuthSession $authSession,
        CartReservationManager $reservationManager
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->repository = $repository;
        $this->authSession = $authSession;
        $this->reservationManager = $reservationManager;
    }

    public function execute()
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $cancelledCount = 0;
            foreach ($collection as $request) {
                $status = $request->getStatus();
                $canCancel = $status === ShoppingCartRequestInterface::STATUS_PENDING
                    || $status === ShoppingCartRequestInterface::STATUS_APPROVED;
                if ($canCancel) {
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
                    $cancelledCount++;
                }
            }
            if ($cancelledCount > 0) {
                $this->messageManager->addSuccessMessage(__('A total of %1 request(s) have been cancelled.', $cancelledCount));
            } else {
                $this->messageManager->addNoticeMessage(
                    __('No pending or approved requests were cancelled. Completed, rejected, or already cancelled rows are left unchanged.')
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error: %1', $e->getMessage()));
        }
        return $this->resultRedirectFactory->create()->setPath('shoppingcart/request/index');
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Osiyatech_ShoppingCart::cart_po_reject');
    }
}
