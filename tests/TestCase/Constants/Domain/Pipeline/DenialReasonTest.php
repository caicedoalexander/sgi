<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\Pipeline;

use App\Constants\Domain\Pipeline\DenialReason;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para el enum DenialReason — motivos de denegación de avance/regresión.
 */
final class DenialReasonTest extends TestCase
{
    public function testHasExpectedCases(): void
    {
        $values = array_map(fn(DenialReason $r) => $r->value, DenialReason::cases());

        $this->assertSame(
            ['terminal_state', 'unauthorized', 'rejected', 'requires_payment', 'managed_elsewhere'],
            $values,
        );
    }

    public function testMessagesAreDistinctAndDescriptive(): void
    {
        $this->assertStringContainsString('estado final', DenialReason::TERMINAL_STATE->message());
        $this->assertStringContainsString('permisos', DenialReason::UNAUTHORIZED->message());
        $this->assertStringContainsString('rechazado', DenialReason::REJECTED->message());
        $this->assertStringContainsString('Tesorería', DenialReason::REQUIRES_PAYMENT->message());
        $this->assertStringContainsString('sección de pagos', DenialReason::MANAGED_ELSEWHERE->message());
    }

    public function testEveryCaseHasNonEmptyMessage(): void
    {
        foreach (DenialReason::cases() as $reason) {
            $this->assertNotSame('', $reason->message());
        }
    }
}
