# Payment Schedulings — Migración a estructura canónica (Plan A)

**Fecha:** 2026-05-06
**Branch sugerido:** `refactor/payment-schedulings-canonical-structure`
**Audit fuente:** `docs/audits/flow-structure-audit-2026-05-06.md`

## Alcance

Plan A del roadmap de unificación de estructura de flujos. Lleva el módulo PaymentSchedulings a la base canónica adoptada en la auditoría:

- Renombre completo `Attachment` → `Document` (tabla + código + acciones del controller).
- Fusión de `PaymentSchedulingService` + `PaymentSchedulingPipelineService` en un único `PaymentSchedulingService` coordinador.
- Extracción del parser de Excel de Siesa a `PaymentSchedulingImportService` separado.
- Aplicación del Pipeline State pattern (`Pipeline/PaymentScheduling/State/*`) replicando el patrón de PettyCash.
- Creación de ViewModels simétricos `Add` + `Edit`.

**Fuera de alcance (decidido en brainstorming):** crear `PaymentSchedulingHistoryService` y tabla `payment_scheduling_histories` — el flujo no contiene datos editables que ameriten auditoría campo a campo; las observations existentes ya cubren la trazabilidad mínima (regresiones con motivo + metadata).

## Resumen de decisiones (brainstorming)

| Decisión | Resuelta como |
|---|---|
| Renombre Attachment → Document | A — completo (tabla + código). El módulo es joven (abril 2026) y el costo es bajo. |
| Split Service vs Pipeline | B — `PaymentSchedulingService` (dominio + pipeline) + `PaymentSchedulingImportService` (parser Excel). El parsing de Siesa es lo bastante complejo y específico para justificar un servicio dedicado. |
| HistoryService | B — no se crea. PaymentScheduling es un agrupador, no un documento con datos editables. Inflar con histories por simetría sería overengineering. |
| State pattern | Replicar PettyCash 1:1 (interfaz `getName/getNext/getPrevious/validateAdvance` + Registry + 4 States). Lo cross-state vive en el coordinador. |
| ViewModels | A — Add y Edit simétricos. Cumple regla canónica "ViewModels siempre simétricos". |
| Estrategia de despliegue | A — migración + código en un solo PR (proyecto en desarrollo). |

## Arquitectura

### Estructura de archivos final

```
src/
├── Constants/
│   └── PaymentSchedulingConstants.php                    ← agregar FORWARD_TRANSITIONS, REJECTION_TARGET
│
├── ViewModel/
│   ├── PaymentSchedulingAddViewModel.php                 ← NUEVO
│   └── PaymentSchedulingEditViewModel.php                ← NUEVO
│
├── Service/
│   ├── PaymentSchedulingService.php                      ← FUSIÓN (Service + PipelineService, sin parsing)
│   ├── PaymentSchedulingDocumentService.php              ← RENOMBRADO desde AttachmentService
│   ├── PaymentSchedulingImportService.php                ← NUEVO (parser Excel extraído)
│   └── Pipeline/
│       └── PaymentScheduling/                            ← NUEVO subdirectorio
│           ├── PaymentSchedulingPipelineState.php        ← interfaz
│           ├── PaymentSchedulingPipelineStateRegistry.php
│           └── State/
│               ├── BorradorState.php
│               ├── TesoreriaState.php
│               ├── AutPagoState.php
│               └── PagadaState.php
│
├── Model/
│   ├── Entity/
│   │   └── PaymentSchedulingDocument.php                 ← RENOMBRADO desde Attachment
│   └── Table/
│       └── PaymentSchedulingDocumentsTable.php           ← RENOMBRADO + setTable a tabla nueva
│
├── Controller/
│   └── PaymentSchedulingsController.php                  ← refactor: usa VMs + servicios renombrados
│
config/Migrations/
└── 20260506HHMMSS_RenamePaymentSchedulingAttachmentsToDocuments.php   ← NUEVO

templates/PaymentSchedulings/                             ← refs Document en lugar de Attachment
└── (sin nuevos templates, solo edits)
```

### Archivos a borrar

- `src/Service/PaymentSchedulingPipelineService.php` (su contenido se fusiona en `PaymentSchedulingService`).
- `src/Service/PaymentSchedulingAttachmentService.php` (renombrado vía `git mv`).
- `src/Model/Entity/PaymentSchedulingAttachment.php` (renombrado vía `git mv`).
- `src/Model/Table/PaymentSchedulingAttachmentsTable.php` (renombrado vía `git mv`).

## Componentes

