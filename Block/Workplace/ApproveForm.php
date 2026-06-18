<?php
namespace Osiyatech\ShoppingCart\Block\Workplace;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;

class ApproveForm extends Template
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

    public function getPoRequest(): ?ShoppingCartRequestInterface
    {
        $request = $this->getData('request');
        if ($request instanceof ShoppingCartRequestInterface) {
            return $request;
        }
        $request = $this->registry->registry(\Osiyatech\ShoppingCart\Controller\Workplace\Approve::REGISTRY_KEY_APPROVE_REQUEST);
        return $request instanceof ShoppingCartRequestInterface ? $request : null;
    }

    public function getApproveUrl(): string
    {
        $request = $this->getPoRequest();
        if (!$request) {
            return '';
        }
        return $this->getUrl('shoppingcart/workplace/approve', [
            'id' => $request->getEntityId(),
            'token' => $request->getApprovalToken(),
        ]);
    }
}
