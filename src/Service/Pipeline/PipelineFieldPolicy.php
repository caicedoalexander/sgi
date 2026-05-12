<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Authorization\AuthorizationFacade;
use App\ValueObject\UserContext;

/**
 * Base abstracta para las políticas de acceso a campos editables y secciones
 * visibles de cada pipeline. Cada subclase declara su mapeo `step → fields` y
 * `step → sections`. La autorización rol×paso se delega a `AuthorizationFacade`.
 *
 * Audit PA-008 — unifica el patrón que antes existía duplicado entre
 * InvoiceFieldAccessPolicy (clase dedicada), NoveltyService (constantes en
 * service), PettyCashService::_filterEditPatch (lógica inline) y
 * RefundsController::edit (lógica inline en controller).
 */
abstract class PipelineFieldPolicy
{
    /**
     * @param \App\Authorization\AuthorizationFacade $auth Fachada de autorización.
     */
    public function __construct(
        protected readonly AuthorizationFacade $auth,
    ) {
    }

    /**
     * Campos editables por paso del pipeline (sin acoplamiento a rol).
     *
     * @return array<string, array<int, string>> step => editable field names
     */
    abstract protected static function fieldsByStep(): array;

    /**
     * Secciones del formulario asociadas a cada paso (unión cuando el rol opera varios).
     *
     * @return array<string, array<int, string>> step => visible section keys
     */
    abstract protected static function sectionsByStep(): array;

    /**
     * Identificador del pipeline para consultar `AuthorizationFacade`.
     *
     * @return string PipelineStepConstants::PIPELINE_*
     */
    abstract protected static function pipelineKey(): string;

    /**
     * Secciones siempre visibles independientemente del rol/estado. Las
     * subclases override si necesitan, p. ej. Invoice retorna `['ledger']`.
     *
     * @return array<int, string>
     */
    protected static function alwaysVisibleSections(): array
    {
        return [];
    }

    /**
     * Campos editables que el rol puede tocar en el step actual del registro.
     *
     * @return array<int, string>
     */
    public function getEditableFields(int $roleId, string $step): array
    {
        if (!$this->auth->canOperate(new UserContext($roleId), static::pipelineKey(), $step)) {
            return [];
        }

        return static::fieldsByStep()[$step] ?? [];
    }

    /**
     * Secciones visibles para el rol — unión de las secciones de todos los steps
     * en los que el rol puede operar, más las siempre visibles.
     *
     * El segundo argumento `$currentStep` es opcional y se ignora; existe para
     * retrocompatibilidad con callers legacy de `InvoiceFieldAccessPolicy` y
     * `NoveltyService` que lo pasaban antes del refactor PA-008.
     *
     * @return array<int, string>
     */
    final public function getVisibleSections(int $roleId, string $currentStep = ''): array
    {
        unset($currentStep);
        $sections = static::alwaysVisibleSections();
        $operableSteps = $this->auth->operableSteps(
            new UserContext($roleId),
            static::pipelineKey(),
        );

        foreach ($operableSteps as $step) {
            $sections = array_merge($sections, static::sectionsByStep()[$step] ?? []);
        }

        return array_values(array_unique($sections));
    }

    /**
     * Filtra los datos crudos del POST a solo los campos editables y aplica
     * validación inline específica del dominio. Las subclases override para
     * añadir reglas (p. ej. PettyCash exige `accrual_date` cuando `accrued=true`).
     */
    public function filterEntityData(array $data, int $roleId, string $step): FilterResult
    {
        $allowed = $this->getEditableFields($roleId, $step);
        $patch = array_intersect_key($data, array_flip($allowed));

        return new FilterResult(patch: $patch, errors: []);
    }
}