### `PaymentSchedulingService` (coordinador)

Fusión de `PaymentSchedulingService` + `PaymentSchedulingPipelineService` actuales, sin el parsing del Excel.

**API pública:**
- Pipeline: `getVisibleStatuses(string $roleName): array`, `canAdvance(int $roleId, string $roleName, string $currentStatus): bool`, `canReject(int $roleId, string $roleName, string $currentStatus): bool`, `canRegress(int $roleId, string $roleName, string $currentStatus): bool`, `getNextStatus(string $currentStatus): ?string`, `getPreviousStatus(string $currentStatus): ?string`, `getRegressionLockMessage(object $scheduling): ?string`.
- Validación: `validateTransitionRequirements(object $scheduling, string $fromStatus): array` — delega a `$stateRegistry->get($fromStatus)->validateAdvance($scheduling)`.
- Operaciones: `regress(PaymentScheduling $scheduling, int $roleId, string $roleName, int $userId, string $reason): ServiceResult`, `linkItems(int $schedulingId, array $validItems): bool`, `applyPayments(int $schedulingId, int $authorizedBy): array`, `calculateTotal(int $schedulingId): float`.

**Constructor:**

```php
public function __construct(
    private readonly InvoicePaymentService $paymentService,
    ?PipelineAuthorizationService $pipelineAuth = null,
    ?PaymentSchedulingPipelineStateRegistry $stateRegistry = null,
) {
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    $this->stateRegistry = $stateRegistry ?? new PaymentSchedulingPipelineStateRegistry();
}
```

Sigue el patrón nullable + `?? new …` que usa `PettyCashService`.

### `PaymentSchedulingImportService` (parser Excel de Siesa)

**API pública:**
- `parseExcel(string $filePath): array` — devuelve `['valid' => [...], 'errors' => [...]]`.

**Privados:**
- `_normalizeSiesaInvoiceNumber(string $raw): string`
- `_extractNit(string $raw): string`

**Constructor:**

```php
public function __construct(
    private readonly InvoicePaymentService $paymentService,
)
```

**Reglas:**
- `ImportService` no llama a `linkItems`. Devuelve resultados parseados; el controller decide cuándo invocar `Service::linkItems(...)` (split parse → preview → confirm conservado tal como está hoy).

### Pipeline State pattern

#### Interfaz `PaymentSchedulingPipelineState`

```php
namespace App\Service\Pipeline\PaymentScheduling;

use App\Model\Entity\PaymentScheduling;

interface PaymentSchedulingPipelineState
{
    public function getName(): string;
    public function getNext(): ?string;
    public function getPrevious(): ?string;

    /** @return array<string> errores de requirements para avanzar */
    public function validateAdvance(PaymentScheduling $scheduling): array;
}
```

#### Registry

`PaymentSchedulingPipelineStateRegistry` — recibe los 4 States por DI con fallback a `new …()`, los indexa por `getName()`, expone `get(string)` y `all()`. Idéntico al de PettyCash.

#### Estados concretos

| Clase | `getName` | `getNext` | `getPrevious` | `validateAdvance` |
|---|---|---|---|---|
| `BorradorState` | `borrador` | `tesoreria` | `null` | si `count(items) === 0` → `["Debe vincular al menos una factura"]`; sino `[]` |
| `TesoreriaState` | `tesoreria` | `aut_pago` | `borrador` | `[]` |
| `AutPagoState` | `aut_pago` | `pagada` | `tesoreria` | `[]` |
| `PagadaState` | `pagada` | `null` | `null` | `[]` |

#### Lo que NO vive en los States (vive en el coordinador)

- `canAdvance` / `canReject` / `canRegress` — requieren `roleId`/`roleName` y consulta a `PipelineAuthorizationService`. Cross-state.
- `applyPayments` durante la transición `aut_pago → pagada` — se ejecuta en el coordinador justo antes del `save()` del nuevo status (igual que hoy en `PaymentSchedulingsController::advance`).
- `regress` con motivo + escritura de observación.
- `REJECTION_TARGET` — sigue en `Constants`.

### ViewModels

#### `PaymentSchedulingAddViewModel`

```php
final class PaymentSchedulingAddViewModel
{
    public function __construct(
        public readonly PaymentScheduling $record,
        public readonly array $operationCenters,
    ) {}
}
```

#### `PaymentSchedulingEditViewModel`

