<?php
namespace Osiyatech\ShoppingCart\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;

class AddWaitingForPoOrderStatus implements DataPatchInterface
{
    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    /** @var StatusFactory */
    private $statusFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        StatusFactory $statusFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->statusFactory = $statusFactory;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        try {
            /** @var Status $status */
            $status = $this->statusFactory->create();
            $status->load('waiting_for_po');

            if (!$status->getStatus()) {
                $status->setStatus('waiting_for_po')
                    ->setLabel('Waiting for PO')
                    ->save();
            }

            $status->assignState(Order::STATE_NEW, false, true);
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
