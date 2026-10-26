<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

interface CustomerMatcherInterface
{
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function findOrCreateDebtor(array $customerData): int;

    public function findOrCreateBranch(int $debtorNo, array $branchData): int;

    public function matchSquareCustomerToFaDebtor(string $squareCustomerId): ?int;

    public function linkSquareCustomer(string $squareCustomerId, int $debtorNo): void;
}
