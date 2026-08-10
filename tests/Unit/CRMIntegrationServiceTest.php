<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit\Services;

use Ksfraser\Frontaccounting\SquareUp\Services\CRMIntegrationService;
use Ksfraser\Frontaccounting\SquareUp\Contracts\CRMAdapterInterface;
use Ksfraser\Frontaccounting\SquareUp\Services\CustomerMatchStrategy;
use Ksfraser\Frontaccounting\SquareUp\DAO\DebtorsMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareCustomerDAO;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\CRMIntegrationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for CRMIntegrationService.
 * 
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 through FR-07.07 - Customer Management
 */
class CRMIntegrationServiceTest extends TestCase
{
    protected MockObject $mockDebtorDao;
    protected MockObject $mockCustomerDao;
    protected MockObject $mockCrmAdapter;
    protected MockObject $mockMatchStrategy;
    protected CRMIntegrationService $crmService;
    protected string $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock DAOs
        $this->mockDebtorDao = $this->createMock(DebtorsMasterDAO::class);
        $this->mockCustomerDao = $this->createMock(SquareCustomerDAO::class);
        
        // Mock CRM adapter
        $this->mockCrmAdapter = $this->createMock(CRMAdapterInterface::class);
        
        // Mock match strategy
        $this->mockMatchStrategy = $this->createMock(CustomerMatchStrategy::class);
        
