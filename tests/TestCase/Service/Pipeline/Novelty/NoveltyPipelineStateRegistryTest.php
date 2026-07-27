<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Novelty;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Service\Pipeline\Novelty\NoveltyPipelineStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoveltyPipelineStateRegistryTest extends TestCase
{
    public function testRegistryHasNineStatesExcludingEdgeStates(): void
    {
        // REGISTRO y RECHAZADA no tienen State class.
        $registry = new NoveltyPipelineStateRegistry();
        $this->assertCount(9, $registry->all());
    }

    #[DataProvider('mappedCases')]
    public function testGetResolvesMappedEnumCase(PipelineStatus $case): void
    {
        $registry = new NoveltyPipelineStateRegistry();
        $this->assertSame($case, $registry->get($case)->getStatus(), $case->value);
    }

    /**
     * Los 9 estados con State class (todos menos REGISTRO y RECHAZADA, que
     * lanzan excepción — cubiertos por testGetRegistroThrows/testGetRechazadaThrows).
     *
     * @return array<string, array{PipelineStatus}>
     */
    public static function mappedCases(): array
    {
        return [
            'aprobacion' => [PipelineStatus::APROBACION],
            'rrhh' => [PipelineStatus::RRHH],
            'contabilidad' => [PipelineStatus::CONTABILIDAD],
            'revision_firmas' => [PipelineStatus::REVISION_FIRMAS],
            'gdp' => [PipelineStatus::GDP],
            'tesoreria' => [PipelineStatus::TESORERIA],
            'autorizacion_pago' => [PipelineStatus::AUTORIZACION_PAGO],
            'verificacion_pago' => [PipelineStatus::VERIFICACION_PAGO],
            'pagada' => [PipelineStatus::PAGADA],
        ];
    }

    public function testGetRegistroThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registro');
        (new NoveltyPipelineStateRegistry())->get(PipelineStatus::REGISTRO);
    }

    public function testGetRechazadaThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rechazada');
        (new NoveltyPipelineStateRegistry())->get(PipelineStatus::RECHAZADA);
    }
}
