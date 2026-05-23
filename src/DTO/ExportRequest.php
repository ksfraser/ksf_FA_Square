<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DTO;

/**
 * Data Transfer Object for export form request data.
 * 
 * This class encapsulates all the form fields from the export page,
 * providing type safety and a clean interface for the business logic.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class ExportRequest
{
    private string $action;
    private string $locationId;
    private int $category;
    private string $stockLike;
    private bool $uploadImages;
    private bool $availableOnline;
    private int $maxItems;
    private bool $sendInactive;
    private bool $fullSync;
    private bool $sortRecent;
    private string $currency;
    private int $salesType;

    public function __construct(
        string $action = '',
        string $locationId = '0',
        int $category = -1,
        string $stockLike = '',
        bool $uploadImages = false,
        bool $availableOnline = false,
        int $maxItems = 10,
        bool $sendInactive = false,
        bool $fullSync = false,
        bool $sortRecent = false,
        string $currency = '',
        int $salesType = 0
    ) {
        $this->action = $action;
        $this->locationId = $locationId;
        $this->category = $category;
        $this->stockLike = $stockLike;
        $this->uploadImages = $uploadImages;
        $this->availableOnline = $availableOnline;
        $this->maxItems = $maxItems > 0 ? $maxItems : 10;
        $this->sendInactive = $sendInactive;
        $this->fullSync = $fullSync;
        $this->sortRecent = $sortRecent;
        $this->currency = $currency;
        $this->salesType = $salesType;
    }

    /**
     * Creates an ExportRequest from an array of data (e.g., $_POST).
     * 
     * @param array $data Array of input data
     * @param string $defaultCurrency Default currency if not provided
     * @param int $defaultSalesType Default sales type if not provided
     * @return self
     */
    public static function fromArray(
        array $data,
        string $defaultCurrency = '',
        int $defaultSalesType = 0
    ): self {
        return new self(
            $data['action'] ?? '',
            $data['location_id'] ?? '0',
            isset($data['category']) ? (int)$data['category'] : -1,
            $data['stocklike'] ?? '',
            isset($data['upload']) ? (int)$data['upload'] === 1 : false,
            isset($data['online']) ? (int)$data['online'] === 1 : false,
            isset($data['max_items']) ? (int)$data['max_items'] : 10,
            isset($data['send_inactive']) ? (int)$data['send_inactive'] === 1 : false,
            isset($data['full_sync']) ? (int)$data['full_sync'] === 1 : false,
            !empty($data['sort_recent']),
            $data['currency'] ?? $defaultCurrency,
            isset($data['sales_type']) ? (int)$data['sales_type'] : $defaultSalesType
        );
    }

    /**
     * Creates an ExportRequest from the $_POST superglobal.
     * 
     * @param string $defaultCurrency Default currency if not provided
     * @param int $defaultSalesType Default sales type if not provided
     * @return self
     */
    public static function fromPost(
        string $defaultCurrency = '',
        int $defaultSalesType = 0
    ): self {
        return self::fromArray($_POST, $defaultCurrency, $defaultSalesType);
    }

    /**
     * Converts the DTO back to an array.
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'location_id' => $this->locationId,
            'category' => $this->category,
            'stocklike' => $this->stockLike,
            'upload' => $this->uploadImages ? 1 : 0,
            'online' => $this->availableOnline ? 1 : 0,
            'max_items' => $this->maxItems,
            'send_inactive' => $this->sendInactive ? 1 : 0,
            'full_sync' => $this->fullSync ? 1 : 0,
            'sort_recent' => $this->sortRecent,
            'currency' => $this->currency,
            'sales_type' => $this->salesType,
        ];
    }

    /**
     * Checks if this is an export action request.
     * 
     * @return bool
     */
    public function isExportAction(): bool
    {
        return $this->action === 'i_export';
    }

    // Getters
    public function getAction(): string
    {
        return $this->action;
    }

    public function getLocationId(): string
    {
        return $this->locationId;
    }

    public function getCategory(): int
    {
        return $this->category;
    }

    public function getCategoryId(): ?int
    {
        return $this->category > 0 ? $this->category : null;
    }

    public function getStockLike(): string
    {
        return $this->stockLike;
    }

    public function shouldUploadImages(): bool
    {
        return $this->uploadImages;
    }

    public function isAvailableOnline(): bool
    {
        return $this->availableOnline;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function shouldSendInactive(): bool
    {
        return $this->sendInactive;
    }

    public function shouldExcludeInactive(): bool
    {
        return !$this->sendInactive;
    }

    public function isFullSync(): bool
    {
        return $this->fullSync;
    }

    public function shouldSortRecent(): bool
    {
        return $this->sortRecent;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSalesType(): int
    {
        return $this->salesType;
    }
}
