<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Guard de autenticación de la bandeja de aprobaciones: sin sesión, redirige a
 * /login (sigue el patrón de PagesControllerTest; el proyecto no tiene infra de
 * sesión autenticada en tests).
 */
class ApprovalsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testInboxRequiresAuthentication(): void
    {
        $this->get('/approvals');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testViewRequiresAuthentication(): void
    {
        $this->get('/approvals/view/invoice/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
