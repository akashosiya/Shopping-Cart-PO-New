<?php
namespace Osiyatech\ShoppingCart\Controller\Workplace;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Registry;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;
use Osiyatech\ShoppingCart\Helper\Email;
use Osiyatech\ShoppingCart\Model\Inventory\CartReservationManager;

class Approve extends Action
{
    const REGISTRY_KEY_APPROVE_REQUEST = 'shoppingcart_approve_request';

    /** @var ShoppingCartRequestRepositoryInterface */
    private $requestRepository;
    /** @var DateTime */
    private $dateTime;
    /** @var PoConfig */
    private $poConfig;
    /** @var Email */
    private $emailHelper;
    /** @var PageFactory */
    private $resultPageFactory;
    /** @var Registry */
    private $registry;
    /** @var FormKeyValidator */
    private $formKeyValidator;

    /** @var CartReservationManager */
    private $reservationManager;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        DateTime $dateTime,
        PoConfig $poConfig,
        Email $emailHelper,
        PageFactory $resultPageFactory,
        Registry $registry,
        FormKeyValidator $formKeyValidator,
        CartReservationManager $reservationManager
    ) {
        parent::__construct($context);
        $this->requestRepository = $requestRepository;
        $this->dateTime = $dateTime;
        $this->poConfig = $poConfig;
        $this->emailHelper = $emailHelper;
        $this->resultPageFactory = $resultPageFactory;
        $this->registry = $registry;
        $this->formKeyValidator = $formKeyValidator;
        $this->reservationManager = $reservationManager;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('id');
        $token = (string) $this->getRequest()->getParam('token');
        if (!$id || !$token) {
            $this->messageManager->addErrorMessage(__('Invalid approval link.'));
            return $resultRedirect->setPath('shoppingcart/approval/success');
        }
        try {
            $request = $this->requestRepository->getById($id);
            if ((string) $request->getApprovalToken() !== $token) {
                $this->messageManager->addErrorMessage(__('Invalid or expired approval token.'));
                return $resultRedirect->setPath('shoppingcart/approval/success');
            }
            if ($request->getStatus() !== ShoppingCartRequestInterface::STATUS_PENDING) {
                $this->messageManager->addNoticeMessage(__('This request is already %1.', $request->getStatus()));
                return $resultRedirect->setPath('shoppingcart/approval/success');
            }

            // POST only: actually approve (avoids auto-approve when email clients/scanners prefetch the link)
            if ($this->getRequest()->isPost()) {
                if (!$this->formKeyValidator->validate($this->getRequest())) {
                    $this->messageManager->addErrorMessage(__('Invalid form key. Please open the approval link from your email and click Confirm Approve.'));
                    return $resultRedirect->setPath('shoppingcart/approval/success');
                }
                $request->setStatus(ShoppingCartRequestInterface::STATUS_APPROVED);
                $request->setApprovedAt($this->dateTime->gmtDate());
                $request->setRejectionReason(null);
                $request->setRejectedBy(null);
                $request->setRejectedAt(null);
                $this->reservationManager->releaseApprovalReservationIfPlaced($request);
                $request->setMsiReservationPlaced(0);
                $request->setReservationReleasedAt($this->dateTime->gmtDate());
                $this->requestRepository->save($request);
                $this->emailHelper->sendApprovedEmail($request);
                return $resultRedirect->setPath('shoppingcart/approval/success', ['_query' => ['approved' => '1']]);
            }

            // GET: show confirmation page so that one-click is required to approve (no prefetch approval)
            $this->registry->register(self::REGISTRY_KEY_APPROVE_REQUEST, $request);
            $resultPage = $this->resultPageFactory->create();
            $block = $resultPage->getLayout()->getBlock('shoppingcart.workplace.approve.form');
            if ($block) {
                $block->setData('request', $request);
            }
            return $resultPage;
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Unable to approve. Please try again.'));
        }
        return $resultRedirect->setPath('shoppingcart/approval/success');
    }
}
