<?php
declare(strict_types=1);
namespace ksfraser\FrontAccounting\Square\Contracts;

interface PushContractsInterface
{
    public function pushCatalog(array $catalogData): void;
    public function pushCustomer(array $customerData): void;
    public function pushOrder(array $orderData): void;
    public function pushCoupon(array $couponData): void;
    public function pushSales(array $salesData): void;
    public function pushGiftCard(array $giftCardData): void;
}
