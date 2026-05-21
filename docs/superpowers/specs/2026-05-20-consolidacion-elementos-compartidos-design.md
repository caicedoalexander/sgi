# Consolidación de elementos compartidos al diseño de Facturas

**Fecha:** 2026-05-20
**Estado:** Diseño aprobado

## Contexto

El módulo de Facturas (`Invoices`) ya fue rediseñado al Sistema de Diseño v2.
Una auditoría de los demás módulos con flujo (Advances, EmployeeNovelties,
PettyCashRecords, Refunds, PaymentSchedulings, NoveltyLiquidationDocs) detectó
que varias vistas no comparten la estructura HTML, clases ni estilos de Facturas.

El análisis de `templates/element/` encontró **dos divergencias reales** entre
elementos compartidos, más una sección que cada vista inlinea por separado:

1. **Chat de observaciones** — existen dos implementaciones paralelas:
   - Vieja (6+ módulos): `observation_bubble.php` + `observation_bubble_template.php`
     + `observation_chat_init.php` — burbujas `.sgi-obs-bubble` renderizadas inline.
   - Nueva (solo `Invoices/edit`): `invoice_edit/observations_drawer.php` +
     `invoice_edit/observation_chat_item.php` + `invoice_edit/_chat_avatar.php` —
     drawer flotante (Bootstrap offcanvas) con el componente `.chat` del sistema.
2. **`pipeline_sidebar`** — es un element compartido (6 módulos), pero Facturas y
   Caja Menor inlinean su propia versión del hero+pipeline con clases distintas
   (`.pipeline-v` / `.pv-step` vs `.sgi-pipeline-v` / `.sgi-pipeline-v-step`).
3. **Sección de Soportes** — `document_row` ya es un element canónico v2, pero el
   *contenedor* de la sección (header + contador + dropzone/empty-state + lista)
   lo inlinea cada vista, con divergencias (bordes inline, markup ad-hoc).

`payment_section`, `email_log_panel` y `confirm_payment_card` ya son elementos
únicos y v2 — no se tocan.

## Objetivo

Dejar **un solo elemento por concern**, con la estructura y estilos de Facturas,
y refactorizar Facturas para que consuma esos elementos compartidos. Esto deja
una base estable y verificada para la fase siguiente (migración de los demás
módulos, con agentes uno por módulo).

## Decisiones de diseño

- **Observaciones:** todos los módulos adoptan el **drawer flotante** (no inline).
- **`pipeline_sidebar`:** se unifica a un solo element; Facturas y Caja Menor lo
  adoptan; las clases CSS se alinean a la versión de Facturas.
- **Secuencia:** construir los 3 elementos consolidados Y refactorizar Facturas
  para que los consuma (Facturas valida la fidelidad de los elementos). La
  migración del resto de módulos es una fase posterior, fuera de este spec.
- **`documents_section`:** el host prepara y pasa la estructura `$groups` ya
  armada; el element no recibe callbacks ni resuelve permisos por documento.

## Alcance

Incluye:

- Tres elementos compartidos nuevos / reescritos.
- Refactor de `Invoices/edit.php` e `Invoices/view.php` para consumirlos.
- Adopción del `pipeline_sidebar` unificado en `PettyCashRecords/view.php` — un
  cambio puntual, necesario porque esta vista es el otro consumidor que hoy
  inlinea su propio hero+pipeline; no se toca el resto del módulo Caja Menor.
- Limpieza de CSS muerto resultante.

Nota: reescribir el element `pipeline_sidebar` cambia automáticamente el render
de los 6 módulos que ya lo consumen. Es inherente a un element compartido y
esperado; el criterio es que la reescritura sea visualmente fiel.

NO incluye (fase siguiente, spec/plan aparte):

- Migración del resto de vistas de Advances, EmployeeNovelties, PettyCashRecords,
  Refunds, PaymentSchedulings, NoveltyLiquidationDocs y `Employees/view`.
