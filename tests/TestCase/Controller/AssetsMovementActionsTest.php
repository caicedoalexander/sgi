<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetsMovementActionsTest extends TestCase
{
    use IntegrationTestTrait;

    public function testAssignRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/assets/assign/1', ['to_employee_id' => 1]);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testGetOnAssignIsNotAllowed(): void
    {
        // Sin sesión, el gate de auth corre antes que allowMethod → redirect login.
        $this->get('/assets/assign/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
