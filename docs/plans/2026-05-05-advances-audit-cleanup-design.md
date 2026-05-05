# Advances — Cierre de auditoría 2026-05-05 (puntos pendientes)

**Fecha:** 2026-05-05
**Branch sugerido:** `chore/advances-audit-cleanup`
**Audit fuente:** `docs/audits/advances-audit-2026-05-05.md`

## Alcance

Cubre los 3 puntos pendientes de la auditoría tras los fixes previos:

| ID | Severidad | Descripción |
|----|-----------|-------------|
| MA-010 | Major | God Service + entidad anémica en `AdvanceLegalizationService` (791 LOC). |
| SU-001 | Suggestion | Extraer `AdvanceLegalizationPipelineState` siguiendo el patrón de Invoices/Petty Cash. |
| SU-003 | Suggestion | Modal de vinculación carga todas las candidatas en cada render — diferir a AJAX. |

MA-010 y SU-001 son el mismo trabajo (extracción del State pattern + mover predicates a la entidad). SU-003 es independiente pero se agrupa para cerrar la auditoría en un solo PR.

## Resumen de decisiones (brainstorming)

1. **Un solo plan/commit** para los 3 puntos.
2. **Híbrido minimalista** para el State pattern: extraer States con la interfaz de 4 métodos de Petty Cash + mover predicates `canXxx()` a la entidad. Sin sobrediseño de States por acción.
3. **Endpoint HTML parcial** para el modal AJAX, reusando el server-side rendering del resto de SGI.
4. **Set completo de predicates** en la entidad (11) y **el `AdvanceLegalizationActionPolicy` delega** a esos predicates para eliminar la duplicación de la regla de estado.

## Arquitectura

### Archivos nuevos

```
src/Service/Pipeline/Advance/
├── AdvanceLegalizationPipelineState.php        (interface)
├── AdvanceLegalizationPipelineStateRegistry.php
└── State/
    ├── ValidacionState.php
    ├── RevisionFirmasState.php
    ├── ContabilidadState.php
    ├── TesoreriaState.php
    ├── AutorizacionPagoState.php
    └── LegalizadaState.php
templates/Advances/link_candidates.php            (fragment HTML del modal)
```

### Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `src/Model/Entity/AdvanceLegalization.php` | Agregar 11 predicates `canXxx()` (state-only). |
| `src/Service/AdvanceLegalizationService.php` | Reemplazar guards `if ($leg->status !== ...)` por `if (!$leg->canXxx())`; inyectar `AdvanceLegalizationPipelineStateRegistry`; `moveToRevisionFirmas` usa el registry. |
| `src/Service/Pipeline/Policy/AdvanceLegalizationActionPolicy.php` | Cada método delega: `_isAccountingOrAdmin($r) && $leg->canXxx()` (o `_isTreasuryOrAdmin($r)` según corresponda). |
| `src/Controller/AdvancesController.php` | Nueva acción `linkCandidates($id)` (HTML parcial); `legalization()` deja de precargar candidatas. |
| `templates/Advances/legalization.php` | Sustituye `requires('advance_link_modal')` por shell del modal con `data-load-url`. |
| `templates/element/advance_link_modal.php` | Eliminado (su contenido se vuelve shell inline en `legalization.php`). |
| `webroot/js/sgi-common.js` | Listener `show.bs.modal` que hace fetch del fragment y lo inyecta. |
| `config/routes.php` | Ruta `GET /advances/link-candidates/{id}` antes de `fallbacks()`. |

### Estimación

- Service baja de 791 LOC a ~700.
- Entidad sube ~50 LOC.
- 7 archivos nuevos + 7 modificados + 1 eliminado.

## Diseño detallado

### State pattern

**Interface** (espejo de `PettyCashPipelineState`):

```php
interface AdvanceLegalizationPipelineState
{
    public function getName(): string;
    public function getNext(): ?string;
    public function getPrevious(): ?string;
    public function validateAdvance(AdvanceLegalization $leg): array;
}
```

