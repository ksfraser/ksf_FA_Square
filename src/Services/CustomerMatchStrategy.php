<?php
declare(strict_types=1);

/**
 * CRM Adapter Interface
 * 
 * Defines the contract for CRM system integration.
 * 
 * @UML Note: Adapter pattern diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Customer Management
 */
interface CRMAdapterInterface
{
    /**
     * Updates a contact in the CRM system.
     * 
     * @param array $contactData Contact data
     * @return bool Success status
     * @throws CRMIntegrationException on failure
     */
    public function updateContact(array $contactData): bool;

    /**
     * Gets customer history from CRM.
     * 
     * @param string $customerId CRM customer ID
     * @return array Customer history
     * @throws CRMIntegrationException on failure
     */
    public function getCustomerHistory(string $customerId): array;

    /**
     * Tracks customer communication in CRM.
     * 
     * @param array $communication Communication data
     * @return bool Success status
     * @throws CRMIntegrationException on failure
     */
    public function trackCommunication(array $communication): bool;
}

/**
 * FrontAccounting CRM Adapter
 * 
 * Implements CRM integration using FrontAccounting's native customer management.
 */
class FACRMAdapter implements CRMAdapterInterface
{
    private DebtorsMasterDAO $debtorDao;
    private CustomerHistoryDAO $historyDao;
    private CommunicationDAO $communicationDao;

    public function __construct(
        DebtorsMasterDAO $debtorDao,
        CustomerHistoryDAO $historyDao,
        CommunicationDAO $communicationDao
    ) {
        $this->debtorDao = $debtorDao;
        $this->historyDao = $historyDao;
        $this->communicationDao = $communicationDao;
    }

    public function updateContact(array $contactData): bool
    {
        try {
            // Extract debtor data
            $debtorData = [
                'name' => $contactData['name'],
                'email' => $contactData['email'],
                'phone' => $contactData['phone'],
                'address1' => $contactData['address1'],
                'address2' => $contactData['address2'],
                'city' => $contactData['city'],
                'state' => $contactData['state'],
                'zip' => $contactData['zip'],
                'country' => $contactData['country'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Update debtor in FA
            $result = $this->debtorDao->updateDebtor($contactData['contact_id'], $debtorData);
            
            if ($result) {
                // Log the update in customer history
                $this->historyDao->recordUpdate([
                    'debtor_no' => $contactData['contact_id'],
                    'action' => 'sync',
                    'source' => 'square_integration',
                    'details' => json_encode([
                        'square_customer_id' => $contactData['square_customer_id'],
                        'sync_at' => $contactData['last_sync_at']
                    ]),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Failed to update contact: " . $e->getMessage());
        }
    }

    public function getCustomerHistory(string $customerId): array
    {
        try {
            // Get debtor number from customer ID
            $debtor = $this->debtorDao->getDebtor($customerId);
            if (!$debtor) {
                throw new CustomerNotFoundException("Debtor not found: $customerId");
            }
            
            // Get customer history
            return $this->historyDao->getCustomerHistory($debtor['debtor_no']);
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Failed to get customer history: " . $e->getMessage());
        }
    }

    public function trackCommunication(array $communication): bool
    {
        try {
            // Validate communication data
            $this->validateCommunication($communication);
            
            // Record communication in FA
            return $this->communicationDao->recordCommunication($communication);
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Failed to track communication: " . $e->getMessage());
        }
    }

    /**
     * Validates communication data.
     * 
     * @param array $communication Communication data
     * @throws CRMIntegrationException on validation failure
     */
    private function validateCommunication(array $communication): void
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
}