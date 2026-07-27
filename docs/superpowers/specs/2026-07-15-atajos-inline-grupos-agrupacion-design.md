# Atajos inline (DIAN + soporte) en la etapa Agrupación de Caja Menor y Reintegros — Diseño

**Fecha:** 2026-07-15
**Estado:** Aprobado para planificación (revisado por spi-design-reviewer)

## Problema

En la vista editable de **Caja Menor** (`PettyCashRecords/edit.php`) y **Reintegros**
(`Refunds/edit.php`), durante la etapa **Agrupación** (donde se vinculan/desvinculan
facturas hijas), la tabla de facturas agrupadas es una tabla artesanal de 3 columnas
(`# Factura / Proveedor / Monto` + botón `×` para desvincular). No ofrece los atajos
inline por fila para **subir el soporte** ni **resolver/indicar el estado DIAN**, a
diferencia de la vista de **Legalización de anticipos** (`Advances/legalization.php`),
que sí los tiene vía el element `advance_legalization/_linked_invoices`.

### Verificación de alcance

| Módulo | `view.php` (detalle) | `edit.php` (Agrupación / vincular) |
|---|---|---|
| Anticipos / Legalización | ✅ atajos inline | ✅ atajos inline (`advance_legalization/_linked_invoices`) |
| **Caja Menor** | ✅ soporte inline (`grouped_invoices_table`) | ❌ tabla pelada de 3 columnas |
| **Reintegros** | ✅ DIAN + soporte inline (`grouped_invoices_table`) | ❌ tabla pelada de 3 columnas |

Caja Menor **no** es el único: Reintegros tiene el hueco idéntico. Ambos módulos se
arreglan en esta iteración; Legalizaciones ya está completo y **no** se toca.

### Causa raíz

Las `view.php` de Caja Menor y Reintegros ya reutilizan el element compartido
`grouped_invoices_table` (7 columnas, con celdas DIAN y Soporte inline). Las `edit.php`,
en cambio, dibujan una tabla propia en la sección de facturas de la etapa Agrupación.
Legalizaciones comparte **un solo** element entre su vista editable y la de detalle;
Caja Menor y Reintegros no.

## Objetivo

Llevar los atajos inline por fila (subir soporte + resolver/indicar DIAN) a la tabla de
facturas de la etapa Agrupación en Caja Menor y Reintegros, reutilizando la maquinaria
existente, con paridad de comportamiento **y de RBAC** respecto a Legalizaciones y respecto
a la `view()` de cada módulo.

## Comportamiento por dominio (correcto y automático)

La celda DIAN de cada fila la resuelve `InvoicePresentation::forGroupedRow($invoice, $canResolveDian)`.
El modo `select` (accionable) se activa **solo** cuando
`$canResolveDian && $invoice->pipeline_status === STATUS_APROBACION` (`InvoicePresentation.php:161`);
en cualquier otro caso cae a `pill` (lectura) o `na` (doctype exento de DIAN, p. ej. Recibo de Caja).

Esto hace el comportamiento correcto sin casos especiales en el markup:

- **Caja Menor:** sus hijas viven en `contabilidad` (auto-avanzan desde `aprobacion` al
  vincularse, por diseño). Como nunca están en `aprobacion`, la celda DIAN se pinta como
  pill de lectura o "No aplica"; el atajo accionable es el de **soporte**. `canResolveDian`
  es irrelevante aquí y se fija en `false`.
- **Reintegros:** sus hijas están en `aprobacion`. La celda DIAN se vuelve **select
  accionable** además del atajo de soporte, paridad total con Legalizaciones — **pero solo
  si el rol pasa el gate DIAN completo** (ver §RBAC).

## Arquitectura

Extender el element compartido `templates/element/grouped_invoices_table.php` con
afordances editables **opcionales** (retrocompatibles), y hacer que las dos `edit.php`
lo usen como **card top-level propia** (extrayendo la sección de facturas de la card
multi-sección actual — ver §Decisión estructural). Se reutilizan sin modificar:

- Sub-elements de celda: `grouped_cells/_dian.php`, `grouped_cells/_support.php`.
- JS: `SpiGroupedInvoices` (guardado inline de DIAN + subida de soporte por fila).
- Endpoint: `InvoicesController::updateDianInline` (whitelist `PARENT_FOREIGN_KEYS` ya
  incluye `petty_cash_record_id` y `refund_id`); revalida el gate DIAN server-side.
- Presentación: `InvoicePresentation::forGroupedRow`, DTO `GroupedInvoiceRowView`,
  DTO `GroupReadinessReport`.

