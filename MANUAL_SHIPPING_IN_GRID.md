# Shipping method in grid – manual checklist

Agar Cursor "Accept" nahi dikha raha ya server par code sync nahi hai, to ye steps follow karo.

---

## 1. Database columns (zaroori)

**File:** `etc/db_schema.xml`  

`<column xsi:type="int" name="rejected_by" .../>` ke **baad**, **`<column xsi:type="timestamp" name="created_at"` se pehle** ye 2 lines honi chahiye:

```xml
<column xsi:type="varchar" name="shipping_method_name" length="255" nullable="true" comment="Shipping method name (for approval email & grid)"/>
<column xsi:type="varchar" name="shipping_method_charges" length="100" nullable="true" comment="Shipping method charges (formatted)"/>
```

Server par run karo:
```bash
php bin/magento setup:upgrade
php bin/magento cache:flush
```

---

## 2. Interface

**File:** `Api/Data/ShoppingCartRequestInterface.php`

- Constants (REJECTED_BY ke baad):  
  `const SHIPPING_METHOD_NAME = 'shipping_method_name';`  
  `const SHIPPING_METHOD_CHARGES = 'shipping_method_charges';`
- Methods (interface ke end):  
  `getShippingMethodName()`, `setShippingMethodName($name)`,  
  `getShippingMethodCharges()`, `setShippingMethodCharges($charges)`

---

## 3. Model

**File:** `Model/ShoppingCartRequest.php`

Class ke end (setRejectedBy ke baad) ye 4 methods:

```php
public function getShippingMethodName() { return $this->getData(self::SHIPPING_METHOD_NAME); }
public function setShippingMethodName($name) { return $this->setData(self::SHIPPING_METHOD_NAME, $name); }
public function getShippingMethodCharges() { return $this->getData(self::SHIPPING_METHOD_CHARGES); }
public function setShippingMethodCharges($charges) { return $this->setData(self::SHIPPING_METHOD_CHARGES, $charges); }
```

---

## 4. Email Helper

**File:** `Helper/Email.php`

- **Use:** `use Osiyatech\ShoppingCart\Api\ShoppingCartRequestRepositoryInterface;`
- **Property:** `/** @var ShoppingCartRequestRepositoryInterface */` and `protected $requestRepository;`
- **Constructor:** last parameter `ShoppingCartRequestRepositoryInterface $requestRepository` and assign: `$this->requestRepository = $requestRepository;`
- **sendApprovalRequestToApprover** mein, fallback ke baad aur `$approveUrl` se pehle:

```php
// Save shipping to request (grid + audit); then send email
try {
    $request->setShippingMethodName($shippingMethodName);
    $request->setShippingMethodCharges($shippingMethodCharges);
    $this->requestRepository->save($request);
} catch (\Exception $e) {
    $this->_logger->warning('ShoppingCart: Could not save shipping to request: ' . $e->getMessage());
}
```

---

## 5. Admin grid

**File:** `view/adminhtml/ui_component/shoppingcart_request_listing.xml`

`<column name="reason_for_purchase">` block ke **baad**, `<column name="cart_number">` se **pehle** ye block:

```xml
<column name="shipping_method_name">
    <settings>
        <filter>text</filter>
        <label translate="true">Shipping Method</label>
    </settings>
</column>
<column name="shipping_method_charges">
    <settings>
        <filter>text</filter>
        <label translate="true">Shipping Charges</label>
    </settings>
</column>
```

---

## 6. Verify

- DB: `DESCRIBE shopping_cart_request;` – `shipping_method_name`, `shipping_method_charges` dikhne chahiye.
- Log: agar save fail ho to `var/log/system.log` mein "Could not save shipping to request" aayega.

Is workspace mein ye sab changes pehle se applied hain; agar server par alag codebase hai to upar ke hisse copy karo ya same files overwrite karo.
