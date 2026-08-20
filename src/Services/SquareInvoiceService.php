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

        // Step 3: Build Invoice
        $invoice = new Invoice();
        $invoice->setOrderId($squareOrderId);
        $invoice->setLocationId($this->locationId);

        if ($squareCustomerId) {
            $recipient = new InvoiceRecipient();
            $recipient->setCustomerId($squareCustomerId);
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

        $invoice->setDeliveryMethod($deliveryMethod);
        $invoice->setTitle('Invoice #' . $faInvoiceNo);

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
        // Try to find existing mapping
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

            // Look up debtor details to create a Square customer
            $sql = "SELECT name, email FROM " . TB_PREF . "debtors_master
                    WHERE debtor_no = " . (int)$debtorNo . " LIMIT 1";
            $result = @db_query($sql);
            if ($result) {
                $row = db_fetch($result);
                if ($row) {
                    return $this->createSquareCustomer(
                        $debtorNo,
                        $row['name'] ?? 'Customer',
                        $row['email'] ?? ''
                    );
                }
            }
        }

        return null;
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

            $request = new CreateCustomerRequest($givenName, $familyName);
            $request->setReferenceId('FA-' . $debtorNo);
            if (!empty($email)) {
                $request->setEmailAddress($email);
            }
            $request->setIdempotencyKey('fa-cust-' . $debtorNo . '-' . time());

            $response = $this->client->getCustomersApi()->createCustomer($request);

            if ($response->isSuccess()) {
                $customerId = $response->getResult()->getCustomer()->getId();

                // Store mapping for future lookups
                if (function_exists('db_query')) {
                    $sql = "REPLACE INTO " . TB_PREF . "square_customer_mappings
                            (fa_debtor_no, square_customer_id)
                            VALUES (" . (int)$debtorNo . ", '" . db_escape($customerId) . "')";
                    @db_query($sql);
                }

                return $customerId;
            }
        } catch (\Throwable $e) {
            error_log('ksf_FA_Square: Failed to create Square customer: ' . $e->getMessage());
        }

        return null;
    }
}
