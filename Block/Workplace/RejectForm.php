<?php
namespace Osiyatech\ShoppingCart\Block\Workplace;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;

class RejectForm extends Template
{
    /** @var ShoppingCartRequestInterface */
    protected $request;

    /** @var Registry */
    private $registry;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    public function setRequest(ShoppingCartRequestInterface $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Get current PO request from block data or registry (set by RejectForm controller).
     */
    public function getPoRequest(): ?ShoppingCartRequestInterface
    {
        $request = $this->getData('request');
        if ($request instanceof ShoppingCartRequestInterface) {
            return $request;
        }
        $request = $this->registry->registry(\Osiyatech\ShoppingCart\Controller\Workplace\RejectForm::REGISTRY_KEY_REJECT_REQUEST);
        return $request instanceof ShoppingCartRequestInterface ? $request : null;
    }

    public function getRejectUrl(): string
    {
        $request = $this->getPoRequest();
        if (!$request) {
            return '';
        }
        return $this->getUrl('shoppingcart/workplace/reject', [
            'id' => $request->getEntityId(),
            'token' => $request->getApprovalToken(),
        ]);
    }
}
