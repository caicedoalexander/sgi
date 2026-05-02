# Pipeline Permissions — Diseño

**Fecha:** 2026-05-02
**Estado:** Diseño aprobado, pendiente de plan de implementación
**Autor:** brainstorming colaborativo

## Contexto y problema

Hoy SGI tiene **dos capas de autorización conviviendo**:

1. **`AuthorizationService` + tabla `permissions`** — RBAC granular configurable por UI: módulo × `can_view/can_create/can_edit/can_delete`. Controla acceso CRUD a controladores.
2. **Constantes de rol hardcodeadas** — 132 usos de `RoleConstants::*` repartidos en 20+ archivos. Controlan qué puede hacer cada rol **dentro de un flujo de pipeline** (transiciones de estado, campos editables por estado, secciones visibles del formulario).

El segundo grupo es el problema: cualquier cambio operativo —ej. "ahora Contabilidad también puede operar en Tesorería"— requiere modificar código y desplegar. Este spec migra esa segunda capa a una tabla configurable desde la UI.

### Alcance

**Dentro:**
- Permisos de transición de pipeline (quién avanza/rechaza desde cada estado).
- Permisos de edición de campos por rol × estado (`InvoiceFieldAccessPolicy` y equivalentes).
- Visibilidad de secciones del formulario asociadas a cada estado.

**Fuera (siguen usando `RoleConstants`):**
- Filtros de listado por rol en index controllers.
- Constantes que mapean rol → aprobador/notificado (`RefundConstants::APPROVER_ROLES`, `PettyCashConstants::*`).
- `SidebarCounterService` (badges).
- Redirecciones según rol (`pagada` → `view` para no-admin).
- Validaciones de identidad cruzada ("el que aprueba no puede ser el mismo que registró").

Razón del recorte: lo de fuera es **lógica de dominio**, no autorización configurable. Mezclarlo agrandaría el alcance sin beneficio operativo.

## Decisiones clave

| # | Decisión | Alternativa descartada |
|---|---|---|
| 1 | Granularidad: **un permiso por paso del pipeline** (`can_operate`) que abarca transición + edición de campos del paso + visibilidad de la sección | Permiso por campo (over-engineering); permiso por acción (más filas, menos legible) |
| 2 | **Tabla nueva** `pipeline_permissions` separada de `permissions` | Extender `permissions` con un campo `scope` mezclaría dos conceptos distintos |
| 3 | **Seed vacío** al desplegar | Seed automático del estado actual evitaría riesgo pero hace implícito lo que debería ser explícito |
| 4 | Admin (rol `Administrador`) **bypassa** la tabla, igual que en `AuthorizationService` | Romper el bypass exigiría seed automático para no dejar admin sin acceso |
| 5 | UI dentro de `Roles/edit` existente, **segunda matriz debajo de la actual** | Pantalla separada agrega navegación sin valor |

## Modelo de datos

### Tabla `pipeline_permissions`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int unsigned PK auto | |
| `role_id` | int unsigned FK → `roles.id` | `ON DELETE CASCADE` |
| `pipeline` | varchar(40) | Valores: `invoices`, `novelties`, `payment_schedulings`, `refunds`, `petty_cash` |
| `step` | varchar(40) | Estado del pipeline. Valores válidos definidos en `PipelineStepConstants` |
| `can_operate` | tinyint(1) NOT NULL DEFAULT 0 | Único permiso. Cubre transición desde el paso + edición de campos definidos en código para ese paso + visibilidad de la sección asociada |
| `created` | datetime NOT NULL | |
| `modified` | datetime NOT NULL | |

**Índice único:** `(role_id, pipeline, step)`.

### Constantes nuevas: `src/Constants/PipelineStepConstants.php`

