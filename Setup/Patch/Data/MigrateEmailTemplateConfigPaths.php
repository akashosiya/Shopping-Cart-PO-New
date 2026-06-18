<?php
/**
 * Copyright © Osiyatech. All rights reserved.
 */

namespace Osiyatech\ShoppingCart\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateEmailTemplateConfigPaths implements DataPatchInterface
{
    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $table = $this->moduleDataSetup->getTable('core_config_data');
        $oldPaths = [
            'shoppingcart/email/cart_ready_template',
            'shoppingcart/email/approval_request_template',
            'shoppingcart/email/submitted_template',
            'shoppingcart/email/approved_template',
            'shoppingcart/email/rejected_template',
        ];

        $connection->delete($table, ['path IN (?)' => $oldPaths]);

        $connection->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
