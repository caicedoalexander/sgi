<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class PendingControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRendersForAnyAuthenticatedUser(): void
    {
        $role = RoleFactory::new()->save();
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $this->session(['Auth' => $user]);

        $this->get('/pendientes');

        $this->assertResponseOk();
        $this->assertResponseContains('Mis Pendientes');
        $this->assertResponseContains('bi-check2-square');
    }

    public function testIndexAcceptsModuleFilter(): void
    {
        $role = RoleFactory::new()->save();
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $this->session(['Auth' => $user]);

        $this->get('/pendientes?module=invoices');

        $this->assertResponseOk();
    }

    public function testIndexToleratesArraySearchParam(): void
    {
        $role = RoleFactory::new()->save();
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $this->session(['Auth' => $user]);

        $this->get('/pendientes?q[]=x');

        $this->assertResponseOk();
    }
}
