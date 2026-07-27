<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoiceApproval;
use App\Service\Approval\ApprovalTokenManager;
use App\Service\InvoiceApprovalService;
use App\Service\NotificationService;
use Cake\Database\Connection;
use Cake\Datasource\ResultSetDecorator;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Caracterización del cambio Ola 3 en el sistema multi-aprobador: el token se
 * persiste y se busca por su hash SHA256 (token_hash), nunca en claro.
 */
final class InvoiceApprovalServiceTest extends TestCase
{
    private ?array $captured = null;
    private ?array $whereConditions = null;

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function mockInvoiceApprovalsTable(): Table
    {
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'contain', 'first'])
            ->getMock();
        $query->method('where')->willReturnCallback(function (array $conditions) use ($query) {
            $this->whereConditions = $conditions;

            return $query;
        });
        $query->method('contain')->willReturnSelf();
        $query->method('first')->willReturn(null);

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find', 'newEntity', 'save'])
            ->getMock();
        $table->method('find')->willReturn($query);
        $table->method('newEntity')->willReturnCallback(function (array $data) {
            $this->captured = $data;

            return new Entity($data);
        });
        $table->method('save')->willReturnCallback(fn($e) => $e);

        return $table;
    }

    public function testPersistApproversStoresHashNotCleartext(): void
    {
        $locator = new TableLocator();
        $locator->set('InvoiceApprovals', $this->mockInvoiceApprovalsTable());
        $locator->set('Users', $this->emptyUsersTable());
        $locator->set('InvoiceHistories', $this->savingTable());
        TableRegistry::setTableLocator($locator);

        $notification = $this->createMock(NotificationService::class);
        $service = new InvoiceApprovalService($notification);

        $invoice = new Invoice(['id' => 10]);
        $service->assignApprovers($invoice, [3], 'https://app.test', 1);

        $this->assertArrayHasKey('token_hash', $this->captured);
        $this->assertArrayNotHasKey('token', $this->captured);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->captured['token_hash']);
    }

    public function testValidateTokenSearchesByHash(): void
    {
        $locator = new TableLocator();
        $locator->set('InvoiceApprovals', $this->mockInvoiceApprovalsTable());
        TableRegistry::setTableLocator($locator);

        $service = new InvoiceApprovalService($this->createMock(NotificationService::class));
        $service->validateToken('plain-secret');

        $this->assertSame(
            ApprovalTokenManager::hashSecret('plain-secret'),
            $this->whereConditions['token_hash'] ?? null,
        );
        $this->assertArrayNotHasKey('token', $this->whereConditions);
    }

    public function testApplyFreshTokenSetsHashFutureExpiryAndReturnsSecret(): void
    {
        $locator = new TableLocator();
        $locator->set('InvoiceApprovals', $this->mockInvoiceApprovalsTable());
        TableRegistry::setTableLocator($locator);

        $service = new InvoiceApprovalService($this->createMock(NotificationService::class));
        $approval = new InvoiceApproval([]);

        $secret = $service->applyFreshToken($approval);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertSame(ApprovalTokenManager::hashSecret($secret), $approval->token_hash);
        $this->assertNotNull($approval->token_expires_at);
        $this->assertTrue($approval->token_expires_at->isFuture());
    }

    private function emptyUsersTable(): Table
    {
        $emptyResultSet = new ResultSetDecorator([]);
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()->onlyMethods(['where', 'all'])->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('all')->willReturn($emptyResultSet);
        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
        $table->method('find')->willReturn($query);

        return $table;
    }

    private function savingTable(): Table
    {
        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()->onlyMethods(['newEntity', 'save'])->getMock();
        $table->method('newEntity')->willReturnCallback(fn(array $d) => new Entity($d));
        $table->method('save')->willReturnCallback(fn($e) => $e);

        return $table;
    }

    // -------------------------------------------------------------------------
    // processResponse — REJECT path
    // -------------------------------------------------------------------------

    public function testProcessResponseRejectNullifiesTokenHashAndCascades(): void
    {
        $invoiceId = 42;
        $approvalId = 7;
        $userId = 3;
        $plainToken = 'my-plain-secret';
        $tokenHash = ApprovalTokenManager::hashSecret($plainToken);

        // Captured data from mocked collaborators
        $savedApproval = null;
        $updateAllFields = null;

        // --- InvoiceApprovals query mock (supports epilog) ---
        $approval = new InvoiceApproval([
            'id' => $approvalId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
            'token_hash' => $tokenHash,
        ]);

        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'contain', 'epilog', 'first'])
            ->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('contain')->willReturnSelf();
        $query->method('epilog')->willReturnSelf();
        $query->method('first')->willReturn($approval);

        // --- Connection mock — runs transactional callback inline ---
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb($connection));

        // --- InvoiceApprovals table mock ---
        $approvalsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find', 'newEntity', 'save', 'updateAll', 'getConnection'])
            ->getMock();
        $approvalsTable->method('find')->willReturn($query);
        $approvalsTable->method('save')->willReturnCallback(function ($entity) use (&$savedApproval) {
            $savedApproval = $entity;

            return $entity;
        });
        $approvalsTable->method('updateAll')->willReturnCallback(function (array $fields) use (&$updateAllFields) {
            $updateAllFields = $fields;

            return 1;
        });
        $approvalsTable->method('getConnection')->willReturn($connection);

        // --- Invoices table mock ---
        $invoiceEntity = new Invoice(['id' => $invoiceId, 'area_approval' => InvoiceConstants::APPROVAL_PENDING]);
        $invoicesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save'])
            ->getMock();
        $invoicesTable->method('get')->willReturn($invoiceEntity);
        $invoicesTable->method('save')->willReturnCallback(fn($e) => $e);

        // --- InvoiceHistories table mock ---
        $historiesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['newEntity', 'save'])
            ->getMock();
        $historiesTable->method('newEntity')->willReturnCallback(fn(array $d) => new Entity($d));
        $historiesTable->method('save')->willReturnCallback(fn($e) => $e);

        // --- Wire up the locator ---
        $locator = new TableLocator();
        $locator->set('InvoiceApprovals', $approvalsTable);
        $locator->set('Invoices', $invoicesTable);
        $locator->set('InvoiceHistories', $historiesTable);
        TableRegistry::setTableLocator($locator);

        $service = new InvoiceApprovalService($this->createMock(NotificationService::class));

        // --- Exercise ---
        $result = $service->processResponse(
            $plainToken,
            ApprovalConstants::ACTION_REJECT,
            'No cumple requisitos',
            '127.0.0.1',
            'TestAgent/1.0',
        );

        // --- Assert result ---
        $this->assertTrue($result->success);
        $this->assertTrue($result->data['rejected']);
        $this->assertFalse($result->data['allApproved']);
        $this->assertSame($invoiceId, $result->data['invoice_id']);

        // --- Assert the approval row was saved with token_hash nullified ---
        $this->assertNotNull($savedApproval, 'save() was never called on approval');
        $this->assertNull($savedApproval->token_hash, 'token_hash must be nullified on the saved approval');
        $this->assertSame(
            InvoiceConstants::APPROVER_STATUS_REJECTED,
            $savedApproval->status,
            'status must be set to APPROVER_STATUS_REJECTED',
        );

        // --- Assert cascade updateAll nullifies token_hash on sibling pending rows ---
        $this->assertNotNull($updateAllFields, 'updateAll() was never called (cascade not executed)');
        $this->assertArrayHasKey('token_hash', $updateAllFields, 'cascade must set token_hash');
        $this->assertNull($updateAllFields['token_hash'], 'cascade must nullify token_hash');
        $this->assertArrayHasKey('token_expires_at', $updateAllFields, 'cascade must clear token_expires_at');
        $this->assertNull($updateAllFields['token_expires_at']);
    }
}