Legalizaciones conserva su element bespoke (`advance_legalization/_linked_invoices`) —
excepción de dominio documentada (soportes con firma). **No** se unifica en esta iteración.

## Decisión estructural (resuelve card-in-card)

El element `grouped_invoices_table` es una **card completa** (`<div class="spi-card">` con su
propio header "Facturas Agrupadas" + `.spi-folder-count` + banner de checklist), pensada para
vivir a nivel top del panel derecho — así la usan `view.php` y Legalizaciones.

Hoy, en ambas `edit.php`, la sección de facturas vive **dentro** de una card multi-sección
compartida con un `foreach ($sections)` reordenado editable-first
(`PettyCashRecords/edit.php:250-441`, `Refunds/edit.php:193-411`). Insertar el element ahí
produciría card-anidada-en-card y header duplicado.

**Resolución:** extraer la sección de facturas del loop `$sections` y renderizar el element
como **card top-level propia** en el panel derecho (`<main>`), en paridad con `view.php` y
Legalizaciones. Ubicación: primera card del panel derecho, tras el banner de errores de
avance y antes de la card multi-sección. Se renderiza en **todos** los estados (con
`editable` solo en Agrupación); en estados posteriores queda como tabla de lectura con Total.
La clave `'invoices'` se elimina del array `$sections` y de su `usort`.

## Componentes y cambios

### 1. Element `templates/element/grouped_invoices_table.php`

Nuevos parámetros opcionales (defaults preservan el markup actual de las `view.php`):

- `editable` (bool, default `false`): cuando `true`, la tabla añade una **columna final de
  desvincular** (un `postLink` a `unlinkAction` por fila). La celda `<td>` de desvincular
  lleva `onclick="event.stopPropagation();"` (las filas son `.clickable-row` que navegan a
  `Invoices::view`; las celdas `_dian`/`_support` ya lo hacen).
- `headerActionsHtml` (string|null, default `null`): slot HTML en el header de la card, a la
  derecha del label — para el botón **"Vincular facturas"** (el modal de vinculación sigue en
  `edit.php`).
- `unlinkAction` (string, default `'removeInvoice'`): acción del controller actual para el
  postLink de desvincular. La URL se arma como `['action' => $unlinkAction, $parentId, $row->id]`
  (routing al controller actual — el element se renderiza dentro de la vista del módulo).
- `totalAmount` (float|null, default `null`): cuando **no es null**, renderiza un `<tfoot>`
  con el Total. Independiente de `editable` (no debe desaparecer en estados post-Agrupación).

Regla anti-drift: los mapeos estado→pill siguen saliendo de `InvoicePresentation` vía los
DTOs; cero literales `pill-*` nuevos en el element.

### 2. ViewModels de edición

- `src/ViewModel/PettyCashEditViewModel.php`: recibe `?GroupReadinessReport $readiness` y
  `bool $canUploadSupport`; construye `public array $groupedRows` con
  `InvoicePresentation::forGroupedRow($inv, canResolveDian: false)` sobre `$record->invoices`
  (hardcode `false`, igual que `PettyCashViewViewModel:83` — sus hijas nunca están en
  `aprobacion`, así que el parámetro no aporta).
- `src/ViewModel/RefundEditViewModel.php`: recibe además `bool $canResolveDian`; construye
  `groupedRows` con `forGroupedRow($inv, $canResolveDian)` (igual que `RefundViewViewModel`).

Ambos exponen también `readiness` y `canUploadSupport` para el element.

### 3. Controllers `edit()`

`src/Controller/PettyCashRecordsController.php` y `src/Controller/RefundsController.php`:

- Añadir `InvoiceDocuments` al `contain` de las hijas (`'Invoices' => [..., 'InvoiceDocuments']`)
  para que `forGroupedRow` calcule `docsCount`. Conservar los contains existentes (`Providers`, etc.).
- Calcular y pasar al EditViewModel `readiness`, `canUploadSupport` y (solo Reintegros)
  `canResolveDian`, **replicando literalmente los gates de la `view()` de cada módulo** (ver §RBAC).
- Guard de `readiness` por módulo (nombres NO uniformes):
  - Caja Menor: `(new \App\Service\Pipeline\PettyCash\Guard\PettyCashGuard())->childRequirements((int)$record->id)`.
  - Reintegros: `(new \App\Service\RefundApprovalGuard())->childRequirements((int)$record->id)`.

### 4. Templates `edit.php`