```php
final class PipelineStepConstants
{
    public const PIPELINE_INVOICES = 'invoices';
    public const PIPELINE_NOVELTIES = 'novelties';
    public const PIPELINE_PAYMENT_SCHEDULINGS = 'payment_schedulings';
    public const PIPELINE_REFUNDS = 'refunds';
    public const PIPELINE_PETTY_CASH = 'petty_cash';

    /** Pares válidos (pipeline => [steps]). Usado por la UI y validación. */
    public const STEPS_BY_PIPELINE = [
        self::PIPELINE_INVOICES => [
            'aprobacion', 'contabilidad', 'tesoreria',
            'autorizacion_pago', 'pagada',
        ],
        self::PIPELINE_NOVELTIES => [/* tomar de NoveltyConstants::PIPELINE_STATUSES */],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            'borrador', 'tesoreria', 'aut_pago', 'pagada',
        ],
        self::PIPELINE_REFUNDS => [/* tomar de RefundConstants */],
        self::PIPELINE_PETTY_CASH => [/* tomar de PettyCashConstants */],
    ];

    /** Etiquetas para UI en español. */
    public const LABELS = [
        'invoices' => [
            'aprobacion' => 'Aprobación',
            'contabilidad' => 'Contabilidad',
            'tesoreria' => 'Tesorería',
            'autorizacion_pago' => 'Autorización de pago',
            'pagada' => 'Pagada',
        ],
        // ...
    ];

    public const PIPELINE_LABELS = [
        'invoices' => 'Facturas',
        'novelties' => 'Novedades',
        'payment_schedulings' => 'Programación de pagos',
        'refunds' => 'Reintegros',
        'petty_cash' => 'Caja menor',
    ];
}
```

> **Nota de implementación:** los listados de `STEPS_BY_PIPELINE` deben quedar **completos y verificados** contra cada `*Constants::PIPELINE_*` y los servicios correspondientes durante la fase de plan/implementación.

## Capa de servicio

### `src/Service/PipelineAuthorizationService.php`

Servicio nuevo, espejo en patrón a `AuthorizationService`:

```php
class PipelineAuthorizationService
{
    private array $cache = [];

    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }
        $perms = $this->_loadForRole($roleId);
        return (bool)($perms[$pipeline][$step] ?? false);
    }

    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array;
    public function getPermissionsMatrix(int $roleId): array; // alimenta UI
    public function savePermissions(int $roleId, array $data): void;

    private function _loadForRole(int $roleId): array; // con cache por request
}
```

- **Cache** en memoria por `role_id` durante el request.
- **Sin fallback a `RoleConstants`**: si la tabla está vacía y el rol no es admin, deniega. Esto es lo que exige la decisión de seed B.
- **Inyección por constructor** con fallback `?? new PipelineAuthorizationService()` (convención del proyecto).

### Reescritura de policies y states

`InvoiceFieldAccessPolicy` (y equivalentes en otros pipelines si existen) pierde los mapeos rol → estado y queda solo con mapeos **estado → campos / sección**:

```php
class InvoiceFieldAccessPolicy
{
    private const FIELDS_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => [
            'invoice_number', 'issue_date', 'due_date',
            // ... idénticos a los actuales del rol REGISTRO_REVISION
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            'accrued', 'accrual_date', 'ready_for_payment',
        ],
        // ... resto
    ];

    private const SECTION_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => 'revision',
        InvoiceConstants::STATUS_CONTABILIDAD => 'accounting',
        InvoiceConstants::STATUS_TESORERIA => 'treasury',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'payment_authorization',
    ];

    public function __construct(private ?PipelineAuthorizationService $pipelineAuth = null)
    {
        $this->pipelineAuth ??= new PipelineAuthorizationService();
    }

    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }
        if (!$this->pipelineAuth->canOperate($roleId, $roleName, 'invoices', $status)) {
            return [];
        }
        return self::FIELDS_BY_STEP[$status] ?? [];
    }

    public function getVisibleSections(int $roleId, string $roleName, string $status): array;
    // (resto: lógica derivada de los pasos donde el rol tiene can_operate)
}
```

**Cambio de firma:** los métodos públicos pasan de recibir `(string $roleName, string $status)` a `(int $roleId, string $roleName, string $status)`. Es un cambio breaking interno; los callers (controllers y servicios) ya tienen ambos datos del usuario autenticado.

### Reescritura de pipeline states y servicios

Patrón generalizado:

```php
// Antes
if ($currentUser['role_name'] !== RoleConstants::TESORERIA) {
    return ServiceResult::fail(['Acceso denegado']);
}

// Después
if (!$this->pipelineAuth->canOperate(
    $currentUser['role_id'], $currentUser['role_name'],
    'invoices', InvoiceConstants::STATUS_TESORERIA
)) {
    return ServiceResult::fail(['Acceso denegado']);
}
```

Archivos afectados (recuento aproximado de usos a reemplazar — la cifra final se valida en el plan):

