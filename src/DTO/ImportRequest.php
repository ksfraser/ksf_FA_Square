<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DTO;

/**
 * Data Transfer Object for import form request data.
 * 
 * This class encapsulates all the form fields from the import page,
 * providing type safety and a clean interface for the business logic.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class ImportRequest
{
    /**
     * @var string
     */
    private $action;

    /**
     * @var int
     */
    private $destCust;

    /**
     * @var string
     */
    private $fromDate;

    /**
     * @var string
     */
    private $toDate;

    /**
     * @var bool
     */
    private $trialRun;

    /**
     * @var string
     */
    private $adjustmentItem;

    /**
     * @var string
     */
    private $tipsItem;

    /**
     * @var string
     */
    private $locationFilter;

    public function __construct(
        string $action = '',
        int $destCust = 0,
        string $fromDate = '',
        string $toDate = '',
        bool $trialRun = false,
        string $adjustmentItem = '',
        string $tipsItem = '',
        string $locationFilter = ''
    ) {
        $this->action = $action;
        $this->destCust = $destCust;
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->trialRun = $trialRun;
        $this->adjustmentItem = $adjustmentItem;
        $this->tipsItem = $tipsItem;
        $this->locationFilter = $locationFilter;
    }

    /**
     * Creates an ImportRequest from an array of data (e.g., $_POST).
     * 
     * @param array $data Array of input data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['action'] ?? '',
            isset($data['destCust']) ? (int)$data['destCust'] : 0,
            $data['from_date'] ?? '',
            $data['to_date'] ?? '',
            isset($data['trial_run']) ? (bool)$data['trial_run'] : false,
            $data['adjustment'] ?? '',
            $data['tips'] ?? '',
            $data['location_id'] ?? ''
        );
    }

    /**
     * Creates an ImportRequest from the $_POST superglobal.
     * 
     * @return self
     */
    public static function fromPost(): self
    {
        return self::fromArray($_POST);
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
            'destCust' => $this->destCust,
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'trial_run' => $this->trialRun ? 1 : 0,
            'adjustment' => $this->adjustmentItem,
            'tips' => $this->tipsItem,
            'location_id' => $this->locationFilter,
        ];
    }

    /**
     * Checks if this is an import action request.
     * 
     * @return bool
     */
    public function isImportAction(): bool
    {
        return $this->action === 'o_import';
    }

    /**
     * Validates the import request parameters.
     * 
     * @return array Validation result with 'valid' flag and 'error' message
     */
    public function validate(): array
    {
        if ($this->destCust <= 0) {
            return ['valid' => false, 'error' => _("Please select a destination customer.")];
        }

        if ($this->fromDate === '' || $this->toDate === '') {
            return ['valid' => false, 'error' => _("Please select a date range.")];
        }

        return ['valid' => true, 'error' => ''];
    }

    // Getters
    public function getAction(): string
    {
        return $this->action;
    }

    public function getDestCust(): int
    {
        return $this->destCust;
    }

    public function getFromDate(): string
    {
        return $this->fromDate;
    }

    public function getToDate(): string
    {
        return $this->toDate;
    }

    public function isTrialRun(): bool
    {
        return $this->trialRun;
    }

    public function getAdjustmentItem(): string
    {
        return $this->adjustmentItem;
    }

    public function getTipsItem(): string
    {
        return $this->tipsItem;
    }

    public function getLocationFilter(): string
    {
        return $this->locationFilter;
    }
}
