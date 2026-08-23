<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Integration;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory;
use ksfraser\FrontAccounting\Square\Services\ImportService;
use PHPUnit\Framework\TestCase;

/**
 * Live sandbox integration test for ImportService.
 *
 * Connects to the real Square sandbox API to verify the full import pipeline:
 * Square API → ImportService.stageFromApi() → staging tables.
 *
 * Requires a valid sandbox access token in the database.
 * Skipped automatically if no token is configured.
 *
 * Run with: phpunit --group sandbox tests/Integration/ImportServiceLiveSandboxTest.php
 *
 * @group sandbox
 * @BABOK Related: FR-SI-001 - Live staging from API
 */
class ImportServiceLiveSandboxTest extends TestCase
{
    /** @var Settings */
    private $settings;

    /** @var string */
    private $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';

        try {
            $this->settings = Settings::fromFADatabase($this->tablePrefix);
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot load settings from FA database: ' . $e->getMessage());
        }

        $token = $this->settings->getAccessToken();
        if ($token === null || $token === '') {
            $this->markTestSkipped('No Square access token configured. Set it in Square Configuration first.');
        }

        if ($this->settings->getEnvironment() !== 'sandbox') {
            $this->markTestSkipped('Not in sandbox environment. Skipping live tests for safety.');
        }
    }

    /** @test */
    public function stageFromApiConnectsToListLocations(): void
    {
        $client = SquareClientFactory::create($this->settings);
        $locationsApi = $client->getLocationsApi();
        $response = $locationsApi->listLocations();

        $this->assertTrue($response->isSuccess(), 'listLocations failed: ' . json_encode($response->getErrors()));
        $locations = $response->getResult()->getLocations();
        $this->assertNotEmpty($locations, 'No locations found in sandbox. Create at least one location.');

        $locationMap = [];
        foreach ($locations as $loc) {
            $locationMap[$loc->getId()] = $loc->getName();
        }

        $this->assertNotEmpty($locationMap);
    }

    /** @test */
    public function stageFromApiReturnsValidResultStructure(): void
    {
        $client = SquareClientFactory::create($this->settings);
        $importService = new ImportService($this->tablePrefix, $this->settings, $client);

        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('now');

        $locationsApi = $client->getLocationsApi();
        $locResponse = $locationsApi->listLocations();
        $locations = [];
        if ($locResponse->isSuccess()) {
            foreach ($locResponse->getResult()->getLocations() as $loc) {
                $locations[$loc->getId()] = $loc->getName();
            }
        }

        if (empty($locations)) {
            $this->markTestSkipped('No locations in sandbox');
        }

        $results = $importService->stageFromApi($from, $to, '', $locations);

        $this->assertArrayHasKey('staged', $results);
        $this->assertArrayHasKey('skipped', $results);
        $this->assertArrayHasKey('errors', $results);
        $this->assertArrayHasKey('payments_found', $results);
        $this->assertIsInt($results['staged']);
        $this->assertIsInt($results['skipped']);
        $this->assertIsArray($results['errors']);
        $this->assertIsInt($results['payments_found']);
    }

    /** @test */
    public function stageFromApiIsIdempotent(): void
    {
        $client = SquareClientFactory::create($this->settings);
        $importService = new ImportService($this->tablePrefix, $this->settings, $client);

        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('now');

        $locationsApi = $client->getLocationsApi();
        $locResponse = $locationsApi->listLocations();
        $locations = [];
        if ($locResponse->isSuccess()) {
            foreach ($locResponse->getResult()->getLocations() as $loc) {
                $locations[$loc->getId()] = $loc->getName();
            }
        }

        if (empty($locations)) {
            $this->markTestSkipped('No locations in sandbox');
        }

        $firstRun = $importService->stageFromApi($from, $to, '', $locations);

        $secondRun = $importService->stageFromApi($from, $to, '', $locations);

        if ($firstRun['staged'] > 0) {
            $this->assertSame(0, $secondRun['staged'], 'Second run should stage 0 new transactions');
            $this->assertGreaterThanOrEqual($firstRun['staged'], $secondRun['skipped']);
        }

        $this->assertArrayHasKey('staged', $secondRun);
    }

    /** @test */
    public function stagingTablesContainStagedData(): void
    {
        $client = SquareClientFactory::create($this->settings);
        $importService = new ImportService($this->tablePrefix, $this->settings, $client);
        $importService->ensureStagingTablesExist();

        $counts = $importService->getStagingStatusCounts();

        $this->assertIsArray($counts);

        $total = array_sum($counts);
        if ($total > 0) {
            $this->assertArrayHasKey('staged', $counts);
        }
    }

    /** @test */
    public function getStagedTransactionsReturnsArray(): void
    {
        $client = SquareClientFactory::create($this->settings);
        $importService = new ImportService($this->tablePrefix, $this->settings, $client);
        $importService->ensureStagingTablesExist();

        $transactions = $importService->getStagedTransactions();

        $this->assertIsArray($transactions);

        if (!empty($transactions)) {
            $first = $transactions[0];
            $this->assertArrayHasKey('id', $first);
            $this->assertArrayHasKey('transaction_id', $first);
            $this->assertArrayHasKey('payment_id', $first);
            $this->assertArrayHasKey('Date', $first);
            $this->assertArrayHasKey('total_collected', $first);
            $this->assertArrayHasKey('environment', $first);
            $this->assertArrayHasKey('status', $first);
        }
    }
}
