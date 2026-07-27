<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationServiceAdvanceTest extends TestCase
{
    public function testMethodExists(): void
    {
        $this->assertTrue(method_exists(NotificationService::class, 'sendAdvanceLegalizationApprovalLinkNotification'));
    }
}
