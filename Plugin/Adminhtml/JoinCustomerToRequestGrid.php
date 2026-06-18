<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Plugin\Adminhtml;

use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

class JoinCustomerToRequestGrid
{
    const REQUEST_NAME = 'shoppingcart_request_listing_data_source';

    /**
     * Add customer name/email and "approved by" (admin username or "Via approval link") to the request grid.
     *
     * @param CollectionFactory $subject
     * @param \Magento\Framework\Data\Collection\AbstractDb $result
     * @param string $requestName
     * @return \Magento\Framework\Data\Collection\AbstractDb
     */
    public function afterGetReport($subject, $result, $requestName)
    {
        if ($requestName !== self::REQUEST_NAME || !$result instanceof \Magento\Framework\Data\Collection\AbstractDb) {
            return $result;
        }
        try {
            $select = $result->getSelect();
            if (!$select instanceof Select) {
                return $result;
            }
            $fromPart = $select->getPart(Select::FROM);
            if (!isset($fromPart['customer_grid_flat'])) {
                $tableName = $result->getResource() ? $result->getResource()->getTable('customer_grid_flat') : 'customer_grid_flat';
                $result->getSelect()->joinLeft(
                    ['customer_grid_flat' => $tableName],
                    'main_table.customer_id = customer_grid_flat.entity_id',
                    [
                        'customer_name' => 'name',
                        'customer_email' => 'email',
                    ]
                );
            }
            // Approved by: admin username when approved from backend, or "Via approval link" when approved via email link
            if (!isset($fromPart['admin_user'])) {
                $adminTable = $result->getConnection()->getTableName('admin_user');
                $result->getSelect()->joinLeft(
                    ['admin_user' => $adminTable],
                    'main_table.approved_by = admin_user.user_id',
                    []
                );
                $result->getSelect()->columns([
                    'approved_by_label' => new Expression(
                        "COALESCE(admin_user.username, IF(main_table.approved_at IS NOT NULL, 'Via approval link', NULL))"
                    )
                ]);
            }

            // One row per quote: same quote_id can have multiple orders (reorder etc.) — duplicate rows break UI Document collection.
            if (!isset($fromPart['shoppingcart_so_latest'])) {
                $connection = $result->getConnection();
                $salesOrderTable = $connection->getTableName('sales_order');
                $subSelect = $connection->select()
                    ->from(
                        ['so_inner' => $salesOrderTable],
                        [
                            'quote_id' => 'quote_id',
                            'max_entity_id' => new Expression('MAX(so_inner.entity_id)'),
                        ]
                    )
                    ->group('so_inner.quote_id');
                $result->getSelect()->joinLeft(
                    ['shoppingcart_so_latest' => new Expression('(' . $subSelect . ')')],
                    'main_table.quote_id = shoppingcart_so_latest.quote_id',
                    []
                );
                $result->getSelect()->joinLeft(
                    ['sales_order' => $salesOrderTable],
                    'sales_order.entity_id = shoppingcart_so_latest.max_entity_id',
                    [
                        'order_id' => 'increment_id',
                    ]
                );
            }
            // Quote grand total when no order yet; latest order grand total when placed.
            $fromForQuote = $result->getSelect()->getPart(Select::FROM);
            if (!isset($fromForQuote['request_quote'])) {
                $quoteTable = $result->getConnection()->getTableName('quote');
                $result->getSelect()->joinLeft(
                    ['request_quote' => $quoteTable],
                    'main_table.quote_id = request_quote.entity_id',
                    []
                );
            }
			
			
			$fromForStore = $result->getSelect()->getPart(Select::FROM);

			if (!isset($fromForStore['request_store'])) {
				$storeTable = $result->getConnection()->getTableName('store');

				$result->getSelect()->joinLeft(
					['request_store' => $storeTable],
					'request_quote.store_id = request_store.store_id',
					[]
				);
			}

			$fromForWebsite = $result->getSelect()->getPart(Select::FROM);

			if (!isset($fromForWebsite['request_website'])) {
				$websiteTable = $result->getConnection()->getTableName('store_website');

				$result->getSelect()->joinLeft(
					['request_website' => $websiteTable],
					'request_store.website_id = request_website.website_id',
					[
						'website_name' => 'name'
					]
				);
			}
			
			
            $hasOrderTotalColumn = false;
            foreach ($result->getSelect()->getPart(Select::COLUMNS) as $col) {
                if (isset($col[2]) && $col[2] === 'order_total') {
                    $hasOrderTotalColumn = true;
                    break;
                }
            }
            if (!$hasOrderTotalColumn) {
                $result->getSelect()->columns([
                    'order_total' => new Expression(
                        'COALESCE(sales_order.grand_total, request_quote.grand_total)'
                    ),
                    'order_total_currency' => new Expression(
                        'COALESCE(sales_order.order_currency_code, request_quote.quote_currency_code)'
                    ),
                ]);
            }
            // Joined tables expose entity_id; qualify filters (mass actions, bookmarks, etc.)
			if (method_exists($result, 'addFilterToMap')) {
				$result->addFilterToMap('entity_id', 'main_table.entity_id');
				$result->addFilterToMap('website_name', 'request_website.website_id');
			}
        } catch (\Throwable $e) {
            // If customer_grid_flat or admin_user is missing, grid still loads
        }
        return $result;
    }
}
