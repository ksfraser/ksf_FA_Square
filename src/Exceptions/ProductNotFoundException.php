<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAEntityNotFoundException;

class ProductNotFoundException extends FAEntityNotFoundException
{
    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function bySku(string $sku): self
    {
        return new self('Product', $sku, 'SquareUp');
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function byStockId(string $stockId): self
    {
        return new self('Product', $stockId, 'SquareUp');
    }
}
