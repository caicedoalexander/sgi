# Diseño — Estructura canónica para Novelties (Plan B)

**Fecha:** 2026-05-06
**Auditoría base:** [`docs/audits/flow-structure-audit-2026-05-06.md`](../audits/flow-structure-audit-2026-05-06.md) — Plan B
**Plan precedente (paridad):** [`2026-05-06-payment-schedulings-canonical-structure-design.md`](2026-05-06-payment-schedulings-canonical-structure-design.md)
**Alcance acordado:** Refactor estructural puro — alineación con la canónica, sin cambiar reglas de negocio ni transiciones.

---

## 1. Punto de partida

| Pieza | Estado actual | Problema |
|---|---|---|
| `NoveltyPipelineService` | 620 líneas, monolítico | Mezcla pipeline + visibilidad + validación grupal + asignación a documento de liquidación |
| `EmployeeNoveltiesController` | 922 líneas (el más obeso del sistema) | `edit()` y `add()` construyen sets de variables inline |
| `NoveltyLiquidationDocsController` | 540 líneas | Comparte `NoveltyPipelineService` y replica el patrón de `edit()` inline |
| ViewModels | inexistentes | Toda la lógica de presentación se ensambla en el controller |
| State pattern | ausente | 8 estados conviven en un único service |
| `NoveltyDocumentService`, `NoveltyHistoryService`, `NoveltyObservationService`, `NoveltySignatureService` | ya cumplen la canónica | — |

**Particularidades del módulo (vs. PaymentSchedulings):**

- 8 estados en vez de 4: `aprobacion → rrhh → contabilidad → revision_firmas → gdp → tesoreria → aut_pago → pagada`.
- Dos modos de avance:
  - **Individual** (`advance`): aplica en `aprobacion`, `rrhh`, `contabilidad`.
  - **Grupal** (`advanceGroup`) sobre `NoveltyLiquidationDoc`: aplica de `contabilidad` en adelante.
- Saltos condicionales según `NoveltyType`: salta `aprobacion` si `requires_boss_approval=false`; salta `gdp` si `requires_employee_signature_review=false`.
- Dos controllers comparten el mismo servicio.

---

## 2. Decisiones tomadas (brainstorming 2026-05-06)

| # | Decisión | Razón |
|---|---|---|
| 1 | Refactor estructural puro (opción A) | Alineación con auditoría, sin agrandar alcance ni tocar reglas |
| 2 | Un State por estado, dos métodos de validación: `validateAdvanceIndividual` y `validateAdvanceGroup` | Mantiene paridad con PaymentSchedulings (un State por estado) y modela explícitamente los dos modos sin `instanceof` ni jerarquías duplicadas |
| 3 | El refactor incluye también `NoveltyLiquidationDocsController` con su propio `EditViewModel` | Evita asimetría dentro del mismo módulo; el controller hermano comparte el servicio renombrado |

**Alcance fuera del plan:**
- Reglas de transición (idénticas a las actuales).
- Lógica de saltos condicionales (`requires_boss_approval`, `requires_employee_signature_review`) — se mantiene en el coordinator.
- POST handling de `add()`/`edit()` (signatures, tokens, notifications) — sigue en el controller.
- Templates: solo cambian las variables (`$novelty` → `$viewModel->novelty`); sin rediseño visual.
- Acciones JSON (`activeEvents`, `allEvents`) — se quedan como están.

---

## 3. Estructura objetivo

### 3.1 Renames

- `NoveltyPipelineService` → `NoveltyService` (mismo namespace `App\Service`).
- Firmas públicas se mantienen idénticas para reducir blast radius en los dos controllers.

### 3.2 Nuevos archivos

```
src/
├── ViewModel/
│   ├── EmployeeNoveltyAddViewModel.php
│   ├── EmployeeNoveltyEditViewModel.php
│   └── NoveltyLiquidationDocEditViewModel.php
└── Service/Pipeline/Novelty/
    ├── NoveltyPipelineState.php                ← interface
    ├── NoveltyPipelineStateRegistry.php
    └── State/
        ├── AprobacionState.php
        ├── RrhhState.php
        ├── ContabilidadState.php
        ├── RevisionFirmasState.php
        ├── GdpState.php
        ├── TesoreriaState.php
        ├── AutPagoState.php
        └── PagadaState.php
```

