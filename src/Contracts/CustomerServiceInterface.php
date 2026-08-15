<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

/**
 * Contract for customer management services.
 * 
 * Defines the interface for handling bi-directional customer synchronization.
 * 
 * @UML Note: Interface definition in ProjectDocs/UML.md
 * @BABOK Related: FR-04.01 through FR-04.08 - Customer Management
 */
interface CustomerServiceInterface
{
    /**
     * Syncs a FrontAccounting debtor to Square customer.
     *
     * @param array $debtor Debtor data from FA
     * @return \Square\Models\Customer Created/updated Square customer
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\CustomerSyncException
     */
    public function syncCustomerFromFA(array $debtor): \Square\Models\Customer;

    /**
     * Syncs a Square customer to FrontAccounting debtor.
     *
     * @param \Square\Models\Customer $squareCustomer Square customer data
     * @return array Created/updated FA debtor
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\CustomerSyncException
     */
    public function syncCustomerToSquare(\Square\Models\Customer $squareCustomer): array;

    /**
     * Finds a Square customer by email address.
     *
     * @param string $email Email address to search for
     * @return \Square\Models\Customer|null
     */
    public function findCustomerByEmail(string $email): ?\Square\Models\Customer;

    /**
     * Matches a Square customer to a FA debtor.
     *
     * @param string $email Email address
     * @param string $phone Phone number (optional)
     * @return array|null Matched FA debtor
     */
    public function matchCustomer(string $email, ?string $phone = null): ?array;

    /**
     * Gets all Square customers.
     *
     * @return array Array of \Square\Models\Customer objects
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\CustomerSyncException
     */
    public function getAllCustomers(): array;
}