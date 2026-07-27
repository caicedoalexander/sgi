# Diseño: Beneficiario, Tipo de documento y fix de click en la tabla de facturas agrupadas

**Fecha:** 2026-07-16
**Rama:** dev
**Alcance:** element compartido `grouped_invoices_table` (Caja Menor + Reintegros) y tabla bespoke de Anticipos (`advance_legalization/_linked_invoices`).

## Problema

En la tarjeta "Facturas Agrupadas" (facturas hijas de un registro padre) hay tres novedades:

1. **Columna "Proveedor" incorrecta.** El beneficiario de una factura hija a veces es un **empleado**, no un proveedor externo. Hoy la columna solo lee `provider->name` y muestra `—` cuando el titular es un empleado.
2. **Falta la columna "Tipo de documento".** No se puede distinguir de un vistazo si una hija es `Caja menor`, `Recibo de Caja`, etc.
3. **El click en la fila no navega.** En `edit.php` aparece el `cursor:pointer` pero al hacer click no redirige a la factura.

## Causas raíz

| # | Hallazgo |
|---|----------|
| 1 | El `Invoice` tiene `provider_id` **y** `employee_id` (ver `InvoicesTable` + comentario "employee_id también se usa en Anticipos (beneficiario empleado)"). Ya existe el resolver canónico `App\View\Presentation\InvoiceBeneficiary::label($invoice)` que maneja Recibo de Caja con titular empleado/manual y el fallback `provider→employee→'—'`. La tabla de Anticipos **ya lo usa**; el element compartido `grouped_invoices_table` **no** (usa `provider->name` crudo vía el DTO). |
| 2 | `document_type` ya está en el entity y ya se calcula dentro de `InvoicePresentation::forGroupedRow()` como variable local `$documentType`; solo no se expone en el DTO ni se pinta. |
| 3 | En `PettyCashRecords/edit.php` y `Refunds/edit.php` la tabla se renderiza **dentro** del `<form class="spi-edit-shell-form">`. El handler global de `.clickable-row` (`webroot/js/spi-common.js`) hace `if (e.target.closest('a, button, form')) return;` → como la fila está dentro de un `<form>`, **siempre** retorna antes de navegar. El `cursor:pointer` se aplica en la misma iteración del loop, de ahí el síntoma exacto. En `view.php` (sin `<form>` envolvente) el click sí funciona. **La tabla de Anticipos NO sufre el bug** porque no está dentro de un form (sus `Form->create` viven después del element). |

## Decisiones tomadas

- **Posición de la columna Tipo:** después de `# Factura`.
- **Presentación de Tipo:** texto plano (coherente con las demás columnas de texto de la tabla; sin badge).
- **Alcance:** element compartido (Caja Menor + Reintegros) **y** tabla de Anticipos. En Anticipos, Beneficiario y click ya están resueltos → solo se agrega la columna Tipo.
- **Fix del click (enfoque A):** navegación scoped en `spi-grouped-invoices.js`, sin el guard de `form`. Enfoques descartados: (B) relajar el guard global de `.clickable-row` — blast radius amplio sobre los `index` con `postLink`; (C) mover la tabla fuera del `<form>` — cirugía estructural del shell sticky.

## Cambios

### DTO + Presentation

- `src/View/Presentation/GroupedInvoiceRowView.php`
  - Renombrar `providerName` → `beneficiaryName`.
  - Agregar `public string $documentType` inmediatamente después de `beneficiaryName` en el constructor (todos los llamadores usan argumentos nombrados, pero se fija la posición para el plan).
- `src/View/Presentation/InvoicePresentation.php::forGroupedRow()`
  - `beneficiaryName: InvoiceBeneficiary::label($invoice)`.
  - `documentType: (string)($invoice->document_type ?? '')`.
  - Actualizar el docblock del método: hoy dice "Requiere que el caller haya contenido Providers e InvoiceDocuments"; agregar **Employees** al contrato (sin él, `InvoiceBeneficiary` cae a `'—'` en silencio para beneficiarios-empleado).

Único consumidor real del campo `providerName` del **DTO** es `grouped_invoices_table.php` + un test. Las demás apariciones de `providerName` en el repo pertenecen a otras clases (`InvoiceViewViewModel`, `NotificationService`, plantillas de email) y quedan **fuera de alcance**.

### Templates

