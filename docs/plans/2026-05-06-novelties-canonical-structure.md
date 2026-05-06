# Novelties — Migración a estructura canónica (Implementation Plan)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Llevar el módulo Novelties a la base canónica de la auditoría 2026-05-06: extraer Pipeline State pattern (8 estados con doble validación individual/grupal), renombrar `NoveltyPipelineService` → `NoveltyService`, crear ViewModels Add/Edit para `EmployeeNoveltiesController` y Edit para `NoveltyLiquidationDocsController`.

**Architecture:** Patrón canónico replicando PaymentSchedulings. `NoveltyService` queda como coordinador (visibilidad por rol + transiciones + asignación a documento de liquidación). `Pipeline/Novelty/State/*` con interfaz `getName/getNext/getPrevious/validateAdvanceIndividual/validateAdvanceGroup` + Registry. ViewModels para los dos controllers que consumen el servicio.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB. Sin migraciones de BD (refactor 100% PHP).

**Design doc:** `docs/plans/2026-05-06-novelties-canonical-structure-design.md`
**Audit fuente:** `docs/audits/flow-structure-audit-2026-05-06.md` (Plan B)
**Branch sugerido:** `refactor/novelties-canonical-structure`

**Política del proyecto (CLAUDE.md):** No hay tests automatizados. Cada task termina con **validación manual** específica en lugar de tests. Si la validación falla, no commit hasta resolver.

---

## Task 1: Crear interfaz y Registry del Pipeline State pattern

**Files:**
- Create: `src/Service/Pipeline/Novelty/NoveltyPipelineState.php`
- Create: `src/Service/Pipeline/Novelty/NoveltyPipelineStateRegistry.php`

**Step 1: Crear directorio**

```bash
mkdir -p src/Service/Pipeline/Novelty/State
```

**Step 2: Crear interfaz**

Archivo `src/Service/Pipeline/Novelty/NoveltyPipelineState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty;

use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;

/**
 * Polymorphic representation of one Novelty pipeline state.
 *
 * Each State knows its base transitions (next/previous, sin saltos condicionales)
 * y dos métodos de validación porque una novedad puede avanzar como individual
 * (EmployeeNovelty) o como grupo (NoveltyLiquidationDoc) según la etapa.
 *
 * Cross-cutting checks (role authorization, conditional skips, side effects)
 * are composed by the coordinator (NoveltyService).
 */
interface NoveltyPipelineState
{
    /** Canonical name (e.g. 'aprobacion'). */
    public function getName(): string;

    /** Base next state's name (sin saltos condicionales); null if terminal. */
    public function getNext(): ?string;

    /** Previous state's name; null if first state. */
    public function getPrevious(): ?string;

    /**
     * Errores que impiden avanzar como novedad individual.
     * Si la etapa no aplica al modo individual, retornar el error correspondiente.
     *
     * @return array<string>
     */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array;

    /**
     * Errores que impiden avanzar como documento de liquidación grupal.
     * Si la etapa no aplica al modo grupal, retornar el error correspondiente.
     *
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array;
}
```

**Step 3: Crear Registry (placeholder hasta tener States — Task 2 los crea)**

Archivo `src/Service/Pipeline/Novelty/NoveltyPipelineStateRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty;

use App\Service\Pipeline\Novelty\State\AprobacionState;
use App\Service\Pipeline\Novelty\State\AutPagoState;
use App\Service\Pipeline\Novelty\State\ContabilidadState;
use App\Service\Pipeline\Novelty\State\GdpState;
use App\Service\Pipeline\Novelty\State\PagadaState;
use App\Service\Pipeline\Novelty\State\RevisionFirmasState;
use App\Service\Pipeline\Novelty\State\RrhhState;
use App\Service\Pipeline\Novelty\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resolves `employee_novelties.pipeline_status` (string) to a concrete State.
 * Sole dependency the coordinator (NoveltyService) needs to access states.
 */
final class NoveltyPipelineStateRegistry
{
    /** @var array<string, \App\Service\Pipeline\Novelty\NoveltyPipelineState> */
    private array $states;

    public function __construct(
        ?AprobacionState $aprobacion = null,
        ?RrhhState $rrhh = null,
        ?ContabilidadState $contabilidad = null,
        ?RevisionFirmasState $revisionFirmas = null,
        ?GdpState $gdp = null,
        ?TesoreriaState $tesoreria = null,
        ?AutPagoState $autPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $aprobacion ?? new AprobacionState(),
            $rrhh ?? new RrhhState(),
            $contabilidad ?? new ContabilidadState(),
            $revisionFirmas ?? new RevisionFirmasState(),
            $gdp ?? new GdpState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): NoveltyPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown novelty pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\Novelty\NoveltyPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}
```

**Step 4: Validación**

Sólo verificación de sintaxis — los States todavía no existen, el Registry no se puede instanciar todavía.

Run: `composer cs-check src/Service/Pipeline/Novelty/`
Expected: sin errores. Si hay, `composer cs-fix`.

Run: `php -l src/Service/Pipeline/Novelty/NoveltyPipelineState.php`
Run: `php -l src/Service/Pipeline/Novelty/NoveltyPipelineStateRegistry.php`
Expected: `No syntax errors detected` para ambos.

**Step 5: Commit**

```bash
git add src/Service/Pipeline/Novelty/
git commit -m "feat(novelties): add pipeline state interface and registry"
```

---

## Task 2: Crear los 8 Estados concretos

