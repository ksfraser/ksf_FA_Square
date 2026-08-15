<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use ksfraser\FrontAccounting\Square\Contracts\CRMAdapterInterface;
use ksfraser\FrontAccounting\Square\Contracts\CRMIntegrationInterface;

use ksfraser\FrontAccounting\Square\DAO\DebtorsMasterDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareCustomerDAO;
use ksfraser\FrontAccounting\Square\Exceptions\CRMIntegrationException;
use ksfraser\FrontAccounting\Square\Exceptions\CustomerNotFoundException;
/**
 * CRM Integration Service
 * 
 * Handles bi-directional synchronization between FrontAccounting debtors and Square customers.
 * Integrates with FA's native customer management system.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Customer Management, FR-07.03 - Customer Conflict Resolution
 */
class CRMIntegrationService implements CRMIntegrationInterface
{
    private DebtorsMasterDAO $debtorDao;
    private SquareCustomerDAO $customerDao;
    private CRMAdapterInterface $crmAdapter;
    private CustomerMatchStrategy $matchStrategy;
    private string $tablePrefix;

    public function __construct(
        DebtorsMasterDAO $debtorDao,
        SquareCustomerDAO $customerDao,
        CRMAdapterInterface $crmAdapter,
        CustomerMatchStrategy $matchStrategy
    ) {
        $this->debtorDao = $debtorDao;
        $this->customerDao = $customerDao;
        $this->crmAdapter = $crmAdapter;
        $this->matchStrategy = $matchStrategy;
        $this->tablePrefix = get_company_pref('table_prefix');
    }

    /**
     * Synchronizes customer data between FrontAccounting and Square/CRM.
     * 
     * @param array $debtor FA debtor data
     * @param array $squareCustomer Square customer data
     * @throws CRMIntegrationException on sync failure
     */
    public function syncCustomerWithCRM(array $debtor, array $squareCustomer): void
    {
        try {
            // Validate input data
            $this->validateCustomerData($debtor, $squareCustomer);
            
            // Convert to CRM format
            $crmData = $this->convertToFACustomer($debtor, $squareCustomer);
            
            // Sync with CRM system
            $this->crmAdapter->updateContact($crmData);
            
            // Update sync timestamp
            $this->customerDao->updateMappingBySquareId(
                $squareCustomer['id'],
                ['crm_sync_at' => date('Y-m-d H:i:s')]
            );
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("CRM sync failed: " . $e->getMessage());
        }
    }

    /**
     * Gets CRM contact history for a customer.
     * 
     * @param int $debtorNo FA debtor number
     * @return array CRM contact history
     * @throws CRMIntegrationException on failure
     */
    public function getCRMContactHistory(int $debtorNo): array
    {
        try {
            $customer = $this->debtorDao->getDebtor($debtorNo);
            if (!$customer) {
                throw new CustomerNotFoundException("Debtor not found: $debtorNo");
            }
            
            $squareCustomer = $this->customerDao->getByDebtorNo($debtorNo);
            if (!$squareCustomer) {
                throw new CustomerNotFoundException("Square customer not found for debtor: $debtorNo");
            }
            
            return $this->crmAdapter->getCustomerHistory($squareCustomer['id']);
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Failed to get CRM history: " . $e->getMessage());
        }
    }

    /**
     * Tracks customer communication in CRM.
     * 
     * @param array $communication Communication data
     * @throws CRMIntegrationException on failure
     */
    public function trackCustomerCommunication(array $communication): void
    {
        try {
            // Validate communication data
            $this->validateCommunicationData($communication);
            
            // Track in CRM
            $this->crmAdapter->trackCommunication($communication);
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Communication tracking failed: " . $e->getMessage());
        }
    }

