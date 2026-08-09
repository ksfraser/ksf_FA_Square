<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit\Services;

use Ksfraser\Frontaccounting\SquareUp\Services\CustomerService;
use Ksfraser\Frontaccounting\SquareUp\DAO\DebtorsMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareCustomerDAO;
use Square\SquareClient;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\CustomerSyncException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\CustomerNotFoundException;
use PHPUnit\Framework\TestCase;
use Square\Models\Customer;
use Square\Models\CreateCustomerRequest;
use Square\Models\UpdateCustomerRequest;
use Square\Models\Address;
use Square\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for CustomerService.
 * 
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-04.01 through FR-04.08 - Customer Management
 */
class CustomerServiceTest extends TestCase
{
    protected MockObject $mockSquareClient;
    protected MockObject $mockDebtorDao;
    protected MockObject $mockSquareCustomerDao;
    protected CustomerService $customerService;
    protected string $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Square client
        $this->mockSquareClient = $this->createMock(\Square\SquareClient::class);
        
        // Mock debtor DAO
        $this->mockDebtorDao = $this->createMock(DebtorsMasterDAO::class);
        
        // Mock square customer DAO
        $this->mockSquareCustomerDao = $this->createMock(SquareCustomerDAO::class);
        
        // Create customer service
        $this->customerService = new CustomerService(
            $this->mockSquareClient,
            $this->mockDebtorDao,
            $this->mockSquareCustomerDao
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function canSyncCustomerFromFASuccessfully(): void
    {
        // Arrange
        $debtorData = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'address1' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
            'country' => 'US'
        ];
        
        // Mock existing customer not found - search returns no customers
        $mockSearchResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockSearchResponse->method('isSuccess')->willReturn(true);
        $mockSearchResult = $this->createMock(\Square\Models\SearchCustomersResponse::class);
        $mockSearchResult->method('getCustomers')->willReturn(null);
        $mockSearchResponse->method('getResult')->willReturn($mockSearchResult);
        
        $mockApi = $this->createMock(\Square\Apis\CustomersApi::class);
        $mockApi->method('searchCustomers')->willReturn($mockSearchResponse);
        $this->mockSquareClient->method('getCustomersApi')->willReturn($mockApi);
        
        // Mock customer creation
        $mockCustomer = new Customer();
        $mockCustomer->setId('cus_123456');
        $mockCustomer->setGivenName('John');
        $mockCustomer->setFamilyName('Doe');
        $mockCustomer->setEmailAddress('john@example.com');
        $mockCustomer->setPhoneNumber('1234567890');
        
        $mockCreateResponse = $this->createMock(\Square\Models\CreateCustomerResponse::class);
        $mockCreateResponse->method('getCustomer')->willReturn($mockCustomer);

        $mockResult = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult->method('isSuccess')->willReturn(true);
        $mockResult->method('getResult')->willReturn($mockCreateResponse);
        
        $mockApi->method('createCustomer')->willReturn($mockResult);
        
        // Mock DAO operations
        $this->mockSquareCustomerDao->expects($this->once())
            ->method('insertMapping')
            ->with($this->callback(function ($data) {
                return $data['fa_debtor_no'] === 123
                    && $data['square_customer_id'] === 'cus_123456'
                    && is_string($data['sync_at'] ?? null);
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->customerService->syncCustomerFromFA($debtorData);
        
        // Assert
        $this->assertInstanceOf(Customer::class, $result);
        $this->assertEquals('cus_123456', $result->getId());
        $this->assertEquals('John', $result->getGivenName());
        $this->assertEquals('Doe', $result->getFamilyName());
    }

    /**
     * @test
     */
    public function syncCustomerFromFAFailsWithInvalidData(): void
    {
        $this->expectException(CustomerSyncException::class);
        $this->expectExceptionMessage("Customer name is required");
        
        // Arrange
        $debtorData = [
            'email' => 'john@example.com'
            // Missing name
        ];
        
        // Act
        $this->customerService->syncCustomerFromFA($debtorData);
    }

    /**
     * @test
     */
    public function syncCustomerFromFAFailsWithNoContactInfo(): void
    {
        $this->expectException(CustomerSyncException::class);
        $this->expectExceptionMessage("Either email or phone is required for customer sync");
        
        // Arrange
        $debtorData = [
            'name' => 'John Doe'
            // Missing email and phone
        ];
        
        // Act
        $this->customerService->syncCustomerFromFA($debtorData);
    }

    /**
     * @test
     */
    public function canSyncCustomerToSquareSuccessfully(): void
    {
        // Arrange
        $mockSquareCustomer = new Customer();
        $mockSquareCustomer->setId('cus_123456');
        $mockSquareCustomer->setGivenName('John');
        $mockSquareCustomer->setFamilyName('Doe');
        $mockSquareCustomer->setEmailAddress('john@example.com');
        $mockSquareCustomer->setPhoneNumber('1234567890');
        
        // Mock existing debtor not found
        $this->mockDebtorDao->method('getByEmail')
            ->with('john@example.com')
            ->willReturn(null);
        
        // Mock debtor creation
        $this->mockDebtorDao->expects($this->once())
            ->method('insertDebtor')
            ->with([
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'phone' => '1234567890',
                'ref' => 'square_cus_123456',
            ])
            ->willReturn(123);
        
        // Mock mapping creation
        $this->mockSquareCustomerDao->expects($this->once())
            ->method('insertMapping')
            ->with($this->callback(function ($data) {
                return $data['fa_debtor_no'] === 123
                    && $data['square_customer_id'] === 'cus_123456'
                    && is_string($data['sync_at'] ?? null);
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->customerService->syncCustomerToSquare($mockSquareCustomer);
        
        // Assert
        $this->assertIsArray($result);
        $this->assertEquals(123, $result['debtor_no']);
        $this->assertEquals('John Doe', $result['name']);
        $this->assertEquals('john@example.com', $result['email']);
    }

    /**
     * @test
     */
    public function canFindCustomerByEmailSuccessfully(): void
    {
        // Arrange
        $email = 'john@example.com';
        $mockCustomer = new Customer();
        $mockCustomer->setId('cus_123456');
        $mockCustomer->setGivenName('John');
        $mockCustomer->setEmailAddress('email');
        
        // Mock API response
        $mockApi = $this->createMock(\Square\Apis\CustomersApi::class);
        $mockResult = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult->method('isSuccess')->willReturn(true);
        $mockSearchResult = $this->createMock(\Square\Models\SearchCustomersResponse::class);
        $mockSearchResult->method('getCustomers')->willReturn([$mockCustomer]);
        $mockResult->method('getResult')->willReturn($mockSearchResult);
        
        $mockApi->method('searchCustomers')->willReturn($mockResult);
        
        $this->mockSquareClient->method('getCustomersApi')->willReturn($mockApi);
        
        // Act
        $result = $this->customerService->findCustomerByEmail($email);
        
        // Assert
        $this->assertInstanceOf(Customer::class, $result);
        $this->assertEquals('cus_123456', $result->getId());
    }

    /**
     * @test
     */
    public function findCustomerByEmailReturnsNullWhenNotFound(): void
    {
        // Arrange
        $email = 'nonexistent@example.com';
        
        // Mock API response with no customers
        $mockApi = $this->createMock(\Square\Apis\CustomersApi::class);
        $mockResult = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult->method('isSuccess')->willReturn(true);
        $mockSearchResult = $this->createMock(\Square\Models\SearchCustomersResponse::class);
        $mockSearchResult->method('getCustomers')->willReturn([]);
        $mockResult->method('getResult')->willReturn($mockSearchResult);
        
        $mockApi->method('searchCustomers')->willReturn($mockResult);
        
        $this->mockSquareClient->method('getCustomersApi')->willReturn($mockApi);
        
        // Act
        $result = $this->customerService->findCustomerByEmail($email);
        
        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function canMatchCustomerByEmail(): void
    {
        // Arrange
        $email = 'john@example.com';
        $phone = '1234567890';
        $matchedDebtor = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ];
        
        // Mock debtor found by email
        $this->mockDebtorDao->method('getByEmail')
            ->with($email)
            ->willReturn($matchedDebtor);
        
        // Act
        $result = $this->customerService->matchCustomer($email, $phone);
        
        // Assert
        $this->assertIsArray($result);
        $this->assertEquals(123, $result['debtor_no']);
        $this->assertEquals('John Doe', $result['name']);
    }

    /**
     * @test
     */
    public function matchCustomerReturnsNullWhenNoMatch(): void
    {
        // Arrange
        $email = 'nonexistent@example.com';
        $phone = '1234567890';
        
        // Mock debtor not found
        $this->mockDebtorDao->method('getByEmail')
            ->with($email)
            ->willReturn(null);
        
        $this->mockDebtorDao->method('getByPhone')
            ->with($phone)
            ->willReturn(null);
        
        // Act
        $result = $this->customerService->matchCustomer($email, $phone);
        
        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function canGetAllCustomers(): void
    {
        // Arrange
        $mockCustomer1 = new Customer();
        $mockCustomer1->setId('cus_123');
        
        $mockCustomer2 = new Customer();
        $mockCustomer2->setId('cus_456');
        
        $mockApi = $this->createMock(\Square\Apis\CustomersApi::class);
        $mockResult = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult->method('isSuccess')->willReturn(true);
        $mockListResult = $this->createMock(\Square\Models\ListCustomersResponse::class);
        $mockListResult->method('getCustomers')->willReturn([$mockCustomer1, $mockCustomer2]);
        $mockResult->method('getResult')->willReturn($mockListResult);
        
        $mockApi->method('listCustomers')->willReturn($mockResult);
        
        $this->mockSquareClient->method('getCustomersApi')->willReturn($mockApi);
        
        // Act
        $result = $this->customerService->getAllCustomers();
        
        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Customer::class, $result[0]);
        $this->assertEquals('cus_123', $result[0]->getId());
    }

    /**
     * @test
     */
    public function getAllCustomersFailsWithApiError(): void
    {
        $this->expectException(CustomerSyncException::class);
        $this->expectExceptionMessage("Square API error listing customers");
        
        // Arrange
        $mockApi = $this->createMock(\Square\Apis\CustomersApi::class);
        $mockRequest = $this->createMock(\Square\Http\HttpRequest::class);
        $mockApi->method('listCustomers')
            ->willThrowException(new ApiException("API error", $mockRequest, null));
        
        $this->mockSquareClient->method('getCustomersApi')->willReturn($mockApi);
        
        // Act
        $this->customerService->getAllCustomers();
    }

    /**
     * @test
     */
    public function canExtractNamePartsCorrectly(): void
    {
        // Test given name extraction
        $debtorData = [
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe'
        ];
        
        $givenName = $this->invokeMethod($this->customerService, 'extractName', [$debtorData, 'given_name']);
        $familyName = $this->invokeMethod($this->customerService, 'extractName', [$debtorData, 'family_name']);
        
        $this->assertEquals('John', $givenName);
        $this->assertEquals('Doe', $familyName);
    }

    /**
     * @test
     */
    public function canBuildAddressFromDebtorData(): void
    {
        // Arrange
        $debtorData = [
            'address1' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
            'country' => 'US'
        ];
        
        // Act
        $address = $this->invokeMethod($this->customerService, 'buildAddress', [$debtorData]);
        
        // Assert
        $this->assertInstanceOf(Address::class, $address);
        $this->assertEquals('123 Main St', $address->getAddressLine1());
        $this->assertEquals('New York', $address->getLocality());
        $this->assertEquals('NY', $address->getAdministrativeDistrictLevel1());
        $this->assertEquals('10001', $address->getPostalCode());
        $this->assertEquals('US', $address->getCountry());
    }

    /**
     * Helper method to invoke private methods
     */
    private function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}