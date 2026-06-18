<?php
namespace Osiyatech\ShoppingCart\Block\Cart;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Osiyatech\ShoppingCart\Controller\Cart\GenerateCartSuccess as SuccessController;
use Osiyatech\ShoppingCart\Helper\Config as PoConfig;

class GenerateCartSuccess extends Template
{
    /** @var Registry */
    protected $registry;

    /** @var PoConfig */
    protected $poConfig;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        PoConfig $poConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->poConfig = $poConfig;
    }

    public function getCartNumber(): string
    {
        $cartNumber = $this->registry->registry(SuccessController::REGISTRY_KEY);
        return $cartNumber ? (string) $cartNumber : '';
    }

    public function wasEmailSent(): bool
    {
        $flag = $this->registry->registry(SuccessController::REGISTRY_EMAIL_SENT_KEY);
        return $flag !== false;
    }

    public function getPoRecipientEmail(): string
    {
        $email = $this->poConfig->getPoRecipientEmail();
        return $email !== '' ? $email : 'hitachi-in.po@prominate.com';
    }

    public function getReservationDays(): int
    {
        return $this->poConfig->getGenerateCartReservationDays();
    }
}