**Files:**
- Create: `src/Service/Pipeline/Novelty/State/AprobacionState.php`
- Create: `src/Service/Pipeline/Novelty/State/RrhhState.php`
- Create: `src/Service/Pipeline/Novelty/State/ContabilidadState.php`
- Create: `src/Service/Pipeline/Novelty/State/RevisionFirmasState.php`
- Create: `src/Service/Pipeline/Novelty/State/GdpState.php`
- Create: `src/Service/Pipeline/Novelty/State/TesoreriaState.php`
- Create: `src/Service/Pipeline/Novelty/State/AutPagoState.php`
- Create: `src/Service/Pipeline/Novelty/State/PagadaState.php`

> **Convención común a todos los States:** los métodos de validación que no aplican al modo retornan un único error explicativo (no array vacío) — así el llamador sabe que la operación está mal dirigida.

**Step 1: AprobacionState**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class AprobacionState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_APROBACION;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_RRHH;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        $errors = [];

        if (empty($novelty->approver_id)) {
            $errors[] = 'Debe asignar un aprobador.';
        }
        if (!empty($novelty->area_approval) && $novelty->area_approval === NoveltyConstants::APPROVAL_REJECTED) {
            $errors[] = 'La novedad fue rechazada por el aprobador. Edite y reenvíe para aprobación.';
        }

        return $errors;
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['Esta etapa no aplica a documentos de liquidación.'];
    }
}
```

**Step 2: RrhhState**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class RrhhState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_RRHH;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_CONTABILIDAD;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_APROBACION;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        if ($novelty->passes_payroll === null) {
            return ['Debe indicar si "Pasa a Nómina".'];
        }

        return [];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['Esta etapa no aplica a documentos de liquidación.'];
    }
}
```

**Step 3: ContabilidadState**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;
use Cake\ORM\TableRegistry;

final class ContabilidadState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_CONTABILIDAD;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_REVISION_FIRMAS;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_RRHH;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        if (empty($novelty->liquidation_doc_id)) {
            return ['La novedad debe estar asignada a un documento de liquidación.'];
        }

        return [];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $hasLiqDoc = $documentsTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'document_type' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
            ])
            ->count();

        if ($hasLiqDoc === 0) {
            return ['Debe subir el documento de liquidación antes de avanzar.'];
        }

        return [];
    }
}
```

**Step 4: RevisionFirmasState**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;
use Cake\ORM\TableRegistry;

final class RevisionFirmasState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_REVISION_FIRMAS;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_GDP;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_CONTABILIDAD;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];
        $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');

        // Solo validar firmas no-trabajador en revision_firmas
        $totalSlots = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
            ])
            ->count();

        $signedCount = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
                'signature_path IS NOT' => null,
            ])
            ->count();

        if ($signedCount < $totalSlots) {
            $errors[] = 'Todas las firmas requeridas (Contador y Coordinador) deben estar presentes para avanzar.';
        }

        // Si GDP va a ser saltado, validar passes_for_payment aquí
        $firstMember = TableRegistry::getTableLocator()->get('EmployeeNovelties')
            ->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $doc->id])
            ->first();

        if ($firstMember && $firstMember->novelty_type && !$firstMember->novelty_type->requires_employee_signature_review) {
            if ($doc->passes_for_payment === null) {
                $errors[] = 'Debe indicar si "Pasa para Pago".';
            }
        }

        return $errors;
    }
}
```

**Step 5: GdpState**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;
use Cake\ORM\TableRegistry;

final class GdpState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_GDP;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_TESORERIA;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_REVISION_FIRMAS;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];

        if ($doc->passes_for_payment === null) {
            $errors[] = 'Debe indicar si "Pasa para Pago".';
        }

        $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
        $workerSlot = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'signer_type' => NoveltyConstants::SIGNER_TRABAJADOR,
            ])
            ->first();

        if ($workerSlot && empty($workerSlot->signature_path)) {
            $errors[] = 'La firma del trabajador es requerida para avanzar.';
        }

        return $errors;
    }
}
```

**Step 6: TesoreriaState**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class TesoreriaState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_TESORERIA;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_AUT_PAGO;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_GDP;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['Debe registrar un pago para avanzar desde Tesorería.'];
    }
}
```

**Step 7: AutPagoState**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class AutPagoState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_AUT_PAGO;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_PAGADA;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_TESORERIA;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['La autorización de pago se gestiona desde la sección de pagos.'];
    }
}
```

**Step 8: PagadaState (terminal)**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class PagadaState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_PAGADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_AUT_PAGO;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['La novedad ya está en el estado final.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['El documento ya está en el estado final.'];
    }
}
```

**Step 9: Validación**

Run: `composer cs-check src/Service/Pipeline/Novelty/`
Expected: sin errores. Si hay, `composer cs-fix`.

Sanity check de instanciación e integridad de transiciones:

```bash
php -r "
require 'vendor/autoload.php';
require 'config/bootstrap.php';
\$r = new App\\Service\\Pipeline\\Novelty\\NoveltyPipelineStateRegistry();
echo \$r->get('aprobacion')->getNext() . PHP_EOL;
echo \$r->get('aut_pago')->getPrevious() . PHP_EOL;
echo (\$r->get('pagada')->getNext() ?? 'null') . PHP_EOL;
"
```

Expected output:
```
rrhh
tesoreria
null
```

Si arroja error, revisar los `use` de cada State.

**Step 10: Commit**

