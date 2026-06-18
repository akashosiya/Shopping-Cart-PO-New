<?php
namespace Osiyatech\ShoppingCart\Controller\Workplace;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Helper\Email;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;

class Reject extends Action
{
    /** @var ShoppingCartRequestRepositoryInterface */
    private $requestRepository;
    /** @var DateTime */
    private $dateTime;
    /** @var Email */
    private $emailHelper;
    /** @var CartReservationManager */
    private $reservationManager;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        DateTime $dateTime,
        Email $emailHelper,
        CartReservationManager $reservationManager
    ) {
        parent::__construct($context);
        $this->requestRepository = $requestRepository;
        $this->dateTime = $dateTime;
        $this->emailHelper = $emailHelper;
        $this->reservationManager = $reservationManager;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('id');
        $token = (string) $this->getRequest()->getParam('token');
        $reason = trim((string) $this->getRequest()->getPostValue('rejection_reason'));
        if (!$id || !$token) {
            $this->messageManager->addErrorMessage(__('Invalid link.'));
            return $resultRedirect->setPath('shoppingcart/approval/success');
        }
        try {
            $request = $this->requestRepository->getById($id);
            if ((string) $request->getApprovalToken() !== $token) {
                $this->messageManager->addErrorMessage(__('Invalid or expired token.'));
                return $resultRedirect->setPath('shoppingcart/approval/success');
            }
            if ($request->getStatus() !== ShoppingCartRequestInterface::STATUS_PENDING) {
                return $resultRedirect->setPath('shoppingcart/approval/success', ['_query' => ['already_rejected' => '1']]);
            }
            $request->setStatus(ShoppingCartRequestInterface::STATUS_REJECTED);
            $request->setRejectedAt($this->dateTime->gmtDate());
            $request->setRejectionReason($reason ?: (string) __('Rejected by approver.'));
            $this->reservationManager->releaseApprovalReservationIfPlaced($request);
            $request->setMsiReservationPlaced(0);
            $request->setReservationReleasedAt($this->dateTime->gmtDate());
            $this->requestRepository->save($request);
            $this->emailHelper->sendRejectedEmail($request);
            return $resultRedirect->setPath('shoppingcart/approval/success', ['_query' => ['rejected' => '1']]);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Unable to reject. Please try again.'));
        }
        return $resultRedirect->setPath('shoppingcart/approval/success');
    }
}
