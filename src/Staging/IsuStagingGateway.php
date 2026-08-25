<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

/**
 * Gateway to ISU staging — all staging operations go through ISU hooks.
 *
 * This class replaces direct access to Square's proprietary staging tables.
 * ISU is THE generic staging layer for all external partners.
 *
 * Square-specific fields (location, environment, card details, raw_json)
 * are stored in ISU's raw_json column and returned in the result arrays.
 *
 * Field mapping (Square → ISU):
 *   payment_id           → source_payment_id
 *   transaction_id       → source_transaction_id
 *   square_order_id      → source_order_id
 *   Date + Time          → transaction_date
 *   total_collected      → total_amount
 *   tax                  → tax_amount
 *   tip                  → tip_amount
 *   partial_refunds      → discount_amount
 *   square_customer_id   → customer_id
 *
 * @package Ksfraser\FrontAccounting\Square\Staging
 * @since 1.1.0
 */
class IsuStagingGateway
{
    private const HOOK_MODULE = 'ksf_FA_ImportStagingProcessing';

    /**
     * Check if a transaction is already staged by source payment ID.
     *
     * @param string $sourcePaymentId The Square payment ID
     * @return bool
     */
    public function exists(string $sourcePaymentId): bool
    {
        if (!function_exists('hook_invoke')) {
            return false;
        }
        $query = new \Ksfraser\StagingDto\StagingExistsQuery('square', $sourcePaymentId, 'transaction');
        $data = $query;
        hook_invoke(self::HOOK_MODULE, 'STAGING_EXISTS', $data);
        return !empty($data['result']['exists']);
    }

    /**
     * Stage a Square order via ISU hooks.
     *
     * Creates a StagingOrder DTO and calls ISU's STAGE_ENTITY hook.
     * Square-specific fields (location, card details, etc.) are passed
     * as extra attributes via raw_json.
     *
     * @param array $paymentData Square payment data
     * @param array $orderData Square order data
     * @param array $lineItems Square line items
     * @return int ISU staging ID, or 0 on failure
     */
    public function stageSquareOrder(array $paymentData, array $orderData, array $lineItems = []): int
    {
        if (!function_exists('hook_invoke')) {
            return 0;
        }

        $dtoLineItems = [];
        foreach ($lineItems as $item) {
            $dtoLineItems[] = new \Ksfraser\StagingDto\StagingLineItem(
                'square',
                $item['source_id'] ?? '',
                $item['transaction_source_id'] ?? '',
                $item['sku'] ?? '',
                $item['name'] ?? '',
                $item['description'] ?? '',
                (int)($item['quantity'] ?? 1),
                (float)($item['unit_price'] ?? 0),
                (float)($item['discount'] ?? 0),
                (float)($item['tax'] ?? 0)
            );
        }

        $dto = new \Ksfraser\StagingDto\StagingOrder(
            'square',
            $paymentData['payment_id'] ?? '',
            (float)($paymentData['total_collected'] ?? 0),
            $paymentData['currency'] ?? 'USD',
            'staged',
            $paymentData['card'] ?? 'card',
            $dtoLineItems,
            $paymentData['square_customer_id'] ?? '',
            [],
            [],
            $paymentData['created_at'] ?? ''
        );

        $data = $dto;
        hook_invoke(self::HOOK_MODULE, 'STAGE_ENTITY', $data);
        $stagingId = (int)($data['result']['stagingId'] ?? 0);

        if ($stagingId > 0) {
            $this->storeSquareMetadata($stagingId, $paymentData, $orderData);
        }

        return $stagingId;
    }

    /**
     * Store Square-specific metadata in the transaction's raw_json field.
     *
     * Merges Square-specific fields (location, environment, card details)
     * into the raw_json so they are preserved for later use.
     *
     * @param int $stagingId ISU staging ID
     * @param array $paymentData Square payment data
     * @param array $orderData Square order data
     * @return void
     */
    private function storeSquareMetadata(int $stagingId, array $paymentData, array $orderData): void
    {
        $existing = $this->getById($stagingId);
        if ($existing === null) {
            return;
        }

        $rawJson = json_decode($existing['raw_json'] ?? '{}', true);
        if (!is_array($rawJson)) {
            $rawJson = [];
        }

        $rawJson['square'] = [
            'location_id' => $paymentData['square_location_id'] ?? '',
            'location_name' => $paymentData['location'] ?? '',
            'environment' => $paymentData['environment'] ?? '',
            'order_id' => $paymentData['square_order_id'] ?? '',
            'card_brand' => $paymentData['card_brand'] ?? '',
            'card_last4' => $paymentData['PAN_suffix'] ?? '',
            'card_entry_methods' => $paymentData['card_entry_methods'] ?? '',
            'gross_sales' => $paymentData['gross_sales'] ?? 0,
            'net_sales' => $paymentData['net_sales'] ?? 0,
            'fa_branch_code' => $paymentData['fa_branch_code'] ?? 0,
        ];

        $this->updateFields($stagingId, ['raw_json' => json_encode($rawJson)]);
    }