```bash
git add src/Service/Pipeline/Novelty/State/
git commit -m "feat(novelties): add concrete pipeline states"
```

---

## Task 3: Renombrar `NoveltyPipelineService` → `NoveltyService` + integrar Registry

**Files:**
- Rename: `src/Service/NoveltyPipelineService.php` → `src/Service/NoveltyService.php`
- Modify: `src/Application.php` (líneas 49, 302, 357)
- Modify: `src/Controller/EmployeeNoveltiesController.php` (líneas 15, 29, 49)
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (líneas 14, 27, 44)
- Modify: `src/Service/SidebarCounterService.php` (líneas 21, 27)

**Step 1: git mv del archivo**

```bash
git mv src/Service/NoveltyPipelineService.php src/Service/NoveltyService.php
```

**Step 2: Reescribir `NoveltyService.php` para usar Registry**

Editar `src/Service/NoveltyService.php`. Cambios:

1. Cambiar `class NoveltyPipelineService` → `class NoveltyService`.

2. Agregar import al tope: `use App\Service\Pipeline\Novelty\NoveltyPipelineStateRegistry;`.

3. Modificar el constructor para aceptar también el Registry:

```php
private PipelineAuthorizationService $pipelineAuth;
private NoveltyPipelineStateRegistry $stateRegistry;

public function __construct(
    ?PipelineAuthorizationService $pipelineAuth = null,
    ?NoveltyPipelineStateRegistry $stateRegistry = null,
) {
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    $this->stateRegistry = $stateRegistry ?? new NoveltyPipelineStateRegistry();
}
```

4. Reemplazar `validateTransition()` por la versión que delega al State:

```php
public function validateTransition(object $novelty, string $fromStatus): array
{
    if ($novelty->isRejected()) {
        return ['La novedad fue rechazada. El flujo ha terminado.'];
    }

    return $this->stateRegistry->get($fromStatus)->validateAdvanceIndividual($novelty);
}
```

5. Reemplazar `validateGroupTransition()`:

```php
public function validateGroupTransition(object $liquidationDoc): array
{
    return $this->stateRegistry->get($liquidationDoc->pipeline_status)->validateAdvanceGroup($liquidationDoc);
}
```

6. Agregar método privado `resolveNextStatus()` después del constructor:

```php
private function resolveNextStatus(object $novelty, ?object $type): ?string
{
    $current = $novelty->pipeline_status;
    if (in_array($current, [NoveltyConstants::STATUS_RECHAZADA, NoveltyConstants::STATUS_PAGADA], true)) {
        return null;
    }

    $next = $this->stateRegistry->get($current)->getNext();

    if ($next === NoveltyConstants::STATUS_APROBACION && $type && !$type->requires_boss_approval) {
        $next = $this->stateRegistry->get($next)->getNext();
    }
    if ($next === NoveltyConstants::STATUS_GDP && $type && !$type->requires_employee_signature_review) {
        $next = $this->stateRegistry->get($next)->getNext();
    }

    return $next;
}
```

7. Reemplazar el cuerpo de `getNextStatus()` por una llamada a `resolveNextStatus()`:

```php
public function getNextStatus(object $novelty, ?object $noveltyType = null): ?string
{
    if (!$noveltyType && !empty($novelty->novelty_type)) {
        $noveltyType = $novelty->novelty_type;
    }
    if (!$noveltyType && !empty($novelty->novelty_type_id)) {
        $noveltyType = TableRegistry::getTableLocator()->get('NoveltyTypes')
            ->get($novelty->novelty_type_id);
    }

    return $this->resolveNextStatus($novelty, $noveltyType);
}
```

8. Eliminar la constante `TRANSITIONS` que pueda haber quedado adentro del service (ya no la usa — vive en `NoveltyConstants::TRANSITIONS`). Verificar con `grep -n "self::TRANSITIONS\|TRANSITIONS =" src/Service/NoveltyService.php`.

> **Nota:** las firmas públicas (`advance`, `advanceGroup`, `reject`, `validateTransition`, `validateGroupTransition`, `getNextStatus`, `getEffectiveStatuses`, `getNoveltyStatuses`, `assignToLiquidationDoc`, `getVisibleStatuses`, `getVisibleLiquidationStatuses`, `getEditableFields`, `getVisibleSections`, `canAdvanceFromStatus`, `canAdvanceIndividually`, `filterEntityData`, `getVisibleFields`) **se mantienen idénticas**. Solo cambia el cuerpo interno de `validateTransition`, `validateGroupTransition` y `getNextStatus`.

**Step 3: Actualizar `src/Application.php`**

Reemplazos exactos (3 ocurrencias):

- Línea 49: `use App\Service\NoveltyPipelineService;` → `use App\Service\NoveltyService;`
- Línea 302: `$container->addShared(NoveltyPipelineService::class)` → `$container->addShared(NoveltyService::class)`
- Línea 357: `NoveltyPipelineService::class,` → `NoveltyService::class,`

Verificar el bloque `addShared` (línea 302 y siguientes): si tiene `->addArgument(...)` con dependencias antiguas, ajustar para que coincida con el nuevo constructor de `NoveltyService` (mismas dependencias: `PipelineAuthorizationService` ya estaba; `NoveltyPipelineStateRegistry` es nullable y se autoinstancia, no requiere wiring extra).

**Step 4: Actualizar `src/Controller/EmployeeNoveltiesController.php`**

Reemplazos exactos:

