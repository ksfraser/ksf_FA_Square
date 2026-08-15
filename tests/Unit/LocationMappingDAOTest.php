<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use ksfraser\FrontAccounting\Square\DAO\LocationMappingDAO;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: FR-04.02 - Location Mapping
 */
class LocationMappingDAOTest extends TestCase
{
    /**
     * @var LocationMappingDAO
     */
    private $dao;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $GLOBALS['__fa_last_sql'] = '';
        $this->dao = new LocationMappingDAO('0_');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(LocationMappingDAO::class, $this->dao);
    }

    public function testGetTableName(): void
    {
        $tableName = $this->dao->getTableName();
        $this->assertStringContainsString('square_location_mappings', $tableName);
    }

    public function testAllLocationsConstantIsAsteriskAll(): void
    {
        $this->assertSame('*ALL*', LocationMappingDAO::ALL_LOCATIONS);
    }

    public function testEnsureTableExistsWhenTablePresent(): void
    {
        $GLOBALS['__fa_result_set']['SHOW TABLES LIKE \'0_square_location_mappings\''] = [
            ['Tables_in_test' => '0_square_location_mappings'],
        ];

        $this->dao->ensureTableExists();

        $this->assertStringNotContainsString('CREATE TABLE', (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testEnsureTableExistsCreatesMissingTable(): void
    {
        $this->dao->ensureTableExists();

        $lastSql = (string)($GLOBALS['__fa_last_sql'] ?? '');
        $this->assertStringContainsString('CREATE TABLE', $lastSql);
        $this->assertStringContainsString('square_location_mappings', $lastSql);
        $this->assertStringContainsString('fa_loc_code', $lastSql);
        $this->assertStringContainsString('square_location_id', $lastSql);
    }

    public function testGetAllFaLocationsReturnsEmptyArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getAllFaLocations();

        $this->assertIsArray($result);
        $this->assertStringContainsString('0_locations', (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetMappingsBySquareLocationReturnsEmptyArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getMappingsBySquareLocation();

        $this->assertIsArray($result);
        $this->assertStringContainsString('square_location_id', (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetAllLocationsMappingReturnsNull(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getAllLocationsMapping();

        $this->assertNull($result);
        $this->assertStringContainsString(LocationMappingDAO::ALL_LOCATIONS, (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetSquareLocationIdReturnsNull(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getSquareLocationId('DEF');

        $this->assertNull($result);
        $this->assertStringContainsString("fa_loc_code = 'DEF'", (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetSquareItemIdReturnsNull(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getSquareItemId(42);

        $this->assertNull($result);
        $this->assertStringContainsString('0_square_tokens', (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetAllMappingsReturnsEmptyArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getAllMappings();

        $this->assertIsArray($result);
    }

    public function testGetQohForLocationsAllLocationsReturnsZero(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getQohForLocations('SH-001', null);

        $this->assertSame(0.0, $result);
        $lastSql = (string)($GLOBALS['__fa_last_sql'] ?? '');
        $this->assertStringContainsString('0_stock_moves', $lastSql);
        $this->assertStringNotContainsString('loc_code IN', $lastSql);
    }

    public function testGetQohForLocationsEmptyLocationsReturnsZero(): void
    {
        $result = $this->dao->getQohForLocations('SH-001', []);

        $this->assertSame(0.0, $result);
        $this->assertSame('', (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetQohForLocationsBuildsLocationFilterSql(): void
    {
        $this->dao->getQohForLocations('SH-001', ['DEF', 'GHI']);

        $lastSql = (string)($GLOBALS['__fa_last_sql'] ?? '');
        $this->assertStringContainsString("stock_id = 'SH-001'", $lastSql);
        $this->assertStringContainsString("loc_code IN", $lastSql);
        $this->assertStringContainsString("'DEF'", $lastSql);
        $this->assertStringContainsString("'GHI'", $lastSql);
        $this->assertStringContainsString('0_voided', $lastSql);
    }

    public function testSetMappingUpsertsMapping(): void
    {
        $result = $this->dao->setMapping('DEF', 'L0001');

        $this->assertTrue($result);
        $lastSql = (string)($GLOBALS['__fa_last_sql'] ?? '');
        $this->assertStringStartsWith('INSERT INTO 0_square_location_mappings', $lastSql);
        $this->assertStringContainsString("'DEF'", $lastSql);
        $this->assertStringContainsString("'L0001'", $lastSql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $lastSql);
    }

    public function testRemoveMappingDeletesMapping(): void
    {
        $result = $this->dao->removeMapping('DEF');

        $this->assertTrue($result);
        $lastSql = (string)($GLOBALS['__fa_last_sql'] ?? '');
        $this->assertStringStartsWith('DELETE FROM 0_square_location_mappings', $lastSql);
        $this->assertStringContainsString("fa_loc_code = 'DEF'", $lastSql);
    }
}
