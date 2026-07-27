# Soportes agrupados en la aprobación de grupo (Reintegros y Legalización de Anticipos)

**Fecha:** 2026-07-08
**Estado:** Diseño (pendiente de plan de implementación)
**Módulos:** Reintegros (`refunds`) · Legalización de Anticipos (`legalizations` / `advance_legalizations`)
**Superficie:** Aprobación externa (`ExternalApprovalsController`, layout `external.php`)

---

## 1. Problema

Las pantallas de aprobación **de grupo** (reintegro y legalización de anticipo) muestran solo una **tabla de las facturas** del lote (número, proveedor/beneficiario, monto), pero **no muestran los soportes** (documentos) de esas facturas. El aprobador de área decide sin poder revisar los adjuntos.

Estado actual verificado en código:

- `ExternalApprovalsController::review()` ramifica por tipo de token:
  - **Reintegro** (`:66-83`): carga `$refund` con `contain: ['Invoices' => ['Providers'], 'BeneficiaryEmployees', 'BeneficiaryProviders']` → **sin `InvoiceDocuments`**. Renderiza `review_group`.
  - **Anticipo** (`:85-109`): carga `$anticipo` con `contain: ['Providers', 'Employees']` y `$linkedInvoices` con `contain(['Providers', 'Employees'])` → **sin `InvoiceDocuments`**. Renderiza `review_group_advance`.
- Plantillas `templates/ExternalApprovals/review_group.php` y `review_group_advance.php`: tabla de facturas + formulario Aprobar/Rechazar. **Cero sección de soportes.**
- En contraste, la aprobación **single-entity de factura** (`review.php:113-161`) sí renderiza una grilla bespoke de "Soportes" iterando `$entity->invoice_documents`, pero solo para una factura.

## 2. Objetivo

Mostrar los **soportes de cada factura del grupo, agrupados por factura**, en ambas pantallas de aprobación de grupo, para que el aprobador revise los adjuntos antes de decidir. Sin cambiar el gate de autorización, el flujo de decisión (todo-o-nada) ni el `process()`.

## 3. Decisiones tomadas (brainstorming)

| # | Decisión | Elección |
|---|----------|----------|
| 1 | Criterio de agrupación / alcance documental | **Por factura**: un grupo por factura del lote, listando sus `invoice_documents`. Reintegro: facturas hijas. Anticipo: **anticipo padre + facturas vinculadas**. |
| 2 | Facturas sin soportes | **Mostrar todos los grupos**: se renderiza un encabezado por cada factura del lote aunque tenga 0 archivos. |
| 3 | Enfoque de construcción | **Controlador amplía el `contain`; la plantilla arma los grupos y reusa el element `documents_section`** (mismo estilo que `review.php`). Sin ViewModels nuevos. |
| 4 | Badge de estado por documento | **No** (`showBadge => false`); el aprobador solo necesita abrir el soporte. |

**Fuera de alcance (YAGNI):** `refund_documents` propios del reintegro; documento de relación/firmas de la legalización (`advance_legalization/_soportes`); badges de estado por documento; subida de archivos desde la pantalla de aprobación.

## 4. Diseño

### 4.1 Controlador — `ExternalApprovalsController::review()`

Solo se amplía el `contain` de los dos caminos de grupo. **No** se toca `process()` ni el gate `#[NoAuthGate]` + match de identidad del aprobador asignado.

- **Reintegro** (`:76-79`): `['Invoices' => ['Providers'], …]` → `['Invoices' => ['Providers', 'InvoiceDocuments'], …]`.
- **Anticipo** (`:98-105`): añadir `'InvoiceDocuments'` al `contain` de `$anticipo` **y** al de `$linkedInvoices`.

Asociación confirmada: `InvoicesTable` → `hasMany('InvoiceDocuments')` (propiedad `invoice_documents`, FK `invoice_id`).

**Orden determinista:** hoy ni el contain del reintegro ni el find de `$linkedInvoices` fijan `order()`, por lo que el orden es dependiente de BD (no es regresión — la tabla actual tampoco ordena). Añadir un orden explícito (p. ej. `Invoices.id ASC`) a la consulta de facturas del grupo, de modo que **tabla y grupos de soportes compartan el mismo orden** (ambos iteran la misma colección).

**Modelo de datos:** N/A — sin migración ni cambios de esquema; solo lectura adicional de `invoice_documents` en el `contain`.

### 4.2 Plantillas — sección de soportes agrupada

**Ubicación (card hermana, no anidada):** `documents_section` **ya emite su propio** `<div class="spi-card …">` (`documents_section.php:33`). Anidarlo dentro del `.spi-card` que hoy envuelve toda la pantalla de grupo produciría card-dentro-de-card (doble borde), rompiendo el canon visual. Por tanto se **reestructura** cada plantilla en cards hermanas de nivel superior (patrón canónico de `templates/Refunds/view.php:163`):

1. **Card 1** (`.spi-card`): título + campos de la entidad + tabla de facturas (el wrapper actual, cerrado tras la tabla).
2. **Card 2** = `element('documents_section', …)` (su propia `.spi-card`): Soportes.
3. **Card 3** (`.spi-card`): el `Form` de observaciones + botones Aprobar/Rechazar (hoy embebido en el wrapper único; se mueve a su propia card).

