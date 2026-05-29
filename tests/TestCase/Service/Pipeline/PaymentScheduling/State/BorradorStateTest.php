<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\PaymentSchedulingGuard;
use App\Service\Pipeline\PaymentScheduling\State\BorradorState;
use PHPUnit\Framework\TestCase;

/**
 * A8 — BorradorState es puro: delega el conteo de items al PaymentSchedulingGuard
 * inyectado (sin BD; el Guard se mockea).
 */
final class BorradorStateTest extends TestCase
{
    public function testStatusTransitions(): void
    {
        $state = new BorradorState($this->createStub(PaymentSchedulingGuard::class));

        $this->assertSame(PipelineStatus::BORRADOR, $state->getStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $state->getNextStatus());
        $this->assertNull($state->getPreviousStatus());
    }

    public function testErrorsWhenNoLinkedItems(): void
    {
        $guard = $this->createMock(PaymentSchedulingGuard::class);
        $guard->expects($this->once())->method('hasLinkedItems')->with(7)->willReturn(false);

        $state = new BorradorState($guard);
        $errors = $state->validateAdvance(new PaymentScheduling(['id' => 7]));

        $this->assertSame(['Debe vincular al menos una factura'], $errors);
    }

    public function testPassesWhenHasLinkedItems(): void
    {
        $guard = $this->createMock(PaymentSchedulingGuard::class);
        $guard->method('hasLinkedItems')->willReturn(true);

        $state = new BorradorState($guard);

        $this->assertSame([], $state->validateAdvance(new PaymentScheduling(['id' => 7])));
    }
}
