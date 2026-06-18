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
use Osiyatech\ShoppingCart\Helper\Email;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;
use Magento\Backend\Model\Auth\Session as AuthSession;

class MassReject extends Action
{
    /** @var Filter */
    protected $filter;
    /** @var CollectionFactory */
    protected $collectionFactory;
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
        Filter $filter,
        CollectionFactory $collectionFactory,
        ShoppingCartRequestRepositoryInterface $repository,
        Email $emailHelper,
        AuthSession $authSession,
        CartReservationManager $reservationManager
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->repository = $repository;
        $this->emailHelper = $emailHelper;
        $this->authSession = $authSession;
        $this->reservationManager = $reservationManager;
    }

    public function execute()
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $rejectionReason = $this->getRequest()->getParam('rejection_reason', '');
            $rejectedCount = 0;
            foreach ($collection as $request) {
                if ($request->getStatus() === ShoppingCartRequestInterface::STATUS_PENDING) {
                    $request->setStatus(ShoppingCartRequestInterface::STATUS_REJECTED);
                    $request->setRejectedBy($this->authSession->getUser()->getId());
                    $request->setRejectedAt(date('Y-m-d H:i:s'));
                    $request->setRejectionReason($rejectionReason ?: __('Rejected by admin'));
                    $request->setApprovedBy(null);
                    $request->setApprovedAt(null);
                    $this->reservationManager->releaseApprovalReservationIfPlaced($request);
                    $request->setMsiReservationPlaced(0);
                    $request->setReservationReleasedAt(date('Y-m-d H:i:s'));
                    $this->repository->save($request);
                    $this->emailHelper->sendRejectedEmail($request);
                    $rejectedCount++;
                }
            }
            $this->messageManager->addSuccessMessage(__('A total of %1 request(s) have been rejected.', $rejectedCount));
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
