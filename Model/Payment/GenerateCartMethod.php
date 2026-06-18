<?php
namespace Osiyatech\ShoppingCart\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;

class GenerateCartMethod extends AbstractMethod
{
    const PAYMENT_METHOD_CODE = 'shoppingcart_generatecart';

    protected $_code = self::PAYMENT_METHOD_CODE;
    protected $_isOffline = true;
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_canUseCheckout = true;
    protected $_canUseInternal = false;

    /** @var PoConfig */
    private $poConfig;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        PoConfig $poConfig,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->poConfig = $poConfig;
    }

    /**
     * Only available when Shopping Cart PO is enabled.
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }
        $storeId = $quote ? (int) $quote->getStoreId() : null;
        return $this->poConfig->isEnabled($storeId);
    }
}
