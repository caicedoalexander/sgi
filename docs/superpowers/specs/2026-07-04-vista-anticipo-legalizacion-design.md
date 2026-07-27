# Hub de consulta del Anticipo con detalle de legalización (Fase 2)

**Fecha:** 2026-07-04
**Estado:** Diseño (pendiente de revisión del usuario → plan de implementación)
**Módulo:** Anticipos (`advances` / entidad `Invoice` tipo `Anticipo`)
**Naturaleza:** Capa de vista (ViewModel ↔ template). **Sin** cambios de pipeline, migraciones ni RBAC.

---

## 1. Problema

Cuando un anticipo entra a **Fase 2 (Legalización)** — es decir, ya existe una fila en `advance_legalizations` — es **imposible consultar el detalle del anticipo**. `AdvancesController::view()` redirige incondicionalmente a la vista operativa de legalización.

Estado actual verificado en código:

- **Redirección forzada** — `AdvancesController::view()` (`src/Controller/AdvancesController.php:296-298`):
  ```php
  // Cuando ya hay legalización iniciada, redirigir a la vista dedicada.
  if ($invoice->advance_legalization) {
      return $this->redirect(['action' => 'legalization', $invoice->id]);
  }
  ```
  Apenas el anticipo llega a `pagada` y el `LegalizationInitializerSubscriber` crea la legalización, entrar a "Ver Anticipo" expulsa a `legalization()`.

- **La vista de legalización es operativa, no de consulta** — `templates/Advances/legalization.php` renderiza el pipeline de la *legalización*, facturas vinculadas, **acciones de paso** (formularios para avanzar/devolver/registrar) y soportes *de la legalización*. El usuario la percibe como "editar" porque está compuesta de formularios y acciones. Es la que el usuario ve forzosamente.

- **Dos brechas de datos:**
  1. `legalization()` **no carga ni muestra** los soportes originales del anticipo (`InvoiceDocuments`) ni el **desembolso** (`InvoicePayments`). Solo expone el beneficiario en el sidebar.
  2. La propia `templates/Advances/view.php` **hoy tampoco muestra soportes ni desembolso** — solo beneficiario + detalle + un banner (`view.php:80-132`). Así que "ver el anticipo con sus soportes" no existe hoy en **ningún** estado.

- **Señal de intención original:** el breadcrumb de `legalization.php:74` enlaza a `['action' => 'view', $invoice->id]`, que hoy redirige de vuelta a legalización → **enlace circular muerto**. Confirma que la vista de detalle debía ser accesible.

## 2. Objetivo

Convertir `AdvancesController::view()` en el **hub de consulta (solo-lectura)** del anticipo, accesible en cualquier estado, que muestre:

- Datos del anticipo (ya existe).
- **Soportes documentales** del anticipo (`InvoiceDocuments`).
- **Desembolso** al beneficiario (`InvoicePayments`).
- Cuando existe legalización, un **bloque de legalización read-only**: estado + totales, facturas vinculadas y soportes de la legalización, con un botón **"Gestionar legalización →"** hacia la vista operativa.

La vista operativa (`legalization()`) se mantiene **intacta** para ejecutar las acciones del pipeline.

## 3. Decisiones tomadas (brainstorming)

| # | Decisión | Elección |
|---|----------|----------|
| 1 | Estructura de la solución | **Hub de consulta + vista operativa**: dos vistas navegables con propósitos separados. `view` = consulta; `legalization` = operación. |
| 2 | Detalle de legalización en el hub | **Resumen rico read-only**: estado + totales + **facturas vinculadas** + **soportes de la legalización**, todo solo-lectura. |
| 3 | Desembolso | **Sí, mostrar** el/los pago(s) al beneficiario (monto, fecha, banco, estado) para trazabilidad del dinero. El soporte del pago aparece en la card de Soportes del anticipo (`InvoiceDocuments`), no inline en el desembolso. |

## 4. Diseño

### 4.1 Comportamiento (controller)

- **Eliminar** el guard de redirección de `AdvancesController::view()` (`src/Controller/AdvancesController.php:296-298`). `view()` deja de redirigir; renderiza el hub en todo estado.
- `view()` **amplía la carga de datos** cuando hay legalización, replicando los `contain`/consultas crudas que hoy hace `legalization()` (`AdvancesController.php:312-352`):
  - `AdvanceLegalization` → `AdvanceLegalizationSignatures` → `SignedByUsers` (soportes de legalización).
  - `linkedInvoices`: facturas `ADVANCE_LINKABLE_DOCTYPES` con `advance_id = $invoice->id` (+ `Providers`, `Employees`).
  - Los `InvoiceDocuments` e `InvoicePayments` del anticipo **ya** los contiene `view()` (`AdvancesController.php:284-286`).
  - **No** se carga `surplusPayment` (el pago del reintegro en caso sobrante): el hub no lo renderiza (ver §4.2); su detalle se consulta en "Gestionar legalización".