Así los soportes quedan entre la tabla y los botones de decisión, sin anidamiento.

Cada plantilla arma el array `$groups` (contrato de `element('documents_section')`: lista de `['label' => ?string, 'pillKind' => ?string, 'rows' => array]`, donde cada `row` son los params de `element('document_row')`) e invoca:

```php
<?= $this->element('documents_section', [
    'groups'     => $groups,
    'totalDocs'  => $totalDocs,
    'canUpload'  => false,
    'emptyTitle' => 'Sin soportes adjuntos',
]) ?>
```

**`review_group.php` (reintegros)** — un grupo por cada `$refund->invoices` en orden:

```
label    => $inv->invoice_number ?? '#'.$inv->id
pillKind => 'pill-info-soft'
rows     => [ ['doc' => $doc, 'showBadge' => false] por cada $inv->invoice_documents ]
```

**`review_group_advance.php` (anticipos)** — primer grupo el **anticipo padre**, luego un grupo por cada `$linkedInvoicesList`:

```
[anticipo] label => $anticipo->invoice_number ?? '#'.$anticipo->id
           pillKind => 'pill-primary-soft'   (distingue el padre)
           rows  => $anticipo->invoice_documents
[hijas]    label => $inv->invoice_number ?? '#'.$inv->id
           pillKind => 'pill-info-soft'
           rows  => $inv->invoice_documents
```

`$totalDocs` = suma de documentos de todos los grupos.

### 4.3 Comportamiento de vacíos

`documents_section` cuenta un grupo como "con docs" solo si tiene `rows`; un grupo con `rows` vacío **igual renderiza su encabezado** con "0 archivos" (contrato ya existente del element). Cuando el **lote entero** no tiene soportes (`totalDocs === 0`), el element muestra además su empty state "Sin soportes adjuntos" por encima de los encabezados con "0 archivos". Se acepta esa doble señal (informativa, no bloqueante) **para no modificar el element compartido** (cambio quirúrgico).

### 4.4 Visual / sistema de diseño

- Se reusa el element canónico `documents_section` + `document_row` (mismo componente que las vistas internas de Reintegro/Anticipo/CajaMenor). No se introduce markup nuevo de documentos ni literales de badge inline (anti-drift).
- El helper `DocumentIcon` y `Number` están disponibles en el layout `external.php` (ya los usa `review.php`).
- `pillKind` de los encabezados usa átomos existentes (`pill-info-soft`, `pill-primary-soft`).

### 4.5 Seguridad / RBAC

Sin cambios de autorización. El aprobador ya está autenticado y validado como el aprobador asignado del grupo (match de identidad en `review()`). Los soportes se abren con enlaces `/<file_path>` (`target="_blank"`), idéntico a `review.php`. El único cambio de datos es agregar la lectura de `invoice_documents` al `contain` de facturas del grupo — información que el aprobador ya está autorizado a revisar.

## 5. Criterios de aceptación

- La pantalla de aprobación de **reintegro** muestra, bajo la tabla de facturas, una sección "Soportes" (card hermana) con un grupo por cada factura hija y sus documentos; cada documento tiene enlace de apertura (`document_row` lo renderiza como ícono `bi-eye` con `href="/<file_path>"` y `title="Abrir"`, no como botón con texto).
- La pantalla de aprobación de **legalización de anticipo** muestra un grupo para el **anticipo padre** seguido de un grupo por cada factura vinculada, con sus documentos.
- Una factura (o el anticipo) **sin soportes** aparece con su encabezado y "0 archivos".
- Si el lote entero no tiene soportes, se muestra el empty state "Sin soportes adjuntos".
- El gate de autorización, el flujo Aprobar/Rechazar y `process()` **no** cambian.
- `composer cs-check` limpio; suite `vendor/bin/phpunit` sin regresiones (baseline 843).

## 6. Testing

- **Integración (controlador):** extender `AdvancesGroupApprovalTest` y `RefundsControllerGroupSupersessionTest` (o los flujos `RefundGroupApprovalFlowTest` / `AdvanceGroupApprovalFlowTest`):
  - `review` con token de grupo de reintegro carga `invoice_documents` de las facturas hijas y la respuesta contiene el `href="/<file_path>"` de cada soporte + el encabezado por factura (assert sobre el `href`, no sobre el texto "Abrir").
  - `review` con token de grupo de anticipo carga `invoice_documents` del anticipo padre y de las facturas vinculadas, y ambos aparecen como grupos.
  - Factura sin soportes → su encabezado aparece con "0 archivos".
- Suite `vendor/bin/phpunit` (baseline 843); credenciales de test en `config/.env`.

## 7. Fuera de alcance

- Aprobación single-entity de factura (`review.php`) — ya muestra soportes; no se toca.
- `refund_documents`, documento de relación/firmas de la legalización, badges de estado por documento, subida de archivos.
- Cualquier cambio en `process()`, tokens, quórum o RBAC.
