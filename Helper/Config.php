<?php
namespace Osiyatech\ShoppingCart\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_ENABLED = 'shoppingcart/cart/enabled';
    public const XML_PATH_SALABLE_QTY_GUARD = 'shoppingcart/cart/salable_qty_guard';
    public const XML_PATH_APPROVAL_THRESHOLD = 'shoppingcart/cart/approval_threshold';
    public const XML_PATH_PO_RECIPIENT_EMAIL = 'shoppingcart/cart/po_recipient_email';
    public const XML_PATH_GENERATE_CART_DAYS = 'shoppingcart/cart/generate_cart_reservation_days';
    public const XML_PATH_APPROVAL_DAYS = 'shoppingcart/cart/approval_reservation_days';
    public const XML_PATH_WAITING_PO_AUTO_CANCEL_DAYS = 'shoppingcart/cart/waiting_for_po_auto_cancel_days';
    public const XML_PATH_APPROVER_LIST = 'shoppingcart/cart/approver_list';
    public const XML_PATH_ARIBA_SUPPLIER_CODE = 'shoppingcart/cart/ariba_supplier_code';
	public const XML_PATH_PURCHASE_TYPE_CHECKOUT_FLOW = 'shoppingcart/cart/purchase_type_checkout_flow';
    public const XML_PATH_EMAIL_SENDER = 'shoppingcart/email/sender';
    public const XML_PATH_EMAIL_CART_READY_TEMPLATE = 'shoppingcart/email/cart_ready';
    public const XML_PATH_EMAIL_APPROVAL_REQUEST_TEMPLATE = 'shoppingcart/email/approval_request';
    public const XML_PATH_EMAIL_SUBMITTED_TEMPLATE = 'shoppingcart/email/submitted';
    public const XML_PATH_EMAIL_APPROVED_TEMPLATE = 'shoppingcart/email/approved';
    public const XML_PATH_EMAIL_REJECTED_TEMPLATE = 'shoppingcart/email/rejected';

    private const DEFAULT_CART_READY_TEMPLATE = 'shoppingcart_email_cart_ready';
    private const DEFAULT_APPROVAL_REQUEST_TEMPLATE = 'shoppingcart_email_approval_request';
    private const DEFAULT_SUBMITTED_TEMPLATE = 'shoppingcart_email_submitted';
    private const DEFAULT_APPROVED_TEMPLATE = 'shoppingcart_email_approved';
    private const DEFAULT_REJECTED_TEMPLATE = 'shoppingcart_email_rejected';
	
	public function isPurchaseTypeCheckoutFlowEnabled(?int $storeId = null): bool
	{
		return $this->scopeConfig->isSetFlag(
			self::XML_PATH_PURCHASE_TYPE_CHECKOUT_FLOW,
			\Magento\Store\Model\ScopeInterface::SCOPE_STORE,
			$storeId
		);
	}

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * When enabled, quote add/update validates qty against MSI salable qty (see Quote / Cart plugins).
     */
    public function isSalableQtyGuardEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SALABLE_QTY_GUARD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApprovalThreshold(?int $storeId = null): float
    {
        $v = $this->scopeConfig->getValue(self::XML_PATH_APPROVAL_THRESHOLD, ScopeInterface::SCOPE_STORE, $storeId);
        return max(0, (float) $v);
    }

    public function getPoRecipientEmail(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_PO_RECIPIENT_EMAIL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getGenerateCartReservationDays(?int $storeId = null): int
    {
        $days = (int) $this->scopeConfig->getValue(self::XML_PATH_GENERATE_CART_DAYS, ScopeInterface::SCOPE_STORE, $storeId);
        return max(1, $days);
    }

    public function getApprovalReservationDays(?int $storeId = null): int
    {
        $days = (int) $this->scopeConfig->getValue(self::XML_PATH_APPROVAL_DAYS, ScopeInterface::SCOPE_STORE, $storeId);
        return max(1, $days);
    }

    /**
     * Days after which an order in "waiting_for_po" is auto-cancelled (inventory released).
     * 0 = disabled.
     */
    public function getWaitingForPoAutoCancelDays(?int $storeId = null): int
    {
        $days = (int) $this->scopeConfig->getValue(
            self::XML_PATH_WAITING_PO_AUTO_CANCEL_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return max(0, $days);
    }

    /**
     * Approver list for Purchase Approval dropdown.
     * Accepts per line: "Name,email@example.com" OR "Name (email@example.com)" — only email is used when sending mail.
     *
     * @return array List of ['label' => display name, 'value' => email]
     */
    public function getApproverList(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_APPROVER_LIST, ScopeInterface::SCOPE_STORE, $storeId);
        $list = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $label = $line;
            $email = '';

            if (strpos($line, ',') !== false) {
                [$label, $email] = array_map('trim', explode(',', $line, 2));
            } elseif (preg_match('/\s*\(([^)]+)\)\s*$/', $line, $m)) {
                $email = trim($m[1]);
                $label = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $line));
            } else {
                $email = $line;
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $list[] = ['label' => $label ?: $email, 'value' => $email];
            }
        }
        return $list;
    }

    public function getAribaSupplierCode(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_ARIBA_SUPPLIER_CODE, ScopeInterface::SCOPE_STORE, $storeId) ?: '1000376302';
    }

    public function getEmailSender(?int $storeId = null): string
    {
        $sender = (string) $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_SENDER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $sender !== '' ? $sender : 'general';
    }

    public function getCartReadyEmailTemplate(?int $storeId = null): string
    {
        return $this->getEmailTemplate(
            self::XML_PATH_EMAIL_CART_READY_TEMPLATE,
            self::DEFAULT_CART_READY_TEMPLATE,
            $storeId
        );
    }

    public function getApprovalRequestEmailTemplate(?int $storeId = null): string
    {
        return $this->getEmailTemplate(
            self::XML_PATH_EMAIL_APPROVAL_REQUEST_TEMPLATE,
            self::DEFAULT_APPROVAL_REQUEST_TEMPLATE,
            $storeId
        );
    }

    public function getSubmittedEmailTemplate(?int $storeId = null): string
    {
        return $this->getEmailTemplate(
            self::XML_PATH_EMAIL_SUBMITTED_TEMPLATE,
            self::DEFAULT_SUBMITTED_TEMPLATE,
            $storeId
        );
    }

    public function getApprovedEmailTemplate(?int $storeId = null): string
    {
        return $this->getEmailTemplate(
            self::XML_PATH_EMAIL_APPROVED_TEMPLATE,
            self::DEFAULT_APPROVED_TEMPLATE,
            $storeId
        );
    }

    public function getRejectedEmailTemplate(?int $storeId = null): string
    {
        return $this->getEmailTemplate(
            self::XML_PATH_EMAIL_REJECTED_TEMPLATE,
            self::DEFAULT_REJECTED_TEMPLATE,
            $storeId
        );
    }

    private function getEmailTemplate(string $configPath, string $defaultTemplateId, ?int $storeId = null): string
    {
        $templateId = (string) $this->scopeConfig->getValue(
            $configPath,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $templateId !== '' ? $templateId : $defaultTemplateId;
    }
}