- `legalization()` **no se toca** (su template sí se refactoriza — §4.3).
- **Navegación bidireccional:** el hub añade botón "Gestionar legalización →" hacia `legalization()`; el breadcrumb de `legalization.php:74` (circular muerto) vuelve a resolver correctamente hacia el hub.

### 4.2 Layout del hub (`templates/Advances/view.php`)

Mantiene el **canon visual de VIEW** (`.spi-invoice-view-grid`, grid `340px 1fr`): `aside.spi-invoice-view-left` con `element('pipeline_sidebar')` del **anticipo** + `main.spi-invoice-view-right` con `spi-card`s en este orden:

1. **Datos del anticipo** — beneficiario, centro operación, tipo gasto, centro costos, fechas, concepto. *(ya existe, `view.php:80-125`)*.
2. **Desembolso al beneficiario** — `InvoicePayments` del anticipo vía `element('payment_section')` en `mode => 'view'` (mismo element usado en `legalization.php:430-442`). Muestra banco / fecha / registrado-por / estado / monto por pago. El element exige `addPaymentUrl` aunque no dibuje botón de alta (`payment_section.php:77` lo construye incondicionalmente): el plan pasa una URL inocua con `canRegisterPayment=false`, `canAuthorize=false`, `canDelete=false`. Solo se renderiza si hay pagos. *(El soporte del pago no va inline aquí; vive en la card 3 como `InvoiceDocument`.)*
3. **Soportes del anticipo** — `InvoiceDocuments` vía el element canónico `element('documents_section')` con `canUpload=false` (empty-state, no dropzone), que renderiza filas `element('document_row')` con `canDelete=false` y `showBadge=true`. Los badges se pasan como `badgeColors = InvoicePresentation::STATUS_BADGES` y `statusLabels = InvoiceConstants::STATUS_LABELS` (**anti-drift**: mapa desde Presentation, no inline). **No** se clona el bloque bespoke de `Invoices/view.php`. Nota: el `document_row` canónico no muestra "subido por" (columna ausente por diseño del element compartido); aceptable para la consulta.
4. **Bloque de legalización** — *solo si `$invoice->advance_legalization`*. Tarjeta con:
   - **Cabecera-resumen:** estado (pill + mini-pipeline del caso `PIPELINE_STATUSES_BY_CASE`), monto anticipo / total vinculado / diferencia (con `diffBadgeClass`) / caso.
   - **Facturas vinculadas** (read-only) → `element('advance_legalization/_linked_invoices')` con `editable=false`.
   - **Soportes de la legalización** (read-only) → `element('advance_legalization/_soportes')` con `editable=false`.
   - Botón **"Gestionar legalización →"** hacia `['action' => 'legalization', $invoice->id]`.
- **Banner condicional:** si **no** hay legalización, se conserva el banner actual "La legalización iniciará automáticamente cuando este anticipo llegue al estado Pagada" (`view.php:127-132`). El template ramifica por `$invoice->advance_legalization`.

### 4.3 Arquitectura y reúso (anti-duplicación)

Para no duplicar markup entre el hub y la vista operativa, se **extraen 2 parciales** desde `legalization.php` y se reusan en ambas plantillas mediante un flag `$editable`:

| Nuevo element | Origen (markup a extraer) | Hub (`view`) | Operativa (`legalization`) |
|---|---|---|---|
| `templates/element/advance_legalization/_linked_invoices.php` | `legalization.php:170-252` | `editable = false` (oculta "Nueva"/"Vincular" y botón desvincular) | `editable = true` |
| `templates/element/advance_legalization/_soportes.php` | `legalization.php:464-631` | `editable = false` (oculta subir/reemplazar relación de facturas) | `editable = true` |

- El flag `editable` gobierna únicamente la visibilidad de los controles de mutación (botones/inputs/formularios de upload). El contenido informativo es idéntico.
- **`legalization.php` se refactoriza** para consumir esos 2 elements (con `editable=true`), preservando su comportamiento actual. Sin cambios funcionales en la vista operativa.
- El `payment_section` **ya** es un element compartido con `mode => 'view'`; no requiere extracción.

### 4.4 ViewModel (con helper compartido en `Support/`)

Sigue el patrón del proyecto (**VM deriva, no consulta** — CR-102; el controller carga los datos crudos y los inyecta).

