<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Ksfraser\Frontaccounting\SquareUp\Contracts\CRMAdapterInterface;

/**
 * CRM Adapter
 * 
 * Adapts Square customer data for CRM systems.
 * 
 * @UML Note: Adapter pattern diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Customer Management
 */
class CRMAdapter implements CRMAdapterInterface
{
    /**
     * Updates a contact in the CRM system.
     * 
     * @param array $contactData Contact data
     * @return bool Success status
     */
    public function updateContact(array $contactData): bool
    {
        return true;
    }

    /**
     * Gets customer history from CRM.
     * 
     * @param string $customerId CRM customer ID
     * @return array Customer history
     */
    public function getCustomerHistory(string $customerId): array
    {
        return [];
    }

    /**
     * Tracks customer communication in CRM.
     * 
     * @param array $communication Communication data
     * @return bool Success status
     */
    public function trackCommunication(array $communication): bool
    {
        return true;
    }

    /**
     * Converts Square customer data to FA customer format.
     * 
     * @param array $squareCustomer Square customer data
     * @return array FA customer data
     */
    public function convertToFacustomer(array $squareCustomer): array
    {
        return [
            'debtor_no' => $squareCustomer['id'] ?? null,
            'name' => trim(($squareCustomer['given_name'] ?? '') . ' ' . ($squareCustomer['family_name'] ?? '')),
            'email' => $squareCustomer['email_address'] ?? '',
            'phone' => $squareCustomer['phone_number'] ?? ''
        ];
    }
}
