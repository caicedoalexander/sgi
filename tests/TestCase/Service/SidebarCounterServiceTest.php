<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Application;
use App\Service\SidebarCounterService;
use App\Test\Factory\RoleFactory;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

class SidebarCounterServiceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::clear('sidebar');
    }

    public function testMyPendingTotalSumsTheEightMineCounts(): void
    {
        // Rol sin permisos: todos los "mine" = 0 -> total 0.
        $roleId = (int)RoleFactory::new()->save()->id;

        $service = (new Application(dirname(__DIR__, 3) . '/config'))->getContainer()
            ->get(SidebarCounterService::class);
        $counters = $service->getCounters($roleId);

        $this->assertArrayHasKey('myPendingTotal', $counters);
        $this->assertArrayHasKey('paymentSchedulingsMineCount', $counters);
        $this->assertSame(0, $counters['myPendingTotal']);
    }
}
