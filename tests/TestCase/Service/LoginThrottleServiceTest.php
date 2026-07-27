<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\LoginThrottleService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class LoginThrottleServiceTest extends TestCase
{
    private LoginThrottleService $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        TableRegistry::getTableLocator()->get('RateLimitBuckets')->deleteAll('1=1');
        $this->throttle = new LoginThrottleService();
    }

    public function testNotBlockedInitially(): void
    {
        $this->assertFalse($this->throttle->isBlocked('ana'));
    }

    public function testBlocksAtThreshold(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertTrue($this->throttle->isBlocked('ana'));
    }

    public function testNineFailuresStayBelowThreshold(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertFalse($this->throttle->isBlocked('ana'));
    }

    public function testClearResetsCounter(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->throttle->clear('ana');
        $this->assertFalse($this->throttle->isBlocked('ana'));
    }

    public function testAccountsAreIndependent(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertTrue($this->throttle->isBlocked('ana'));
        $this->assertFalse($this->throttle->isBlocked('bob'));
    }

    public function testUsernameIsNormalized(): void
    {
        // 'Ana', ' ana ' y 'ana' apuntan al mismo bucket → 10 fallos → bloqueada.
        $this->throttle->registerFailure('Ana');
        $this->throttle->registerFailure(' ana ');
        for ($i = 0; $i < 8; $i++) {
            $this->throttle->registerFailure('ana');
        }
        $this->assertTrue($this->throttle->isBlocked('ANA'));
    }
}
