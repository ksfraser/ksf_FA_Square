<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

/**
 * Bulk refresh contract for receiving modules.
 *
 * Split out of PushContracts.php so PSR-4 can resolve it: the autoloader looks
 * for BulkRefreshInterface.php, so declaring it inside PushContracts.php made
 * the interface permanently unresolvable.
 *
 * @BABOK Related: FR-SQUARE-PUSH-HOOKS
 */
interface BulkRefreshInterface
{
    public function refreshAllCustomers(): array;

    public function refreshAllItems(): array;

    public function refreshAllOrders(): array;
}
