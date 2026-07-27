<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\View\Presentation\AdvancePresentation;
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\NoveltyPresentation;
use App\View\Presentation\PaymentSchedulingPresentation;
use App\View\Presentation\PettyCashPresentation;
use App\View\Presentation\PipelineColorMap;
use App\View\Presentation\RefundPresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guard anti-drift: garantiza que el color de cada tipo de estado es ÚNICO
 * a través de todos los módulos de flujo. La fuente es PipelineColorMap;
 * cada {Modulo}Presentation::STATUS_BADGES debe coincidir estado-por-estado.
 *
 * Si este test falla, alguien asignó a un estado un pill distinto al canónico
 * (regresión de la consistencia cross-módulo).
 */
class PipelineColorConsistencyTest extends TestCase
{
    /** @return array<string, array{0: array<string, string>}> */
    public static function statusBadgesProvider(): array
    {
        return [
            'Invoice'           => [InvoicePresentation::STATUS_BADGES],
            'PettyCash'         => [PettyCashPresentation::STATUS_BADGES],
            'Refund'            => [RefundPresentation::STATUS_BADGES],
            'PaymentScheduling' => [PaymentSchedulingPresentation::STATUS_BADGES],
            'Advance'           => [AdvancePresentation::STATUS_BADGES],
            'Novelty'           => [NoveltyPresentation::STATUS_BADGES],
        ];
    }

    /**
     * @param array<string, string> $badges
     */
    #[DataProvider('statusBadgesProvider')]
    public function testStatusBadgesMatchCanonicalMap(array $badges): void
    {
        foreach ($badges as $status => $pill) {
            $this->assertSame(
                PipelineColorMap::pill($status),
                $pill,
                sprintf('El estado "%s" tiene un pill divergente del canon PipelineColorMap.', $status),
            );
        }
    }

    public function testContabilidadIsOrangeEverywhere(): void
    {
        // Caso explícito de la regla: un mismo estado = un mismo color en todos.
        foreach (self::statusBadgesProvider() as $module => [$badges]) {
            if (isset($badges['contabilidad'])) {
                $this->assertSame(
                    'pill-orange-soft',
                    $badges['contabilidad'],
                    sprintf('contabilidad debe ser naranja en %s.', $module),
                );
            }
        }
    }
}