- Línea 15: `use App\Service\NoveltyPipelineService;` → `use App\Service\NoveltyService;`
- Línea 29: `private NoveltyPipelineService $pipelineService;` → `private NoveltyService $pipelineService;`
- Línea 49: `$this->pipelineService = $container->get(NoveltyPipelineService::class);` → `$this->pipelineService = $container->get(NoveltyService::class);`

> **Nota:** la propiedad sigue llamándose `$pipelineService` para minimizar diff. Renombrarla a `$noveltyService` agrandaría el blast radius (todos los llamadores en el controller). Se queda igual.

**Step 5: Actualizar `src/Controller/NoveltyLiquidationDocsController.php`**

Mismos reemplazos:

- Línea 14: `use App\Service\NoveltyPipelineService;` → `use App\Service\NoveltyService;`
- Línea 27: `private NoveltyPipelineService $pipelineService;` → `private NoveltyService $pipelineService;`
- Línea 44: `$this->pipelineService = $container->get(NoveltyPipelineService::class);` → `$this->pipelineService = $container->get(NoveltyService::class);`

**Step 6: Actualizar `src/Service/SidebarCounterService.php`**

- Línea 21 (PHPDoc): `@param \App\Service\NoveltyPipelineService $noveltyPipeline` → `@param \App\Service\NoveltyService $noveltyPipeline`
- Línea 27 (constructor): `private readonly NoveltyPipelineService $noveltyPipeline,` → `private readonly NoveltyService $noveltyPipeline,`
- Y el `use` correspondiente: `use App\Service\NoveltyPipelineService;` → `use App\Service\NoveltyService;`.

**Step 7: Verificar que no queda ninguna referencia obsoleta**

Run: `grep -rn "NoveltyPipelineService" src/ config/`
Expected: sin output (cero referencias).

Si aparece alguna, actualizarla antes de continuar.

**Step 8: Validación manual**

Run: `php bin/cake server`

1. Login. Si la página no carga porque DI falla → error en Step 3 (Application.php).
2. Abrir `/employee-novelties` → listado renderiza.
3. Abrir `/novelty-liquidation-docs` → listado renderiza.
4. Abrir cualquier novedad existente en `/employee-novelties/edit/{id}` → carga sin error 500. Botón Avanzar visible si corresponde al rol.
5. Abrir un documento de liquidación en `/novelty-liquidation-docs/edit/{id}` → carga sin error.
6. **Smoke test del Registry:** intentar avanzar una novedad — la transición debe comportarse igual que antes (mismas reglas, mismos errores).

Si alguno falla, revisar el log `logs/error.log` y `logs/debug.log`.

**Step 9: cs-check final**

Run: `composer cs-check`
Expected: sin errores. Si hay, `composer cs-fix`.

**Step 10: Commit**

```bash
git add src/Service/NoveltyService.php src/Application.php src/Controller/EmployeeNoveltiesController.php src/Controller/NoveltyLiquidationDocsController.php src/Service/SidebarCounterService.php
git commit -m "refactor(novelties): rename NoveltyPipelineService to NoveltyService with state registry"
```

---

## Task 4: Crear `EmployeeNoveltyEditViewModel` e integrar en controller

**Files:**
- Create: `src/ViewModel/EmployeeNoveltyEditViewModel.php`
- Modify: `src/Controller/EmployeeNoveltiesController.php` (método `edit`)
- Modify: `templates/EmployeeNovelties/edit.php`

**Step 1: Crear ViewModel**

Archivo `src/ViewModel/EmployeeNoveltyEditViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\EmployeeNovelty;

/**
 * Datos inmutables de vista para EmployeeNoveltiesController::edit().
 */
final class EmployeeNoveltyEditViewModel
{
    /**
     * @param array<string> $effectiveStatuses
     * @param array<string> $noveltyStatuses
     * @param array<string> $transitionErrors
     * @param array<int, string> $approversList
     * @param array<string, mixed> $documentsByStatus
     * @param array<int, string> $liquidationDocs
     * @param array<string> $editableFields
     * @param array<string> $visibleSections
     * @param iterable<int, mixed> $emailLogs
     */
    public function __construct(
        public readonly EmployeeNovelty $novelty,
        public readonly string $roleName,
        public readonly array $editableFields,
        public readonly array $visibleSections,
        public readonly array $effectiveStatuses,
        public readonly array $noveltyStatuses,
        public readonly ?string $nextStatus,
        public readonly array $transitionErrors,
        public readonly bool $canAdvance,
        public readonly bool $isApprovalRejected,
        public readonly array $approversList,
        public readonly array $documentsByStatus,
        public readonly array $liquidationDocs,
        public readonly iterable $emailLogs,
    ) {
    }
}
```

**Step 2: Refactorizar el método `edit` del controller**

En `src/Controller/EmployeeNoveltiesController.php`, reemplazar la implementación de `edit()` (líneas 381-475 aprox) por:

