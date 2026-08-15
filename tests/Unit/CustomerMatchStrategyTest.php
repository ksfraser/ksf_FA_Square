<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services;

use ksfraser\FrontAccounting\Square\Services\CustomerMatchStrategy;
use ksfraser\FrontAccounting\Square\Contracts\CRMAdapterInterface;
use ksfraser\FrontAccounting\Square\DAO\DebtorsMasterDAO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for CustomerMatchStrategy.
 * 
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-07.03 - Customer deduplication matching
 */
class CustomerMatchStrategyTest extends TestCase
{
    protected MockObject $mockCrmAdapter;
    protected MockObject $mockDebtorDao;
    protected CustomerMatchStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockCrmAdapter = $this->createMock(CRMAdapterInterface::class);
        $this->mockDebtorDao = $this->createMock(DebtorsMasterDAO::class);

        $this->strategy = new CustomerMatchStrategy(
            $this->mockCrmAdapter,
            $this->mockDebtorDao
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function matchReturnsDebtorWhenEmailMatches(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'john@example.com',
            'phone_number' => '1234567890'
        ];

        $debtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890'
        ];

        $this->mockDebtorDao->expects($this->once())
            ->method('getByEmail')
            ->with('john@example.com')
            ->willReturn($debtor);

        $this->mockDebtorDao->expects($this->never())
            ->method('getByPhone');

        // Act
        $result = $this->strategy->match($squareCustomer);

        // Assert
        $this->assertEquals($debtor, $result);
    }

    /**
     * @test
     */
    public function matchFallsBackToPhoneWhenEmailHasNoMatch(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'john@example.com',
            'phone_number' => '1234567890'
        ];

        $debtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'phone' => '1234567890'
        ];

        $this->mockDebtorDao->expects($this->once())
            ->method('getByEmail')
            ->with('john@example.com')
            ->willReturn(null);

        $this->mockDebtorDao->expects($this->once())
            ->method('getByPhone')
            ->with('1234567890')
            ->willReturn($debtor);

        // Act
        $result = $this->strategy->match($squareCustomer);

        // Assert
        $this->assertEquals($debtor, $result);
    }

    /**
     * @test
     */
    public function matchFallsBackToNameWhenNoEmailOrPhone(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123',
            'given_name' => 'John',
            'family_name' => 'Doe'
        ];

        $debtor = [
            'debtor_no' => 123,
            'name' => 'John Doe'
        ];

        $this->mockDebtorDao->expects($this->never())
            ->method('getByEmail');

        $this->mockDebtorDao->expects($this->never())
            ->method('getByPhone');

        $this->mockDebtorDao->expects($this->once())
            ->method('getByName')
            ->with('John Doe')
            ->willReturn([$debtor]);

        // Act
        $result = $this->strategy->match($squareCustomer);

        // Assert
        $this->assertEquals($debtor, $result);
    }

    /**
     * @test
     */
    public function matchReturnsNullWhenNameIsAmbiguous(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123',
            'given_name' => 'John',
            'family_name' => 'Doe'
        ];

        $this->mockDebtorDao->expects($this->never())
            ->method('getByEmail');

        $this->mockDebtorDao->expects($this->never())
            ->method('getByPhone');

        $this->mockDebtorDao->expects($this->once())
            ->method('getByName')
            ->with('John Doe')
            ->willReturn([
                ['debtor_no' => 123, 'name' => 'John Doe'],
                ['debtor_no' => 456, 'name' => 'John Doe']
            ]);

        // Act
        $result = $this->strategy->match($squareCustomer);

        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function matchReturnsNullWhenNoCriteriaMatch(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'nobody@example.com',
            'phone_number' => '0000000000'
        ];

        $this->mockDebtorDao->expects($this->once())
            ->method('getByEmail')
            ->with('nobody@example.com')
            ->willReturn(null);

        $this->mockDebtorDao->expects($this->once())
            ->method('getByPhone')
            ->with('0000000000')
            ->willReturn(null);

        $this->mockDebtorDao->expects($this->once())
            ->method('getByName')
            ->with('John Doe')
            ->willReturn([]);

        // Act
        $result = $this->strategy->match($squareCustomer);

        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function matchTrimsWhitespaceFromSquareFields(): void
    {
        // Arrange
        $squareCustomer = [
            'id' => 'cus_123',
            'given_name' => ' John ',
            'family_name' => ' Doe ',
            'email_address' => ' john@example.com ',
            'phone_number' => ' 1234567890 '
        ];

        $debtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890'
        ];

        $this->mockDebtorDao->expects($this->once())
            ->method('getByEmail')
            ->with('john@example.com')
            ->willReturn($debtor);

        // Act
        $result = $this->strategy->match($squareCustomer);

        // Assert
        $this->assertEquals($debtor, $result);
    }
}
