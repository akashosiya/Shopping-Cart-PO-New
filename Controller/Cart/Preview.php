<?php
namespace Osiyatech\ShoppingCart\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;

/**
 * Preview cart contents by approval_token (e.g. for approver viewing request cart).
 */
class Preview extends Action implements HttpGetActionInterface
{
    /** @var PageFactory */
    protected $pageFactory;
    /** @var QuoteRepository */
    protected $quoteRepository;
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $requestRepository;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        QuoteRepository $quoteRepository,
        ShoppingCartRequestRepositoryInterface $requestRepository
    ) {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
        $this->quoteRepository = $quoteRepository;
        $this->requestRepository = $requestRepository;
    }

    public function execute()
    {
        $token = trim((string) $this->getRequest()->getParam('token'));
        if ($token === '') {
            return $this->_redirect('checkout/cart');
        }
        $request = $this->requestRepository->getByApprovalToken($token);
        if (!$request || !$request->getQuoteId()) {
            return $this->_redirect('checkout/cart');
        }
        try {
            $quote = $this->quoteRepository->get($request->getQuoteId());
        } catch (\Throwable $e) {
            return $this->_redirect('checkout/cart');
        }
        $result = $this->pageFactory->create();
        $result->getConfig()->getTitle()->set(__('Cart Preview – Approval Request'));
        $result->addHandle('shoppingcart_cart_preview');
        return $result;
    }
}