```php
public function edit(?string $id = null)
{
    $novelty = $this->EmployeeNovelties->get($id, contain: [
        'Employees',
        'NoveltyTypes',
        'ApprovedByUsers',
        'RegisteredByUsers',
        'RrhhByUsers',
        'NoveltyLiquidationDocs',
        'NoveltyObservations' => [
            'Users',
            'sort' => ['NoveltyObservations.created' => 'ASC'],
        ],
        'NoveltyDocuments' => [
            'UploadedByUsers',
            'sort' => ['NoveltyDocuments.created' => 'DESC'],
        ],
        'NoveltyMassiveEmployees' => ['Employees'],
    ]);

    $user = $this->Authentication->getIdentity()->getOriginalData();
    $roleName = $this->_getUserRoleName($user);
    $roleId = (int)$user->role_id;

    // Marcar observaciones como leídas (side effect previo a build)
    $this->observationService->markAsRead($user->id, noveltyId: $novelty->id);

    $this->set('viewModel', $this->_buildEditViewModel($novelty, $roleId, $roleName));
}

private function _buildEditViewModel(
    EmployeeNovelty $novelty,
    int $roleId,
    string $roleName,
): EmployeeNoveltyEditViewModel {
    $editableFields = $this->pipelineService->getEditableFields($roleId, $roleName, $novelty->pipeline_status);
    $visibleSections = $this->pipelineService->getVisibleSections($roleId, $roleName, $novelty->pipeline_status);
    $effectiveStatuses = $this->pipelineService->getEffectiveStatuses($novelty->novelty_type);
    $noveltyStatuses = $this->pipelineService->getNoveltyStatuses($novelty->novelty_type);
    $nextStatus = $this->pipelineService->getNextStatus($novelty);
    $transitionErrors = $this->pipelineService->validateTransition($novelty, $novelty->pipeline_status);
    $canAdvance = !$novelty->isRejected()
        && !$novelty->isPaid()
        && !$novelty->isGrouped()
        && $nextStatus !== null;

    $isApprovalRejected = $novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION
        && $novelty->area_approval === NoveltyConstants::APPROVAL_REJECTED;

    $approversList = [];
    if ($novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION) {
        $approvers = TableRegistry::getTableLocator()->get('Approvers')
            ->find()
            ->contain(['Users'])
            ->where(['Approvers.active' => true])
            ->all();
        foreach ($approvers as $approver) {
            if ($approver->user) {
                $approversList[$approver->user->id] = $approver->user->full_name;
            }
        }
    }

    $documentsByStatus = $this->documentService->getDocumentsByStatus($novelty->id);

    $liquidationDocs = [];
    if (
        $novelty->pipeline_status === NoveltyConstants::STATUS_CONTABILIDAD
        && !$novelty->isGrouped()
    ) {
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $liquidationDocs = $liquidationDocsTable->find('list', [
            'keyField' => 'id',
            'valueField' => 'liquidation_number',
        ])->where(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->toArray();
    }

    $emailLogService = $this->getContainer()->get(EmailLogService::class);
    $emailLogs = $emailLogService->forEntity('employee_novelty', (int)$novelty->id);

    return new EmployeeNoveltyEditViewModel(
        novelty: $novelty,
        roleName: $roleName,
        editableFields: $editableFields,
        visibleSections: $visibleSections,
        effectiveStatuses: $effectiveStatuses,
        noveltyStatuses: $noveltyStatuses,
        nextStatus: $nextStatus,
        transitionErrors: $transitionErrors,
        canAdvance: $canAdvance,
        isApprovalRejected: $isApprovalRejected,
        approversList: $approversList,
        documentsByStatus: $documentsByStatus,
        liquidationDocs: $liquidationDocs,
        emailLogs: $emailLogs,
    );
}
```

Agregar imports al tope del archivo:
- `use App\ViewModel\EmployeeNoveltyEditViewModel;`
- `use App\Model\Entity\EmployeeNovelty;` (si no estaba)

**Step 3: Actualizar template `templates/EmployeeNovelties/edit.php`**

Reemplazar referencias en el template. Lista exhaustiva de variables (todas accedidas vía `$viewModel->...`):

- `$novelty` → `$viewModel->novelty`
- `$roleName` → `$viewModel->roleName`
- `$editableFields` → `$viewModel->editableFields`
- `$visibleSections` → `$viewModel->visibleSections`
- `$effectiveStatuses` → `$viewModel->effectiveStatuses`
- `$noveltyStatuses` → `$viewModel->noveltyStatuses`
- `$nextStatus` → `$viewModel->nextStatus`
- `$transitionErrors` → `$viewModel->transitionErrors`
- `$canAdvance` → `$viewModel->canAdvance`
- `$isApprovalRejected` → `$viewModel->isApprovalRejected`
- `$approversList` → `$viewModel->approversList`
- `$documentsByStatus` → `$viewModel->documentsByStatus`
- `$liquidationDocs` → `$viewModel->liquidationDocs`
- `$emailLogs` → `$viewModel->emailLogs`

Buscar cada variable en el template (`grep -n '\$novelty\|\$roleName\|...'`) y reemplazar. Si alguna no aparece, está bien — significa que el set anterior la pasaba pero el template no la usaba.

**Step 4: Validación manual**

Run: `php bin/cake server`

1. Login como Auxiliar de Personal → `/employee-novelties` → entrar a una novedad en `aprobacion`. Vista carga, secciones visibles correctas, botón Avanzar deshabilitado si falta `approver_id`.
2. Login como Contabilidad → entrar a una novedad en `contabilidad` sin `liquidation_doc_id`. Aparece el dropdown "Asignar a documento de liquidación" con la lista de docs en estado `contabilidad`.
3. Editar y guardar cambios; avanzar de `rrhh` → `contabilidad` con `passes_payroll=true`. Avance exitoso, status cambia.
4. Verificar que las observaciones aparecen marcadas como leídas tras visitar la página.

