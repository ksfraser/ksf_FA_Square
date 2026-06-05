<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Ksfraser\Frontaccounting\SquareUp\Infrastructure\SquareClient;
use Ksfraser\Frontaccounting\SquareUp\DAO\DebtorsMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareCustomerDAO;
use Ksfraser\Frontaccounting\SquareUp\Contracts\CustomerServiceInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\CustomerSyncException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\CustomerNotFoundException;
use Square\Models\Customer;
use Square\Models\CreateCustomerRequest;
use Square\Models\UpdateCustomerRequest;
use Square\Models\SearchCustomersRequest;
use Square\Models\Address;
use Square\Exceptions\ApiException;

/**
 * Service for managing bi-directional customer synchronization between Square and FrontAccounting.
 * 
 * Handles customer data mapping, synchronization, and matching.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.01 through FR-04.08 - Customer Management
 */
class CustomerService implements CustomerServiceInterface
{
    /**
     * @var SquareClient
     */
    private $client;

    /**
     * @var DebtorsMasterDAO
     */
    private $debtorDao;

    /**
     * @var SquareCustomerDAO
     */
    private $squareCustomerDao;

    /**
     * @var array
     */
    private $emailFieldMapping = [
        'email' => 'email_address',
        'email_address' => 'email_address',
    ];

    /**
     * @var array
     */
    private $phoneFieldMapping = [
        'phone' => 'phone_number',
        'phone_number' => 'phone_number',
        'mobile' => 'phone_number',
    ];

    /**
     * @var array
     */
    private $nameFieldMapping = [
        'name' => 'given_name',
        'customer_name' => 'given_name',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
        'surname' => 'family_name',
    ];

    public function __construct(
        SquareClient $client, 
        DebtorsMasterDAO $debtorDao, 
        SquareCustomerDAO $squareCustomerDao
    ) {
        $this->client = $client;
        $this->debtorDao = $debtorDao;
        $this->squareCustomerDao = $squareCustomerDao;
    }

    /**
     * Syncs a FrontAccounting debtor to Square customer.
     *
     * @param array $debtor Debtor data from FA
     * @return Customer Created/updated Square customer
     * @throws CustomerSyncException If sync fails
     */
    public function syncCustomerFromFA(array $debtor): Customer
    {
        $this->validateDebtorData($debtor);

        try {
            $api = $this->client->getCustomersApi();
            
            // Check if customer already exists in Square
            $existingCustomer = $this->findSquareCustomerByEmail($debtor['email'] ?? '');
            
            if ($existingCustomer) {
                // Update existing customer
                return $this->updateSquareCustomer($existingCustomer, $debtor);
            } else {
                // Create new customer
                return $this->createSquareCustomer($debtor);
            }
        } catch (ApiException $e) {
            throw new CustomerSyncException(
                "Square API error syncing customer from FA: " . $e->getMessage()
            );
        }
    }

    /**
     * Syncs a Square customer to FrontAccounting debtor.
     *
     * @param Customer $squareCustomer Square customer data
     * @return array Created/updated FA debtor
     * @throws CustomerSyncException If sync fails
     */
    public function syncCustomerToSquare(Customer $squareCustomer): array
    {
        $this->validateSquareCustomerData($squareCustomer);

        try {
            // Check if debtor already exists in FA
            $existingDebtor = $this->findDebtorByEmail($squareCustomer->getEmailAddress());
            
            if ($existingDebtor) {
                // Update existing debtor
                return $this->updateDebtor($existingDebtor, $squareCustomer);
            } else {
                // Create new debtor
                return $this->createDebtor($squareCustomer);
            }
        } catch (\Exception $e) {
            throw new CustomerSyncException(
                "FA error syncing customer from Square: " . $e->getMessage()
            );
        }
    }

