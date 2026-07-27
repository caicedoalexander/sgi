<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Approval;

use App\Service\Approval\TokenRecord;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

class TokenRecordTest extends TestCase
{
    public function testExposesImmutableFields(): void
    {
        $expiresAt = new DateTime('2026-06-05 12:00:00');

        $record = new TokenRecord('invoices', 42, $expiresAt);

        $this->assertSame('invoices', $record->entityType);
        $this->assertSame(42, $record->entityId);
        $this->assertSame($expiresAt, $record->expiresAt);
    }
}
