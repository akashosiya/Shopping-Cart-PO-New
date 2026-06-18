<?php
namespace Osiyatech\ShoppingCart\Controller\Workplace;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Registry;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;

class RejectForm extends Action
{
    const REGISTRY_KEY_REJECT_REQUEST = 'shoppingcart_reject_request';

    /** @var ShoppingCartRequestRepositoryInterface */
    private $requestRepository;
    /** @var PoConfig */
    private $poConfig;
    /** @var PageFactory */
    private $resultPageFactory;
    /** @var Registry */
    private $registry;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        PoConfig $poConfig,
        PageFactory $resultPageFactory,
        Registry $registry
    ) {
        parent::__construct($context);
        $this->requestRepository = $requestRepository;
        $this->poConfig = $poConfig;
        $this->resultPageFactory = $resultPageFactory;
        $this->registry = $registry;
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $token = (string) $this->getRequest()->getParam('token');
        if (!$id || !$token) {
            $this->messageManager->addErrorMessage(__('Invalid rejection link.'));
            return $this->resultRedirectFactory->create()->setPath('shoppingcart/approval/success');
        }
        try {
            $request = $this->requestRepository->getById($id);
            if ((string) $request->getApprovalToken() !== $token) {
                $this->messageManager->addErrorMessage(__('Invalid or expired token.'));
                return $this->resultRedirectFactory->create()->setPath('shoppingcart/approval/success');
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Invalid request.'));
            return $this->resultRedirectFactory->create()->setPath('shoppingcart/approval/success');
        }

        $this->registry->register(self::REGISTRY_KEY_REJECT_REQUEST, $request);
        $resultPage = $this->resultPageFactory->create();
        $block = $resultPage->getLayout()->getBlock('shoppingcart.workplace.reject.form');
        if ($block) {
            $block->setData('request', $request);
        }
        return $resultPage;
    }
}
