<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\InvoiceApprovalService;
use App\Service\NotificationService;
use App\Service\RefundApprovalService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Cobertura del path de grupo de reintegros en /approve/{token} (Task 10).
 *
 * Autenticación en tests de integración: `Application::getAuthenticationService`
 * carga `Authentication.Session` (src/Application.php), así que
 * `$this->session(['Auth' => $userEntity])` con una entidad `User` real (no un
 * array) autentica la request — `getIdentity()->getOriginalData()` devuelve esa
 * entidad y expone `->id`/`->role_id` tal como el controller los usa. Confirmado:
 * las requests autenticadas SÍ llegan al cuerpo del controller (pasan el gate de
 * auth y el de RBAC).
 *
 * Drift real verificado en la BD de test compartida (`sgi_test`, no introducido
 * por esta tarea): `invoice_approvals` carece físicamente de la columna
 * `token_hash` aunque `cake_migrations` registra `HashInvoiceApprovals` /
 * `DropTokenFromInvoiceApprovals` como aplicadas (confirmado con `DESCRIBE`
 * directo contra esa BD; la BD `default` sí tiene el esquema correcto). Como
 * `ExternalApprovalsController::review()/process()` prueban PRIMERO, siempre,
 * el path multi-aprobador de facturas (`InvoiceApprovalService::validateToken`)
 * antes de caer al path de grupo de reintegros, cualquier request a
 * `/approve/*` — sea cual sea el tipo real de token — dispara esa consulta rota.
 * Los tests autenticados de abajo aíslan el path de reintegros (el que de
 * verdad se está cubriendo aquí) de ese defecto de entorno ajeno, sustituyendo
 * `InvoiceApprovalService` vía el seam oficial de CakePHP para tests de
 * integración (`ContainerStubTrait::mockService()`, incluido en
 * `IntegrationTestTrait`) por un stub cuyo `validateToken()` devuelve `null` —
 * exactamente el valor que produciría en un entorno migrado correctamente para
 * un token que no es de `invoice_approvals`. No se toca BD ni código de app.
 */
class ExternalApprovalsGroupTest extends TestCase
{
    use IntegrationTestTrait;

    private const FAKE_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function _service(): RefundApprovalService
    {
        return new RefundApprovalService($this->createStub(NotificationService::class));
    }

    /**
     * Aísla el path de reintegros del drift descrito en el docblock de clase:
     * stub de InvoiceApprovalService::validateToken() → null (mismo resultado
     * que produciría en un entorno sano para un token que no es suyo).
     */
    private function _stubInvoiceApprovalService(): void
    {
        $this->mockService(InvoiceApprovalService::class, function () {
            $stub = $this->createStub(InvoiceApprovalService::class);
            $stub->method('validateToken')->willReturn(null);

            return $stub;
        });
    }

    public function testReviewRequiresAuthentication(): void
    {
        $this->get('/approve/' . self::FAKE_TOKEN);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testProcessRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/approve/' . self::FAKE_TOKEN . '/process', ['action' => 'approve']);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testAssignedApproverSeesGroupReview(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();

        $svc = $this->_service();
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);

        $approvalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $approval = $approvalsTable->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($approval);
        $approvalsTable->saveOrFail($approval);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => $approver]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Reintegro');
    }

    public function testApproverCanApproveGroup(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $invoice = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();

        $svc = $this->_service();
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);

        $approvalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $approval = $approvalsTable->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($approval);
        $approvalsTable->saveOrFail($approval);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => $approver]);
        $this->enableCsrfToken();
        $this->post('/approve/' . $secret . '/process', ['action' => 'approve']);

        $this->assertResponseOk();
        $this->assertResponseContains('aprobada');

        $reloadedInvoice = TableRegistry::getTableLocator()->get('Invoices')->get($invoice->id);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloadedInvoice->area_approval);
    }

    public function testNonAssignedUserIsRejected(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();
        $otherUser = UserFactory::new()->save();

        $svc = $this->_service();
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);

        $approvalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $approval = $approvalsTable->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($approval);
        $approvalsTable->saveOrFail($approval);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => $otherUser]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Sin autorización');
        $this->assertResponseNotContains('Aprobar grupo');
    }

    public function testAssignedApproverSeesGroupedSupports(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $invoiceWithDoc = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'invoice_number' => 'FAC-A',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'invoice_number' => 'FAC-B',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $docs = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $docs->saveOrFail($docs->newEntity([
            'invoice_id' => $invoiceWithDoc->id,
            'pipeline_status' => 'aprobacion',
            'file_name' => 'soporte-a.pdf',
            'file_path' => 'storage/test/soporte-a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
        ]));

        $approver = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);
        $approvalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $approval = $approvalsTable->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($approval);
        $approvalsTable->saveOrFail($approval);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => $approver]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes'); // sección de soportes presente
        $this->assertResponseContains('soporte-a.pdf'); // documento de FAC-A renderizado
        $this->assertResponseContains('storage/test/soporte-a.pdf'); // href de apertura del soporte
        $this->assertResponseContains('0 archivo'); // grupo de FAC-B (sin soportes) visible
    }

    public function testEmptyLotShowsEmptyState(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'invoice_number' => 'FAC-X'])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $approver = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);
        $approvalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $approval = $approvalsTable->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($approval);
        $approvalsTable->saveOrFail($approval);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => $approver]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Sin soportes adjuntos'); // empty state cuando el lote no tiene soportes
    }
}
