<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\ApprovalInboxConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Model\Entity\Employee;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoiceApproval;
use App\Model\Entity\NoveltyType;
use App\Model\Entity\Provider;
use App\Service\ApprovalInboxService;
use App\Service\Dto\ApprovalInboxItem;
use Cake\I18n\DateTime;
use PHPUnit\Framework\TestCase;

final class ApprovalInboxServiceTest extends TestCase
{
    public function testApprovalInboxItemHoldsAllFields(): void
    {
        $assigned = new DateTime('2026-06-01 10:00:00');
        $item = new ApprovalInboxItem(
            module: ApprovalInboxConstants::MODULE_INVOICE,
            entityId: 42,
            code: 'FAC-001',
            counterparty: 'Proveedor SA',
            summary: '$ 1.000.000',
            status: ApprovalInboxConstants::STATUS_PENDING,
            assignedAt: $assigned,
            respondedAt: null,
            observations: null,
        );

        $this->assertSame('invoice', $item->module);
        $this->assertSame(42, $item->entityId);
        $this->assertSame('FAC-001', $item->code);
        $this->assertSame('Proveedor SA', $item->counterparty);
        $this->assertSame('$ 1.000.000', $item->summary);
        $this->assertSame('pending', $item->status);
        $this->assertSame($assigned, $item->assignedAt);
        $this->assertNull($item->respondedAt);
        $this->assertNull($item->observations);
    }

    private function service(): ApprovalInboxService
    {
        // Tablas no usadas por los métodos puros; se pasan null y el service
        // resuelve lazy solo en los métodos de fetching (no ejercitados aquí).
        return new ApprovalInboxService();
    }

    public function testNormalizeInvoiceApprovalMapsSpanishStatusToSlug(): void
    {
        $approval = new InvoiceApproval([
            'invoice_id' => 7,
            'user_id' => 3,
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING, // 'Pendiente'
            'responded_at' => null,
            'observations' => null,
            'created' => new DateTime('2026-06-02 09:00:00'),
            'invoice' => new Invoice([
                'id' => 7,
                'invoice_number' => 'FAC-7',
                'amount' => 1500000,
                'provider' => new Provider(['name' => 'ACME SAS']),
            ]),
        ]);

        $item = $this->service()->normalizeInvoiceApproval($approval);

        $this->assertSame('invoice', $item->module);
        $this->assertSame(7, $item->entityId);
        $this->assertSame('FAC-7', $item->code);
        $this->assertSame('ACME SAS', $item->counterparty);
        $this->assertSame('pending', $item->status);
        $digitsOnly = preg_replace('/\D/', '', $item->summary);
        $this->assertStringContainsString('1500000', $digitsOnly);
    }

    public function testNormalizeNoveltyMapsAreaApprovalToSlug(): void
    {
        $novelty = new EmployeeNovelty([
            'id' => 11,
            'area_approval' => NoveltyConstants::APPROVAL_REJECTED, // 'Rechazada'
            'approved_at' => new DateTime('2026-06-03 12:00:00'),
            'observations' => 'No procede',
            'created' => new DateTime('2026-06-01 08:00:00'),
            'employee' => new Employee(['first_name' => 'Ana', 'last_name1' => 'Gil', 'last_name2' => '']),
            'novelty_type' => new NoveltyType(['name' => 'Permiso']),
        ]);

        $item = $this->service()->normalizeNovelty($novelty);

        $this->assertSame('novelty', $item->module);
        $this->assertSame(11, $item->entityId);
        $this->assertSame('#11', $item->code);
        $this->assertSame('Ana Gil', $item->counterparty);
        $this->assertSame('Permiso', $item->summary);
        $this->assertSame('rejected', $item->status);
    }

    public function testNormalizeNoveltyTreatsNullAreaApprovalAsPending(): void
    {
        $novelty = new EmployeeNovelty([
            'id' => 12,
            'area_approval' => null,
            'created' => new DateTime('2026-06-01 08:00:00'),
            'employee' => new Employee(['first_name' => 'Luis', 'last_name1' => 'Paz', 'last_name2' => '']),
            'novelty_type' => new NoveltyType(['name' => 'Licencia']),
        ]);

        $item = $this->service()->normalizeNovelty($novelty);

        $this->assertSame('pending', $item->status);
    }

