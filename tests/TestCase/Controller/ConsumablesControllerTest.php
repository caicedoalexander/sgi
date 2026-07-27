<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ConsumablesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/consumables');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testStockInRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/consumables/stock-in/1', ['quantity' => 5]);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
