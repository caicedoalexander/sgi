<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Novelty;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Service\Pipeline\Novelty\NoveltyPipelineStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NoveltyPipelineStateRegistryTest extends TestCase
{
    public function testRegistryHasNineStatesExcludingEdgeStates(): void
    {
        // REGISTRO y RECHAZADA no tienen State class.
        $registry = new NoveltyPipelineStateRegistry();
        $this->assertCount(9, $registry->all());
    }

    public function testGetResolvesEveryMappedEnumCase(): void
    {
        $registry = new NoveltyPipelineStateRegistry();
        $excluded = [PipelineStatus::REGISTRO, PipelineStatus::RECHAZADA];

        foreach (PipelineStatus::cases() as $case) {
            if (in_array($case, $excluded, true)) {
                continue;
            }
            $this->assertSame($case, $registry->get($case)->getStatus(), $case->value);
        }
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
