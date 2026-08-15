<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use ksfraser\FrontAccounting\Square\Contracts\CRMAdapterInterface;
use ksfraser\FrontAccounting\Square\DAO\DebtorsMasterDAO;

/**
 * Strategy for matching a Square customer to an existing FA debtor.
 * 
 * Prevents duplicate debtors by matching on email, then phone, then
 * full name. Email and phone matches are authoritative; name matches
 * are only accepted when unambiguous (a single matching debtor).
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.03 - Customer deduplication matching
 */
class CustomerMatchStrategy
{
    private CRMAdapterInterface $crmAdapter;

    private DebtorsMasterDAO $debtorsMasterDao;

    public function __construct(
        CRMAdapterInterface $crmAdapter,
        DebtorsMasterDAO $debtorsMasterDao
    ) {
        $this->crmAdapter = $crmAdapter;
        $this->debtorsMasterDao = $debtorsMasterDao;
    }

    public function getCrmAdapter(): CRMAdapterInterface
    {
        return $this->crmAdapter;
    }

    public function getDebtorsMasterDao(): DebtorsMasterDAO
    {
        return $this->debtorsMasterDao;
    }

    /**
     * Matches a Square customer to an existing FA debtor.
     *
     * Lookup order:
     *  1. Email address (authoritative)
     *  2. Phone number (authoritative)
     *  3. Full name (only when exactly one debtor matches)
     *
     * @param array $squareCustomer Square customer data
     * @return array|null Matched FA debtor or null if no match
     */
    public function match(array $squareCustomer): ?array
    {
        $email = trim((string)($squareCustomer['email_address'] ?? ''));
        if ($email !== '') {
            $debtor = $this->debtorsMasterDao->getByEmail($email);
            if ($debtor !== null) {
                return $debtor;
            }
        }

        $phone = trim((string)($squareCustomer['phone_number'] ?? ''));
        if ($phone !== '') {
            $debtor = $this->debtorsMasterDao->getByPhone($phone);
            if ($debtor !== null) {
                return $debtor;
            }
        }

        $givenName = trim((string)($squareCustomer['given_name'] ?? ''));
        $familyName = trim((string)($squareCustomer['family_name'] ?? ''));
        $name = trim($givenName . ' ' . $familyName);

        if ($name !== '') {
            $matches = $this->debtorsMasterDao->getByName($name);
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }
}
