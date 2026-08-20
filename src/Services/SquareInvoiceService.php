<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use Square\SquareClient;
use Square\Models\Invoice;
use Square\Models\InvoiceRecipient;
use Square\Models\InvoicePaymentRequest;
use Square\Models\CreateInvoiceRequest;
use Square\Models\PublishInvoiceRequest;
use Square\Models\InvoiceRequestType;
use Square\Models\InvoiceDeliveryMethod;
use Square\Models\InvoiceAcceptedPaymentMethods;
use Square\Models\Money;
use Square\Models\Order;
use Square\Models\CreateOrderRequest;
use Square\Models\OrderLineItem;
use Square\Models\OrderLineItemTax;
use Square\Models\CreateCustomerRequest;
use Square\Models\UpdateCustomerRequest;
use Square\Models\SearchCustomersRequest;
use Square\Models\CustomerQuery;
use Square\Models\CustomerFilter;
use Square\Models\CustomerTextFilter;
use Square\Exceptions\ApiException;
use ksfraser\FrontAccounting\Square\Contracts\SquareInvoiceServiceInterface;
use ksfraser\FrontAccounting\Square\DAO\SquareInvoiceMapDAO;

/**
 * Square Invoice Service.
 *
 * Creates and manages Square Invoices via the InvoicesApi,
 * linking them to FA sales invoices for payment tracking.
 *
 * Workflow:
 * 1. FA creates a sales invoice (ST_SALESINVOICE)
 * 2. db_prewrite detects square_invoice* destination, suppresses auto-payment
 * 3. db_postwrite calls this service to create a Square Invoice
 * 4. Customer pays via Square payment link or phone app
 * 5. Staging import matches the Square transaction to the FA invoice
 * 6. Payment is recorded against the existing FA invoice
 */
class SquareInvoiceService implements SquareInvoiceServiceInterface
{
    private SquareClient $client;
    private SquareInvoiceMapDAO $mapDao;
    private string $locationId;

    public function __construct(
        SquareClient $client,
        SquareInvoiceMapDAO $mapDao,
        string $locationId = ''
    ) {
        $this->client = $client;
        $this->mapDao = $mapDao;
        $this->locationId = $locationId;
    }