**Mapeo de transiciones lineales:**

| State | getNext | getPrevious | validateAdvance retorna errores si |
|-------|---------|-------------|------------------------------------|
| `ValidacionState` | `revision_firmas` | `null` | no hay ≥1 factura vinculada · no hay doc PDF pendiente · alguna linked no está en `contabilidad` |
| `RevisionFirmasState` | `contabilidad` | `validacion` | (vacío — el avance lineal lo dispara `markSigned`, no `validateAdvance`) |
| `ContabilidadState` | `null` | `revision_firmas` | (vacío — bifurca por acción explícita) |
| `TesoreriaState` | `null` | `contabilidad` | (vacío — bifurca por `case_type`) |
| `AutorizacionPagoState` | `legalizada` | `tesoreria` | (vacío — el avance lo dispara la autorización del pago) |
| `LegalizadaState` | `null` | — | (terminal) |

**Registry:** réplica directa de `PettyCashPipelineStateRegistry` — constructor con dependencias nulables y `?? new XxxState()`, método `get(string $name)`, método `all()`.

**Uso en el coordinador:**

- `moveToRevisionFirmas()` pide `$state = $registry->get($leg->status)`, agrupa los errores de `$state->validateAdvance($leg)` y los retorna como `ServiceResult::fail`.
- Las acciones bifurcantes (`markExact`, `registerShortage`, `registerSurplus`, `confirmShortageReceipt`, `registerRefundPayment`) **no** usan el registry; usan los predicates de entidad (siguiente sección).
- `_setStatus()` no cambia.

**No incluido (YAGNI):**

- `getEditableFields()` por estado (Advance no tiene formulario campo-a-campo como Invoices).
- `validateRegression()` (las regresiones son contadas y viven en sus métodos).
- States por bifurcación (sobrediseño para 3 acciones discretas con guards triviales).

### Predicates en `AdvanceLegalization`

11 métodos `canXxx()` que encapsulan **solo la regla de estado** (el rol vive en el Policy):

```php
public function canLinkInvoices(): bool
    { return $this->status === STATUS_VALIDACION; }

public function canUnlinkInvoice(): bool
    { return $this->status === STATUS_VALIDACION; }

public function canMoveToRevision(): bool
    { return $this->status === STATUS_VALIDACION; }

public function canUploadRelationDocument(): bool
    { return in_array($this->status, [STATUS_VALIDACION, STATUS_REVISION_FIRMAS], true); }

public function canMarkSigned(): bool
    { return $this->status === STATUS_REVISION_FIRMAS; }

public function canReturnToValidacion(): bool
    { return $this->status === STATUS_REVISION_FIRMAS; }

public function canMarkExact(): bool
    { return $this->status === STATUS_CONTABILIDAD && $this->case_type === null; }

public function canRegisterShortage(): bool
    { return $this->status === STATUS_CONTABILIDAD && $this->case_type === null; }

public function canRegisterSurplus(): bool
    { return $this->status === STATUS_CONTABILIDAD && $this->case_type === null; }

public function canConfirmShortage(): bool
    { return $this->status === STATUS_TESORERIA && $this->case_type === CASE_FALTANTE; }

public function canRegisterRefund(): bool
    { return $this->status === STATUS_TESORERIA
          && $this->case_type === CASE_SOBRANTE
          && empty($this->surplus_payment_id); }
```

**Cambios derivados:**

- **Service:** cada acción cambia `if ($leg->status !== STATUS_X) return fail('...');` por `if (!$leg->canXxx()) return fail('La legalización no permite esta acción.');`. Mensajes más genéricos a cambio de eliminar duplicación de la regla de estado.
- **Policy:** cada método pasa a una línea: `return $this->_isAccountingOrAdmin($r) && $leg->canXxx();` (o `_isTreasuryOrAdmin` según la acción). Una sola fuente de verdad para la regla de estado.
- **Templates:** `legalization.php` ya consume el Policy, no requiere cambios.

