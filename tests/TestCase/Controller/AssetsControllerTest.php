<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/assets');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testViewRequiresAuthentication(): void
    {
        $this->get('/assets/view/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
