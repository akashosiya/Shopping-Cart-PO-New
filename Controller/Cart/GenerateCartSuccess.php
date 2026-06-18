<?php
namespace Osiyatech\ShoppingCart\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Psr\Log\LoggerInterface;

class GenerateCartSuccess extends Action
{
    const SESSION_KEY = 'generate_cart_success';
    const REGISTRY_KEY = 'generate_cart_success_cart_number';
    const REGISTRY_EMAIL_SENT_KEY = 'generate_cart_success_email_sent';

    /** @var PageFactory */
    protected $resultPageFactory;

    /** @var RedirectFactory */
    protected $resultRedirectFactory;

    /** @var CheckoutSession */
    protected $checkoutSession;

    /** @var Registry */
    protected $registry;

    /** @var QuoteRepository */
    protected $quoteRepository;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        RedirectFactory $resultRedirectFactory,
        CheckoutSession $checkoutSession,
        Registry $registry,
        QuoteRepository $quoteRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->registry = $registry;
        $this->quoteRepository = $quoteRepository;
        $this->logger = $logger;
    }

    public function execute()
    {
        $data = $this->checkoutSession->getData(self::SESSION_KEY);
        if (!is_array($data) || empty($data['cart_number'])) {
            $redirect = $this->resultRedirectFactory->create();
            return $redirect->setPath('checkout/cart');
        }
        $this->checkoutSession->unsetData(self::SESSION_KEY);
        $this->registry->register(self::REGISTRY_KEY, $data['cart_number']);
        $this->registry->register(
            self::REGISTRY_EMAIL_SENT_KEY,
            array_key_exists('email_sent', $data) ? (bool) $data['email_sent'] : true
        );

        $this->clearCartItems();

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set(__('Thank you'));
        return $resultPage;
    }

    /**
     * Remove all items from the current quote so the cart is empty after thank you page.
     */
    private function clearCartItems(): void
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId() || !$quote->getItemsCount()) {
                return;
            }
            foreach ($quote->getAllVisibleItems() as $item) {
                $quote->removeItem($item->getId());
            }
            $this->quoteRepository->save($quote);
        } catch (\Throwable $e) {
            $this->logger->error('ShoppingCart: Failed to clear cart after thank you page. ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