### 3.3 Naming

- ViewModels: `EmployeeNovelty*ViewModel` (paridad con entity/controller `EmployeeNovelty`/`EmployeeNoveltiesController`).
- Servicios y constants: siguen con `Novelty*` (dominio conceptual).
- States: nombre del estado en PascalCase + `State` (paridad con PaymentSchedulings: `AprobacionState`, `RrhhState`, …).

---

## 4. State pattern

### 4.1 Interface

```php
namespace App\Service\Pipeline\Novelty;

use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;

interface NoveltyPipelineState
{
    public function getName(): string;
    public function getNext(): ?string;            // base, sin saltos condicionales
    public function getPrevious(): ?string;

    /** @return array<string> errores que impiden avanzar como novedad individual */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array;

    /** @return array<string> errores que impiden avanzar como documento grupal */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array;
}
```

### 4.2 Comportamiento por State

| State | Individual | Grupal | Notas |
|---|---|---|---|
| `AprobacionState` | valida `approver_id` y `area_approval != Rechazada` | error: "Esta etapa no aplica a documentos grupales" | Skipeable |
| `RrhhState` | valida `passes_payroll != null` | no aplica | |
| `ContabilidadState` | valida `liquidation_doc_id` presente | valida documento de liquidación subido | Pivote individual ↔ grupal |
| `RevisionFirmasState` | no aplica | valida firmas Contador + Coordinador (+ `passes_for_payment` si GDP saltado) | |
| `GdpState` | no aplica | valida firma del trabajador y `passes_for_payment` | Skipeable |
| `TesoreriaState` | no aplica | error: "Avance gestionado desde la sección de pagos" | Avance lo dispara el módulo de pagos |
| `AutPagoState` | no aplica | error: "Autorización gestionada desde pagos" | Avance externo |
| `PagadaState` | terminal (`getNext()=null`) | terminal | |

### 4.3 Lo que NO va en los States

- Saltos condicionales (skip `aprobacion`/`gdp`) → coordinator (`NoveltyService::resolveNextStatus`).
- Visibilidad por rol → coordinator.
- Autorización (`canAdvance` por rol) → coordinator vía `PipelineAuthorizationService`.
- Side effects (transacciones, save, signatures) → coordinator.

### 4.4 Registry

Espejo del de PaymentSchedulings: constructor con los 8 States nullables, mapa `name => state`, métodos `get(string)` y `all()`. `InvalidArgumentException` ante un nombre desconocido.

---

## 5. `NoveltyService` — API y composición

### 5.1 Constructor

```php
public function __construct(
    ?NoveltyPipelineStateRegistry $registry = null,
    ?PipelineAuthorizationService $pipelineAuth = null,
) {
    $this->registry = $registry ?? new NoveltyPipelineStateRegistry();
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
}
```

### 5.2 Métodos públicos (firmas idénticas a las actuales)

| Método | Cambio interno |
|---|---|
| `advance(EmployeeNovelty, int): ServiceResult` | usa `registry->get(state)->validateAdvanceIndividual()` y `resolveNextStatus()` |
| `advanceGroup(NoveltyLiquidationDoc, int): ServiceResult` | usa `registry->get(state)->validateAdvanceGroup()` |
| `reject(EmployeeNovelty, int, ?string): ServiceResult` | sin cambios |
| `validateTransition(object, string): array` | delega al State (individual) |
| `validateGroupTransition(object): array` | delega al State (grupal) |
| `getNextStatus(object, ?object): ?string` | usa `resolveNextStatus()` |
| `getEffectiveStatuses(?object): array` | sin cambios |
| `getNoveltyStatuses(?object): array` | sin cambios |
| `assignToLiquidationDoc(...)` | sin cambios |
| `getVisibleStatuses(string): array` | sin cambios |
| `getVisibleLiquidationStatuses(string): array` | sin cambios |
| `getEditableFields(int, string, string): array` | sin cambios |
| `getVisibleSections(int, string, string): array` | sin cambios |
| `canAdvanceFromStatus(int, string, string): bool` | sin cambios |
| `canAdvanceIndividually(object): bool` | sin cambios |
| `filterEntityData(array, int, string, string): array` | sin cambios |
| `getVisibleFields(object, string): array` | sin cambios |

