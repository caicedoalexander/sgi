# Vincular "Recibo de Caja" a la legalización — Fase 3 (diseño)

**Fecha:** 2026-07-01
**Estado:** Diseño aprobado — pendiente plan de implementación
**Autor:** Alexander + brainstorming asistido

---

## 1. Resumen

Las Fases 1 y 2 (mergeadas) permiten vincular un `Recibo de Caja` (o `Legalización`) existente a la
legalización de un anticipo y darle el tratamiento completo de legalización. Pero el flujo actual
tiene **dos pasos**: se crea la factura suelta (sin `advance_id`, en su pipeline normal) y luego se
la vincula desde el modal de la legalización.

La **Fase 3** añade un **acceso directo para crear la factura ya vinculada** desde el inicio: un
botón en la vista de legalización del anticipo abre el formulario de creación con el anticipo
pre-vinculado, de modo que la factura **nace con `advance_id`** y, por lo tanto, muestra el pipeline
reducido de legalización (F2) desde el primer momento — sin el paso separado de vincular.

Es una feature de **UX de captura**: no cambia el comportamiento post-creación (freeze, promoción,
vista), que ya viven en F1/F2.

---

## 2. Alcance

### Incluido
- Botón "Nueva" en la sección "Facturas vinculadas" de la vista de legalización (junto a "Vincular",
  bajo el mismo guard `status === Validación`) → `Invoices::add?advance_id=<anticipo_id>`.
- `InvoicesController::add()` acepta `advance_id` (GET para pre-rellenar, POST para persistir), con
  **re-validación** del contexto de legalización en el POST.
- `templates/Invoices/add.php` (legacy) adaptado para el caso vinculado: hidden `advance_id`, banner
  de contexto, OC pre-seleccionado (editable), selector de `document_type` limitado a los 2 tipos
  vinculables, y ajuste del JS de visibilidad.
- `InvoiceAddViewModel` expone `advance_id` y el anticipo (OC, número) para el form.
- Redirección a `Advances::legalization/<anticipo_id>` tras crear una factura vinculada.

### Fuera de alcance
- Modernizar `templates/Invoices/add.php` fuera del soporte de `advance_id` (sigue siendo deuda
  legacy `.card`, decidida por módulo — ver CLAUDE.md "Canon visual").
- Cambios en el comportamiento de F1/F2 (freeze, promoción, pipeline visual, banner de la vista).
- Crear vinculado desde otros orígenes que no sean la vista de legalización.
- Exigir el gate de vinculación (`pipeline legalizations`) — se decidió RBAC solo `can_create` (§6).

---

## 3. Contexto técnico

`InvoicesController::add()` (`:237-272`) hoy: en POST fija `pipeline_status = STATUS_APROBACION`
(`:242`), parchea la entidad con el `$data` del form y guarda; **no** setea `advance_id`. Pero
`advance_id` **es mass-assignable** en `Invoice::$_accessible` (`:41`), así que basta con que el
`$data` lo incluya para que se persista.

El botón "Vincular" vive en `templates/Advances/legalization.php:171-175`, dentro de
`if ($leg->status === AdvanceConstants::STATUS_VALIDACION)`. El acceso directo va al lado, con el
mismo guard.

Una factura creada con `advance_id` no-nulo satisface `Invoice::usesLegalizationView()` (F2) → nace
con el pipeline reducido de 3 pasos (`aprobacion` resaltado) y avanza a `contabilidad` por su flujo
normal de aprobación, donde el freeze de F1 (`blocksAdvance` en `contabilidad`) la congela. Coincide
exactamente con cómo se comporta una `Legalización` vinculada.

---

## 4. Diseño

### 4.1 Acceso directo (vista de legalización)

En `templates/Advances/legalization.php` (`:167-175`), junto al botón "Vincular", añadir un botón
"Nueva" (link GET). Como hoy el header de la card usa `justify-content-between` con un único botón a
la derecha, envolver ambos en un `<div class="d-flex gap-2">` para no romper el layout. El botón
"Nueva" va dentro del mismo `if status === VALIDACION` **y** gateado por `can_create` de invoices
(consistente con §6):

```php
<?php if (!empty($userPermissions['invoices']['can_create'])): ?>
<?= $this->Html->link(
    '<i class="bi bi-file-earmark-plus" aria-hidden="true"></i>Nueva',
    ['controller' => 'Invoices', 'action' => 'add', '?' => ['advance_id' => $leg->advance_invoice_id]],
    ['class' => 'btn btn-default btn-sm', 'escape' => false],
) ?>
<?php endif; ?>
```

