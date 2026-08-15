<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

/**
 * CRM Integration Interface
 * 
 * Defines the contract for CRM integration services.
 * 
 * @UML Note: Interface diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Customer Management, FR-07.03 - Customer Conflict Resolution
 */
interface CRMIntegrationInterface
{
    /**
     * Synchronizes customer data between FrontAccounting and Square/CRM.
     * 
     * @param array $debtor FA debtor data
     * @param array $squareCustomer Square customer data
     * @throws CRMIntegrationException on sync failure
     */
    public function syncCustomerWithCRM(array $debtor, array $squareCustomer): void;

    /**
     * Gets CRM contact history for a customer.
     * 
     * @param int $debtorNo FA debtor number
     * @return array CRM contact history
     * @throws CRMIntegrationException on failure
     */
    public function getCRMContactHistory(int $debtorNo): array;

    /**
     * Tracks customer communication in CRM.
     * 
     * @param array $communication Communication data
     * @throws CRMIntegrationException on failure
     */
    public function trackCustomerCommunication(array $communication): void;

    /**
     * Synchronizes a Square customer to FrontAccounting.
     * 
     * @param array $squareCustomer Square customer data
     * @return array FA debtor data
     * @throws CRMIntegrationException on failure
     */
    public function syncCustomerToSquare(array $squareCustomer): array;
}