- `templates/element/grouped_invoices_table.php`
  - Header `Proveedor` → `Beneficiario`.
  - Nueva `<th>Tipo</th>` + celda después de `# Factura`, mostrando `$row->documentType` como texto plano con fallback `?: '—'` para hijas sin `document_type`.
  - Celda de beneficiario usa `$row->beneficiaryName`.
  - Recalcular `colspan` del `<tfoot>`: la etiqueta "Total:" pasa de `colspan="2"` a `colspan="3"` (ahora hay 3 columnas antes de Monto: #Factura, Tipo, Beneficiario); el trailing `colspan` (4 / 5 según `editable`) se mantiene. Total de columnas: 8 (no editable) / 9 (editable).
- `templates/element/advance_legalization/_linked_invoices.php`
  - Agregar la columna Tipo después de `# Factura`:
    - `$liGrid`: 8 → 9 tracks (insertar un track tras `# Factura`).
    - Header: nuevo `<span>Tipo</span>`.
    - Fila: nuevo `<span>` con `$rowView->documentType` (fallback `?: '—'`); `$rowView` ya se calcula vía `forGroupedRow`.
    - **Fila de total (cuidado con el off-by-one):** hoy es `label(grid-column:1/4 = 3 cols) + Monto(auto,1) + 4 spans vacíos = 8 tracks`. Con Tipo el grid pasa a **9 tracks**. Ensanchar **solo** el label a `grid-column:1 / 5` (abarca #Factura, Tipo, Beneficiario, Fecha) y **mantener los 4 spans vacíos existentes** → `4 + 1 + 4 = 9`. **NO** agregar un 5º span vacío (produciría 10 items en 9 columnas → celda fantasma en fila implícita, rompe el criterio #4).
  - Consolidar beneficiario: cambiar la celda (línea 88) de `InvoiceBeneficiary::label($li)` inline a `$rowView->beneficiaryName` — mismo resultado (mismo resolver vía el DTO), elimina cómputo redundante y consolida la derivación en el DTO (regla anti-drift).

### Controllers (contain de `Employees` en las hijas)

- `src/Controller/PettyCashRecordsController.php` — acciones `view` y `edit`: agregar `'Employees'` al contain de `'Invoices'` (hoy `['Providers', ...]`).
- `src/Controller/RefundsController.php` — acciones `view` y `edit`: idem.
- `src/Controller/AdvancesController.php` — **sin cambios** (ya contiene `Employees` en las hijas; verificado línea ~384).

Nota: `equivalent_holder_type` y `manual_document_number` son columnas propias de `invoices` (se cargan por defecto con el entity), no requieren contain adicional.

### JS

- `webroot/js/spi-grouped-invoices.js::init()`
  - Handler delegado `root.addEventListener('click', ...)` que:
    - retorna si el target está dentro de `a, button, select, input, textarea, label`;
    - busca la fila (`[data-href]`) contenida en el root y navega a su `data-href`.
  - Las celdas DIAN / Soporte / desvincular ya hacen `event.stopPropagation()`, por lo que quedan excluidas naturalmente. Coexiste sin daño con el handler global de `.clickable-row` en páginas sin form (Anticipos, view.php): ambos apuntan al mismo `href`.

### Tests

- `tests/TestCase/View/GroupedInvoicesTableElementTest.php` — actualizar el constructor del DTO (`providerName` → `beneficiaryName`, agregar `documentType`); aserción de que la columna Tipo se renderiza.
- `tests/TestCase/View/Presentation/InvoicePresentationGroupedRowTest.php` — actualizar aserciones por el rename + `documentType`; agregar caso de **beneficiario empleado** (provider nulo, employee presente → `full_name`).
- Reusar `tests/TestCase/View/Presentation/InvoiceBeneficiaryTest.php` (ya cubre la resolución del label).

## Modelo de datos

**Sin cambios de esquema ni migración.** Todos los campos usados (`provider_id`, `employee_id`, `document_type`, `equivalent_holder_type`, `manual_document_number`) ya existen. El único cambio de acceso a datos es agregar `Employees` al eager-load (`contain`) de las hijas en los controllers de Caja Menor y Reintegros.

## RBAC / permisos

El fix del click navega a `/invoices/view/{id}`, que pasa por `AppController::_enforcePermission()` sobre el módulo `invoices`. Un rol que opere la etapa (vía `pipeline_permissions`) pero **sin** `invoices.can_view` será rebotado al hacer click.

**Es una dependencia preexistente y aceptable, no una regresión:** la misma fila en `view.php` ya navegaba a la misma URL con la misma dependencia; el fix solo restaura ese comportamiento en `edit.php`. No se agrega degradación condicional del click por permiso (mantener paridad con `view.php` y con la tabla de Anticipos, que ya se comportan así).

## Criterios de éxito

1. En Caja Menor y Reintegros (edit y view) la columna se titula **"Beneficiario"** y muestra el nombre del empleado cuando la hija no tiene proveedor (antes `—`).
2. Existe una columna **"Tipo"** después de `# Factura` en los 3 contextos (Caja Menor, Reintegros, Anticipos) con el `document_type` de cada hija.
3. Hacer click en una fila (fuera de las celdas DIAN/Soporte/acciones) **navega** a `/invoices/view/{id}`, incluido dentro de `edit.php`.
4. `<tfoot>` de total y grid de Anticipos quedan alineados con la nueva columna (sin celda fantasma).
5. **Smoke-test manual** de que, con el nuevo listener en `root`, las celdas interactivas siguen intactas: el botón `.grouped-upload-btn` abre el modal de subida y el `<select>` DIAN inline no dispara navegación de fila (no hay cobertura JS automatizada de estos flujos).
6. Suite verde (`vendor/bin/phpunit`) y `composer cs-check` limpio.

## Fuera de alcance

- `templates/element/link_invoices_modal.php` (modal de selección, no la tarjeta de agrupadas).
- `InvoiceViewViewModel::providerName` y plantillas de email (`providerName` en otras clases).
- Convertir Tipo en badge/pill (posible mejora futura).