    /**
     * Get a staging record by ISU ID.
     *
     * Returns data in ISU field format:
     *   source_payment_id, source_transaction_id, total_amount,
     *   tax_amount, tip_amount, transaction_date, etc.
     *
     * @param int $id ISU staging ID
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        if (!function_exists('hook_invoke')) {
            return null;
        }
        $data = ['id' => $id, 'entity_type' => 'transaction'];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:getById',
            'id' => $id,
            'entity_type' => 'transaction',
        ]);
        return $data['result'] ?? null;
    }

    /**
     * Delete a staging record and its line items.
     *
     * @param int $id ISU staging ID
     * @return void
     */
    public function delete(int $id): void
    {
        if (!function_exists('hook_invoke')) {
            return;
        }
        $this->deleteLineItemsByTransaction($id);
        $data = ['id' => $id, 'entity_type' => 'transaction'];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:delete',
            'id' => $id,
            'entity_type' => 'transaction',
        ]);
    }

    /**
     * Delete all line items for a staging transaction.
     *
     * @param int $stagingId ISU staging ID
     * @return void
     */
    public function deleteLineItemsByTransaction(int $stagingId): void
    {
        if (!function_exists('hook_invoke')) {
            return;
        }
        $data = ['staging_id' => $stagingId];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:deleteLineItemsByTransaction',
            'staging_id' => $stagingId,
        ]);
    }

    /**
     * Get staging status counts grouped by status.
     *
     * @param string|null $source Source filter (e.g., 'square')
     * @return array [status => count]
     */
    public function getStatusCounts(?string $source = null): array
    {
        if (!function_exists('hook_invoke')) {
            return [];
        }
        $data = ['source' => $source];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:getStatusCounts',
            'source' => $source,
        ]);
        return $data['result'] ?? [];
    }

    /**
     * Get staged transactions by status.
     *
     * @param string $status Status filter (e.g., 'staged')
     * @param string|null $fromDate From date (Y-m-d)
     * @param string|null $toDate To date (Y-m-d)
     * @return array
     */
    public function getByStatus(
        string $status,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        if (!function_exists('hook_invoke')) {
            return [];
        }
        $filters = ['status' => $status, 'source' => 'square'];
        if ($fromDate !== null) {
            $filters['from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $filters['to_date'] = $toDate;
        }
        $data = ['filters' => $filters];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:getStagedTransactions',
            'filters' => $filters,
        ]);
        return $data['result'] ?? [];
    }

    /**
     * Update staging record status.
     *
     * @param int $id ISU staging ID
     * @param string $status New status
     * @param array $extraFields Additional fields to update
     * @return void
     */
    public function updateStatus(int $id, string $status, array $extraFields = []): void
    {
        if (!function_exists('hook_invoke')) {
            return;
        }
        if (!empty($extraFields)) {
            $this->updateFields($id, $extraFields);
        }
        $data = ['id' => $id, 'status' => $status];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:updateStatus',
            'id' => $id,
            'status' => $status,
        ]);
    }

    /**
     * Update staging record fields.
     *
     * @param int $id ISU staging ID
     * @param array $fields Fields to update (ISU field names)
     * @return void
     */
    public function updateFields(int $id, array $fields): void
    {
        if (!function_exists('hook_invoke')) {
            return;
        }
        $data = ['id' => $id, 'fields' => $fields, 'entity_type' => 'transaction'];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:updateFields',
            'id' => $id,
            'fields' => $fields,
            'entity_type' => 'transaction',
        ]);
    }

    /**
     * Get line items by ISU staging transaction ID.
     *
     * @param int $stagingId ISU staging transaction ID
     * @return array
     */
    public function getLineItems(int $stagingId): array
    {
        if (!function_exists('hook_invoke')) {
            return [];
        }
        $data = ['staging_id' => $stagingId];
        hook_invoke(self::HOOK_MODULE, 'respondToCapabilityRequest', $data, [
            'request' => 'staging:getItemsByTransaction',
            'staging_id' => $stagingId,
        ]);
        return $data['result'] ?? [];
    }
}