Si falla en runtime con "Undefined variable", buscar la variable en el template y reemplazarla.

**Step 5: Commit**

```bash
git add src/ViewModel/EmployeeNoveltyEditViewModel.php src/Controller/EmployeeNoveltiesController.php templates/EmployeeNovelties/edit.php
git commit -m "refactor(novelties): introduce EmployeeNoveltyEditViewModel"
```

---

## Task 5: Crear `EmployeeNoveltyAddViewModel` e integrar en controller

**Files:**
- Create: `src/ViewModel/EmployeeNoveltyAddViewModel.php`
- Modify: `src/Controller/EmployeeNoveltiesController.php` (método `add`, sólo la rama GET / set final)
- Modify: `templates/EmployeeNovelties/add.php`

**Step 1: Crear ViewModel**

Archivo `src/ViewModel/EmployeeNoveltyAddViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\EmployeeNovelty;

/**
 * Datos inmutables de vista para EmployeeNoveltiesController::add() (GET).
 */
final class EmployeeNoveltyAddViewModel
{
    /**
     * @param iterable<int, mixed> $employees
     * @param array<int, array<int, string>> $noveltyTypes
     * @param array<int, string> $approversList
     */
    public function __construct(
        public readonly EmployeeNovelty $novelty,
        public readonly iterable $employees,
        public readonly array $noveltyTypes,
        public readonly array $approversList,
        public readonly ?int $preselectedEmployee,
    ) {
    }
}
```

**Step 2: Refactorizar el método `add` del controller**

En `src/Controller/EmployeeNoveltiesController.php`, **NO TOCAR el bloque POST** (líneas 567-670 aprox: signature handling, token generation, notification email — todo se mantiene tal cual). Solo modificar el bloque GET final (líneas ~683-700).

Reemplazar el bloque final del método `add()` (después del `if ($this->request->is('post')) { ... }`) por:

```php
$employees = $this->EmployeeNovelties->Employees->find('list', [
    'keyField' => 'id',
    'valueField' => 'full_name',
])->all();

$noveltyTypes = $this->_getNoveltyTypesGrouped();

$preselectedEmployeeRaw = $this->request->getQuery('employee_id');
$preselectedEmployee = $preselectedEmployeeRaw !== null ? (int)$preselectedEmployeeRaw : null;

$approvers = TableRegistry::getTableLocator()->get('Approvers')
    ->find()
    ->contain(['Users'])
    ->where(['Approvers.active' => true])
    ->all();

$approversList = [];
foreach ($approvers as $approver) {
    if ($approver->user) {
        $approversList[$approver->user->id] = $approver->user->full_name;
    }
}

$this->set('viewModel', new EmployeeNoveltyAddViewModel(
    novelty: $novelty,
    employees: $employees,
    noveltyTypes: $noveltyTypes,
    approversList: $approversList,
    preselectedEmployee: $preselectedEmployee,
));
```

> **Nota:** se elimina el `$this->set(compact(...))` final con las 5 vars y se reemplaza por el `set('viewModel', ...)`.

Agregar import al tope: `use App\ViewModel\EmployeeNoveltyAddViewModel;` (si no estaba ya por Task 4).

**Step 3: Actualizar template `templates/EmployeeNovelties/add.php`**

Reemplazos:
- `$novelty` → `$viewModel->novelty`
- `$employees` → `$viewModel->employees`
- `$noveltyTypes` → `$viewModel->noveltyTypes`
- `$preselectedEmployee` → `$viewModel->preselectedEmployee`
- `$approversList` → `$viewModel->approversList`

Si el template usa `$preselectedEmployee` en una comparación con un string, ahora es `int|null` — verificar que la comparación siga válida (probablemente `==` queda bien; si es `===`, ajustar).

**Step 4: Validación manual**

Run: `php bin/cake server`

1. `/employee-novelties/add` → formulario carga sin errores.
2. Crear novedad de tipo con `requires_boss_approval=true` (ej. tipo "Permiso") con un aprobador y empleado: estado inicial = `aprobacion`, email enviado al aprobador (verificar `logs/email_*` o panel de Email Logs si existe).
3. Crear novedad de tipo con `requires_boss_approval=false` (ej. tipo administrativo): estado inicial = `rrhh`, sin email.
4. Crear novedad masiva (varios empleados seleccionados): 1 novedad + N entradas en `novelty_massive_employees`.
5. Subir firma del empleado vía canvas (base64) y vía archivo: ambas rutas guardan archivo y path.
6. Acceder con `?employee_id=42` y verificar que el select de empleado preselecciona ese ID.

**Step 5: Commit**

```bash
git add src/ViewModel/EmployeeNoveltyAddViewModel.php src/Controller/EmployeeNoveltiesController.php templates/EmployeeNovelties/add.php
git commit -m "refactor(novelties): introduce EmployeeNoveltyAddViewModel"
```

---

## Task 6: Crear `NoveltyLiquidationDocEditViewModel` e integrar en controller

**Files:**
- Create: `src/ViewModel/NoveltyLiquidationDocEditViewModel.php`
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (método `edit`)
- Modify: `templates/NoveltyLiquidationDocs/edit.php`

**Step 1: Crear ViewModel**