- **Nuevo `src/ViewModel/Support/LegalizationSummary.php`** (`final readonly`) — fuente única de la **derivación de dominio** del resumen de legalización, hoy embebida en `AdvanceLegalizationViewModel::build()` (`:92-140`): `linkedTotal`, `advanceTotal`, `diff`, `diffBadgeClass` (umbral `0.005`), split `relationDocument` vs `signatureHistory`, `linkedCount`, `caseLabels`, badge de estado (derivado de `AdvancePresentation::STATUS_BADGES`), mini-pipeline por caso (`PIPELINE_STATUSES_BY_CASE`). Alinea con el canon `ViewModel/Support/` ("derivación cross-módulo del VM") y evita el drift de tener el umbral `0.005` / `caseLabels` en dos sitios.
- **`AdvanceViewViewModel`** (`src/ViewModel/AdvanceViewViewModel.php`) se amplía: recibe del controller `?AdvanceLegalization $leg` + `iterable $linkedInvoices`; cuando hay legalización, delega en `LegalizationSummary` y expone `hasLegalization` (bool) para que el template ramifique banner vs. bloque.
- **`AdvanceLegalizationViewModel`** se toca **mínimamente**: `build()` delega esa derivación en `LegalizationSummary` (misma salida, **sin cambio de comportamiento** de la vista operativa). No se altera su interfaz ni sus responsabilidades operativas (`approvals`, `canRegisterRefund`, etc.), ni se carga el VM operativo en la vista de consulta.
- **Anti-drift (regla dura):** el mapeo estado→pill/icono vive solo en `AdvancePresentation`/`InvoicePresentation`; el helper/VM exponen los badges ya derivados; el template **no** recomputa mapas.

### 4.5 RBAC y seguridad

- **Sin cambios.** `view()` conserva `#[Permission(action: 'view')]` (módulo `advances`).
- El hub es **solo-lectura**: no expone ninguna acción de mutación. `legalization()` conserva `#[Permission(action: 'view')]`; son los **endpoints POST** del pipeline (`linkInvoices`, `unlinkInvoice`, `moveToAprobacion`, `markSigned`, …) los que llevan `#[PipelineAction(...)]` y quedan gateados por `pipeline_permissions`. Consistente con la invariante "operar implica ver": `can_view` gobierna el hub; `pipeline_permissions` gobierna la operación.
- Los links "ver/descargar" de soportes apuntan a `/'.$doc->file_path` con `target=_blank rel=noopener`, igual que el resto del sistema.

## 5. Alcance y decisiones por defecto

- El hub muestra el bloque de legalización a **todo** rol con `advances.can_view`, aunque no pueda operar la legalización (es consulta).
- El botón "Volver" del hub sigue hacia `index` (sin cambio). El de `legalization.php` puede opcionalmente apuntar a `view` en lugar de `index` — **decisión menor, se confirma en el plan** (default: dejarlo como está para no ampliar el diff).
- El botón "Editar" del hub (`view.php:39-45`) ya se oculta en estado terminal (`isTerminal = status === pagada`); en Fase 2 el anticipo está `pagada` → se mantiene oculto. Sin conflicto.

## 6. Criterios de aceptación

- Entrar a "Ver Anticipo" de un anticipo en Fase 2 **ya no redirige**: muestra el hub de consulta.
- El hub muestra, cuando existen: datos del anticipo, **desembolso** (pagos), **soportes** del anticipo, y el **bloque de legalización** (estado + totales + vinculadas + soportes de legalización) con botón "Gestionar legalización →".
- Un anticipo **sin** legalización (aún no pagado) muestra el banner "iniciará al llegar a Pagada" y **no** el bloque de legalización.
- Desde el hub se llega a la vista operativa y viceversa (breadcrumb ya no circular).
- La vista operativa `legalization()` conserva su comportamiento actual (acciones de vincular/desvincular, subir/reemplazar relación, avanzar/devolver) — verificado con los 2 elements extraídos en modo `editable=true`.
- El bloque de legalización en el hub es **estrictamente read-only** (sin botones de vincular/desvincular/subir).
- `composer cs-check` limpio; suite `vendor/bin/phpunit` sin regresiones.

## 7. Testing

- **Controller (integración):**
  - `view()` de un anticipo en Fase 2 responde 200 y **no** redirige; el body incluye el bloque de legalización.
  - `view()` de un anticipo sin legalización responde 200 con el banner y sin bloque de legalización.
  - `view()` de una factura que no es Anticipo redirige a `index` (guard existente `AdvancesController.php:289-293`, preservado).
- **ViewModel (unit):** `AdvanceViewViewModel` deriva `linkedTotal`/`diff`/`diffBadgeClass`/`relationDocument` vs `signatureHistory`/`hasLegalization` correctamente, con y sin legalización (AAA).
- **Regresión visual/manual:** la vista operativa `legalization.php` renderiza idéntica tras extraer los 2 parciales; en cada estado del pipeline los controles de mutación siguen presentes (`editable=true`).
- Suite `vendor/bin/phpunit` (baseline vigente); credenciales de test en `config/.env`.

## 8. Fuera de alcance

- Cambios en el pipeline de legalización, sus estados o transiciones.
- Migraciones, cambios de RBAC o `pipeline_permissions`.
- Cambiar el **comportamiento o la interfaz** de `AdvanceLegalizationViewModel` o la lógica de `legalization()`. (Sí se hace una refactorización interna sin cambio de comportamiento: `build()` delega en `LegalizationSummary`, y el template de `legalization.php` pasa a consumir los 2 parciales `editable=true`.)
- Otros módulos de flujo (Facturas, Reintegros, Caja Menor, Novedades, Programación de pagos).
- Añadir soportes/desembolso a la vista de otros tipos de documento.
