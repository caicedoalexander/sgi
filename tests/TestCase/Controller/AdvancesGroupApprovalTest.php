<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Guard de rutas/HTTP method de las 4 acciones de grupo de Legalización de
 * Anticipos (sendApprovalLinks/modifyApprovers/moveToAprobacion/returnFromAprobacion).
 *
 * Sin sesión: igual que `RefundsControllerApprovalTest` (mismo patrón para el
 * análogo de Reintegros), el gate de auth (`AuthenticationComponent::startup`,
 * `requireIdentity`) corre en `Controller.startup` — ANTES de que la acción
 * llegue a ejecutar `allowMethod(['post'])` — así que una request sin sesión
 * siempre redirige a `/login` (302), sin importar si la ruta/acción ya existen.
 * No verifica el wiring por sí solo (confirmado: da 302 tanto antes como
 * después de conectar las rutas).
 *
 * Con sesión (`$this->session(['Auth' => $userEntity])` con una entidad `User`
 * real, confirmado en Fase 1 de Reintegros — ver `ExternalApprovalsGroupTest`):
 * la request sí llega al cuerpo de la acción. Las 4 acciones nuevas usan
 * `#[PipelineAction(pipeline: PIPELINE_LEGALIZATIONS)]` SIN `step` (acción
 * dinámica), por lo que el gate RBAC de `beforeFilter` se salta sin exigir
 * `pipeline_permissions` — cualquier usuario autenticado llega hasta
 * `allowMethod(['post'])`, que sí responde 405 en GET. Estos 4 casos
 * autenticados son los que realmente ejercitan el wiring de rutas/acciones.
 */
class AdvancesGroupApprovalTest extends TestCase
{
    use IntegrationTestTrait;

    public function testSendApprovalLinksRequiresAuthentication(): void
    {
        $this->get('/advances/send-approval-links/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testModifyApproversRequiresAuthentication(): void
    {
        $this->get('/advances/modify-approvers/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testMoveToAprobacionRequiresAuthentication(): void
    {
        $this->get('/advances/move-to-aprobacion/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testReturnFromAprobacionRequiresAuthentication(): void
    {
        $this->get('/advances/return-from-aprobacion/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testSendApprovalLinksGetNotAllowedWhenAuthenticated(): void
    {
        $this->session(['Auth' => $this->_authenticatedUser()]);
        $this->get('/advances/send-approval-links/1');
        $this->assertResponseCode(405);
    }

    public function testModifyApproversGetNotAllowedWhenAuthenticated(): void
    {
        $this->session(['Auth' => $this->_authenticatedUser()]);
        $this->get('/advances/modify-approvers/1');
        $this->assertResponseCode(405);
    }

    public function testMoveToAprobacionGetNotAllowedWhenAuthenticated(): void
    {
        $this->session(['Auth' => $this->_authenticatedUser()]);
        $this->get('/advances/move-to-aprobacion/1');
        $this->assertResponseCode(405);
    }

    public function testReturnFromAprobacionGetNotAllowedWhenAuthenticated(): void
    {
        $this->session(['Auth' => $this->_authenticatedUser()]);
        $this->get('/advances/return-from-aprobacion/1');
        $this->assertResponseCode(405);
    }

    private function _authenticatedUser(): object
    {
        $role = RoleFactory::new()->save();

        return UserFactory::new(['role_id' => $role->id])->save();
    }
}