### 5.3 Método privado nuevo

```php
private function resolveNextStatus(object $novelty, ?object $type): ?string
{
    $current = $novelty->pipeline_status;
    if (in_array($current, [STATUS_RECHAZADA, STATUS_PAGADA], true)) {
        return null;
    }

    $next = $this->registry->get($current)->getNext();

    if ($next === STATUS_APROBACION && $type && !$type->requires_boss_approval) {
        $next = $this->registry->get($next)->getNext();
    }
    if ($next === STATUS_GDP && $type && !$type->requires_employee_signature_review) {
        $next = $this->registry->get($next)->getNext();
    }

    return $next;
}
```

### 5.4 Tamaño objetivo

`NoveltyService` debe quedar entre **480–540 líneas** (de 620). La reducción viene de extraer `validateGroupTransition` (~80 líneas) hacia los States. Si supera 550, revisar.

---

## 6. ViewModels

### 6.1 `EmployeeNoveltyAddViewModel`

```php
public function __construct(
    public readonly EmployeeNovelty $novelty,
    public readonly array $employees,
    public readonly array $noveltyTypes,
    public readonly array $approversList,
    public readonly ?int $preselectedEmployee,
) {}
```

### 6.2 `EmployeeNoveltyEditViewModel`

```php
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
    public readonly array $emailLogs,
) {}
```

### 6.3 `NoveltyLiquidationDocEditViewModel`

```php
public function __construct(
    public readonly NoveltyLiquidationDoc $doc,
    public readonly string $roleName,
    public readonly array $members,
    public readonly array $signatures,
    public readonly array $documents,
    public readonly ?string $nextStatus,
    public readonly array $transitionErrors,
    public readonly bool $canAdvance,
    public readonly array $documentTypeLabels,
    public readonly array $emailLogs,
) {}
```

### 6.4 Construcción en el controller (paridad con PaymentSchedulings)

- `_buildAddViewModel(EmployeeNovelty)`
- `_buildEditViewModel(EmployeeNovelty, int $roleId, string $roleName)`
- `_buildLiquidationEditViewModel(NoveltyLiquidationDoc, string $roleName)`

`$this->set('viewModel', $this->_buildEditViewModel(...))`.

---

## 7. Templates

Cambios mínimos: reemplazar referencias a variables sueltas por `$viewModel->propiedad`.

- `templates/EmployeeNovelties/add.php` (5 vars → `$viewModel`).
- `templates/EmployeeNovelties/edit.php` (14 vars → `$viewModel`).
- `templates/NoveltyLiquidationDocs/edit.php` (todas las vars → `$viewModel`).

Sin rediseño visual. Sin cambios en CSS o componentes.

---

## 8. Adelgazamiento esperado

| Archivo | Antes | Después | Δ |
|---|---|---|---|
| `EmployeeNoveltiesController` | 922 | 720–760 | −160 a −200 |
| `NoveltyLiquidationDocsController` | 540 | 480–500 | −40 a −60 |
| `NoveltyService` (renombre) | 620 | 480–540 | −80 a −140 |

---

## 9. Orden de ejecución

Cada paso es un commit independiente.

1. **State pattern** — interface, 8 States, Registry. Sin tocar el resto.
2. **Renombrar** `NoveltyPipelineService` → `NoveltyService`, refactorizar internamente para usar el Registry, actualizar imports en ambos controllers.
3. **`EmployeeNoveltyEditViewModel`** + adaptar `EmployeeNoveltiesController::edit()` + template.
4. **`EmployeeNoveltyAddViewModel`** + adaptar `EmployeeNoveltiesController::add()` GET + template.
5. **`NoveltyLiquidationDocEditViewModel`** + adaptar `NoveltyLiquidationDocsController::edit()` + template.