- Retiro físico de los elementos viejos `observation_bubble*` (ver más abajo).

## Elemento 1 — Drawer de observaciones compartido

### Archivos

Generalizar desde `invoice_edit/` a un subdirectorio compartido:

- `templates/element/observations/drawer.php` — drawer flotante autocontenido.
- `templates/element/observations/chat_item.php` — un `.chat-item` server-side.
- `templates/element/observations/chat_avatar.php` — avatar `.chat-av` con
  iniciales + color por hash.

### Interfaz del drawer

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$observations` | iterable | Entidades de observación, en orden cronológico |
| `$count` | int | Número de observaciones |
| `$formUrl` | array\|string | URL del POST de `addObservation` del módulo |
| `$currentUserName` | string | Nombre del usuario actual (avatar del `<template>`) |
| `$emptyMessage` | ?string | Texto del empty state. Default: "Sin observaciones aún" |

### Comportamiento

- Autocontenido: emite su propio `<template>` y el `<script>` de inicialización.
- Contrato JS sin cambios: `webroot/js/sgi-observation-chat.js` y los IDs
  estándar `#obs-form`, `#obs-chat-scroll`, `#obs-empty-state`, `#obs-count`.
  El `bubbleTemplateSelector` pasa a un id genérico (`#sgi-obs-chat-item`).
- Duck-typing de la entidad observación: requiere `id`, `message`, `created`,
  `user->full_name` (o `user->username`). Compatible con `InvoiceObservation`,
  `NoveltyObservation` y equivalentes.
- Restricción de uso: el element se incluye **fuera** del `<form>` principal de
  la vista anfitriona (el drawer tiene su propio `<form>`); y los IDs `#obs-form`
  / `#obs-count` deben existir una sola vez en el DOM.

### Elementos viejos a retirar

`observation_bubble.php`, `observation_bubble_template.php` y
`observation_chat_init.php` quedan obsoletos. Su retiro físico ocurre en la fase
siguiente, **cuando el último consumidor haya migrado** — incluido `Employees/view`,
que no es módulo de flujo pero usa el chat viejo y debe migrarse para poder
eliminar el trío.

## Elemento 2 — `pipeline_sidebar` unificado

### Archivo

Reescribir `templates/element/pipeline_sidebar.php` con el markup y clases de
Facturas:

- Cards con `.sgi-card` (no `.card`).
- Hero: caja de icono, id en `.mono`, pills, `.sgi-label`, `.sgi-display`.
- Pipeline vertical: `.pipeline-v` > `.pv-step` (`.is-done` / `.is-current` /
  `.is-rejected` / `.is-pending`) > `.pv-marker` + `.pv-label` + `.pv-meta`.

### Interfaz

Se conserva la interfaz actual del element (`icon`, `idLabel`, `typeLabel`,
`statusPill`, `statusLabel`, `isRejected`, `extraPillHtml`, `entityLabel`,
`entityValue`, `entitySubLabel`, `entitySubIcon`, `amountLabel`, `amount`,
`amountExtraHtml`, `pipelineSteps`, `pipelineLabels`, `currentStatus`,
`isTerminal`, `modifiedAt`, `registryLines`, `actionsHtml`).

Se añade un slot:

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$heroExtraHtml` | ?string | HTML libre al pie del hero (field-rows de fechas en `view`, bloque Pagado/Saldo en `edit`). null para omitir. |

### Adopción

- `Invoices/view.php`, `Invoices/edit.php` y `PettyCashRecords/view.php` dejan su
  hero+pipeline inline y llaman al element.
- El element sigue siendo agnóstico a la grilla: renderiza las cards apiladas; la
  vista anfitriona aporta la columna contenedora.

### Limpieza CSS

Las clases `.sgi-pipeline-v*` y `.sgi-hero-*` quedan sin uso tras la unificación.
Se eliminan de `webroot/css/` (`styles.css` / `components.css`).

## Elemento 3 — Sección de Soportes (`documents_section`)

### Archivo

Nuevo `templates/element/documents_section.php`. `document_row.php` y
`document_row_template.php` **no cambian**.

### Estructura

Card `.sgi-card` con:

- Header: icono `bi-paperclip` + "Soportes" + `.sgi-folder-count` con el conteo +
  botón "Subir" opcional.
- Empty state: `.dropzone` (cuando hay subida habilitada) o `.empty-state`.
- `#docs-list`: por cada grupo, un encabezado opcional (pill de estado + conteo)
  seguido de las filas `document_row`.