Archivo `src/ViewModel/NoveltyLiquidationDocEditViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\NoveltyLiquidationDoc;
use App\Model\Entity\User;

/**
 * Datos inmutables de vista para NoveltyLiquidationDocsController::edit().
 */
final class NoveltyLiquidationDocEditViewModel
{
    /**
     * @param array<string> $groupErrors
     * @param array<string> $effectiveStatuses
     * @param array<string, mixed> $documentsByStatus
     * @param array<int, string> $bankingEntities
     */
    public function __construct(
        public readonly NoveltyLiquidationDoc $doc,
        public readonly string $roleName,
        public readonly array $groupErrors,
        public readonly array $effectiveStatuses,
        public readonly array $documentsByStatus,
        public readonly mixed $liquidationDocument,
        public readonly User $currentUser,
        public readonly bool $skipsGdp,
        public readonly array $bankingEntities,
        public readonly bool $isTesoreriaEdit,
        public readonly bool $isContadorAutPago,
    ) {
    }
}
```

> **Nota sobre `$liquidationDocument`:** es lo que retorna `NoveltyDocumentService::getLiquidationDocument()`. Mantener `mixed` evita acoplar con su tipo concreto si fuera nullable o un entity; si al ejecutar se confirma que es siempre `?NoveltyDocument`, ajustar a `?NoveltyDocument` en un follow-up.

**Step 2: Refactorizar el método `edit` del controller**

En `src/Controller/NoveltyLiquidationDocsController.php`, reemplazar la implementación de `edit()` (líneas 185-252):

```php
public function edit(?string $id = null)
{
    $doc = $this->NoveltyLiquidationDocs->get($id, contain: [
        'PerformedByUsers',
        'CreatedByUsers',
        'EmployeeNovelties' => ['Employees', 'NoveltyTypes'],
        'NoveltyLiquidationSignatures' => ['SignedByUsers'],
        'NoveltyObservations' => [
            'Users',
            'sort' => ['NoveltyObservations.created' => 'ASC'],
        ],
        'NoveltyDocuments' => [
            'UploadedByUsers',
            'sort' => ['NoveltyDocuments.created' => 'DESC'],
        ],
        'LiquidationDocPayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
    ]);

    $user = $this->Authentication->getIdentity()->getOriginalData();
    $this->observationService->markAsRead($user->id, liquidationDocId: $doc->id);

    $roleName = $this->_getUserRoleName($user);
    $this->set('viewModel', $this->_buildLiquidationEditViewModel($doc, $user, $roleName));
}

private function _buildLiquidationEditViewModel(
    NoveltyLiquidationDoc $doc,
    User $user,
    string $roleName,
): NoveltyLiquidationDocEditViewModel {
    $groupErrors = $this->pipelineService->validateGroupTransition($doc);
    $firstNovelty = $doc->employee_novelties[0] ?? null;
    $noveltyType = $firstNovelty?->novelty_type;
    $skipsGdp = $noveltyType && !$noveltyType->requires_employee_signature_review;
    $effectiveStatuses = $this->pipelineService->getEffectiveStatuses($noveltyType);
    $documentsByStatus = $this->documentService->getGroupDocumentsByStatus($doc->id);
    $liquidationDocument = $this->documentService->getLiquidationDocument($doc->id);

    $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();
    $roleId = (int)$user->role_id;
    $canOpTesoreria = $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_NOVELTIES,
        NoveltyConstants::STATUS_TESORERIA,
    );
    $canOpAutPago = $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_NOVELTIES,
        NoveltyConstants::STATUS_AUT_PAGO,
    );
    $isTesoreriaEdit = $canOpTesoreria
        && $doc->pipeline_status === NoveltyConstants::STATUS_TESORERIA;
    $isContadorAutPago = $canOpAutPago
        && $doc->pipeline_status === NoveltyConstants::STATUS_AUT_PAGO;

    return new NoveltyLiquidationDocEditViewModel(
        doc: $doc,
        roleName: $roleName,
        groupErrors: $groupErrors,
        effectiveStatuses: $effectiveStatuses,
        documentsByStatus: $documentsByStatus,
        liquidationDocument: $liquidationDocument,
        currentUser: $user,
        skipsGdp: (bool)$skipsGdp,
        bankingEntities: $bankingEntities,
        isTesoreriaEdit: $isTesoreriaEdit,
        isContadorAutPago: $isContadorAutPago,
    );
}
```

Agregar imports al tope:
- `use App\ViewModel\NoveltyLiquidationDocEditViewModel;`
- `use App\Model\Entity\NoveltyLiquidationDoc;`
- `use App\Model\Entity\User;`

**Step 3: Actualizar template `templates/NoveltyLiquidationDocs/edit.php`**

Reemplazos:
- `$doc` → `$viewModel->doc`
- `$groupErrors` → `$viewModel->groupErrors`
- `$effectiveStatuses` → `$viewModel->effectiveStatuses`
- `$documentsByStatus` → `$viewModel->documentsByStatus`
- `$liquidationDocument` → `$viewModel->liquidationDocument`
- `$currentUser` → `$viewModel->currentUser`
- `$skipsGdp` → `$viewModel->skipsGdp`
- `$roleName` → `$viewModel->roleName`
- `$bankingEntities` → `$viewModel->bankingEntities`
- `$isTesoreriaEdit` → `$viewModel->isTesoreriaEdit`
- `$isContadorAutPago` → `$viewModel->isContadorAutPago`

**Step 4: Validación manual**

Run: `php bin/cake server`

