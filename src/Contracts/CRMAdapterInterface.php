<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

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