---

## 10. Validación manual

Ejecutar en `php bin/cake server` (proyecto sin tests automatizados, ver `CLAUDE.md` § Testing Policy).

| # | Acción | Esperado |
|---|---|---|
| **E1** | Login Auxiliar de Personal → `/employee-novelties` → entrar a novedad en `aprobacion` | Vista carga, secciones visibles correctas, botón Avanzar deshabilitado si falta `approver_id` |
| **E1.2** | Login Contabilidad → entrar a novedad en `contabilidad` sin `liquidation_doc_id` | Aparece dropdown "Asignar a documento de liquidación", lista de docs en estado `contabilidad` |
| **E1.3** | Editar y guardar; avanzar `rrhh → contabilidad` con `passes_payroll=true` | Avance exitoso, status cambia |
| **E2** | `/employee-novelties/add` → crear novedad con tipo `requires_boss_approval=true` | Estado inicial = `aprobacion`, email enviado al aprobador (verificar logs) |
| **E2.2** | Crear novedad con tipo `requires_boss_approval=false` | Estado inicial = `rrhh`, sin email |
| **E2.3** | Crear novedad masiva (varios empleados) | 1 novedad + N entradas en `novelty_massive_employees` |
| **E2.4** | Subir firma del empleado vía canvas (base64) y vía archivo | Ambas rutas guardan archivo y path |
| **E3** | Login Contador → `/novelty-liquidation-docs` → doc en `revision_firmas` | Lista de firmas (2 o 3 slots según tipo), miembros del grupo, botón Avanzar |
| **E3.2** | Subir firma Contador, intentar avanzar antes de firma Coordinador | Error: "Todas las firmas requeridas..." |
| **E3.3** | Avanzar a `gdp` → `passes_for_payment=true` → firma trabajador → avanzar a `tesoreria` | Cada transición exitosa |
| **E4** | Calendarios `/employee-novelties/active` y `/employee-novelties/all` | Eventos cargan con colores correctos |
| **E5** | `/employee-novelties/rejected` | Solo novedades en `rechazada` |
| **E6** | Rechazar novedad desde `aprobacion` con observaciones | Status → `rechazada`, observaciones guardadas |
| **E7** | Tipo con `requires_employee_signature_review=false`: `revision_firmas` salta directo a `tesoreria` | El siguiente status omite `gdp` |

### Criterios de cierre

- `composer cs-check` pasa.
- Todos los pasos E1–E7 verificados en navegador.
- `EmployeeNoveltiesController` ≤ 760 líneas.
- `NoveltyService` entre 480–540 líneas.
- Cero referencias a `NoveltyPipelineService` en el código (`grep -r NoveltyPipelineService src/`).

---

## 11. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| `NoveltyLiquidationDocsController` y `EmployeeNoveltiesController` consumen el mismo servicio — un cambio rompe ambos | Mantener firmas públicas idénticas en `NoveltyService`; verificar `grep` antes de commitear cada paso |
| Saltos condicionales (skip aprobacion/gdp) son fáciles de romper al refactorizar | `resolveNextStatus()` aislado y cubierto por paso E2/E2.2/E7 de validación manual |
| Templates referencian docenas de variables; un nombre mal mapeado se ve solo en runtime | Cambiar en orden: ViewModel → controller → template; revisar la vista en navegador antes del commit |
| Pipeline grupal valida cosas distintas según el tipo (skip GDP cambia la validación de `revision_firmas`) | Cubierto en `RevisionFirmasState::validateAdvanceGroup()` mirando `requires_employee_signature_review` del primer miembro |

---

## 12. Cambios a este diseño

> Cualquier desviación durante la ejecución se registra acá con fecha y razón.

- **2026-05-06** — Creación inicial. Decisiones 1, 2, 3 acordadas vía brainstorming.
