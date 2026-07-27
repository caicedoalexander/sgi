<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\InvoiceApprovalService;
use App\Service\NotificationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Cobertura del 4º path (legalización de anticipos) en /approve/{token} (Task 10).
 *
 * Igual que `ExternalApprovalsGroupTest` (Fase 1, reintegros): la BD de test
 * compartida (`sgi_test`) tiene un drift físico en `invoice_approvals` (falta
 * `token_hash`) aunque `cake_migrations` lo marca aplicado. Como el path
 * multi-aprobador de facturas (`InvoiceApprovalService::validateToken`) corre
 * PRIMERO, siempre, en `review()`/`process()`, cualquier request autenticada a
 * `/approve/*` dispara esa consulta rota antes de llegar al path de esta
 * tarea. Se aísla sustituyendo `InvoiceApprovalService` vía el seam oficial de
 * CakePHP (`ContainerStubTrait::mockService()`, incluido en
 * `IntegrationTestTrait`) por un stub cuyo `validateToken()` devuelve `null`
 * — el mismo resultado que produciría en un entorno migrado correctamente
 * para un token que no es de `invoice_approvals`. No se toca BD ni código de
 * app.
 */
class ExternalApprovalsAdvanceGroupTest extends TestCase
{
    use IntegrationTestTrait;

    private function _stubInvoiceApprovalService(): void
    {
        $this->mockService(InvoiceApprovalService::class, function () {
            $stub = $this->createStub(InvoiceApprovalService::class);
            $stub->method('validateToken')->willReturn(null);

            return $stub;
        });
    }

    public function testAssignedApproverSeesReviewGroupAdvance(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        $approver = UserFactory::new()->save();
        $svc = new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
        $svc->assignApprovers($leg, [$approver->id], 'https://x', (int)$approver->id);
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => TableRegistry::getTableLocator()->get('Users')->get($approver->id)]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Legalización'); // review_group_advance render
    }

    public function testNonAssignedUserIsRejected(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        $approver = UserFactory::new()->save();
        $otherUser = UserFactory::new()->save();
        $svc = new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
        $svc->assignApprovers($leg, [$approver->id], 'https://x', (int)$approver->id);
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => TableRegistry::getTableLocator()->get('Users')->get($otherUser->id)]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Sin autorización');
    }

    public function testApproverCanApproveGroup(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        $approver = UserFactory::new()->save();
        $svc = new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
        $svc->assignApprovers($leg, [$approver->id], 'https://x', (int)$approver->id);
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => TableRegistry::getTableLocator()->get('Users')->get($approver->id)]);
        $this->enableCsrfToken();
        $this->post('/approve/' . $secret . '/process', ['action' => 'approve']);

        $this->assertResponseOk();
        $this->assertResponseContains('aprobada');

        $reloadedApproval = $table->get($a->id);
        $this->assertSame(InvoiceConstants::APPROVER_STATUS_APPROVED, $reloadedApproval->status);
    }

    public function testAssignedApproverSeesGroupedSupports(): void
    {
        $anticipo = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'invoice_number' => 'ANT-1',
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $linked = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => $anticipo->id,
            'invoice_number' => 'FAC-L',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $docs = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $docs->saveOrFail($docs->newEntity([
            'invoice_id' => $anticipo->id,
            'pipeline_status' => 'aprobacion',
            'file_name' => 'anticipo-doc.pdf',
            'file_path' => 'storage/test/anticipo-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2222,
        ]));
        $docs->saveOrFail($docs->newEntity([
            'invoice_id' => $linked->id,
            'pipeline_status' => 'aprobacion',
            'file_name' => 'legal-doc.pdf',
            'file_path' => 'storage/test/legal-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3333,
        ]));

        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        $approver = UserFactory::new()->save();
        $svc = new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
        $svc->assignApprovers($leg, [$approver->id], 'https://x', (int)$approver->id);
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => TableRegistry::getTableLocator()->get('Users')->get($approver->id)]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseContains('anticipo-doc.pdf'); // grupo del anticipo padre
        $this->assertResponseContains('legal-doc.pdf'); // grupo de la factura vinculada
    }
}
