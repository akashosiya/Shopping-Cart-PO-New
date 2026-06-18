<?php
/**
 * Resend "Your Shopping Cart is Ready" email to customer (Generate Shopping Cart requests).
 */
namespace Osiyatech\ShoppingCart\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;
use Osiyatech\ShoppingCart\Helper\Email;
use Magento\Framework\Exception\NoSuchEntityException;

class ResendCartEmail extends Action
{
    const ADMIN_RESOURCE = 'Osiyatech_ShoppingCart::cart_po';

    /** @var ShoppingCartRequestRepositoryInterface */
    protected $repository;

    /** @var Email */
    protected $emailHelper;

    public function __construct(
        Context $context,
        ShoppingCartRequestRepositoryInterface $repository,
        Email $emailHelper
    ) {
        parent::__construct($context);
        $this->repository = $repository;
        $this->emailHelper = $emailHelper;
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create()->setPath('shoppingcart/request/index');
        if (!$id) {
            $this->messageManager->addErrorMessage(__('Request ID is required.'));
            return $redirect;
        }
        try {
            $request = $this->repository->getById($id);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Request not found.'));
            return $redirect;
        }
        $redirect->setPath('shoppingcart/request/view', ['id' => $id]);
        if ($this->emailHelper->resendCartReadyEmailForRequest($request)) {
            $this->messageManager->addSuccessMessage(
                __('The shopping cart ready email was sent again to the customer.')
            );
        } else {
            $this->messageManager->addErrorMessage(
                __('Could not resend the email. Check that this is a Generate Shopping Cart request with a cart number and a valid customer.')
            );
        }
        return $redirect;
    }
}