| Archivo | Usos hoy | Acción |
|---|---:|---|
| `Service/NoveltyPipelineService.php` | 26 | Reescritura completa de guards |
| `Service/PaymentSchedulingPipelineService.php` | 11 | Idem |
| `Service/InvoiceFieldAccessPolicy.php` | 11 | Reestructura (ver arriba) |
| `Service/RefundService.php` | 9 | Reemplazar guards de operación (mantener constantes de aprobador) |
| `Service/PettyCashService.php` | 9 | Idem |
| `Service/Pipeline/State/AutorizacionPagoState.php` | 7 | Reemplazar guards |
| `Service/Pipeline/State/TesoreriaState.php` | 6 | Idem |
| `Service/Pipeline/State/ContabilidadState.php` | 6 | Idem |
| `Service/Pipeline/State/AprobacionState.php` | 6 | Idem |
| `Service/Pipeline/State/PagadaState.php` | 5 | Idem |
| `Service/Pipeline/State/LegalizadaState.php` | 5 | Idem |
| `Controller/InvoicePaymentsController.php` | 7 | Reemplazar guards |
| `Controller/InvoicesController.php` | 3 | Idem |
| `Controller/LiquidationDocPaymentsController.php` | 3 | Idem |
| `Controller/RefundsController.php` | 2 | Idem |
| `Controller/PettyCashRecordsController.php` | 2 | Idem |
| `Controller/NoveltyLiquidationDocsController.php` | 2 | Idem |
| `Service/InvoicePipelineService.php` | 2 | Idem |

**No tocar:** `Constants/RefundConstants.php`, `Constants/PettyCashConstants.php` — sus referencias a `RoleConstants` describen mapeos rol → aprobador, fuera de alcance.

## UI de configuración

**Ubicación:** `templates/Roles/edit.php` (extender la existente). Sin pantalla nueva.

**Layout:**

```
[Card 1] Permisos del módulo  ← matriz actual sin cambios
[Card 2] Permisos de pipeline ← matriz nueva
         agrupada por pipeline (Facturas, Novedades, ...)
         columnas: Paso | Puede operar (checkbox)
```

- Estilo SGI: cards con borde superior 2px verde (`var(--primary-color)`).
- Etiquetas en español desde `PipelineStepConstants::LABELS`.
- Un solo `<form>`, un solo submit.
- El rol `Administrador` no se edita (mismo trato que la matriz actual).

**Controller:** `RolesController::edit()`:

1. En `GET`: cargar `pipelinePermissionsMatrix` vía `PipelineAuthorizationService::getPermissionsMatrix($roleId)` y pasarla a la vista.
2. En `POST`:
   - Iniciar transacción.
   - `AuthorizationService::savePermissionsForRole(...)` (existente).
   - `PipelineAuthorizationService::savePermissions(...)` (nuevo).
   - Commit; en error rollback.
3. **Validar input defensivamente:** `savePermissions` ignora pares `(pipeline, step)` que no estén en `PipelineStepConstants::STEPS_BY_PIPELINE`.

`RolesController::view()` recibe el mismo tratamiento de carga (read-only).

## Migración

### Migración de BD

`migrations/YYYYMMDDHHMMSS_CreatePipelinePermissions.php`:
- Crea tabla `pipeline_permissions` con columnas e índice descritos arriba.
- **No siembra datos.**
- `down()` borra la tabla.
- Usa `Migrations\BaseMigration` (no `AbstractMigration`).
- Guard con `$this->hasTable('pipeline_permissions')` antes de crear.

### Configuración inicial recomendada

Tras desplegar, **un admin debe entrar a `/roles/edit/{id}` para cada rol no-admin** y marcar la matriz que reproduzca el comportamiento actual:

| Rol | Pipeline | Paso a marcar `can_operate` |
|---|---|---|
| Registro/Revisión | invoices | aprobacion |
| Contabilidad | invoices | contabilidad |
| Tesorería | invoices | tesoreria, autorizacion_pago |
| Contador | invoices | autorizacion_pago |
| (resto de pipelines) | — | a derivar de los mapeos hardcodeados actuales en `NoveltyPipelineService`, `PaymentSchedulingPipelineService`, `RefundService`, `PettyCashService` |

> **Nota:** la matriz exacta para novelties / payment_schedulings / refunds / petty_cash se completa en el plan de implementación tras leer los servicios. Esta tabla queda como **placeholder explícito** que debe llenarse antes de cerrar el plan.

### Estrategia de corte

Un solo PR con todo:

1. Migración + constantes + servicio nuevo + UI funcionando (estado intermedio: tabla vacía, todos los flujos rotos para no-admin).
2. Reescritura de policies/states/services/controllers en el mismo PR.
3. Antes del merge final, paso operativo manual: admin configura la matriz según la guía anterior.
4. Validación manual completa (ver siguiente sección).