    /**
     * Finds a Square customer by email address.
     *
     * @param string $email Email address to search for
     * @return Customer|null
     */
    public function findCustomerByEmail(string $email): ?Customer
    {
        if (empty($email)) {
            return null;
        }

        try {
            $api = $this->client->getCustomersApi();
            
            $request = new SearchCustomersRequest();
            $query = 'email:"' . addslashes($email) . '"';
            $request->setQuery($query);
            
            $result = $api->searchCustomers($request);
            
            if ($result->isSuccess()) {
                $customers = $result->getResult()->getCustomers();
                return !empty($customers) ? $customers[0] : null;
            }
        } catch (ApiException $e) {
            // Log error but don't throw for search failures
            error_log("Error searching for customer by email {$email}: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Matches a Square customer to a FA debtor.
     *
     * @param string $email Email address
     * @param string $phone Phone number (optional)
     * @return array|null Matched FA debtor
     */
    public function matchCustomer(string $email, ?string $phone = null): ?array
    {
        // First try to match by email
        if (!empty($email)) {
            $debtor = $this->findDebtorByEmail($email);
            if ($debtor) {
                return $debtor;
            }
        }

        // Then try to match by phone
        if (!empty($phone)) {
            $debtor = $this->findDebtorByPhone($phone);
            if ($debtor) {
                return $debtor;
            }
        }

        // If no match found, return null
        return null;
    }

    /**
     * Gets all Square customers.
     *
     * @return array Array of Customer objects
     * @throws CustomerSyncException
     */
    public function getAllCustomers(): array
    {
        try {
            $api = $this->client->getCustomersApi();
            $result = $api->listCustomers();
            
            if ($result->isSuccess()) {
                return $result->getResult()->getCustomers() ?? [];
            }
            
            throw new CustomerSyncException("Failed to list customers from Square");
        } catch (ApiException $e) {
            throw new CustomerSyncException(
                "Square API error listing customers: " . $e->getMessage()
            );
        }
    }

    /**
     * Validates debtor data before sync.
     *
     * @param array $debtor Debtor data
     * @throws CustomerSyncException If validation fails
     */
    private function validateDebtorData(array $debtor): void
    {
        if (empty($debtor['name'] ?? '')) {
            throw new CustomerSyncException("Customer name is required");
        }

        if (empty($debtor['email'] ?? '') && empty($debtor['phone'] ?? '')) {
            throw new CustomerSyncException("Either email or phone is required for customer sync");
        }
    }

    /**
     * Validates Square customer data before sync.
     *
     * @param Customer $customer Square customer
     * @throws CustomerSyncException If validation fails
     */
    private function validateSquareCustomerData(Customer $customer): void
    {
        if (empty($customer->getGivenName())) {
            throw new CustomerSyncException("Customer given name is required");
        }
    }

    /**
     * Creates a new Square customer from FA debtor.
     *
     * @param array $debtor Debtor data
     * @return Customer Created customer
     */
    private function createSquareCustomer(array $debtor): Customer
    {
        $api = $this->client->getCustomersApi();
        
        $request = new CreateCustomerRequest([
            'given_name' => $this->extractName($debtor, 'given_name', $debtor['name']),
            'family_name' => $this->extractName($debtor, 'family_name'),
            'email_address' => $debtor['email'] ?? '',
            'phone_number' => $debtor['phone'] ?? '',
            'address' => $this->buildAddress($debtor),
            'reference_id' => 'debtor_' . ($debtor['debtor_no'] ?? ''),
            'note' => 'Synced from FrontAccounting',
        ]);

        $result = $api->createCustomer($request);
        
        if (!$result->isSuccess()) {
            throw new CustomerSyncException(
                "Failed to create Square customer: " . $this->getApiErrorMessage($result->getErrors())
            );
        }

        $customer = $result->getResult()->getCustomer();
        
        // Store customer mapping in our database
        $this->squareCustomerDao->insertMapping([
            'fa_debtor_no' => $debtor['debtor_no'] ?? 0,
            'square_customer_id' => $customer->getId(),
            'sync_at' => date('Y-m-d H:i:s'),
        ]);

        return $customer;
    }

    /**
     * Updates an existing Square customer.
     *
     * @param Customer $existingCustomer Existing customer
     * @param array $debtor Debtor data
     * @return Customer Updated customer
     */
    private function updateSquareCustomer(Customer $existingCustomer, array $debtor): Customer
    {
        $api = $this->client->getCustomersApi();
        
        $request = new UpdateCustomerRequest([
            'given_name' => $this->extractName($debtor, 'given_name', $debtor['name']),
            'family_name' => $this->extractName($debtor, 'family_name'),
            'email_address' => $debtor['email'] ?? '',
            'phone_number' => $debtor['phone'] ?? '',
            'address' => $this->buildAddress($debtor),
            'version' => $existingCustomer->getVersion(),
        ]);

        $result = $api->updateCustomer($existingCustomer->getId(), $request);
        
        if (!$result->isSuccess()) {
            throw new CustomerSyncException(
                "Failed to update Square customer: " . $this->getApiErrorMessage($result->getErrors())
            );
        }

        // Update our mapping
        $this->squareCustomerDao->updateMappingBySquareId($existingCustomer->getId(), [
            'fa_debtor_no' => $debtor['debtor_no'] ?? 0,
            'sync_at' => date('Y-m-d H:i:s'),
        ]);

        return $result->getResult()->getCustomer();
    }

    /**
     * Creates a new FA debtor from Square customer.
     *
     * @param Customer $squareCustomer Square customer
     * @return array Created debtor
     */
    private function createDebtor(Customer $squareCustomer): array
    {
        $debtorData = [
            'name' => trim(($squareCustomer->getGivenName() ?? '') . ' ' . ($squareCustomer->getFamilyName() ?? '')),
            'email' => $squareCustomer->getEmailAddress() ?? '',
            'phone' => $squareCustomer->getPhoneNumber() ?? '',
            'ref' => 'square_' . $squareCustomer->getId(),
        ];

        // Insert new debtor
        $debtorNo = $this->debtorDao->insertDebtor($debtorData);

        // Store mapping
        $this->squareCustomerDao->insertMapping([
            'fa_debtor_no' => $debtorNo,
            'square_customer_id' => $squareCustomer->getId(),
            'sync_at' => date('Y-m-d H:i:s'),
        ]);

        return array_merge($debtorData, ['debtor_no' => $debtorNo]);
    }

    /**
     * Updates an existing FA debtor.
     *
     * @param array $existingDebtor Existing debtor
     * @param Customer $squareCustomer Square customer
     * @return array Updated debtor
     */
    private function updateDebtor(array $existingDebtor, Customer $squareCustomer): array
    {
        $updateData = [
            'name' => trim(($squareCustomer->getGivenName() ?? '') . ' ' . ($squareCustomer->getFamilyName() ?? '')),
            'email' => $squareCustomer->getEmailAddress() ?? '',
            'phone' => $squareCustomer->getPhoneNumber() ?? '',
        ];

        $this->debtorDao->updateDebtor($existingDebtor['debtor_no'], $updateData);

        // Update mapping
        $this->squareCustomerDao->updateMappingBySquareId($squareCustomer->getId(), [
            'sync_at' => date('Y-m-d H:i:s'),
        ]);

        return array_merge($existingDebtor, $updateData);
    }

    /**
     * Finds a FA debtor by email.
     *
     * @param string $email Email address
     * @return array|null
     */
    private function findDebtorByEmail(string $email): ?array
    {
        if (empty($email)) {
            return null;
        }

        // Use existing DAO to find debtor by email
        $result = $this->debtorDao->getByEmail($email);
        return $result !== false ? $result : null;
    }

    /**
     * Finds a FA debtor by phone.
     *
     * @param string $phone Phone number
     * @return array|null
     */
    private function findDebtorByPhone(string $phone): ?array
    {
        if (empty($phone)) {
            return null;
        }

        // Use existing DAO to find debtor by phone
        $result = $this->debtorDao->getByPhone($phone);
        return $result !== false ? $result : null;
    }

    /**
     * Extracts name parts from debtor data.
     *
     * @param array $debtor Debtor data
     * @param string $part Name part to extract (given_name, family_name)
     * @param string $default Default value
     * @return string
     */
    private function extractName(array $debtor, string $part, string $default = ''): string
    {
        // Check for mapped fields first
        foreach ($this->nameFieldMapping as $field => $mappedPart) {
            if ($mappedPart === $part && isset($debtor[$field])) {
                return $debtor[$field];
            }
        }

        // For given_name, use full name if not specified
        if ($part === 'given_name' && empty($debtor[$part])) {
            return $default;
        }

        return $debtor[$part] ?? $default;
    }

    /**
     * Builds Square address from debtor data.
     *
     * @param array $debtor Debtor data
     * @return Address|null
     */
    private function buildAddress(array $debtor): ?Address
    {
        $address = [];
        
        if (!empty($debtor['address1'])) {
            $address['address_line_1'] = $debtor['address1'];
        }
        
        if (!empty($debtor['address2'])) {
            $address['address_line_2'] = $debtor['address2'];
        }
        
        if (!empty($debtor['city'])) {
            $address['locality'] = $debtor['city'];
        }
        
        if (!empty($debtor['state'])) {
            $address['administrative_district_level_1'] = $debtor['state'];
        }
        
        if (!empty($debtor['zip'])) {
            $address['postal_code'] = $debtor['zip'];
        }
        
        if (!empty($debtor['country'])) {
            $address['country'] = $debtor['country'];
        }

        return !empty($address) ? new Address($address) : null;
    }

    /**
     * Extracts error message from API response.
     *
     * @param array $errors API errors
     * @return string Error message
     */
    private function getApiErrorMessage(array $errors): string
    {
        $messages = array_map(function ($error) {
            return $error->getDetail() ?? $error->getCode() ?? 'Unknown error';
        }, $errors);
        
        return implode('; ', $messages);
    }
}