<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Los 4 listados de facturas comparten un único criterio: si la factura tiene
 * un registro padre, no se opera desde el módulo de Facturas.
 */
final class InvoicesListFiltersTest extends TestCase
{
    use IntegrationTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        Cache::clear('sidebar');
    }

    /**
     * Usuario con can_view de invoices y capacidad de operar los pasos dados
     * del pipeline de facturas.
     *
     * @param list<string> $operableSteps
     */
    private function _loginWithSteps(array $operableSteps): void
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'invoices',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach ($operableSteps as $step) {
            $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
                'step' => $step,
                'can_operate' => true,
            ]));
        }

        $this->session(['Auth' => UserFactory::new(['role_id' => $role->id])->save()]);
    }

    public function testInboxHidesInvoiceGroupedIntoRefund(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'invoice_number' => 'ZZ-AGRUPADA-REI'])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ZZ-AGRUPADA-REI');
    }

    public function testInboxHidesGroupedPettyCashInvoiceStillInAprobacion(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id, 'invoice_number' => 'ZZ-AGRUPADA-CM'])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->get('/invoices');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ZZ-AGRUPADA-CM');
    }

    public function testInboxShowsUngroupedRefundInvoice(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_REINTEGRO,
            'invoice_number' => 'ZZ-LIBRE-REI',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->get('/invoices');

        $this->assertResponseOk();
        $this->assertResponseContains('ZZ-LIBRE-REI');
    }

    public function testArchiveShowsGroupedInvoices(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'invoice_number' => 'ZZ-ARCHIVO-REI'])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/all');

        $this->assertResponseOk();
        $this->assertResponseContains('ZZ-ARCHIVO-REI');
    }

    public function testArchiveRendersParentBadgeWithParentCode(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/all');

        $this->assertResponseOk();
        $this->assertResponseContains((string)$refund->code);
        $this->assertResponseContains('bi-link-45deg');
    }

    public function testOverdueExcludesDocumentTypesWithoutRealDueDate(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        $ayer = date('Y-m-d', strtotime('-1 day'));

        InvoiceFactory::new(['due_date' => $ayer, 'invoice_number' => 'ZZ-VENCIDA-FAC'])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['due_date' => $ayer, 'invoice_number' => 'ZZ-VENCIDA-LEG'])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['due_date' => $ayer, 'invoice_number' => 'ZZ-VENCIDA-RC'])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/overdue');

        $this->assertResponseOk();
        $this->assertResponseContains('ZZ-VENCIDA-FAC');
        $this->assertResponseNotContains('ZZ-VENCIDA-LEG');
        $this->assertResponseNotContains('ZZ-VENCIDA-RC');
    }

    public function testRejectedIsScopedToOperableSteps(): void
    {
        // Contabilidad no opera `aprobacion`, que es donde vive toda rechazada.
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        InvoiceFactory::new([
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
            'invoice_number' => 'ZZ-RECHAZADA',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->get('/invoices/rejected');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ZZ-RECHAZADA');
    }

    public function testInvoiceViewShowsParentNoticeForRefund(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        $refund = RefundFactory::new()->save();
        $agrupada = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/view/' . $agrupada->id);

        $this->assertResponseOk();
        $this->assertResponseContains((string)$refund->code);
        $this->assertResponseContains('/refunds/view/' . $refund->id);
    }
}
