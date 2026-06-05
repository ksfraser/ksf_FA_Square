<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAEntityNotFoundException;

class ProductNotFoundException extends FAEntityNotFoundException
{
    public static function bySku(string $sku): self
    {
        return new self('Product', $sku, 'SquareUp');
    }

    public static function byStockId(string $stockId): self
    {
        return new self('Product', $stockId, 'SquareUp');
    }
}