`templates/PettyCashRecords/edit.php` y `templates/Refunds/edit.php`:

- Eliminar la clave `'invoices'` del array `$sections` (y de su `usort`); eliminar el bloque
  de tabla artesanal de facturas.
- Renderizar el element como card top-level del panel derecho (ver §Decisión estructural):
  `$this->element('grouped_invoices_table', ['rows' => $groupedRows, 'readiness' => $readiness,
  'parentField' => 'petty_cash_record_id'|'refund_id', 'parentId' => (int)$record->id,
  'canUploadSupport' => $canUploadSupport, 'uploadModalId' => $canUploadSupport ? 'groupedUploadModal' : null,
  'editable' => $record->isAgrupacion(), 'headerActionsHtml' => $record->isAgrupacion() ? <botón Vincular> : null,
  'totalAmount' => (float)$record->total_amount])`.
  - Fuente de las claves: en PettyCash el controller publica con `set(get_object_vars($vm))`
    (variables sueltas `$groupedRows`, `$readiness`, `$canUploadSupport`); en Refund con
    `set('viewModel', $vm)` (`$viewModel->groupedRows`, etc.). El plan debe usar la fuente
    correcta por template (deuda pre-existente, no la unificamos aquí).
- Incluir el modal de subida grupal (`upload_doc_modal` con `modalId => 'groupedUploadModal'`,
  `formId => 'grouped-upload-form'`, `showDocumentType => true`) cuando `canUploadSupport`, igual
  que la `view.php`.
- El modal de vinculación (`link_invoices_modal`) se conserva; el botón "Vincular facturas"
  pasa al `headerActionsHtml`.

## RBAC (gates exactos — transcritos de la `view()` real)

**Caja Menor** (`PettyCashRecordsController::view():198-204`) — soporte gateado por el step
`contabilidad` (donde viven las hijas). `edit()` debe replicarlo:

```php
$roleId  = (int)$this->_getCurrentUser()->role_id;
$context = new UserContext($roleId);
$canUploadSupport = $this->authFacade->canOperate(
    $context, PipelineStepConstants::PIPELINE_INVOICES, InvoiceConstants::STATUS_CONTABILIDAD,
) && $this->_checkPermission('invoices', 'edit');
// canResolveDian NO se pasa (hardcode false en el VM): las hijas están en contabilidad.
```

**Reintegros** (`RefundsController::view():~248-264`) — gate de soporte de 2 partes; gate DIAN
de **3 partes** (la tercera vía `InvoiceFieldAccessPolicy`). `edit()` debe replicar **ambos**:

```php
$canOperateAprobacion = $this->authFacade->canOperate(
    $context, PipelineStepConstants::PIPELINE_INVOICES, InvoiceConstants::STATUS_APROBACION);
$canEditInvoices = $this->_checkPermission('invoices', 'edit');

$canUploadSupport = $canOperateAprobacion && $canEditInvoices;

$canResolveDian = $canOperateAprobacion && $canEditInvoices
    && in_array(
        'dian_validation',
        $fieldPolicy->getEditableFields($roleId, InvoiceConstants::STATUS_APROBACION),
        true,
    );
```

`canResolveDian` **NO** debe igualarse a `canUploadSupport`: un rol que opera `aprobacion` con
`can_edit(invoices)` pero cuyo `InvoiceFieldAccessPolicy` no incluye `dian_validation` no debe
recibir el `<select>` DIAN accionable (invariante "FieldAccessPolicy rol-aware", CLAUDE.md ›
Auth & Permissions). El plan debe extraer el cálculo del gate de `view()` a un helper privado
compartido por `view()`/`edit()` (o replicarlo idéntico) para evitar drift entre ambos.

## Flujo de datos

```
controller.edit()
  → get(record, contain: Invoices => [Providers, ..., InvoiceDocuments])
  → readiness = {Guard por módulo}.childRequirements(record.id)
  → gates: canUploadSupport [, canResolveDian]  (transcritos de view(), ver §RBAC)
  → new {Modulo}EditViewModel(readiness, canUploadSupport [, canResolveDian], ...)
        → groupedRows = forGroupedRow(inv, canResolveDian) por hija
  → edit.php: element('grouped_invoices_table', editable=isAgrupacion, rows=groupedRows, ...)
        → por fila: grouped_cells/_dian + grouped_cells/_support (mismo gating por fila)
  → SpiGroupedInvoices: guardado DIAN inline + subida soporte contra endpoints existentes
```

## Criterios de aceptación