    /**
     * Synchronizes a Square customer from FrontAccounting.
     * 
     * Wraps syncCustomerToSquare and returns a summary result with the
     * matched or created customer ID.
     * 
     * @param array $squareCustomer Square customer data
     * @return array Sync result summary
     * @throws CRMIntegrationException on failure
     */
    public function syncCustomerFromSquare(array $squareCustomer): array
    {
        try {
            $result = $this->syncCustomerToSquare($squareCustomer);

            return [
                'success' => true,
                'customer_id' => $result['debtor_no'] ?? ($result['id'] ?? null)
            ];
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Customer sync failed: " . $e->getMessage());
        }
    }

    /**
     * Synchronizes a Square customer to FrontAccounting.
     * 
     * @param array $squareCustomer Square customer data
     * @return array FA debtor data
     * @throws CRMIntegrationException on failure
     */
    public function syncCustomerToSquare(array $squareCustomer): array
    {
        try {
            // Validate Square customer data
            $this->validateSquareCustomerData($squareCustomer);
            
            // Try to match with existing FA debtor
            $matchedDebtor = $this->matchStrategy->match($squareCustomer);
            
            if ($matchedDebtor) {
                // Update existing debtor
                return $this->updateDebtorFromSquare($matchedDebtor, $squareCustomer);
            } else {
                // Create new debtor
                return $this->createDebtorFromSquare($squareCustomer);
            }
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Customer sync to FA failed: " . $e->getMessage());
        }
    }

    /**
     * Updates existing debtor from Square customer data.
     * 
     * @param array $debtor Existing FA debtor
     * @param array $squareCustomer Square customer data
     * @return array Updated debtor data
     */
    private function updateDebtorFromSquare(array $debtor, array $squareCustomer): array
    {
        $updateData = [
            'name' => trim(($squareCustomer['given_name'] ?? '') . ' ' . ($squareCustomer['family_name'] ?? '')),
            'email' => $squareCustomer['email_address'] ?? '',
            'phone' => $squareCustomer['phone_number'] ?? '',
            'address' => $this->buildDebtorAddress($squareCustomer)
        ];
        
        // Only update fields that have changed
        $changes = $this->getChangedFields($debtor, $updateData);
        if (!empty($changes)) {
            $this->debtorDao->updateDebtor($debtor['debtor_no'], $changes);
        }
        
        // Update mapping
        $this->customerDao->updateMappingBySquareId(
            $squareCustomer['id'],
            ['fa_debtor_no' => $debtor['debtor_no'], 'sync_at' => date('Y-m-d H:i:s')]
        );
        
        return array_merge($debtor, $changes);
    }

    /**
     * Creates new debtor from Square customer data.
     * 
     * @param array $squareCustomer Square customer data
     * @return array New debtor data
     */
    private function createDebtorFromSquare(array $squareCustomer): array
    {
        $debtorData = [
            'name' => trim(($squareCustomer['given_name'] ?? '') . ' ' . ($squareCustomer['family_name'] ?? '')),
            'email' => $squareCustomer['email_address'] ?? '',
            'phone' => $squareCustomer['phone_number'] ?? '',
            'address' => $this->buildDebtorAddress($squareCustomer),
            'debtor_ref' => 'square_' . $squareCustomer['id'],
            'sales_type' => 1 // FA default sales type
        ];
        
        // Create debtor in FA
        $debtorNo = $this->debtorDao->insertDebtor($debtorData);
        
        // Create mapping
        $this->customerDao->insertMapping([
            'fa_debtor_no' => $debtorNo,
            'square_customer_id' => $squareCustomer['id'],
            'sync_at' => date('Y-m-d H:i:s'),
            'sync_direction' => 'square_to_fa'
        ]);
        
        $debtorData['debtor_no'] = $debtorNo;
        return $debtorData;
    }

    /**
     * Gets changed fields between old and new data.
     * 
     * @param array $oldData Old data
     * @param array $newData New data
     * @return array Changed fields
     */
    private function getChangedFields(array $oldData, array $newData): array
    {
        $changes = [];
        
        foreach ($newData as $key => $value) {
            // Skip timestamp fields that are always updated
            if ($key === 'updated_at') {
                continue;
            }
            
            if (!array_key_exists($key, $oldData)) {
                // New field: only count as a change if it has a value
                if (!empty($value)) {
                    $changes[$key] = $value;
                }
                continue;
            }
            
            if ($oldData[$key] !== $value) {
                $changes[$key] = $value;
            }
        }
        
        return $changes;
    }

