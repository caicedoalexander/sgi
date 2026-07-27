<?php
declare(strict_types=1);

namespace App\Test\TestCase\Authorization;

use App\Authorization\PipelineViewCoercion;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests puros para PipelineViewCoercion. Verifican el invariante "operar
 * implica ver": un pipeline con pasos marcados fuerza can_view del módulo
 * mapeado, incluyendo los 3 mapeos cuyo slug de pipeline difiere del de módulo.
 */
final class PipelineViewCoercionTest extends TestCase
{
    public function testStepMarkedForcesCanViewOnSameSlugModule(): void
    {
        $data = [
            'pipeline_permissions' => [
                'invoices' => ['aprobacion' => '1'],
            ],
        ];

        $result = PipelineViewCoercion::apply($data);

        $this->assertSame('1', $result['permissions']['invoices']['can_view']);
    }

    public function testLegalizationsPipelineForcesCanViewOnAdvancesModule(): void
    {
        // Mapeo divergente clave: pipeline 'legalizations' → módulo 'advances'.
        $data = [
            'pipeline_permissions' => [
                'legalizations' => ['contabilidad' => '1'],
            ],
        ];

        $result = PipelineViewCoercion::apply($data);

        $this->assertSame('1', $result['permissions']['advances']['can_view']);
        $this->assertArrayNotHasKey('legalizations', $result['permissions']);
    }

    public function testNoveltyPipelinesMapToTheirDistinctModules(): void
    {
        $data = [
            'pipeline_permissions' => [
                'novelties' => ['aprobacion' => '1'],
                'liquidation_docs' => ['contabilidad' => '1'],
            ],
        ];

        $result = PipelineViewCoercion::apply($data);

        $this->assertSame('1', $result['permissions']['employee_novelties']['can_view']);
        $this->assertSame('1', $result['permissions']['novelty_liquidation_docs']['can_view']);
    }

    public function testNoStepsLeavesPermissionsUntouched(): void
    {
        $data = [
            'pipeline_permissions' => [
                'invoices' => ['aprobacion' => '0', 'contabilidad' => ''],
            ],
        ];

        $result = PipelineViewCoercion::apply($data);

        $this->assertArrayNotHasKey('permissions', $result);
    }

    public function testEmptyPipelinePermissionsIsNoop(): void
    {
        $this->assertSame([], PipelineViewCoercion::apply([]));
    }

    public function testPreservesExistingCrudFlagsWhenForcingView(): void
    {
        $data = [
            'permissions' => [
                'invoices' => ['can_edit' => '1', 'can_delete' => '1'],
            ],
            'pipeline_permissions' => [
                'invoices' => ['tesoreria' => '1'],
            ],
        ];

        $result = PipelineViewCoercion::apply($data);

        $this->assertSame('1', $result['permissions']['invoices']['can_view']);
        $this->assertSame('1', $result['permissions']['invoices']['can_edit']);
        $this->assertSame('1', $result['permissions']['invoices']['can_delete']);
    }

    public function testIsIdempotent(): void
    {
        $data = [
            'pipeline_permissions' => [
                'refunds' => ['agrupacion' => '1'],
            ],
        ];

        $once = PipelineViewCoercion::apply($data);
        $twice = PipelineViewCoercion::apply($once);

        $this->assertSame($once, $twice);
    }
}
