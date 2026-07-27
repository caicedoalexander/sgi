<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Guard de autenticación de las acciones de aprobación de grupo de Reintegros
 * (sendApprovalLinks/modifyApprovers): sin sesión, ambas rutas redirigen a
 * /login. El proyecto no tiene infra de sesión autenticada en tests de
 * integración (ver InvoicesControllerTest, ApprovalsControllerTest,
 * AssetsMovementActionsTest); el comportamiento autenticado (creación de
 * refund_approvals, envío de enlaces, supersedeAll) está cubierto a nivel de
 * servicio por RefundApprovalServiceTest.
 */
final class RefundsControllerApprovalTest extends TestCase
{
    use IntegrationTestTrait;

    public function testSendApprovalLinksRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/refunds/send-approval-links/1', ['approver_ids' => [1]]);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testGetOnSendApprovalLinksIsNotAllowed(): void
    {
        // Sin sesión, el gate de auth corre antes que allowMethod → redirect login.
        $this->get('/refunds/send-approval-links/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testModifyApproversRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/refunds/modify-approvers/1', ['approver_ids' => [1], 'reason' => 'test']);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testGetOnModifyApproversIsNotAllowed(): void
    {
        $this->get('/refunds/modify-approvers/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
