<?php
declare(strict_types=1);

// --- Stub FA functions in the global namespace ---
// SquareNoteService calls function_exists('get_crm_persons') with an
// unqualified name from inside namespace ksfraser\FrontAccounting\Square\Services;
// PHP resolves unqualified function_exists against the global namespace, so
// defining the stubs here (in namespace { }) makes them visible to the service.
namespace {
    if (!function_exists('get_crm_persons')) {
        function get_crm_persons($type, $action, $entityId)
        {
            return 'SELECT * FROM crm_persons_' . $type . '_' . $action . '_' . $entityId;
        }
    }
    if (!function_exists('get_customer')) {
        function get_customer($customerId)
        {
            return isset($GLOBALS['ksf_test_stub_get_customer'])
                ? $GLOBALS['ksf_test_stub_get_customer']
                : null;
        }
    }
    if (!function_exists('ksf_log')) {
        function ksf_log($module, $level, $message, array $context = [])
        {
            // no-op in tests
        }
    }
}

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services {

use ksfraser\FrontAccounting\Square\Services\SquareNoteService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for SquareNoteService.
 *
 * Covers note link building, branch contact resolution, and short Square ID
 * extraction — the contact-id path that feeds the note Renderer's
 * "Related-To" display.
 *
 * @BABOK Related: NFR-ISU-007
 */
class SquareNoteServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['path_to_root'] = '/var/www/html';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['ksf_test_stub_get_customer']);
        unset($GLOBALS['__fa_result_set']);
        unset($GLOBALS['__fa_result_pos']);
    }

    /* ========================================================================
     * extractShortSquareId (public static)
     * ======================================================================== */

    /** @test */
    public function extractShortSquareIdReturnsLastDashSegmentWhenValid(): void
    {
        $this->assertSame('ABCDEF12', SquareNoteService::extractShortSquareId('inv-001-ABCDEF12'));
        $this->assertSame('XYZ789', SquareNoteService::extractShortSquareId('SQ-INV-XYZ789'));
    }

    /** @test */
    public function extractShortSquareIdReturnsFullIdWhenNoDash(): void
    {
        $this->assertSame('fullid123', SquareNoteService::extractShortSquareId('fullid123'));
    }

    /** @test */
    public function extractShortSquareIdReturnsFullIdWhenSegmentTooShort(): void
    {
        $this->assertSame('inv-AB', SquareNoteService::extractShortSquareId('inv-AB'));
    }

    /** @test */
    public function extractShortSquareIdReturnsFullIdWhenDashAtEnd(): void
    {
        $this->assertSame('inv-', SquareNoteService::extractShortSquareId('inv-'));
    }

    /* ========================================================================
     * buildNoteLinks (private, via Reflection)
     * ======================================================================== */

    private function invokeBuildNoteLinks(int $faInvoiceNo, array $data): array
    {
        $service = new SquareNoteService();
        $method = new ReflectionMethod($service, 'buildNoteLinks');
        $method->setAccessible(true);
        return $method->invoke($service, $faInvoiceNo, $data);
    }

    /** @test */
    public function buildNoteLinksAlwaysIncludesCustomerAndInvoiceLinks(): void
    {
        $links = $this->invokeBuildNoteLinks(42, [
            'customer_id' => 'C100',
            'branch_code' => '',
        ]);

        $this->assertContains(['customer', 'C100', 'reference'], $links);
        $this->assertContains(['invoice', '42', 'source_invoice'], $links);
        $this->assertCount(2, $links);
    }

    /** @test */
    public function buildNoteLinksAddsContactLinksForBranch(): void
    {
        $GLOBALS['__fa_result_set']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = [
            ['id' => 42, 'ref' => 'C100', 'contact_id' => 101], // person id=42, relation id=101
            ['id' => 43, 'ref' => 'C100', 'contact_id' => 102], // person id=43, relation id=102
            ['id' => 44, 'ref' => 'C200', 'contact_id' => 103], // C200 doesn't match
        ];
        $GLOBALS['__fa_result_pos']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = 0;

        $links = $this->invokeBuildNoteLinks(42, [
            'customer_id' => 'C100',
            'branch_code' => 'BR1',
        ]);

        $this->assertContains(['customer', 'C100', 'reference'], $links);
        $this->assertContains(['invoice', '42', 'source_invoice'], $links);
        $this->assertContains(['contact', 42, 'invoice_contact'], $links); // person id
        $this->assertContains(['contact', 43, 'invoice_contact'], $links); // person id
        $this->assertNotContains(['contact', 44, 'invoice_contact'], $links); // C200 doesn't match
        $this->assertNotContains(['contact', 101, 'invoice_contact'], $links); // NOT relation id
        $this->assertNotContains(['contact', 102, 'invoice_contact'], $links); // NOT relation id
        $this->assertCount(4, $links);
    }

    /** @test */
    public function buildNoteLinksSkipsContactsWhenNoBranchCode(): void
    {
        $links = $this->invokeBuildNoteLinks(42, [
            'customer_id' => 'C100',
            'branch_code' => '',
        ]);

        $contactLinks = array_filter($links, fn($l) => $l[0] === 'contact');
        $this->assertEmpty($contactLinks);
        $this->assertCount(2, $links);
    }

    /** @test */
    public function buildNoteLinksSkipsContactsWhenBranchCodeNull(): void
    {
        $links = $this->invokeBuildNoteLinks(42, [
            'customer_id' => 'C100',
            'branch_code' => null,
        ]);

        $contactLinks = array_filter($links, fn($l) => $l[0] === 'contact');
        $this->assertEmpty($contactLinks);
        $this->assertCount(2, $links);
    }

    /* ========================================================================
     * getBranchInvoiceContacts (private, via Reflection)
     * ======================================================================== */

    private function invokeGetBranchInvoiceContacts(string $branchCode, string $customerId): array
    {
        $service = new SquareNoteService();
        $method = new ReflectionMethod($service, 'getBranchInvoiceContacts');
        $method->setAccessible(true);
        return $method->invoke($service, $branchCode, $customerId);
    }

    /** @test */
    public function getBranchInvoiceContactsReturnsEmptyWhenNoBranchCode(): void
    {
        $this->assertSame([], $this->invokeGetBranchInvoiceContacts('', 'C100'));
    }

    /** @test */
    public function getBranchInvoiceContactsReturnsEmptyWhenNoCustomerId(): void
    {
        $this->assertSame([], $this->invokeGetBranchInvoiceContacts('BR1', ''));
    }

    /** @test */
    public function getBranchInvoiceContactsMatchesByDebtorRefWhenAvailable(): void
    {
        $GLOBALS['ksf_test_stub_get_customer'] = ['debtor_no' => 100, 'debtor_ref' => 'CUST-REF-100'];
        $GLOBALS['__fa_result_set']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = [
            ['id' => 77, 'ref' => 'CUST-REF-100', 'contact_id' => 177], // person id=77, relation id=177
            ['id' => 88, 'ref' => 'C100',            'contact_id' => 188], // customer_id, not debtor_ref
        ];
        $GLOBALS['__fa_result_pos']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = 0;

        $contacts = $this->invokeGetBranchInvoiceContacts('BR1', 'C100');

        $this->assertSame([77], $contacts);
    }

    /** @test */
    public function getBranchInvoiceContactsFallsBackToCustomerIdWhenNoDebtorRef(): void
    {
        $GLOBALS['ksf_test_stub_get_customer'] = ['debtor_no' => 100, 'debtor_ref' => ''];
        $GLOBALS['__fa_result_set']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = [
            ['id' => 55, 'ref' => 'C100', 'contact_id' => 155], // person id=55, relation id=155
            ['id' => 66, 'ref' => 'OTHER', 'contact_id' => 166],
        ];
        $GLOBALS['__fa_result_pos']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = 0;

        $contacts = $this->invokeGetBranchInvoiceContacts('BR1', 'C100');

        $this->assertSame([55], $contacts);
    }

    /** @test */
    public function getBranchInvoiceContactsReturnsEmptyWhenNoRows(): void
    {
        $GLOBALS['ksf_test_stub_get_customer'] = ['debtor_no' => 100, 'debtor_ref' => 'C100'];
        $GLOBALS['__fa_result_set']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = [];
        $GLOBALS['__fa_result_pos']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = 0;

        $contacts = $this->invokeGetBranchInvoiceContacts('BR1', 'C100');

        $this->assertSame([], $contacts);
    }

    /* ========================================================================
     * createSquareInvoiceNote (public)
     * ======================================================================== */

    private function findInvocation(string $ext, string $method): ?array
    {
        $found = null;
        foreach ($GLOBALS['ksf_test_invocations'] ?? [] as $inv) {
            if ($inv[0] === $ext && $inv[1] === $method) {
                $found = $inv;
            }
        }
        return $found;
    }

    /** @test */
    public function createSquareInvoiceNoteSucceedsAndLogsLinks(): void
    {
        $GLOBALS['ksf_test_invoke_returns']['ksf_FA_Notes::CREATE_NOTE'] = true;

        $service = new SquareNoteService();
        $result = $service->createSquareInvoiceNote(
            42,
            'SQ-001',
            'https://square.example.com/inv/abc',
            ['trans_no' => 42, 'customer_id' => 'C100'],
            ['square_invoice_id' => 'inv-001-SQ-001'],
            [
                'customer_id' => 100,
                'branch_code' => '',
            ],
            ['loginname' => 'testuser'],
        );

        $this->assertSame(['ok' => true, 'note_id' => 0], $result);
        $this->assertNotNull($this->findInvocation('ksf_FA_Notes', 'CREATE_NOTE'));
    }

    /** @test */
    public function createSquareInvoiceNoteIncludesContactLinksWhenBranchSet(): void
    {
        $GLOBALS['ksf_test_invoke_returns']['ksf_FA_Notes::CREATE_NOTE'] = true;
        $GLOBALS['ksf_test_stub_get_customer'] = ['debtor_no' => 100, 'debtor_ref' => 'C100'];
        $GLOBALS['__fa_result_set']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = [
            ['id' => 9, 'ref' => 'C100', 'contact_id' => 109], // person id=9, relation id=109
        ];
        $GLOBALS['__fa_result_pos']['SELECT * FROM crm_persons_cust_branch_invoice_BR1'] = 0;

        $service = new SquareNoteService();
        $result = $service->createSquareInvoiceNote(
            7,
            'SQ-007',
            'https://square.example.com/inv/xyz',
            ['trans_no' => 7, 'customer_id' => 'C100'],
            ['square_invoice_id' => 'inv-007-SQ-007'],
            [
                'customer_id' => 100,
                'branch_code' => 'BR1',
            ],
            ['loginname' => 'testuser'],
        );

        $this->assertSame(['ok' => true, 'note_id' => 0], $result);

        $inv = $this->findInvocation('ksf_FA_Notes', 'CREATE_NOTE');
        $this->assertNotNull($inv);
        $noteOpts = $inv[3];
        $this->assertArrayHasKey('links', $noteOpts);
        $this->assertContains(['contact', 9, 'invoice_contact'], $noteOpts['links']);
    }

    /** @test */
    public function createSquareInvoiceNoteFailsWhenHookReturnsFalse(): void
    {
        $GLOBALS['ksf_test_invoke_returns']['ksf_FA_Notes::CREATE_NOTE'] = false;

        $service = new SquareNoteService();
        $result = $service->createSquareInvoiceNote(
            42,
            'SQ-001',
            'https://square.example.com/inv/abc',
            ['trans_no' => 42, 'customer_id' => 'C100'],
            ['square_invoice_id' => 'inv-001-SQ-001'],
            ['customer_id' => 100, 'branch_code' => ''],
            ['loginname' => 'testuser'],
        );

        $this->assertSame(['ok' => false, 'error' => 'unknown'], $result);
    }

    /** @test */
    public function createSquareInvoiceNotePassesCustomerIdFromDataArray(): void
    {
        $GLOBALS['ksf_test_invoke_returns']['ksf_FA_Notes::CREATE_NOTE'] = true;

        $service = new SquareNoteService();
        $service->createSquareInvoiceNote(
            42,
            'SQ-001',
            'https://square.example.com/inv/abc',
            ['trans_no' => 42, 'customer_id' => 'C100'],
            ['square_invoice_id' => 'inv-001-SQ-001'],
            ['customer_id' => 100, 'branch_code' => ''],
            ['loginname' => 'testuser'],
        );

        $inv = $this->findInvocation('ksf_FA_Notes', 'CREATE_NOTE');
        $this->assertNotNull($inv);
        $noteData = $inv[2];
        $this->assertStringContainsString('#42', $noteData['note']);
        $this->assertSame('square_invoice', $noteData['note_type']);
        $this->assertSame('testuser', $noteData['owner']);
    }
}
}