**Rollback:** revert del PR + `bin/cake migrations rollback`. Sin pérdida de datos: la tabla nueva no contiene estado de negocio.

## Validación manual (en lugar de tests automatizados)

Política del proyecto: no se agregan tests automatizados. Validación con `php bin/cake server` y navegador.

### Criterios de aceptación

1. `migrations migrate` crea `pipeline_permissions` sin filas; `migrations rollback` la elimina sin error.
2. `/roles/edit/{id}` muestra la matriz nueva debajo de la existente; checkboxes persisten tras submit y reload.
3. Admin opera todos los flujos sin filas en la tabla.
4. Rol no-admin sin permisos recibe denegación clara al intentar operar cualquier paso.
5. Tras configurar la matriz "espejo del estado actual", todos los flujos se comportan idéntico a antes del cambio.

### Guion de validación post-merge

a. Admin → `/roles/edit` para cada rol no-admin → marcar matriz según la guía → guardar.

b. Por cada pipeline, recorrer flujo completo con un usuario del rol dueño:
- **Facturas:** Registro/Revisión crea → Contabilidad causa → Tesorería registra pago → Contador autoriza → marca pagada.
- **Novedades:** ciclo completo según `NoveltyPipelineService`.
- **Programación de pagos:** borrador → tesorería → aut_pago → pagada.
- **Reintegros, Caja menor:** ciclos completos.

c. Por cada pipeline, intentar operar con un rol sin permiso → confirmar denegación + mensaje claro al usuario.

d. **Campos editables:** abrir factura en estado `contabilidad` con rol Contabilidad → solo `accrued`, `accrual_date`, `ready_for_payment` editables. Repetir con Tesorería en `tesoreria`.

e. **Secciones visibles:** Contador en `view` de factura ve `payment_authorization`; Contabilidad ve `accounting`; admin ve todo.

f. **Reconfiguración en caliente:** desmarcar `can_operate` de Tesorería en `tesoreria`, reintentar registrar pago → denegación. Volver a marcar → vuelve a funcionar.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Olvidar configurar un permiso → flujo bloqueado en producción | Guía paso-a-paso explícita en el spec; admin configura antes del merge final |
| Cambio de firma de `InvoiceFieldAccessPolicy` rompe callers no detectados | Búsqueda exhaustiva de `getEditableFields\|getVisibleSections\|filterEntityData` durante el plan |
| Cache de `PipelineAuthorizationService` no se invalida tras editar permisos | Cache es por request (instancia de servicio) — request siguiente lee fresco. La UI de Roles invalida implícitamente al recargar |
| Listados `STEPS_BY_PIPELINE` incompletos | El plan debe verificar contra cada `*Constants::PIPELINE_*` y servicios reales antes de cerrar |

## Archivos nuevos / modificados (resumen)

**Nuevos:**
- `migrations/YYYYMMDDHHMMSS_CreatePipelinePermissions.php`
- `src/Constants/PipelineStepConstants.php`
- `src/Service/PipelineAuthorizationService.php`
- `src/Model/Table/PipelinePermissionsTable.php`
- `src/Model/Entity/PipelinePermission.php`

**Modificados:**
- `src/Service/InvoiceFieldAccessPolicy.php` (reestructura)
- `src/Service/Pipeline/State/*.php` (6 archivos — guards)
- `src/Service/NoveltyPipelineService.php`
- `src/Service/PaymentSchedulingPipelineService.php`
- `src/Service/RefundService.php`
- `src/Service/PettyCashService.php`
- `src/Service/InvoicePipelineService.php`
- `src/Controller/RolesController.php`
- `src/Controller/InvoicesController.php`
- `src/Controller/InvoicePaymentsController.php`
- `src/Controller/LiquidationDocPaymentsController.php`
- `src/Controller/RefundsController.php`
- `src/Controller/PettyCashRecordsController.php`
- `src/Controller/NoveltyLiquidationDocsController.php`
- `templates/Roles/edit.php`
- `templates/Roles/view.php`

**Sin cambios (a pesar de usar `RoleConstants`):**
- `src/Constants/RefundConstants.php`, `src/Constants/PettyCashConstants.php`
- `src/Service/SidebarCounterService.php`
- Filtros de listado en controllers de index
- Redirecciones según rol
- Validaciones de identidad cruzada