### Interfaz

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$groups` | array | Lista de grupos ya preparados por el host (ver estructura) |
| `$totalDocs` | int | Conteo total para el `.sgi-folder-count` |
| `$canUpload` | bool | true → empty state como `.dropzone`; false → `.empty-state` |
| `$uploadModalId` | ?string | Id del modal de subida (target del botón y la dropzone) |
| `$emptyTitle` | string | Título del empty state. Default: "Sin soportes adjuntos" |

Estructura de cada grupo en `$groups`:

```php
[
    'label'    => ?string,  // null = grupo sin encabezado (sin agrupación por estado)
    'pillKind' => ?string,  // clase de pill del encabezado (e.g. 'pill-warning-soft')
    'rows'     => array,    // lista de arrays de parámetros para document_row
]
```

Cada entrada de `rows` es el array de parámetros que hoy se pasa a
`element('document_row', [...])` (`doc`, `canDelete`, `deleteUrl`, `showBadge`,
`badgeColors`, `statusLabels`). El host resuelve `canDelete` y `deleteUrl` por
documento y los deja listos en `$groups`; el element no recibe callbacks ni
lógica de permisos.

### Contrato JS

Se conservan los IDs `#docs-list`, `#docs-empty-state` y `#docs-folder-count`
(contrato de `webroot/js/sgi-document-uploader.js`).

## Refactor de Facturas

- `Invoices/edit.php`: reemplazar el drawer `invoice_edit/observations_drawer`
  por `observations/drawer`; el hero+pipeline+acciones inline por
  `pipeline_sidebar`; la card de Soportes inline por `documents_section`.
- `Invoices/view.php`: reemplazar el hero+pipeline inline por `pipeline_sidebar`
  y, si la vista tiene sección de soportes, por `documents_section`.
- Los elements de `invoice_edit/` específicos de Facturas (aprobadores, modales,
  `scripts.php`) no se tocan.

## Criterios de validación manual

Tras el refactor (no hay tests automatizados — política del proyecto):

1. `php bin/cake server` y abrir el módulo de Facturas.
2. `Invoices/index` — sin cambios (no afectado), se ve igual.
3. `Invoices/view` de una factura — hero, pipeline vertical, registro y sección
   de soportes se ven **idénticos** a antes del refactor.
4. `Invoices/edit` de una factura — hero, pipeline, acciones de etapa, sección de
   Soportes y drawer de Observaciones se ven idénticos; abrir el drawer, publicar
   una observación y verificar que aparece, que el contador del disparador y del
   header se actualizan, y que el empty state se oculta.
5. Subir y eliminar un soporte desde `edit` — el `document_row` se agrega/quita y
   el `.sgi-folder-count` se actualiza.
6. Repetir el punto 3 en `PettyCashRecords/view` (también adopta
   `pipeline_sidebar` en esta ronda).
7. Revisar la consola del navegador: sin errores JS.

## Fase siguiente (fuera de este spec)

Con los elementos estables y validados en Facturas, se planifica la migración de
los demás módulos con flujo (`Advances`, `EmployeeNovelties`, `PettyCashRecords`,
`Refunds`, `PaymentSchedulings`, `NoveltyLiquidationDocs`) y de `Employees/view`,
con agentes trabajando módulo por módulo. Ese trabajo incluye el retiro físico de
los elementos viejos `observation_bubble*` una vez sin consumidores.
