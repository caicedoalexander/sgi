# Migración de módulos de flujo al diseño de Facturas — PROGRESO

> Documento de continuidad. Última actualización: **2026-05-20**. HEAD: `436fb3f` (rama `main`, todo commiteado, nada pusheado).

## Qué es esto

Migración de los módulos con pipeline para que adopten los elementos compartidos y
la estructura de Facturas. Trabajo previo (ya terminado): la **ronda de
consolidación de elementos** (drawer de observaciones, `documents_section`,
`pipeline_sidebar` reescrito) — commits `e89da4c`..`518c891`.

- **Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md`
- **Spec de la consolidación previa:** `docs/superpowers/specs/2026-05-20-consolidacion-elementos-compartidos-design.md`

## Estado: 4 de 7 completados

| Módulo | Estado | Plan |
|---|---|---|
| Refunds | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-refunds.md` |
| PettyCashRecords | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-petty-cash.md` |
| PaymentSchedulings | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-payment-schedulings.md` |
| EmployeeNovelties | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-employee-novelties.md` |
| **Advances** | ⏳ PENDIENTE (siguiente) | — falta escribir el plan |
| **NoveltyLiquidationDocs** | ⏳ pendiente | — falta escribir el plan |
| **Employees/view** | ⏳ pendiente (solo observaciones) | — falta escribir el plan |

## Cómo reanudar mañana

El flujo por módulo (definido en el spec, sección "Flujo de trabajo"):

1. Invocar la skill **`superpowers:writing-plans`** y escribir el plan del módulo
   en `docs/superpowers/plans/2026-05-20-migracion-<modulo>.md`.
2. Ejecutar con **`superpowers:subagent-driven-development`**: por cada tarea, un
   subagente implementador (modelo `sonnet`) + un subagente revisor de spec. La
   revisión de calidad de código corre **una sola vez al final** del plan (no por
   tarea — preferencia del usuario).
3. Commits directos en `main`. Validación funcional manual la hace el usuario
   entre módulos.

Empezar por **Advances** (es el más pesado).

## Desglose de los 3 módulos pendientes (del spec)

### Advances — el más pesado
- `index` → dialecto `.sgi-row-fact` (clases sin CSS) → reescribir a la estructura
  de `Invoices/index.php`; añadir search bar.
- `view` → ligero; observaciones → drawer si las tuviera.
- `legalization.php` → **el panel con más markup legacy**: `border:1px solid` inline
  (viola "sin bordes"), tablas Bootstrap crudas, modales con markup inline, ~150
  líneas de estilos inline en la sección de soportes → `documents_section`;
  observaciones → drawer; divisores manuales → `.sgi-label`+`.hr`; botones Bootstrap
  crudos → botones del sistema.

### NoveltyLiquidationDocs
- `index` → reescritura completa: `<table>` Bootstrap dentro de `.card-primary` →
  estructura de `Invoices/index`.
- `view` → varias `<table>` crudas → filas del sistema; bloques de soporte con
  estilos inline → `documents_section`/`document_row`; cards de firma ad-hoc →
  patrón del sistema; observaciones → drawer.
- `edit` → cards de firma ad-hoc; `.form-label`→`.input-label`; bloque "D.
  Liquidación" con estilos inline → tokens/clases; observaciones → drawer.

### Employees/view
- Solo: el chat de observaciones ad-hoc (`observation_bubble`) → `observations/drawer`.

## Cierre de la migración (tras Employees/view)

Cuando ningún consumidor use ya el chat viejo:
- Eliminar `templates/element/observation_bubble.php`,
  `observation_bubble_template.php`, `observation_chat_init.php`.
- Verificar que no quedan referencias a `.sgi-row-fact*` / `.sgi-status-tab*` ni a
  `observation_bubble*` en `templates/`.

## Patrones establecidos (repetidos en cada módulo)

- **Listado (`index`)**: reescribir al dialecto de `Invoices/index.php` — grid CSS
  inline en `.sgi-card`, chips `.chip`/`.dot`, search `<label class="input">`,
  filtros en `.collapse` + `.sgi-card compact`, `.pipeline-mini`, pills `pill-sm`,
  `.empty-state`. Los planes de Refunds y PaymentSchedulings tienen el código
  completo como referencia.
- **Observaciones**: reemplazar el chat viejo (`observation_bubble` +
  `observation_chat_init`, o markup ad-hoc) por
  `element('observations/drawer', ['observations'=>…, 'count'=>…, 'formUrl'=>['action'=>'addObservation', $record->id], 'currentUserName'=>$currentUser->full_name ?? ($currentUser->username ?? 'Usuario')])`.
  El drawer va **fuera de cualquier `<form>`**. Si la vista tenía `sgi-edit-side-grid`
  con Soportes+Observaciones, se quita el grid y Soportes queda a ancho completo.
- **Soportes**: `element('documents_section', ['groups'=>$docGroups, 'totalDocs'=>…, 'canUpload'=>…, 'uploadModalId'=>…, 'emptyTitle'=>…])`; `$docGroups` se arma en el
  host (ver el patrón en `Invoices/edit.php` y `EmployeeNovelties/view.php`).
- **Divisores manuales** (`text-uppercase fw-semibold` + barra 1px) → `.sgi-label`
  + `<div class="hr">`.
- `$currentUser` está disponible globalmente en todas las vistas.

## Gotchas del entorno

- **Sin tests automatizados** — no escribir tests; validación = `php -l` + revisión
  manual del usuario.
- **`composer cs-fix` / `cs-check` NO corren** en este entorno (faltan extensiones
  PHP `gd`/`zip` en `vendor/composer/platform_check.php`). No intentarlo.
- Mensajes de commit terminan con
  `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## Decisiones de alcance ya tomadas

- **`add.php` fuera de alcance** — el patrón `card-primary` de los formularios de
  creación es transversal a 23 archivos (incl. `Invoices/add`); no es divergencia
  flujo-vs-Facturas.
- **Cabeceras de `view`/`edit`** (`sgi-page-title`, `sgi-page-header`,
  `sgi-edit-id-chip`, `btn-ghost-card` — clases sin CSS) son preexistentes y
  transversales (~85 archivos). **Fuera del alcance** de esta migración; pendiente
  de decisión del usuario si se aborda como esfuerzo aparte.
- **EmployeeNovelties — diferidos**: la tabla "Historial de Cambios" de `view.php`
  se dejó como `<table>`; la card Bootstrap de `edit.php` solo se limpió el
  `!important` (sin reestructurar). Documentado en el self-review de su plan.

## Pendiente de respuesta del usuario

El usuario no respondió aún si las **cabeceras de view/edit** (clases sin CSS,
~85 archivos) entran en algún momento al alcance. Retomar esa pregunta si procede.
