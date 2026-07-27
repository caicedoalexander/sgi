<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetCategoriesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/asset-categories');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testAddRequiresAuthentication(): void
    {
        $this->get('/asset-categories/add');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