        // Create CRM integration service
        $this->crmService = new CRMIntegrationService(
            $this->mockDebtorDao,
            $this->mockCustomerDao,
            $this->mockCrmAdapter,
            $this->mockMatchStrategy
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function canSyncCustomerWithCRMSuccessfully(): void
    {
        // Arrange
        $debtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890'
        ];
        
        $squareCustomer = [
            'id' => 'cus_123456',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'john@example.com',
            'phone_number' => '1234567890'
        ];
        
        $expectedCrmData = [
            'contact_id' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'square_customer_id' => 'cus_123456',
            'source' => 'square_integration'
        ];
        
        // Mock CRM adapter
        $this->mockCrmAdapter->expects($this->once())
            ->method('updateContact')
            ->with($this->callback(function ($data) use ($expectedCrmData) {
                foreach ($expectedCrmData as $key => $value) {
                    if (($data[$key] ?? null) !== $value) {
                        return false;
                    }
                }
                return is_string($data['last_sync_at'] ?? null);
            }))
            ->willReturn(true);
        
        // Mock customer DAO update
        $this->mockCustomerDao->expects($this->once())
            ->method('updateMappingBySquareId')
            ->with('cus_123456', $this->callback(function ($data) {
                return is_string($data['crm_sync_at'] ?? null);
            }))
            ->willReturn(true);
        
        // Act
        $result = $this->crmService->syncCustomerWithCRM($debtor, $squareCustomer);
        
        // Assert - should not throw exception
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function syncCustomerWithCRMFailsWithInvalidDebtor(): void
    {
        $this->expectException(CRMIntegrationException::class);
        $this->expectExceptionMessage("Debtor number is required");
        
        // Arrange
        $debtor = [
            'name' => 'John Doe'
            // Missing debtor_no
        ];
        
        $squareCustomer = [
            'id' => 'cus_123456',
            'given_name' => 'John',
            'family_name' => 'Doe'
        ];
        
        // Act
        $this->crmService->syncCustomerWithCRM($debtor, $squareCustomer);
    }

    /**
     * @test
     */
    public function syncCustomerWithCRMFailsWithInvalidSquareCustomer(): void
    {
        $this->expectException(CRMIntegrationException::class);
        $this->expectExceptionMessage("Square customer ID is required");
        
        // Arrange
        $debtor = [
            'debtor_no' => 123,
            'name' => 'John Doe'
        ];
        
        $squareCustomer = [
            // Missing id
            'given_name' => 'John',
            'family_name' => 'Doe'
        ];
        
        // Act
        $this->crmService->syncCustomerWithCRM($debtor, $squareCustomer);
    }

    /**
     * @test
     */
    public function getCRMContactHistorySuccessfully(): void
    {
        // Arrange
        $debtorNo = 123;
        $expectedHistory = [
            [
                'id' => 1,
                'action' => 'update',
                'timestamp' => '2023-01-01 00:00:00',
                'details' => 'Customer updated'
            ]
        ];
        
        // Mock debtor DAO
        $this->mockDebtorDao->expects($this->once())
            ->method('getDebtor')
            ->with($debtorNo)
            ->willReturn(['debtor_no' => $debtorNo, 'name' => 'John Doe']);
        
        // Mock customer DAO
        $this->mockCustomerDao->expects($this->once())
            ->method('getByDebtorNo')
            ->with($debtorNo)
            ->willReturn(['id' => 'cus_123', 'debtor_no' => $debtorNo]);
        
        // Mock CRM adapter
        $this->mockCrmAdapter->expects($this->once())
            ->method('getCustomerHistory')
            ->with('cus_123')
            ->willReturn($expectedHistory);
        
        // Act
        $result = $this->crmService->getCRMContactHistory($debtorNo);
        
        // Assert
        $this->assertEquals($expectedHistory, $result);
    }

    /**
     * @test
     */
    public function getCRMContactHistoryFailsWhenDebtorNotFound(): void
    {
        $this->expectException(CRMIntegrationException::class);
        $this->expectExceptionMessage("Debtor not found: 123");
        
        // Arrange
        $debtorNo = 123;
        
        // Mock debtor DAO returns null
        $this->mockDebtorDao->expects($this->once())
            ->method('getDebtor')
            ->with($debtorNo)
            ->willReturn(null);
        
        // Act
        $this->crmService->getCRMContactHistory($debtorNo);
    }

    /**
     * @test
     */
    public function trackCustomerCommunicationSuccessfully(): void
    {
        // Arrange
        $communication = [
            'debtor_no' => 123,
            'type' => 'email',
            'message' => 'Customer inquiry about order status',
            'timestamp' => '2023-01-01 00:00:00'
        ];
        
        // Mock CRM adapter
        $this->mockCrmAdapter->expects($this->once())
            ->method('trackCommunication')
            ->with($communication)
            ->willReturn(true);
        
        // Act
        $result = $this->crmService->trackCustomerCommunication($communication);
        
        // Assert - should not throw exception
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function trackCustomerCommunicationFailsWithInvalidData(): void
    {
        $this->expectException(CRMIntegrationException::class);
        $this->expectExceptionMessage("Communication debtor_no is required");
        
        // Arrange
        $communication = [
            'type' => 'email',
            'message' => 'Customer inquiry',
            'timestamp' => '2023-01-01 00:00:00'
            // Missing debtor_no
        ];
        
        // Act
        $this->crmService->trackCustomerCommunication($communication);
    }

    /**
     * @test
     */
    public function syncCustomerToSquareWithExistingMatch(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123456',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'john@example.com',
            'phone_number' => '1234567890',
            'address_line_1' => '123 Main St',
            'locality' => 'New York',
            'country' => 'US'
        ];
        
        $matchedDebtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890'
        ];
        
        // Mock match strategy returns existing debtor
        $this->mockMatchStrategy->expects($this->once())
            ->method('match')
            ->with($squareCustomer)
            ->willReturn($matchedDebtor);
        
        // Mock debtor DAO update
        $this->mockDebtorDao->expects($this->once())
            ->method('updateDebtor')
            ->with(123, $this->callback(function($data) {
                return $data['address'] === '123 Main St, New York, US';
            }))
            ->willReturn(true);
        
        // Mock customer DAO update
        $this->mockCustomerDao->expects($this->once())
            ->method('updateMappingBySquareId')
            ->with('cus_123456', $this->callback(function ($data) {
                return $data['fa_debtor_no'] === 123
                    && is_string($data['sync_at'] ?? null);
            }))
            ->willReturn(true);
        
        // Act
        $result = $this->crmService->syncCustomerToSquare($squareCustomer);
        
        // Assert
        $this->assertEquals(123, $result['debtor_no']);
        $this->assertEquals('John Doe', $result['name']);
    }

    /**
     * @test
     */
    public function syncCustomerToSquareCreatesNewDebtor(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123456',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'john@example.com',
            'phone_number' => '1234567890'
        ];
        
