<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Ksfraser\Frontaccounting\SquareUp\Contracts\CRMAdapterInterface;

class CustomerMatchStrategy
{
    private CRMAdapterInterface $crmAdapter;

    public function __construct(CRMAdapterInterface $crmAdapter)
    {
        $this->crmAdapter = $crmAdapter;
    }

    public function getCrmAdapter(): CRMAdapterInterface
    {
        return $this->crmAdapter;
    }

    /**
     * Matches a Square customer to an existing FA debtor.
     *
     * @param array $squareCustomer Square customer data
     * @return array|null Matched FA debtor or null if no match
     */
    public function match(array $squareCustomer): ?array
    {
        return null;
    }
}