```php
final class PaymentSchedulingEditViewModel
{
    public function __construct(
        public readonly PaymentScheduling $record,
        public readonly string $roleName,
        public readonly string $currentStatus,
        public readonly bool $canAdvance,
        public readonly bool $canReject,
        public readonly bool $canRegress,
        public readonly ?string $nextStatus,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,
        public readonly array $advanceErrors,
        public readonly float $total,
        public readonly array $pipelineLabels,
        public readonly array $bankingEntities,
    ) {}
}
```

Templates pasan a recibir `$viewModel` como variable única (`$this->set('viewModel', $vm)`), y dentro acceden vía `$viewModel->record`, `$viewModel->canAdvance`, etc.

### Controller

**Métodos del controller que cambian de nombre:**

| Antes | Después | URL nueva |
|---|---|---|
| `uploadAttachment` | `uploadDocument` | `POST /payment-schedulings/upload-document/{id}` |
| `deleteAttachment` | `deleteDocument` | `POST /payment-schedulings/delete-document/{id}/{docId}` |

**Métodos que mantienen nombre:**
`index`, `view`, `add`, `edit`, `advance`, `reject`, `regressStatus`, `importExcel`, `previewImport`, `confirmImport`, `addItem`, `removeItem`, `addObservation`.

**Dependencias inyectadas en `initialize()`:**

```php
$this->schedulingService = $container->get(PaymentSchedulingService::class);
$this->importService     = $container->get(PaymentSchedulingImportService::class);
$this->documentService   = $container->get(PaymentSchedulingDocumentService::class);
```

Se elimina `$this->pipeline` (su API queda dentro de `schedulingService`).

**Construcción de VMs:**

- `add()`: helper privado `_buildAddViewModel(PaymentScheduling $record): PaymentSchedulingAddViewModel`.
- `edit()`: helper privado `_buildEditViewModel(PaymentScheduling $record, int $roleId, string $roleName): PaymentSchedulingEditViewModel`.

**Tamaño esperado:** controller pasa de 512 → ~480 líneas (no hay objetivo de adelgazamiento agresivo aquí; el controller ya era el más chico de los 6).

### Migración SQL

`config/Migrations/20260506HHMMSS_RenamePaymentSchedulingAttachmentsToDocuments.php`:

```php
public function change(): void
{
    $this->table('payment_scheduling_attachments')
        ->rename('payment_scheduling_documents')
        ->update();
}
```

`change()` permite rollback automático invertido por Phinx.

### Constantes a mover

Hoy en `PaymentSchedulingPipelineService`:

```php
public const STATUSES         = PaymentSchedulingConstants::PIPELINE_STATUSES;
public const STATUS_LABELS    = PaymentSchedulingConstants::STATUS_LABELS;
public const TRANSITIONS      = [...];
public const REJECTION_TARGET = PaymentSchedulingConstants::STATUS_TESORERIA;
```

Se eliminan del service y se mueven a `PaymentSchedulingConstants`:
- Agregar `FORWARD_TRANSITIONS` (reemplaza la const local `TRANSITIONS`).
- Agregar `REJECTION_TARGET`.
- `STATUSES`/`STATUS_LABELS` ya existen en Constants — solo limpiar las copias del service.

Refs externas a `PaymentSchedulingPipelineService::REJECTION_TARGET` (línea 252 del controller) pasan a `PaymentSchedulingConstants::REJECTION_TARGET`.

## Renombre `Attachment` → `Document` (lista exhaustiva)

### Símbolos

- Clase entity `PaymentSchedulingAttachment` → `PaymentSchedulingDocument`
- Clase table `PaymentSchedulingAttachmentsTable` → `PaymentSchedulingDocumentsTable`
- Clase service `PaymentSchedulingAttachmentService` → `PaymentSchedulingDocumentService`
- Constante interna del service: `private const TABLE = 'PaymentSchedulingAttachments'` → `'PaymentSchedulingDocuments'`
- Métodos del service: `uploadAttachment` → `uploadDocument`, `deleteAttachment` → `deleteDocument`
- Asociación en `PaymentSchedulingsTable`: `hasMany('PaymentSchedulingAttachments')` → `hasMany('PaymentSchedulingDocuments')` (con `foreignKey` y `dependent` actualizados si aplica)
- Property name en entity `PaymentScheduling`: `payment_scheduling_attachments` → `payment_scheduling_documents` (auto-derivado por CakePHP desde el alias de la asociación)

### Lugares que tocan la asociación

- `PaymentSchedulingsController::view()` y `edit()`: `contain` y refs a `$record->payment_scheduling_attachments`
- Templates `view.php` y `edit.php`: refs a `$record->payment_scheduling_attachments`
- Métodos del controller: `uploadAttachment` → `uploadDocument`, `deleteAttachment` → `deleteDocument`
- URLs en templates que apunten a esas acciones

