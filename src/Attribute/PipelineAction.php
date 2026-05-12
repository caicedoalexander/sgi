<?php
declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Marca una acción como operación de un paso de pipeline.
 *
 * - Con $step explícito ⇒ el gate llama
 *   `PipelineAuthorizationService::canOperate(roleId, pipeline, step)`
 *   y rechaza con 403 si falla. El CRUD del módulo no se chequea.
 *
 * - Sin $step (null) ⇒ acción dinámica (advance/regress, uploadDocument
 *   contextual, etc.): el gate SOLO salta el CRUD; la responsabilidad
 *   de llamar `canOperate` o `denialReasonForAdvance` queda dentro del
 *   método del controller.
 *
 * Valores válidos para $pipeline: ver constantes `PIPELINE_*` en
 * `PipelineStepConstants`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PipelineAction
{
    /**
     * @param string $pipeline Slug del pipeline (constantes `PIPELINE_*` en `PipelineStepConstants`).
     * @param string|null $step Paso específico, o null para acciones dinámicas (el gate sólo salta el CRUD).
     */
    public function __construct(
        public readonly string $pipeline,
        public readonly ?string $step = null,
    ) {
    }
}
