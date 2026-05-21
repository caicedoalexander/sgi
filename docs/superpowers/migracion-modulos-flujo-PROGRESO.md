# Migración de módulos de flujo al diseño de Facturas — PROGRESO

> Documento de continuidad. Última actualización: **2026-05-21**. HEAD: `83d69bc` (rama `main`, todo commiteado, nada pusheado).
>
> ✅ **MIGRACIÓN COMPLETA** — los 7 módulos migrados y el chat viejo retirado.

## Qué es esto

Migración de los módulos con pipeline para que adopten los elementos compartidos y
la estructura de Facturas. Trabajo previo (ya terminado): la **ronda de
consolidación de elementos** (drawer de observaciones, `documents_section`,
`pipeline_sidebar` reescrito) — commits `e89da4c`..`518c891`.

- **Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md`
- **Spec de la consolidación previa:** `docs/superpowers/specs/2026-05-20-consolidacion-elementos-compartidos-design.md`

## Estado: 7 de 7 completados ✅

| Módulo | Estado | Plan |
|---|---|---|
| Refunds | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-refunds.md` |
| PettyCashRecords | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-petty-cash.md` |
| PaymentSchedulings | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-payment-schedulings.md` |
| EmployeeNovelties | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-employee-novelties.md` |
| Advances | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-advances.md` |
| NoveltyLiquidationDocs | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-novelty-liquidation-docs.md` |
| Employees/view | ✅ migrado | `docs/superpowers/plans/2026-05-20-migracion-employees-view.md` |

## Cierre de la migración — ✅ hecho

- Eliminados `templates/element/observation_bubble.php`,
  `observation_bubble_template.php`, `observation_chat_init.php` (commit `83d69bc`).
- Verificado: `git grep` en `templates/` de `observation_bubble*` /
  `observation_chat_init` y de `.sgi-row-fact*` / `.sgi-status-tab*` → 0 resultados.
- `webroot/js/sgi-observation-chat.js` se conserva — lo usa `observations/drawer`.

Todo el trabajo está commiteado en `main`, **nada pusheado**. La validación
funcional final (recorrer las vistas de los 7 módulos en el navegador) la hace el
usuario.

## Flujo que se siguió (referencia)

Un spec → por cada módulo, en el orden de ejecución: `writing-plans` → ejecución
con `subagent-driven-development` (implementador `sonnet` + revisor de spec por
tarea; revisión de calidad de código una vez al final del plan) → commits directos
en `main` → validación manual del usuario.

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
  `sgi-edit-id-chip`, `btn-ghost-card` — clases sin CSS) — fueron **fuera del
  alcance** de esta migración, pero se abordaron después como esfuerzo aparte
  (solo-CSS): spec `docs/superpowers/specs/2026-05-21-cabeceras-view-edit-design.md`,
  commit `af4e469`. Se definieron las 5 clases en `styles.css`/`components.css`
  sin tocar markup.
- **EmployeeNovelties — diferidos**: la tabla "Historial de Cambios" de `view.php`
  se dejó como `<table>`; la card Bootstrap de `edit.php` solo se limpió el
  `!important` (sin reestructurar). Documentado en el self-review de su plan.
- **NoveltyLiquidationDocs — diferidos/decisiones**: la tabla "Historial de Cambios
  del Grupo" de `view.php` se dejó como `<table>` (tabla de auditoría densa; mismo
  criterio que el diferido de EmployeeNovelties). Las cards de "Firmas" de
  `view.php`/`edit.php` se dejaron sin cambios — ya usan tokens, sin bordes ni
  clases Bootstrap crudas, y no existe componente de firma en el Sistema de Diseño.
  El documento de liquidación destacado ("D. Liquidación") se limpió in-situ en su
  propia card (no encaja en `documents_section`, igual que la "Relación de facturas"
  de Advances); la lista de soportes adjuntos sí adoptó `documents_section`. Nota:
  al migrar a `documents_section`, el `counterSelector` del uploader en `edit.php`
  tuvo que pasar de `.sgi-folder-count` a `#docs-folder-count` (commit `d53b851`,
  regresión detectada en la revisión de calidad — `#docs-list` ya no vive dentro de
  `.card` sino de `.sgi-card`).
- **Employees/view — pestaña de observaciones**: la vista es tabbed; adoptar el
  `observations/drawer` flotante implicó **eliminar la pestaña "Observaciones"**
  (botón de nav + panel) — decisión aprobada por el usuario. Las observaciones
  quedan accesibles desde cualquier pestaña vía el disparador fijo del drawer.
  El CSS `.sgi-obs-*` del chat viejo queda muerto pero limpiarlo está fuera de
  alcance (el spec solo pedía verificar referencias en `templates/`).
- **Advances — Soportes de `legalization.php`**: los 3 documentos especiales
  (Relación de facturas / Comprobante de consignación / Historial de firmas) **no**
  se forzaron a `documents_section` — no encajan (reemplazo-subida AJAX, "documento"
  que no es entidad, estado firmado/pendiente). Se limpiaron in-situ (decisión
  aprobada por el usuario). Diferidos menores señalados en la revisión de calidad:
  el callout "Monto pendiente" conserva un `border-left:2px` (token, no `border:1px`)
  y `legalization.php` mezcla formato de moneda (entero en la tabla migrada, 2
  decimales en los formularios de faltante/sobrante). Fuera de los pasos del plan;
  barrer en un follow-up si se quiere consistencia visual total.

## Pendiente de respuesta del usuario

Sin pendientes. La única pregunta abierta (cabeceras de view/edit) se resolvió —
ver "Decisiones de alcance ya tomadas".
