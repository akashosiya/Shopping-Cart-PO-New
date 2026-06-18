<?php
namespace Osiyatech\ShoppingCart\Plugin\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Framework\Controller\ResultInterface;

/**
 * When user lands on cart page after restoring quote via token link, re-collect totals
 * so the Summary block shows correct Subtotal/Shipping/Order Total instead of 0.
 */
class CollectTotalsAfterRestore
{
    /** @var CheckoutSession */
    private $checkoutSession;
    /** @var QuoteRepository */
    private $quoteRepository;

    public function __construct(
        CheckoutSession $checkoutSession,
        QuoteRepository $quoteRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * After cart index action: if quote was just restored by token, collect totals and save.
     * Also: when cart is loaded with shoppingcart_request_id in URL, store it in session so Generate Cart updates that row.
     *
     * @param \Magento\Checkout\Controller\Cart\Index $subject
     * @param ResultInterface $result
     * @return ResultInterface
     */
    public function afterExecute($subject, $result)
    {
        if (!$result instanceof ResultInterface) {
            return $result;
        }
        $requestId = (int) $subject->getRequest()->getParam('shoppingcart_request_id');
        if ($requestId > 0) {
            $this->checkoutSession->setData('shoppingcart_generate_cart_request_id', $requestId);
        }
        if (!$this->checkoutSession->getData('shoppingcart_quote_restored')) {
            return $result;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId() && $quote->getItemsCount() > 0) {
                $quote->collectTotals();
                $this->quoteRepository->save($quote);
            }
        } catch (\Throwable $e) {
            // ignore so cart page still loads
        }
        $this->checkoutSession->unsetData('shoppingcart_quote_restored');

        return $result;
    }
}