**Nota sobre `canMarkExact` / `canRegisterShortage` / `canRegisterSurplus`:** se incluye `case_type === null` (regla MA-005). El predicate vuelve la verdad — el botón en el template se oculta al instante tras declarar un caso.

### Modal AJAX (SU-003)

**Endpoint nuevo:** `AdvancesController::linkCandidates(int $id)`

```php
public function linkCandidates(int $id): ?Response
{
    $this->request->allowMethod(['get']);
    $leg = $this->_loadLegalization($id);
    if (!$this->actionPolicy->canLinkInvoices($leg, $roleName)) {
        throw new ForbiddenException();
    }

    $invoices = $this->fetchTable('Invoices');
    $advance  = $invoices->get($leg->advance_invoice_id, [
        'fields' => ['id', 'operation_center_id'],
    ]);

    $filters = [
        'date_from'           => $this->request->getQuery('date_from'),
        'date_to'             => $this->request->getQuery('date_to'),
        'provider_id'         => $this->request->getQuery('provider_id'),
        'operation_center_id' => $this->request->getQuery('operation_center_id')
                                 ?: $advance->operation_center_id,
    ];

    $conditions = [
        'Invoices.document_type' => DOCTYPE_LEGALIZACION,
        'Invoices.advance_id IS' => null,
    ];
    if (!empty($filters['operation_center_id'])) {
        $conditions['Invoices.operation_center_id'] = $filters['operation_center_id'];
    }
    if (!empty($filters['date_from'])) { $conditions['Invoices.issue_date >='] = $filters['date_from']; }
    if (!empty($filters['date_to']))   { $conditions['Invoices.issue_date <='] = $filters['date_to']; }
    if (!empty($filters['provider_id'])) { $conditions['Invoices.provider_id'] = $filters['provider_id']; }

    $candidates = $invoices->find()
        ->where($conditions)
        ->contain(['Providers', 'Employees', 'OperationCenters'])
        ->order(['Invoices.issue_date' => 'DESC'])
        ->limit(200)
        ->all();

    $this->set(compact('leg', 'candidates', 'filters'));
    $this->viewBuilder()->disableAutoLayout();
    // renderiza templates/Advances/link_candidates.php
}
```

**Ruta** (antes de `$builder->fallbacks()`):

```php
$builder->connect(
    '/advances/link-candidates/{id}',
    ['controller' => 'Advances', 'action' => 'linkCandidates'],
    ['id' => '\d+', 'pass' => ['id']],
);
```

**Template `templates/Advances/link_candidates.php`:** renderiza el form con filtros + `<table>` con `<tbody>` poblado. Reusa la estructura interna que hoy provee `link_invoices_modal.php`. El form de filtros apunta al mismo endpoint vía GET; JS intercepta el submit para reemplazar solo el cuerpo del modal.

**Shell del modal en `legalization.php`:**

```html
<div class="modal fade" id="advanceLinkModal"
     data-load-url="<?= $this->Url->build(['action' => 'linkCandidates', $leg->id]) ?>">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-body text-center py-5 text-muted modal-loading-state">
        <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
      </div>
    </div>
  </div>
</div>
```

**JS en `webroot/js/sgi-common.js`:**

```js
document.addEventListener('show.bs.modal', async (ev) => {
    const url = ev.target.dataset.loadUrl;
    if (!url) return;
    const body = ev.target.querySelector('.modal-content');
    const res  = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    body.innerHTML = await res.text();
    window.sgiInit?.(body);   // re-init Flatpickr/Select2 sobre el fragment cargado
});

// Filtros: form submit dentro del modal hace fetch en lugar de navegar
document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!form.matches('[data-modal-filter-form]')) return;
    ev.preventDefault();
    const modal = form.closest('.modal');
    const url   = form.action + '?' + new URLSearchParams(new FormData(form));
    const body  = modal.querySelector('.modal-content');
    const res   = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    body.innerHTML = await res.text();
    window.sgiInit?.(body);
});
```

