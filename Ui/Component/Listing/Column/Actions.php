<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\UrlInterface;
use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;

class Actions extends Column
{
    /** @var UrlInterface */
    protected $urlBuilder;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                $name = $this->getData('name');
                $item[$name]['view'] = [
                    'href' => $this->urlBuilder->getUrl('shoppingcart/request/view', ['id' => $item['entity_id']]),
                    'label' => __('View')
                ];
                if ($item['status'] === ShoppingCartRequestInterface::STATUS_PENDING) {
                    $item[$name]['approve'] = [
                        'href' => $this->urlBuilder->getUrl('shoppingcart/request/approve', ['id' => $item['entity_id']]),
                        'label' => __('Approve'),
                        'confirm' => [
                            'title' => __('Approve Cart PO Request'),
                            'message' => __('Are you sure you want to approve this request?')
                        ]
                    ];
                    $item[$name]['reject'] = [
                        'href' => $this->urlBuilder->getUrl('shoppingcart/request/rejectForm', ['id' => $item['entity_id']]),
                        'label' => __('Reject')
                    ];
                    $item[$name]['cancel'] = [
                        'href' => $this->urlBuilder->getUrl('shoppingcart/request/cancel', ['id' => $item['entity_id']]),
                        'label' => __('Cancel'),
                        'confirm' => [
                            'title' => __('Cancel Cart PO Request'),
                            'message' => __('Are you sure you want to cancel this request? Reserved quantity will be released.')
                        ]
                    ];
                } elseif ($item['status'] === ShoppingCartRequestInterface::STATUS_APPROVED) {
                    $item[$name]['cancel'] = [
                        'href' => $this->urlBuilder->getUrl('shoppingcart/request/cancel', ['id' => $item['entity_id']]),
                        'label' => __('Cancel'),
                        'confirm' => [
                            'title' => __('Cancel Approved Cart PO Request'),
                            'message' => __(
                                'This request was already approved. Cancelling will withdraw approval and release reserved quantity.'
                            )
                        ]
                    ];
                }
                $item[$name]['delete'] = [
                    'href' => $this->urlBuilder->getUrl('shoppingcart/request/delete', ['id' => $item['entity_id']]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete Cart PO Request'),
                        'message' => __('Are you sure you want to delete this request?')
                    ]
                ];
            }
        }
        return $dataSource;
    }
}
