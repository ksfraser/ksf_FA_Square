<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use ksfraser\FrontAccounting\Square\Contracts\CRMAdapterInterface;
use ksfraser\FrontAccounting\Square\DAO\DebtorsMasterDAO;
use ksfraser\FrontAccounting\Square\DAO\CustomerHistoryDAO;
use ksfraser\FrontAccounting\Square\DAO\CommunicationDAO;
use ksfraser\FrontAccounting\Square\Exceptions\CRMIntegrationException;
use ksfraser\FrontAccounting\Square\Exceptions\CustomerNotFoundException;

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

            $result = $this->debtorDao->updateDebtor($contactData['contact_id'], $debtorData);
            
            if ($result) {
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
            $debtor = $this->debtorDao->getDebtor($customerId);
            if (!$debtor) {
                throw new CustomerNotFoundException("Debtor not found: $customerId");
            }
            
            return $this->historyDao->getCustomerHistory($debtor['debtor_no']);
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Failed to get customer history: " . $e->getMessage());
        }
    }

    public function trackCommunication(array $communication): bool
    {
        try {
            $this->validateCommunication($communication);
            
            return $this->communicationDao->recordCommunication($communication);
            
        } catch (\Exception $e) {
            throw new CRMIntegrationException("Failed to track communication: " . $e->getMessage());
        }
    }

    private function validateCommunication(array $communication): void
    {
        $requiredFields = ['debtor_no', 'type', 'message', 'timestamp'];
        
        foreach ($requiredFields as $field) {
            if (empty($communication[$field])) {
                throw new CRMIntegrationException("Communication $field is required");
            }
        }
        
        $validTypes = ['email', 'phone', 'in_person', 'note'];
        if (!in_array($communication['type'], $validTypes)) {
            throw new CRMIntegrationException("Invalid communication type: " . $communication['type']);
        }
    }
}
