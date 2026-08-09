<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Contracts;

interface SettingsInterface
{
    public function getAccessToken(): ?string;

    public function setAccessToken(string $token): void;

    public function getSandboxAccessToken(): ?string;

    public function setSandboxAccessToken(string $token): void;

    public function getProductionAccessToken(): ?string;

    public function setProductionAccessToken(string $token): void;

    public function getEnvironment(): string;

    public function setEnvironment(string $env): void;

    public function getLastImportDate(): ?\DateTimeInterface;

    public function setLastImportDate(\DateTimeInterface $date): void;

    public function getDestinationCustomer(): ?int;

    public function setDestinationCustomer(int $debtorNo): void;

    public function getDefaultLocation(): ?string;

    public function setDefaultLocation(string $locationId): void;

    public function getDefaultTaxGroup(): ?int;

    public function setDefaultTaxGroup(int $taxGroupId): void;
}