### Carpeta de upload

`'payment_schedulings/' . $schedulingId` — se mantiene (ya neutral, no menciona "attachments").

### Strings UI que NO cambian

- "Soporte", "Subir soporte", "soporte eliminado" en `Flash` y templates: se mantienen (es la palabra de negocio en español; "Document" es solo convención técnica del código).
- Campo `name`/`mensaje` para usuarios nunca expone "attachment".

### Permisos y rutas

- Tabla `permissions`: módulo sigue siendo `payment-schedulings` (no se toca).
- Rutas en `config/routes.php`: si hay rutas custom que refieren `uploadAttachment` o `deleteAttachment`, se actualizan. Si no hay, el fallback de CakePHP las regenera automáticamente desde el nombre del método del controller.

## Validación manual

Por política del proyecto (ver `CLAUDE.md` — Testing Policy), no hay tests automatizados. Tras el merge:

1. **Server up**: `php bin/cake server`.
2. **Migración**: `php bin/cake migrations migrate` — verificar que la tabla `payment_scheduling_documents` existe y `payment_scheduling_attachments` no.
3. **Listado** (`/payment-schedulings`): confirmar que el listado se renderiza y los filtros funcionan.
4. **Crear programación** (`/payment-schedulings/add`): crear una con name + operation_center.
5. **Editar programación** (`/payment-schedulings/edit/{id}`): verificar que la página carga, el VM expone todos los datos, los botones de pipeline aparecen según rol.
6. **Importar Excel**: subir un Excel de Siesa, verificar el preview y confirmar la importación.
7. **Subir/eliminar soporte** (renombrado a Document):
   - Click "Subir soporte" → archivo se sube y aparece listado.
   - Click eliminar → archivo se elimina.
   - Verificar JSON responses (AJAX) si los templates lo usan.
8. **Avanzar pipeline** desde Borrador → Tesorería → Aut. Pago → Pagada (con un ítem vinculado a una factura en Tesorería válida).
9. **Rechazar** desde Aut. Pago → debe volver a Tesorería.
10. **Regresar con motivo** desde Tesorería o Aut. Pago — verificar que se crea la observación.
11. **Rollback de migración**: `php bin/cake migrations rollback` — verificar que la tabla vuelve a llamarse `payment_scheduling_attachments` (sólo para confirmar que `change()` es reversible; luego volver a hacer `migrate`).

## Riesgos y consideraciones

- **Riesgo bajo de breakage en producción**: el módulo está en desarrollo, sin datos críticos en `payment_scheduling_attachments`. El rename de tabla preserva datos.
- **CakePHP convention magic**: cambiar el alias de la asociación de `PaymentSchedulingAttachments` → `PaymentSchedulingDocuments` cambia automáticamente el nombre de la propiedad en la entity. Esto requiere actualizar TODOS los `$record->payment_scheduling_attachments` a `$record->payment_scheduling_documents` en templates y controller — fácil de olvidar uno.
- **Templates de email**: ninguno aplica a este flujo, pero verificar `templates/email/` por las dudas.
- **Routes**: el cambio de nombre de las acciones del controller (`uploadAttachment` → `uploadDocument`) cambia las URLs. Si hay AJAX hardcodeado en JS, hay que actualizarlo.

## Orden de ejecución sugerido

1. **Migración SQL** (rename tabla).
2. **`git mv` de archivos PHP** (entity, table, service).
3. **Actualizar contenido** de los archivos renombrados (clase, namespace, constante TABLE).
4. **Actualizar `PaymentSchedulingsTable`** (asociación).
5. **Actualizar `PaymentSchedulingConstants`** (agregar FORWARD_TRANSITIONS, REJECTION_TARGET).
6. **Crear State pattern** (interfaz, Registry, 4 States).
7. **Crear `PaymentSchedulingImportService`** (mover `parseExcel` + helpers privados).
8. **Refactorizar `PaymentSchedulingService`** (fusionar PipelineService, inyectar StateRegistry, usar `validateAdvance` del state actual).
9. **Borrar `PaymentSchedulingPipelineService`**.
10. **Crear ViewModels** Add y Edit.
11. **Refactorizar el controller**: rename actions, inyectar services renombrados, construir VMs en `add` y `edit`.
12. **Actualizar templates** (refs a association, refs a actions, recibir `$viewModel`).
13. **Validación manual** (sección anterior).