1. Login como Contador → `/novelty-liquidation-docs` → entrar a un doc en `revision_firmas`. Lista de firmas (2 o 3 slots según el tipo de novedad), miembros del grupo, botón Avanzar.
2. Subir firma del Contador → intentar avanzar antes de la firma del Coordinador. Error: "Todas las firmas requeridas...".
3. Avanzar a `gdp` → marcar `passes_for_payment=true` → subir firma del trabajador → avanzar a `tesoreria`. Cada transición exitosa.
4. Visitar un doc en `tesoreria` siendo Tesorería: `isTesoreriaEdit=true` se refleja en la vista (sección de pagos editable).
5. Visitar un doc en `aut_pago` siendo Contador: `isContadorAutPago=true` se refleja (botón autorizar visible).

**Step 5: Commit**

```bash
git add src/ViewModel/NoveltyLiquidationDocEditViewModel.php src/Controller/NoveltyLiquidationDocsController.php templates/NoveltyLiquidationDocs/edit.php
git commit -m "refactor(novelties): introduce NoveltyLiquidationDocEditViewModel"
```

---

## Task 7: Validación end-to-end final

Sin commit. Sólo ejecutar el checklist completo y reportar resultados. Si algún check falla, abrir un commit fix referenciando este task.

**Setup:**

```bash
php bin/cake server
```

Tener cuentas en estos roles disponibles: Auxiliar de Personal, Asistente de Personal, Contabilidad, Contador, Coordinador Administrativo, Tesorería, Administrador.

**Checklist:**

- [ ] **E1.1** — Login Auxiliar de Personal → `/employee-novelties` → novedad en `aprobacion` carga; secciones visibles correctas; botón Avanzar deshabilitado si falta `approver_id`.
- [ ] **E1.2** — Login Contabilidad → novedad en `contabilidad` sin `liquidation_doc_id`: aparece dropdown "Asignar a documento de liquidación".
- [ ] **E1.3** — Avanzar `rrhh → contabilidad` con `passes_payroll=true`: status cambia.
- [ ] **E2.1** — Crear novedad con tipo `requires_boss_approval=true`: estado inicial = `aprobacion`, email al aprobador.
- [ ] **E2.2** — Crear novedad con tipo `requires_boss_approval=false`: estado inicial = `rrhh`, sin email.
- [ ] **E2.3** — Crear novedad masiva: 1 novedad + N entradas en `novelty_massive_employees`.
- [ ] **E2.4** — Subir firma del empleado vía canvas (base64) y vía archivo: ambas rutas guardan archivo y path.
- [ ] **E3.1** — Login Contador → `/novelty-liquidation-docs` → doc en `revision_firmas`: lista de firmas (2 o 3 slots según tipo), miembros del grupo, botón Avanzar.
- [ ] **E3.2** — Subir firma Contador, intentar avanzar antes de firma Coordinador: error "Todas las firmas requeridas...".
- [ ] **E3.3** — Avanzar `revision_firmas → gdp → tesoreria` con todas las firmas y `passes_for_payment=true`: cada transición exitosa.
- [ ] **E4** — Calendarios `/employee-novelties/active` y `/employee-novelties/all`: eventos cargan con colores correctos (1 color por novelty_type).
- [ ] **E5** — `/employee-novelties/rejected`: solo novedades en `rechazada`.
- [ ] **E6** — Rechazar una novedad desde `aprobacion` con observaciones: status → `rechazada`, observaciones guardadas.
- [ ] **E7** — Saltos condicionales: novedad con `requires_employee_signature_review=false` que pasa de `revision_firmas` directo a `tesoreria` (omite `gdp`).
- [ ] **E8** — Saltos condicionales: novedad con `requires_boss_approval=false` creada en `rrhh` (omite `aprobacion`).
- [ ] **E9** — Sidebar counter de novedades pendientes: número correcto (validar con SidebarCounterService inyectando `NoveltyService` ahora).
- [ ] **Cero refs**: `grep -rn "NoveltyPipelineService" src/ config/` → sin output.
- [ ] **Code style**: `composer cs-check` → sin errores. Si hay, `composer cs-fix` y commit.
- [ ] **Tamaños objetivo**:
  - `wc -l src/Controller/EmployeeNoveltiesController.php` → ≤ 760.
  - `wc -l src/Service/NoveltyService.php` → entre 480 y 540.

Si todos los checks pasan: el plan está completo. Si alguno falla: revisar el task correspondiente y abrir un commit fix.

**Step final (si todo pasa): merge a main**

```bash
git checkout main
git merge --no-ff refactor/novelties-canonical-structure
git push origin main
```

Después actualizar `docs/audits/flow-structure-audit-2026-05-06.md` sección "Estado de los planes":

| Plan | Flujo | Estado | Fecha cierre |
|---|---|---|---|
| Plan A | PaymentSchedulings | ✅ Cerrado | 2026-05-DD |
| Plan B | Novelties | ✅ Cerrado | 2026-05-DD |

---

## Resumen de commits esperados

1. `feat(novelties): add pipeline state interface and registry`
2. `feat(novelties): add concrete pipeline states`
3. `refactor(novelties): rename NoveltyPipelineService to NoveltyService with state registry`
4. `refactor(novelties): introduce EmployeeNoveltyEditViewModel`
5. `refactor(novelties): introduce EmployeeNoveltyAddViewModel`
6. `refactor(novelties): introduce NoveltyLiquidationDocEditViewModel`

(Task 7 no tiene commit propio — sólo validación. Si surgen fixes, son commits adicionales con prefijo `fix(novelties): ...`.)