1. En Caja Menor (Agrupación), cada fila de factura agrupada muestra el atajo de **subir
   soporte** (si `canUploadSupport`) y el estado DIAN de lectura/`na`; conserva la columna de
   desvincular y el Total.
2. En Reintegros (Agrupación), cada fila muestra soporte inline y, para hijas en `aprobacion`
   con rol que pasa el gate DIAN de 3 partes, el **`<select>` DIAN accionable**; conserva
   desvincular y Total.
3. El botón "Vincular facturas" y el modal siguen funcionando (ahora en el header del element).
4. En estados posteriores a Agrupación, la card de facturas se muestra en **lectura** (sin
   columna desvincular, sin botón Vincular) pero **con el Total** visible.
5. Un rol sin `dian_validation` editable en `aprobacion` **no** ve el `<select>` DIAN en
   Reintegros (paridad con `view()`).
6. Las `view.php` de ambos módulos quedan visualmente idénticas (retrocompatibilidad del element).

## Pruebas

**Element (`grouped_invoices_table`):**
- `editable = true`: aparece la columna de desvincular (con `stopPropagation`).
- `editable = false` (regresión de las `view.php`): sin columna desvincular; markup idéntico al actual.
- `totalAmount !== null`: aparece `<tfoot>` de Total, con `editable` true **y** false.

**Controllers (`edit()`):**
- `PettyCashRecordsController::edit()` y `RefundsController::edit()` cargan `InvoiceDocuments` en las hijas.
- El EditViewModel expone `groupedRows`, `readiness`, `canUploadSupport` (y Refund `canResolveDian`).
- Una hija con soporte requerido y `docsCount = 0` aparece en `readiness->supportMissing`.

**Gating / RBAC:**
- Hija de Caja Menor (doctype `Caja menor`, en `contabilidad`): celda DIAN en modo `pill`; soporte accionable si `canUploadSupport`.
- Hija Recibo de Caja de Caja Menor: DIAN en modo `na` (exento); soporte requerido.
- Hija de Reintegro en `aprobacion`, rol con gate DIAN completo: celda DIAN en modo `select`.
- Hija de Reintegro en `aprobacion`, rol con `can_edit(invoices)` pero **sin** `dian_validation`
  editable: celda DIAN en modo `pill` (no `select`) — paridad con `view()`.

## Qué NO cambia

- Legalizaciones (`Advances/legalization.php` y su element `_linked_invoices`).
- Pipeline, servicios de coordinación, endpoints, `forGroupedRow`, sub-elements de celda.
- Los ViewModels de **vista** (`PettyCashViewViewModel`, `RefundViewViewModel`) y las `view.php`.
- Cualquier dato persistido, migración, RBAC persistido (tablas `permissions`/`pipeline_permissions`) o convención de slugs.

El cambio es capa de vista + propagación de datos y gates que ya existen en el sistema.

## Riesgos y mitigaciones

- **Regresión visual en las `view.php`:** mitigada por los defaults retrocompatibles del element
  y un test que compara el markup con `editable = false` / `totalAmount` presente.
- **Card-in-card / doble header:** resuelto extrayendo la sección de facturas a card top-level
  propia (§Decisión estructural).
- **Sobre-exposición del control DIAN en Reintegros:** mitigada replicando el gate de 3 partes
  de `view()`; además `updateDianInline` revalida server-side.
- **Total perdido en estados post-Agrupación:** mitigado desacoplando `<tfoot>` de `editable`
  (se liga a `totalAmount !== null`).
- **Doble uploader en `edit.php`** (soporte del padre + soporte grupal): la coexistencia de dos
  `upload_doc_modal` es nueva en `edit.php` (la `view.php` incluye solo el agrupado), pero es
  segura: `upload_doc_modal.php` parametriza `modalId`/`formId` y sus inputs usan solo `name`
  (sin IDs hardcodeados); los selectores del uploader del padre (`#upload-doc-form` /
  `#uploadPcDocModal`/`#uploadRefundDocModal`) son distintos de los del agrupado
  (`#grouped-upload-form` / `#groupedUploadModal`). El plan debe confirmar que el
  `counterSelector: '.spi-folder-count'` del uploader del padre sigue apuntando al contador
  correcto (la sección de facturas ya emitía un `.spi-folder-count` antes de este cambio).
- **DIAN accionable indebido en Caja Menor:** imposible por construcción — `forGroupedRow` exige
  `pipeline_status === aprobacion` para el modo `select`, y las hijas de Caja Menor nunca están ahí.
