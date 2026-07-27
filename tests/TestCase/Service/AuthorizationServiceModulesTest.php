<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\AuthorizationService;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceModulesTest extends TestCase
{
    public function testItamModulesRegistered(): void
    {
        foreach (['assets', 'consumables', 'asset_categories', 'asset_alerts'] as $module) {
            $this->assertArrayHasKey($module, AuthorizationService::MODULES);
        }
        $this->assertSame('Activos', AuthorizationService::MODULES['assets']);
    }

    public function testEveryModuleBelongsToAGroup(): void
    {
        $grouped = array_merge(...array_values(AuthorizationService::MODULE_GROUPS));
        foreach (array_keys(AuthorizationService::MODULES) as $module) {
            $this->assertContains($module, $grouped, "El módulo '$module' no está en ningún MODULE_GROUP.");
        }
    }
}
