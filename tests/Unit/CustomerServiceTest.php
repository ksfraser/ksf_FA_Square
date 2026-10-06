<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services;

use ksfraser\FrontAccounting\Square\Services\CustomerService;
use ksfraser\FrontAccounting\Square\DAO\DebtorsMasterDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareCustomerDAO;
use Square\SquareClient;
use ksfraser\FrontAccounting\Square\Exceptions\CustomerSyncException;
use ksfraser\FrontAccounting\Square\Exceptions\CustomerNotFoundException;
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

        // Reset the FA hook doubles. These are globals, so without this a
        // scripted responder reply leaks into the next test and a test can
        // pass for the wrong reason.
        $GLOBALS['ksf_test_broadcasts'] = [];
        $GLOBALS['ksf_test_invocations'] = [];
        $GLOBALS['ksf_test_invoke_returns'] = [];
        $GLOBALS['ksf_test_invoke_writes'] = [];
        
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
        $result = $this->customerService->syncCustomerFromFAToSquare($debtorData);
        
        // Assert
        $this->assertInstanceOf(Customer::class, $result);
        $this->assertEquals('cus_123456', $result->getId());
        $this->assertEquals('John', $result->getGivenName());
        $this->assertEquals('Doe', $result->getFamilyName());
    }

    /**
     * @test
     */
    public function syncCustomerFromFAToSquareFailsWithInvalidData(): void
    {
        $this->expectException(CustomerSyncException::class);
        $this->expectExceptionMessage("Customer name is required");
        
        // Arrange
        $debtorData = [
            'email' => 'john@example.com'
            // Missing name
        ];
        
        // Act
        $this->customerService->syncCustomerFromFAToSquare($debtorData);
    }

    /**
     * @test
     */
    public function syncCustomerFromFAToSquareFailsWithNoContactInfo(): void
    {
        $this->expectException(CustomerSyncException::class);
        $this->expectExceptionMessage("Either email or phone is required for customer sync");
        
        // Arrange
        $debtorData = [
            'name' => 'John Doe'
            // Missing email and phone
        ];
        
        // Act
        $this->customerService->syncCustomerFromFAToSquare($debtorData);
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

        // A new Square customer is never written to the FA debtor table by
        // Square. It is handed to Import Staging as a StagingCustomer DTO via
        // hook_invoke('ksf_FA_ImportStagingProcessing', 'STAGE_ENTITY', $dto),
        // so the debtor DAO and the Square mapping DAO must stay untouched.
        $this->mockDebtorDao->expects($this->never())
            ->method('insertDebtor');
        $this->mockSquareCustomerDao->expects($this->never())
            ->method('insertMapping');

        // Script ISU's reply. ISU replaces $data wholesale (it receives a DTO), so
        // the double writes back a response array rather than adding a key.
        $GLOBALS['ksf_test_invocations'] = [];
        $GLOBALS['ksf_test_invoke_writes']['ksf_FA_ImportStagingProcessing::STAGE_ENTITY'] = [
            'success' => true,
            'result' => [
                'id' => 77,
                'stagingId' => 77,
                'status' => 'staged',
                '_event' => 'ENTITY_STAGED',
                '_module' => 'ksf_FA_ImportStagingProcessing',
                '_dto_type' => 'StagingCustomer',
            ],
        ];

        // Act
        $result = $this->customerService->syncCustomerFromSquareToFA($mockSquareCustomer);

        // Assert: a staging reference, not a fabricated debtor
        $this->assertIsArray($result);
        $this->assertEquals('staged', $result['status']);
        $this->assertEquals('square', $result['source']);
        $this->assertEquals('cus_123456', $result['source_customer_id']);
        $this->assertEquals('john@example.com', $result['email']);
        $this->assertEquals(77, $result['staging_id']);
        $this->assertNull($result['fa_debtor_no'], 'staging must not report a debtor_no');

        // The DTO must have crossed the boundary as a DTO instance, not an array.
        $invocation = null;
        foreach ($GLOBALS['ksf_test_invocations'] as $call) {
            if ($call[1] === 'STAGE_ENTITY') {
                $invocation = $call;
            }
        }
        $this->assertNotNull($invocation, 'STAGE_ENTITY was not invoked');
        $this->assertEquals('ksf_FA_ImportStagingProcessing', $invocation[0]);
        $this->assertInstanceOf(
            \Ksfraser\StagingDto\StagingCustomer::class,
            $invocation[2],
            'ISU requires a StagingEntity DTO instance'
        );

        $dto = $invocation[2];
        $this->assertEquals('square', $dto->getSource());
        $this->assertEquals('cus_123456', $dto->getSourceId());
        $this->assertEquals('John', $dto->getFirstName());
        $this->assertEquals('Doe', $dto->getLastName());
        $this->assertEquals('john@example.com', $dto->getEmail());
        $this->assertEquals('1234567890', $dto->getPhone());
    }

    /**
     * @BABOK Related: UT-SQUARE-004-001-002
     */
    public function testNewSquareCustomerIsStagedNotFabricatedAsDebtor(): void
    {
        $mockSquareCustomer = $this->createMock(Customer::class);
        $mockSquareCustomer->method('getId')->willReturn('cus_999');
        $mockSquareCustomer->method('getGivenName')->willReturn('Ada');
        $mockSquareCustomer->method('getFamilyName')->willReturn('Lovelace');
        $mockSquareCustomer->method('getEmailAddress')->willReturn('ada@example.com');
        $mockSquareCustomer->method('getPhoneNumber')->willReturn('');

        $this->mockDebtorDao->method('getByEmail')->willReturn(null);
        $this->mockDebtorDao->expects($this->never())->method('insertDebtor');

        // No ISU responder is scripted, so the call cannot silently succeed.
        // The old implementation returned a debtor-shaped array (name/email/
        // debtor_ref) implying an FA row existed; that must now be impossible.
        $this->expectException(CustomerSyncException::class);

        $this->customerService->syncCustomerFromSquareToFA($mockSquareCustomer);
    }

    /**
     * A Square customer must never be reported as an FA debtor before review.
     *
     * @BABOK Related: UT-SQUARE-004-001-003
     */
    public function testStagedResultNeverContainsDebtorFields(): void
    {
        $mockSquareCustomer = $this->createMock(Customer::class);
        $mockSquareCustomer->method('getId')->willReturn('cus_777');
        $mockSquareCustomer->method('getGivenName')->willReturn('Grace');
        $mockSquareCustomer->method('getFamilyName')->willReturn('Hopper');
        $mockSquareCustomer->method('getEmailAddress')->willReturn('grace@example.com');
        $mockSquareCustomer->method('getPhoneNumber')->willReturn('');

        $this->mockDebtorDao->method('getByEmail')->willReturn(null);

        $GLOBALS['ksf_test_invoke_writes']['ksf_FA_ImportStagingProcessing::STAGE_ENTITY'] = [
            'success' => true,
            'result' => ['id' => 12, 'stagingId' => 12],
        ];

        $result = $this->customerService->syncCustomerFromSquareToFA($mockSquareCustomer);

        // Guard against the old fabricated-debtor shape regressing back in.
        $this->assertArrayNotHasKey('debtor_ref', $result);
        $this->assertArrayNotHasKey('name', $result);
        $this->assertArrayNotHasKey('phone', $result);
        $this->assertNull($result['fa_debtor_no']);
        $this->assertEquals(12, $result['staging_id']);
    }

    /**
     * ISU rejecting the payload must surface as a CustomerSyncException.
     *
     * @BABOK Related: UT-SQUARE-004-001-004
     */
    public function testStagingErrorFromIsuBecomesCustomerSyncException(): void
    {
        $mockSquareCustomer = $this->createMock(Customer::class);
        $mockSquareCustomer->method('getId')->willReturn('cus_555');
        $mockSquareCustomer->method('getGivenName')->willReturn('Alan');
        $mockSquareCustomer->method('getFamilyName')->willReturn('Turing');
        $mockSquareCustomer->method('getEmailAddress')->willReturn('alan@example.com');
        $mockSquareCustomer->method('getPhoneNumber')->willReturn('');

        $this->mockDebtorDao->method('getByEmail')->willReturn(null);

        $GLOBALS['ksf_test_invoke_writes']['ksf_FA_ImportStagingProcessing::STAGE_ENTITY'] = [
            'error' => 'Unauthorized',
            'success' => false,
        ];

        $this->expectException(CustomerSyncException::class);
        $this->expectExceptionMessage('Import Staging rejected customer: Unauthorized');

        $this->customerService->syncCustomerFromSquareToFA($mockSquareCustomer);
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