        // Mock match strategy returns null (no match)
        $this->mockMatchStrategy->expects($this->once())
            ->method('match')
            ->with($squareCustomer)
            ->willReturn(null);
        
        // Mock debtor DAO insert
        $expectedDebtorData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'debtor_ref' => 'square_cus_123456'
        ];
        
        $this->mockDebtorDao->expects($this->once())
            ->method('insertDebtor')
            ->with($this->callback(function($data) {
                return $data['name'] === 'John Doe'
                    && $data['email'] === 'john@example.com'
                    && $data['debtor_ref'] === 'square_cus_123456'
                    && $data['sales_type'] === 1
                    && !isset($data['zip'])
                    && !isset($data['category_id'])
                    && !isset($data['ref'])
                    && !isset($data['created_at'])
                    && !isset($data['updated_at']);
            }))
            ->willReturn(123);
        
        // Mock customer DAO insert
        $this->mockCustomerDao->expects($this->once())
            ->method('insertMapping')
            ->with($this->callback(function ($data) {
                return $data['fa_debtor_no'] === 123
                    && $data['square_customer_id'] === 'cus_123456'
                    && $data['sync_direction'] === 'square_to_fa'
                    && is_string($data['sync_at'] ?? null);
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->crmService->syncCustomerToSquare($squareCustomer);
        
        // Assert
        $this->assertEquals(123, $result['debtor_no']);
        $this->assertEquals('John Doe', $result['name']);
    }

    /**
     * @test
     */
    public function syncCustomerToSquareFailsWithInvalidEmail(): void
    {
        $this->expectException(CRMIntegrationException::class);
        $this->expectExceptionMessage("Invalid email format: invalid-email");
        
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123456',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'invalid-email' // Invalid email format
        ];
        
        // Act
        $this->crmService->syncCustomerToSquare($squareCustomer);
    }

    /**
     * @test
     */
    public function onlyUpdatesChangedFields(): void
    {
        // Arrange
        $existingDebtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'address' => '123 Main St, New York, NY, 10001, US'
        ];
        
        $squareCustomer = [
            'id' => 'cus_123456',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'john@example.com', // Same as existing
            'phone_number' => '1234567890', // Same as existing
            'address_line_1' => '123 Main St', // Same as existing
            'locality' => 'New York', // Same as existing
            'administrative_district_level_1' => 'NY', // Same as existing
            'postal_code' => '10001', // Same as existing
            'country' => 'US' // Same as existing
        ];
        
        // Mock match strategy returns existing debtor
        $this->mockMatchStrategy->expects($this->once())
            ->method('match')
            ->with($squareCustomer)
            ->willReturn($existingDebtor);
        
        // Mock debtor DAO should not be called since no changes
        $this->mockDebtorDao->expects($this->never())
            ->method('updateDebtor');
        
        // Mock customer DAO update mapping
        $this->mockCustomerDao->expects($this->once())
            ->method('updateMappingBySquareId')
            ->with('cus_123456', $this->callback(function ($data) {
                return $data['fa_debtor_no'] === 123
                    && is_string($data['sync_at'] ?? null);
            }))
            ->willReturn(true);
        
        // Act
        $result = $this->crmService->syncCustomerToSquare($squareCustomer);
        
        // Assert
        $this->assertEquals(123, $result['debtor_no']);
        // Verify no update was made to debtor
        $this->assertEquals($existingDebtor, $result);
    }

    /**
     * @test
     */
    public function syncCustomerWithCRMHandlesCRMAdapterError(): void
    {
        $this->expectException(CRMIntegrationException::class);
        $this->expectExceptionMessage("CRM sync failed: CRM adapter error");
        
        // Arrange
        $debtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ];
        
        $squareCustomer = [
            'id' => 'cus_123456',
            'given_name' => 'John',
            'family_name' => 'Doe'
        ];
        
        // Mock CRM adapter throws exception
        $this->mockCrmAdapter->expects($this->once())
            ->method('updateContact')
            ->willThrowException(new \Exception("CRM adapter error"));
        
        // Act
        $this->crmService->syncCustomerWithCRM($debtor, $squareCustomer);
    }
}