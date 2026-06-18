<?php
namespace Osiyatech\ShoppingCart\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;
use Magento\Framework\UrlInterface;
use Magento\Framework\Data\Form\FormKey;

class ConfigProvider implements ConfigProviderInterface
{
    const SESSION_REQUEST_ID_KEY = 'shoppingcart_generate_cart_request_id';

    /** @var PoConfig */
    private $poConfig;
    /** @var UrlInterface */
    private $urlBuilder;
    /** @var FormKey */
    private $formKey;
    /** @var CheckoutSession */
    private $checkoutSession;

    public function __construct(
        PoConfig $poConfig,
        UrlInterface $urlBuilder,
        FormKey $formKey,
        CheckoutSession $checkoutSession
    ) {
        $this->poConfig = $poConfig;
        $this->urlBuilder = $urlBuilder;
        $this->formKey = $formKey;
        $this->checkoutSession = $checkoutSession;
    }

    public function getConfig(): array
    {
        if (!$this->poConfig->isEnabled()) {
            return ['generateCart' => null];
        }
        $requestId = (int) $this->checkoutSession->getData(self::SESSION_REQUEST_ID_KEY);
        $quote = $this->checkoutSession->getQuote();
        $storeId = $quote && $quote->getId() ? (int) $quote->getStoreId() : null;
        return [
            'generateCart' => [
                'submitUrl' => $this->urlBuilder->getUrl('shoppingcart/cart/generateCart', ['_secure' => true]),
                'poRecipientEmail' => $this->poConfig->getPoRecipientEmail($storeId) ?: 'hitachi-in.po@prominate.com',
                'reservationDays' => $this->poConfig->getGenerateCartReservationDays($storeId),
                'aribaSupplierCode' => $this->poConfig->getAribaSupplierCode($storeId),
                'purchaseTypeCheckoutFlowEnabled' => $this->poConfig->isPurchaseTypeCheckoutFlowEnabled($storeId),
                'formKey' => $this->formKey->getFormKey(),
                'requestId' => $requestId ?: null,
            ],
        ];
    }
}