**Eliminación:** `templates/element/advance_link_modal.php` se borra. Su query queda absorbida por el endpoint `linkCandidates`, su markup queda absorbido por el shell + el fragment.

**Consecuencias positivas:**

- `legalization.php` deja de ejecutar la query de candidatas en cada render (gana en cada visita a la pantalla, no solo al abrir el modal).
- Filtros del modal hacen fetch al mismo endpoint sin recargar la página.
- Límite duro de 200 resultados como red de seguridad — si crece, se añade paginación con un parámetro `page` adicional sin tocar el JS.

## Validación manual (sustituye tests)

Tras merge, ejecutar contra `php bin/cake server`:

1. **Abrir `/advances/legalization/{id}`** y verificar en network tab que **no** hay query de candidatas. Solo al abrir el modal `#advanceLinkModal` debe dispararse `GET /advances/link-candidates/{id}`.
2. **Modal carga correctamente:** spinner aparece, luego se reemplaza por la lista. Cambiar filtros (fecha, OC, proveedor) dispara nuevo fetch sin recargar. Inicialización de Flatpickr/Select2 funciona sobre el fragment cargado.
3. **403 por rol:** loguearse como Tesorería e intentar `POST /advances/mark-exact/{id}` → 403 (Policy delegando al predicate de entidad).
4. **Predicate de entidad bloquea botón:** setear `case_type='exacto'` directamente en BD → recargar `legalization` → botón "Marcar exacto" no aparece.
5. **End-to-end caso exacto:** crear anticipo, pagar, vincular factura legalización (mismo OC), subir relación, marcar firmado, marcar exacto → verificar `status=legalizada`, `case_type=exacto`, factura vinculada promovida a `legalizada` por el subscriber.
6. **End-to-end caso sobrante:** mismo flujo pero registrar sobrante en Contabilidad → registrar reintegro en Tesorería → autorizar pago de reintegro como Contador → verificar `status=legalizada`.
7. **End-to-end caso faltante:** registrar faltante → confirmar consignación en Tesorería → verificar `status=legalizada` (no pasa por aut_pago).
8. **Avance lineal vía registry:** verificar que `moveToRevisionFirmas` retorna los mensajes correctos cuando faltan facturas vinculadas o documento PDF.

## Plan de implementación

Orden sugerido para minimizar cambios cruzados:

1. **Predicates en la entidad** (sin tocar service) → no rompe nada.
2. **State pattern + Registry** (archivos nuevos) → no rompe nada.
3. **Refactor del service** para usar predicates y registry → primer cambio funcional.
4. **Refactor del Policy** para delegar a predicates → simplificación.
5. **Endpoint `linkCandidates` + template fragment + ruta** → backend del AJAX listo.
6. **Shell del modal en `legalization.php` + JS + eliminación de `advance_link_modal`** → frontend del AJAX.
7. **Validación manual** end-to-end de los 3 casos (exacto, faltante, sobrante).
8. **Commit único** con mensaje `chore(advances): cierre auditoría — State pattern, predicates de entidad, modal AJAX (MA-010, SU-001, SU-003)`.

## No incluido en este plan

- Refactor del modal genérico `link_invoices_modal.php` para usarlo también en otros módulos (Reintegros, Caja Menor) vía AJAX. Si se quiere unificar, se hace en plan aparte.
- Paginación del endpoint `linkCandidates`. Hoy con `LIMIT 200` y filtros por OC el set es manejable; si crece, se añade después sin romper la API actual.
- Migración de `AdvanceLegalizationActionPolicy` a una clase base compartida con futuros policies (Refunds, etc.). Esperar a que aparezca el segundo caso.