    /**
     * Builds the FA debtors_master address field from Square address parts.
     *
     * FA 2.4.19 stores the customer address in a single `address` column,
     * so the Square street/city/state/zip/country parts are folded into
     * one comma-separated string.
     *
     * @param array $squareCustomer Square customer data
     * @return string Comma-separated address string
     */
    private function buildDebtorAddress(array $squareCustomer): string
    {
        $parts = array_filter([
            $squareCustomer['address_line_1'] ?? '',
            $squareCustomer['address_line_2'] ?? '',
            $squareCustomer['locality'] ?? '',
            $squareCustomer['administrative_district_level_1'] ?? '',
            $squareCustomer['postal_code'] ?? '',
            $squareCustomer['country'] ?? ''
        ]);

        return implode(', ', $parts);
    }

    /**
     * Validates customer data for synchronization.
     * 
     * @param array $debtor FA debtor data
     * @param array $squareCustomer Square customer data
     * @throws CRMIntegrationException on validation failure
     */
    private function validateCustomerData(array $debtor, array $squareCustomer): void
    {
        if (empty($debtor['debtor_no'])) {
            throw new CRMIntegrationException("Debtor number is required");
        }
        
        if (empty($squareCustomer['id'])) {
            throw new CRMIntegrationException("Square customer ID is required");
        }
        
        // Validate required fields
        $requiredFields = ['name'];
        foreach ($requiredFields as $field) {
            if (empty($debtor[$field])) {
                throw new CRMIntegrationException("Debtor $field is required");
            }
        }
    }

    /**
     * Validates Square customer data.
     * 
     * @param array $squareCustomer Square customer data
     * @throws CRMIntegrationException on validation failure
     */
    private function validateSquareCustomerData(array $squareCustomer): void
    {
        if (empty($squareCustomer['id'])) {
            throw new CRMIntegrationException("Square customer ID is required");
        }
        
        // Validate email format if present
        if (!empty($squareCustomer['email_address'])) {
            if (!filter_var($squareCustomer['email_address'], FILTER_VALIDATE_EMAIL)) {
                throw new CRMIntegrationException("Invalid email format: " . $squareCustomer['email_address']);
            }
        }
    }

    /**
     * Validates communication data.
     * 
     * @param array $communication Communication data
     * @throws CRMIntegrationException on validation failure
     */
    private function validateCommunicationData(array $communication): void
    {
        $requiredFields = ['debtor_no', 'type', 'message', 'timestamp'];
        
        foreach ($requiredFields as $field) {
            if (empty($communication[$field])) {
                throw new CRMIntegrationException("Communication $field is required");
            }
        }
        
        // Validate communication type
        $validTypes = ['email', 'phone', 'in_person', 'note'];
        if (!in_array($communication['type'], $validTypes)) {
            throw new CRMIntegrationException("Invalid communication type: " . $communication['type']);
        }
    }

    /**
     * Converts FA debtor and Square customer to CRM format.
     * 
     * @param array $debtor FA debtor data
     * @param array $squareCustomer Square customer data
     * @return array CRM data
     */
    private function convertToFACustomer(array $debtor, array $squareCustomer): array
    {
        return [
            'contact_id' => $debtor['debtor_no'],
            'name' => $debtor['name'],
            'email' => $debtor['email'] ?? '',
            'phone' => $debtor['phone'] ?? '',
            'address1' => $debtor['address1'] ?? '',
            'address2' => $debtor['address2'] ?? '',
            'city' => $debtor['city'] ?? '',
            'state' => $debtor['state'] ?? '',
            'zip' => $debtor['zip'] ?? '',
            'country' => $debtor['country'] ?? 'US',
            'source' => 'square_integration',
            'square_customer_id' => $squareCustomer['id'],
            'last_sync_at' => date('Y-m-d H:i:s')
        ];
    }
}