    /** @return ApprovalInboxItem[] */
    private function sampleItems(): array
    {
        return [
            new ApprovalInboxItem(
                'invoice',
                1,
                'FAC-1',
                'Prov A',
                '$1',
                'approved',
                new DateTime('2026-06-01'),
                new DateTime('2026-06-02'),
                null,
            ),
            new ApprovalInboxItem(
                'novelty',
                2,
                '#2',
                'Ana Gil',
                'Permiso',
                'pending',
                new DateTime('2026-06-05'),
                null,
                null,
            ),
            new ApprovalInboxItem(
                'invoice',
                3,
                'FAC-3',
                'Prov B',
                '$3',
                'rejected',
                new DateTime('2026-06-03'),
                new DateTime('2026-06-04'),
                null,
            ),
        ];
    }

    public function testFilterByStatusKeepsOnlyMatching(): void
    {
        $out = $this->service()->filterAndSort($this->sampleItems(), 'pending', null, null);
        $this->assertCount(1, $out);
        $this->assertSame(2, $out[0]->entityId);
    }

    public function testFilterByModuleKeepsOnlyMatching(): void
    {
        $out = $this->service()->filterAndSort($this->sampleItems(), null, 'invoice', null);
        $this->assertCount(2, $out);
    }

    public function testFilterBySearchMatchesCodeOrCounterparty(): void
    {
        $out = $this->service()->filterAndSort($this->sampleItems(), null, null, 'ana');
        $this->assertCount(1, $out);
        $this->assertSame(2, $out[0]->entityId);

        $out2 = $this->service()->filterAndSort($this->sampleItems(), null, null, 'FAC-3');
        $this->assertCount(1, $out2);
        $this->assertSame(3, $out2[0]->entityId);
    }

    public function testSortPlacesPendingFirstThenByAssignedDesc(): void
    {
        $out = $this->service()->filterAndSort($this->sampleItems(), null, null, null);
        // pending primero (id 2), luego no-pending por assignedAt desc (id 3 antes que id 1)
        $this->assertSame([2, 3, 1], array_map(fn($i) => $i->entityId, $out));
    }

    public function testPaginateSlicesAndReportsTotal(): void
    {
        $items = $this->sampleItems();
        $page = $this->service()->paginateItems($items, 1, 2);
        $this->assertSame(3, $page['total']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(2, $page['perPage']);
        $this->assertCount(2, $page['items']);

        $page2 = $this->service()->paginateItems($items, 2, 2);
        $this->assertCount(1, $page2['items']);
    }

    public function testNormalizeInvoiceApprovalFallsBackWhenInvoiceMissing(): void
    {
        $approval = new InvoiceApproval([
            'invoice_id' => 99,
            'user_id' => 1,
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
            'created' => new DateTime('2026-06-02 09:00:00'),
        ]);
        // invoice no contenido → null

        $item = $this->service()->normalizeInvoiceApproval($approval);

        $this->assertSame('#99', $item->code);
        $this->assertSame('—', $item->counterparty);
    }

    public function testNormalizeInvoiceApprovalFallsBackWhenProviderMissing(): void
    {
        $approval = new InvoiceApproval([
            'invoice_id' => 7,
            'user_id' => 1,
            'status' => InvoiceConstants::APPROVER_STATUS_APPROVED,
            'created' => new DateTime('2026-06-02 09:00:00'),
            'invoice' => new Invoice(['id' => 7, 'invoice_number' => 'FAC-7', 'amount' => 1000]),
        ]);

        $item = $this->service()->normalizeInvoiceApproval($approval);

        $this->assertSame('FAC-7', $item->code);
        $this->assertSame('—', $item->counterparty);
    }

    public function testNormalizeNoveltyFallsBackWhenAssociationsMissing(): void
    {
        $novelty = new EmployeeNovelty([
            'id' => 5,
            'area_approval' => NoveltyConstants::APPROVAL_PENDING,
            'created' => new DateTime('2026-06-01 08:00:00'),
        ]);
        // employee y novelty_type no contenidos → null

        $item = $this->service()->normalizeNovelty($novelty);

        $this->assertSame('—', $item->counterparty);
        $this->assertSame('—', $item->summary);
    }

    public function testPaginateNormalizesNonPositivePageToOne(): void
    {
        $page = $this->service()->paginateItems($this->sampleItems(), 0, 10);
        $this->assertSame(1, $page['page']);
        $this->assertCount(3, $page['items']);
    }
}