El plan debe confirmar que `$userPermissions` llega a `legalization.php`; si no, gatear solo por
`status === VALIDACION` como el botón "Vincular" adyacente (que ya delega el deny al server vía
`#[Permission]`).

### 4.2 `InvoicesController::add()` — GET

Leer `advance_id` del query. Validar el contexto (§4.4). Si es válido, cargar el anticipo y pasar al
form `advance_id` + el anticipo (OC, número) vía el `InvoiceAddViewModel`/`set`. Si es inválido o
ausente, el form es el normal (sin cambios respecto a hoy).

### 4.3 `InvoicesController::add()` — POST

Si el `$data` trae `advance_id` no vacío:
1. **Re-validar** el contexto de legalización (§4.4). Si falla, `Flash->error` y volver al form
   (no crear vinculado). No confiar en el hidden del cliente.
2. Persistir normalmente (`advance_id` ya es mass-assignable). El estado inicial sigue
   `STATUS_APROBACION`.
3. **Registrar el vínculo en el historial de la legalización** (§4.7) — paridad de auditoría con el
   modal.
4. Redirigir a `['controller' => 'Advances', 'action' => 'legalization', $advanceId]` en lugar de
   `_redirectForInvoice(...)`, para volver a la legalización desde donde se creó.

Si no trae `advance_id`, el comportamiento es el actual.

### 4.4 Validación del contexto de legalización

`advance_id` es válido para creación vinculada cuando:
- existe un `Invoice` con ese id y `document_type = Anticipo` (opcional, pero coherente), y
- su `AdvanceLegalization` está en `status = STATUS_VALIDACION` (mismo estado en que se puede
  vincular en F1), y
- en el POST, además: `document_type ∈ InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES`.

La verificación de la legalización en `Validación` se resuelve cargando la `AdvanceLegalization` por
`advance_invoice_id` (reutilizando el patrón de F1; `AdvanceLegalizationService` ya conoce la
entidad). Encapsular el predicado en un método pequeño (p. ej. `AdvanceLegalizationService::
canCreateLinkedInvoice(int $advanceInvoiceId): bool`) mantiene `add()` delgado.

### 4.5 `templates/Invoices/add.php`

Cuando el form recibe un `advance_id` válido (legacy, se toca solo lo necesario):
- **Hidden input** `advance_id`.
- **Banner** de contexto: *"Comprobante para el Anticipo #{número}"* con enlace al anticipo.
- **Centro de Operación** (`operation_center_id`, el `<select>` de `:137`, siempre visible; **no**
  `purchase_order`/"Orden de Compra", que el JS oculta para `Legalización`) pre-seleccionado con el
  del anticipo, **editable**. Es el mismo campo por el que F1 filtra candidatos.
- **`<select>` de `document_type`** limitado a `Legalización` y `Recibo de Caja` (en vez de todos
  los `DOCUMENT_TYPES`).
- El **JS de visibilidad** existente (`isLegalization`/`isReciboDeCaja`, `:237-246`) se ajusta para
  el caso vinculado (los campos que ya oculta para esos tipos siguen ocultos).

### 4.6 `InvoiceAddViewModel`

Exponer `advance_id` (nullable) y el anticipo asociado (número, `operation_center_id`) para que
`add.php` los consuma. **Wiring:** hoy `add()` hace `set('invoice', $vm->invoice)` y el template
legacy consume `$invoice` (no el VM). Para F3, el controller además setea las vars del contexto
vinculado (p. ej. `set('advance', $anticipo)`) — o se extiende el VM y se adapta `add.php` a
consumirlo. El plan fija el mecanismo exacto (`add.php` es la excepción legacy que aún usa
`$invoice`, no `$viewModel`).

### 4.7 Registro de auditoría del vínculo (paridad con el modal)

`AdvanceLegalizationService::linkInvoices()` escribe una entrada `invoices_linked` en el historial
de la legalización (`recordFieldChange($leg->id, 'invoices_linked', null, …)`, `:139-145`), que
alimenta el "registro" de la vista de legalización. F3 crea la factura directamente (sin pasar por
`linkInvoices`), así que debe **registrar el vínculo por su cuenta** para paridad de trazabilidad.
Delegar en un método de `AdvanceLegalizationService` (p. ej. `recordDirectLink(AdvanceLegalization
$leg, Invoice $invoice, int $userId)`) que reusa el `recordFieldChange('invoices_linked', …)` — así
`add()` no conoce el `AdvanceLegalizationHistoryService` directamente (coordinador delgado). La
factura además obtiene su `recordStatusChange` de creación en `invoice_histories` (como hoy).

