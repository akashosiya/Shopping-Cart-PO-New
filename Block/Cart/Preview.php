<?php
namespace Osiyatech\ShoppingCart\Block\Cart;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;

class Preview extends Template
{
    /** @var RequestInterface */
    protected $request;
    /** @var QuoteRepository */
    protected $quoteRepository;
    /** @var ShoppingCartRequestRepositoryInterface */
    protected $requestRepository;

    public function __construct(
        Context $context,
        RequestInterface $request,
        QuoteRepository $quoteRepository,
        ShoppingCartRequestRepositoryInterface $requestRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->request = $request;
        $this->quoteRepository = $quoteRepository;
        $this->requestRepository = $requestRepository;
    }

    /**
     * @return \Magento\Quote\Model\Quote|null
     */
    public function getQuote()
    {
        $request = $this->getRequestModel();
        if (!$request || !$request->getQuoteId()) {
            return null;
        }
        try {
            return $this->quoteRepository->get($request->getQuoteId());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return \Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface|null
     */
    public function getRequestModel()
    {
        $token = trim((string) $this->request->getParam('token'));
        if ($token === '') {
            return null;
        }
        return $this->requestRepository->getByApprovalToken($token);
    }
}
