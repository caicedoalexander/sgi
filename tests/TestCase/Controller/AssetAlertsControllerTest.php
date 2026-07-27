<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetAlertsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/asset-alerts');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testResolveRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/asset-alerts/resolve/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