---

## 5. Comportamiento resultante

- Desde la legalización en `Validación`, "Nueva" abre el form con el anticipo pre-vinculado.
- El usuario elige `Legalización` o `Recibo de Caja`, completa los campos y guarda.
- La factura se crea con `advance_id` en `aprobacion`, mostrando ya el pipeline reducido (F2), y el
  usuario vuelve a la vista de la legalización (donde la factura aparece como vinculada).
- El comprobante avanza a `contabilidad` por su flujo de aprobación normal; ahí se congela (F1) y,
  al legalizarse el anticipo, se promueve a `legalizada` (F2).
- **Nota operativa:** mientras el comprobante creado por F3 esté en `aprobacion` (antes de
  `contabilidad`), la legalización **no puede avanzar** de `Validación` — el gate
  `ValidacionState::validateAdvance` exige que **todas** las vinculadas estén en `Contabilidad`
  (misma situación que el RC regresado, F1 §8). La invariante anti-doble-pago se mantiene (no hay
  pago posible antes de `tesoreria`).
- Un `advance_id` manipulado o inválido (anticipo inexistente, legalización no en `Validación`, o
  `document_type` no vinculable) **no** crea una factura vinculada — se rechaza en el POST.

---

## 6. Modelo de datos y RBAC

- **Modelo de datos: N/A.** Sin migración; `advance_id` ya existe y es mass-assignable.
- **RBAC:** el acceso directo y `add()` siguen gobernados por `#[Permission(action: 'add')]`
  (`invoices.can_create`). Crear-vinculado se trata como crear una factura (el `advance_id` es un
  campo más); **no** se exige el gate de vinculación (`pipeline legalizations`). El botón "Nueva"
  aparece si el usuario puede crear facturas (además del guard de `status === Validación`).

---

## 7. Lo que NO cambia

- El estado inicial de toda factura sigue `STATUS_APROBACION`.
- El freeze/validación/promoción/vista de F1 y F2 se reusan tal cual (la factura vinculada por F3 es
  indistinguible de una vinculada por el modal).
- El flujo de vinculación de F1 (`linkCandidates`/`linkInvoices`) sigue disponible; F3 es un atajo,
  no un reemplazo.
- `add.php` sigue legacy salvo el bloque condicional de `advance_id`.

---

## 8. Testing

- **Acceso directo:** el botón "Nueva" solo aparece cuando `status === Validación`.
- **GET válido:** `add(?advance_id=X)` con anticipo cuya legalización está en `Validación` pre-rellena
  el form (advance_id, OC, banner) y limita el selector de tipo.
- **POST válido:** crea la factura con `advance_id` seteado, `pipeline_status = aprobacion`, y
  `usesLegalizationView() = true`; redirige a `Advances::legalization`.
- **POST inválido (seguridad):** `advance_id` de un anticipo sin legalización en `Validación`, o con
  `document_type ∉ ADVANCE_LINKABLE_DOCTYPES`, **no** persiste el vínculo (rechaza con error).
- **Sin `advance_id`:** el `add()` normal se comporta como hoy (test de regresión).

---

## 9. Convenciones SPI aplicables

- **Validación en el boundary autoritativo:** el `advance_id` se re-valida en el POST, no solo en el
  listado/GET (misma disciplina que el fix de doble pago de F1).
- **Constante fuente única:** los tipos permitidos derivan de `ADVANCE_LINKABLE_DOCTYPES`.
- **Slugs persistidos inmutables:** no se tocan `'Legalización'`/`'Recibo de Caja'` ni estados.
- **Coordinador delgado:** la validación del contexto de legalización se delega a un método de
  `AdvanceLegalizationService`, no se incrusta en el controller.
- **Deuda legacy respetada:** `add.php` no se moderniza fuera del alcance; el canon visual de `add`
  sigue siendo decisión pendiente por módulo.
- **Auditoría por dominio:** el vínculo creado por F3 se registra en el historial de la legalización
  (`invoices_linked`), en paridad con el modal — sin importar la vía de creación (§4.7).