    /**
     * {@inheritdoc}
     */
    public function createInvoiceFromFA(
        int $faInvoiceNo,
        int $debtorNo,
        array $lineItems,
        string $dueDate,
        string $deliveryMethod = 'SHARE_MANUALLY',
        ?string $automaticPaymentSource = null
    ): array {
        $this->mapDao->ensureTableExists();

        // Check if already mapped
        $existing = $this->mapDao->findByFaInvoiceNo($faInvoiceNo);
        if ($existing !== null) {
            return [
                'square_invoice_id' => $existing['square_invoice_id'],
                'square_order_id'   => $existing['square_order_id'],
                'public_url'        => $existing['public_url'],
                'status'            => $existing['status'],
            ];
        }

        // Step 1: Create a Square Order from FA line items
        $orderResult = $this->createSquareOrder($debtorNo, $lineItems);
        $squareOrderId = $orderResult['order_id'];
        $totalAmountCents = $orderResult['total_cents'];

        // Step 2: Look up or create Square Customer (required to publish)
        $squareCustomerId = $this->resolveOrCreateSquareCustomer($debtorNo);
        $debtorEmail = $this->lookupDebtorEmail($debtorNo);

        // Step 3: Build Invoice
        $invoice = new Invoice();
        $invoice->setOrderId($squareOrderId);
        $invoice->setLocationId($this->locationId);

        if ($squareCustomerId) {
            // Note: do NOT set emailAddress here — it's derived from the customer profile
            $recipient = new InvoiceRecipient();
            $recipient->setCustomerId($squareCustomerId);
            $invoice->setPrimaryRecipient($recipient);
        } elseif (!empty($debtorEmail)) {
            $recipient = new InvoiceRecipient();
            $recipient->setEmailAddress($debtorEmail);
            $invoice->setPrimaryRecipient($recipient);
        }

        $paymentRequest = new InvoicePaymentRequest();
        $paymentRequest->setRequestType(InvoiceRequestType::BALANCE);
        $paymentRequest->setDueDate($dueDate);

        if ($automaticPaymentSource && $automaticPaymentSource !== 'NONE') {
            $paymentRequest->setAutomaticPaymentSource($automaticPaymentSource);
        }

        $invoice->setPaymentRequests([$paymentRequest]);

        // Required: accepted_payment_methods
        $acceptedPaymentMethods = new InvoiceAcceptedPaymentMethods();
        $acceptedPaymentMethods->setCard(true);
        $invoice->setAcceptedPaymentMethods($acceptedPaymentMethods);

        // Note: title is a derived field — Square auto-computes it. Do NOT set it.
        $invoice->setDeliveryMethod($deliveryMethod);

        // Step 4: Create the invoice (DRAFT)
        $request = new CreateInvoiceRequest($invoice);
        $request->setIdempotencyKey('fa-inv-' . $faInvoiceNo . '-' . time());

        $invoicesApi = $this->client->getInvoicesApi();
        $response = $invoicesApi->createInvoice($request);

        if (!$response->isSuccess()) {
            $errors = $response->getErrors();
            $errMsg = 'Square Invoice creation failed';
            if ($errors) {
                foreach ($errors as $err) {
                    $errMsg .= ': ' . ($err->getDetail() ?? $err->getCode() ?? 'unknown');
                }
            }
            throw new \RuntimeException($errMsg);
        }

        $created = $response->getResult()->getInvoice();
        $squareInvoiceId = $created->getId();
        $version = $created->getVersion();
        $publicUrl = $created->getPublicUrl() ?? '';

        // Step 5: Publish the invoice
        $publishResult = $this->publishInvoiceInternal($squareInvoiceId, $version, $deliveryMethod);
        $status = $publishResult['status'] ?? 'UNPAID';
        if (!empty($publishResult['public_url'])) {
            $publicUrl = $publishResult['public_url'];
        }

        // Step 6: Store mapping
        $this->mapDao->insert([
            'fa_invoice_no'      => $faInvoiceNo,
            'square_invoice_id'  => $squareInvoiceId,
            'square_order_id'    => $squareOrderId,
            'square_customer_id' => $squareCustomerId ?? '',
            'amount_cents'       => $totalAmountCents,
            'currency'           => 'CAD',
            'destination'        => $deliveryMethod === 'EMAIL' ? 'square_invoice_email' : 'square_invoice',
            'status'             => $status,
            'public_url'         => $publicUrl,
        ]);

        return [
            'square_invoice_id' => $squareInvoiceId,
            'square_order_id'   => $squareOrderId,
            'public_url'        => $publicUrl,
            'status'            => $status,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function publishInvoice(string $squareInvoiceId, int $version): array
    {
        return $this->publishInvoiceInternal($squareInvoiceId, $version, 'SHARE_MANUALLY');
    }

    /**
     * {@inheritdoc}
     */
    public function getInvoiceStatus(string $squareInvoiceId): string
    {
        $invoicesApi = $this->client->getInvoicesApi();
        $response = $invoicesApi->getInvoice($squareInvoiceId);

        if (!$response->isSuccess()) {
            return 'UNKNOWN';
        }

        $invoice = $response->getResult()->getInvoice();
        return $invoice->getStatus() ?? 'UNKNOWN';
    }

    /**
     * {@inheritdoc}
     */
    public function findBySquareInvoiceId(string $squareInvoiceId): ?array
    {
        return $this->mapDao->findBySquareInvoiceId($squareInvoiceId);
    }

    /**
     * {@inheritdoc}
     */
    public function findBySquareOrderId(string $squareOrderId): ?array
    {
        return $this->mapDao->findBySquareOrderId($squareOrderId);
    }

    /**
     * {@inheritdoc}
     */
    public function updateMappingStatus(int $faInvoiceNo, string $status): bool
    {
        return $this->mapDao->updateStatus($faInvoiceNo, $status);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function publishInvoiceInternal(
        string $squareInvoiceId,
        int $version,
        string $deliveryMethod
    ): array {
        $invoicesApi = $this->client->getInvoicesApi();

        $publishRequest = new PublishInvoiceRequest($version);
        $publishRequest->setIdempotencyKey('pub-' . $squareInvoiceId . '-' . time());

        $response = $invoicesApi->publishInvoice($squareInvoiceId, $publishRequest);

        if (!$response->isSuccess()) {
            $errors = $response->getErrors();
            $errMsg = 'Square Invoice publish failed';
            if ($errors) {
                foreach ($errors as $err) {
                    $errMsg .= ': ' . ($err->getDetail() ?? $err->getCode() ?? 'unknown');
                }
            }
            throw new \RuntimeException($errMsg);
        }

        $invoice = $response->getResult()->getInvoice();
        return [
            'status'     => $invoice->getStatus() ?? 'UNPAID',
            'public_url' => $invoice->getPublicUrl() ?? '',
        ];
    }

    private function createSquareOrder(int $debtorNo, array $lineItems): array
    {
        $ordersApi = $this->client->getOrdersApi();

        $orderLineItems = [];
        $totalCents = 0;

        foreach ($lineItems as $item) {
            $qty = (string)($item['quantity'] ?? 1);
            $li = new OrderLineItem($qty);
            $li->setName($item['stock_id'] ?? $item['item_description'] ?? 'Item');

            $unitPrice = (int)(($item['price'] ?? 0) * 100);
            $money = new Money();
            $money->setAmount($unitPrice);
            $money->setCurrency('CAD');
            $li->setBasePriceMoney($money);

            if (isset($item['tax_type_id']) && $item['tax_type_id'] > 0) {
                $tax = new OrderLineItemTax();
                $tax->setCatalogObjectId((string)$item['tax_type_id']);
                $tax->setScope('ORDER');
                $li->setAppliedTaxes([$tax]);
            }

            $totalCents += $unitPrice * (int)($item['quantity'] ?? 1);
            $orderLineItems[] = $li;
        }

        $order = new Order($this->locationId);
        $order->setLocationId($this->locationId);
        $order->setLineItems($orderLineItems);
        $order->setPricingOptions(
            (new \Square\Models\OrderPricingOptions())->setAutoApplyDiscounts(true)
        );

        $request = new CreateOrderRequest();
        $request->setOrder($order);
        $request->setIdempotencyKey('fa-order-' . $debtorNo . '-' . time());

        $response = $ordersApi->createOrder($request);

        if (!$response->isSuccess()) {
            $errors = $response->getErrors();
            $errMsg = 'Square Order creation failed';
            if ($errors) {
                foreach ($errors as $err) {
                    $errMsg .= ': ' . ($err->getDetail() ?? $err->getCode() ?? 'unknown');
                }
            }
            throw new \RuntimeException($errMsg);
        }

        $created = $response->getResult()->getOrder();
        return [
            'order_id'    => $created->getId(),
            'total_cents' => $totalCents,
        ];
    }

    /**
     * Look up Square customer from mapping table, or create one if not found.
     *
     * Square Invoices require a customer_id to publish.
     */
    private function resolveOrCreateSquareCustomer(int $debtorNo): ?string
    {
        // Step 1: Check local mapping table
        if (function_exists('db_query') && function_exists('db_fetch')) {
            $sql = "SELECT square_customer_id FROM " . TB_PREF . "square_customer_mappings
                    WHERE fa_debtor_no = " . (int)$debtorNo . " LIMIT 1";
            $result = @db_query($sql);
            if ($result) {
                $row = db_fetch($result);
                if ($row && !empty($row['square_customer_id'])) {
                    return $row['square_customer_id'];
                }
            }
        }

        // Step 2: Search Square by reference_id (FA-{debtorNo})
        $existingId = $this->searchSquareCustomerByReference($debtorNo);
        if ($existingId !== null) {
            $this->storeCustomerMapping($debtorNo, $existingId);
            $this->ensureCustomerHasEmail($existingId, $debtorNo);
            return $existingId;
        }

        // Step 3: Create new Square customer
        $debtorName = $this->lookupDebtorName($debtorNo);
        $debtorEmail = $this->lookupDebtorEmail($debtorNo);

        $created = $this->createSquareCustomer(
            $debtorNo,
            $debtorName ?: 'Customer',
            $debtorEmail
        );

        if ($created !== null) {
            return $created;
        }

        error_log('ksf_FA_Square: Failed to resolve/create Square customer for debtor #' . $debtorNo);
        return null;
    }

    /**
     * Search Square for an existing customer by reference_id.
     *
     * @return string|null Square customer ID or null
     */
    private function searchSquareCustomerByReference(int $debtorNo): ?string
    {
        try {
            $refFilter = new CustomerTextFilter();
            $refFilter->setExact('FA-' . $debtorNo);

            $filter = new CustomerFilter();
            $filter->setReferenceId($refFilter);

            $query = new CustomerQuery();
            $query->setFilter($filter);

            $request = new SearchCustomersRequest();
            $request->setQuery($query);

            $response = $this->client->getCustomersApi()->searchCustomers($request);

            if ($response->isSuccess()) {
                $results = $response->getResult()->getCustomers();
                if (!empty($results)) {
                    return $results[0]->getId();
                }
            }
        } catch (\Throwable $e) {
            error_log('ksf_FA_Square: Square customer search failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Ensure the Square customer has an email address.
     * If missing, pull from FA crm_contacts and update the Square profile.
     * Required because Square derives email on InvoiceRecipient from the customer profile.
     */
    private function ensureCustomerHasEmail(string $squareCustomerId, int $debtorNo): void
    {
        try {
            $response = $this->client->getCustomersApi()->retrieveCustomer($squareCustomerId);
            if (!$response->isSuccess()) {
                return;
            }
            $customer = $response->getResult()->getCustomer();
            if (!empty($customer->getEmailAddress())) {
                return; // Already has email
            }

            $email = $this->lookupDebtorEmail($debtorNo);
            if (empty($email)) {
                return; // No email available in FA either
            }

            $version = $customer->getVersion() ?? 0;
            $updateRequest = new UpdateCustomerRequest();
            $updateRequest->setVersion($version);
            $updateRequest->setEmailAddress($email);

            $this->client->getCustomersApi()->updateCustomer($squareCustomerId, $updateRequest);
        } catch (\Throwable $e) {
            error_log('ksf_FA_Square: Failed to update Square customer email: ' . $e->getMessage());
        }
    }

    /**
     * Look up debtor name from FA database.
     */
    private function lookupDebtorName(int $debtorNo): string
    {
        if (!function_exists('db_query') || !function_exists('db_fetch')) {
            return '';
        }

        $sql = "SELECT name FROM " . TB_PREF . "debtors_master
                WHERE debtor_no = " . (int)$debtorNo . " LIMIT 1";
        $result = @db_query($sql);
        if ($result) {
            $row = db_fetch($result);
            if ($row) {
                return $row['name'] ?? '';
            }
        }
        return '';
    }

    /**
     * Look up debtor email from crm_contacts/crm_persons.
     *
     * FA 2.4 stores contact info in crm_persons linked via crm_contacts.
     * Type 'customer' = debtor-level, type 'cust_branch' = branch contact.
     * We check both, preferring the debtor-level email.
     */
    private function lookupDebtorEmail(int $debtorNo): string
    {
        if (!function_exists('db_query') || !function_exists('db_fetch')) {
            return '';
        }

        // Try customer-level contact first
        $sql = "SELECT p.email FROM " . TB_PREF . "crm_contacts c
                JOIN " . TB_PREF . "crm_persons p ON c.person_id = p.id
                WHERE c.type = 'customer' AND c.entity_id = " . (int)$debtorNo . "
                AND p.email != '' LIMIT 1";
        $result = @db_query($sql);
        if ($result) {
            $row = db_fetch($result);
            if ($row && !empty($row['email'])) {
                return $row['email'];
            }
        }

        // Fall back to branch contact
        $sql = "SELECT p.email FROM " . TB_PREF . "crm_contacts c
                JOIN " . TB_PREF . "crm_persons p ON c.person_id = p.id
                WHERE c.type = 'cust_branch' AND c.entity_id = " . (int)$debtorNo . "
                AND p.email != '' LIMIT 1";
        $result = @db_query($sql);
        if ($result) {
            $row = db_fetch($result);
            if ($row && !empty($row['email'])) {
                return $row['email'];
            }
        }

        return '';
    }

    /**
     * Store or update the FA debtor → Square customer mapping.
     */
    private function storeCustomerMapping(int $debtorNo, string $squareCustomerId): void
    {
        if (!function_exists('db_query')) {
            return;
        }
        $sql = "DELETE FROM " . TB_PREF . "square_customer_mappings
                WHERE fa_debtor_no = " . (int)$debtorNo;
        @db_query($sql);
        $sql = "INSERT INTO " . TB_PREF . "square_customer_mappings
                (fa_debtor_no, square_customer_id)
                VALUES (" . (int)$debtorNo . ", '" . db_escape($squareCustomerId) . "')";
        @db_query($sql);
    }

    /**
     * Create a new customer in Square via the CustomersApi.
     */
    private function createSquareCustomer(int $debtorNo, string $name, string $email): ?string
    {
        try {
            $parts = explode(' ', $name, 2);
            $givenName = $parts[0] ?? $name;
            $familyName = $parts[1] ?? '';

            $request = new CreateCustomerRequest();
            $request->setGivenName($givenName);
            if (!empty($familyName)) {
                $request->setFamilyName($familyName);
            }
            $request->setReferenceId('FA-' . $debtorNo);
            if (!empty($email)) {
                $request->setEmailAddress($email);
            }
            $request->setIdempotencyKey('fa-cust-' . $debtorNo . '-' . time());

            $response = $this->client->getCustomersApi()->createCustomer($request);

            if ($response->isSuccess()) {
                $customerId = $response->getResult()->getCustomer()->getId();
                $this->storeCustomerMapping($debtorNo, $customerId);
                return $customerId;
            }
        } catch (\Throwable $e) {
            error_log('ksf_FA_Square: Failed to create Square customer: ' . $e->getMessage());
        }

        return null;
    }
}
