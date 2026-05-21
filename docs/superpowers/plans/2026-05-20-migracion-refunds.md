# Migración del módulo Refunds — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Alinear el módulo Refunds (Reintegros) al diseño de Facturas: listado al dialecto canónico, observaciones al drawer compartido.

**Architecture:** `Refunds/index.php` se reescribe espejo de `Invoices/index.php`. `Refunds/view.php` y `Refunds/edit.php` reemplazan su tarjeta inline de observaciones por `element('observations/drawer', …)`.

**Tech Stack:** CakePHP 5.3 (templates PHP en `templates/Refunds/`), CSS del Sistema de Diseño v2 (`webroot/css/components.css`), JS `sgi-observation-chat.js`.

**Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md` (módulo Refunds, primer módulo del orden de ejecución).

**Política del proyecto:** sin tests automatizados. Cada tarea cierra con `php -l` del archivo tocado y un commit. `composer cs-fix` NO corre en este entorno (limitación conocida) — no usarlo. La validación funcional (servidor + navegador) la hace el usuario.

---

## Contexto

- Los elementos compartidos ya existen y están estables: `templates/element/observations/drawer.php`
  (drawer flotante autocontenido, params `observations`/`count`/`formUrl`/`currentUserName`),
  `templates/element/document_row.php`, `templates/element/pipeline_sidebar.php`.
- `Refunds/index.php` usa hoy el dialecto `.sgi-row-fact*` / `.sgi-status-tab*` /
  `.sgi-search-bar` / `.sgi-page-title` — **clases sin CSS definido** → la página
  renderiza casi sin estilos. La estructura canónica es la de `Invoices/index.php`.
- `Refunds/view.php` y `Refunds/edit.php` muestran las observaciones con el chat
  viejo (`element('observation_bubble', …)` + clases `.sgi-obs-*`). En `edit.php`
  el chat incluye un `<form id="obs-form">` **anidado dentro** del `<form id="refundEditForm">`
  (HTML inválido) — el drawer lo corrige porque va fuera del form principal.

## Estructura de archivos

| Archivo | Cambio |
|---|---|
| `templates/Refunds/index.php` | Reescritura completa al dialecto de `Invoices/index`. |
| `templates/Refunds/view.php` | Tarjeta de observaciones inline → `observations/drawer`; Soportes a ancho completo. |
| `templates/Refunds/edit.php` | Ídem; drawer fuera del `<form>`; quitar `observation_chat_init`; cosmética. |

`Refunds/add.php` no se toca (fuera de alcance del spec).

---

## Task 1: Reescribir `Refunds/index.php`

**Files:**
- Modify: `templates/Refunds/index.php` (reescritura completa)

- [ ] **Step 1: Reemplazar el contenido completo de `templates/Refunds/index.php`**

Es una reescritura espejo de `templates/Invoices/index.php`, adaptada a los datos
de Refunds. Contenido exacto:

```php
<?php
/**
 * Listado de Reintegros — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php: header con meta, search +
 * filtros colapsables, chips por estado, tabla con grid CSS inline, .pipeline-mini
 * y pills soft, empty state y paginación.
 *
 * @var \App\View\AppView $this
 * @var iterable $records
 * @var array $visibleStatuses
 */

use App\Constants\RefundConstants;
use App\View\Presentation\RefundPresentation;

$action = $this->request->getParam('action');
$pageTitles = [
    'all'     => 'Todos los Reintegros',
    'pending' => 'Pendientes',
];
$pageTitle = $pageTitles[$action] ?? 'Reintegros';
$this->assign('title', $pageTitle);

$statusBadge   = RefundPresentation::STATUS_BADGES;
$statusLabels  = RefundConstants::STATUS_LABELS;
$pipelineSteps = RefundConstants::STATUSES;

$query        = $this->request->getQueryParams();
$activeStatus = (string)($query['status'] ?? '');
$searchValue  = (string)($query['code'] ?? '');
$activeFilters = array_filter(
    $query,
    fn ($v, $k) => $k !== 'page' && $v !== '' && $v !== null,
    ARRAY_FILTER_USE_BOTH
);
$filterCount = count($activeFilters);

// Materializar el ResultSet para sumar y luego iterar.
$recordsArr = is_array($records) ? $records : iterator_to_array($records, false);
$pageTotal  = 0.0;
foreach ($recordsArr as $r) {
    $pageTotal += (float)$r->total_amount;
}
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

// Chips por estado.
$baseQuery = array_diff_key($query, ['status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($action, $baseQuery): array {
    $params = ['action' => $action ?: 'index'];
    if ($status !== null) {
        $params['?'] = $baseQuery + ['status' => $status];
    } elseif (!empty($baseQuery)) {
        $params['?'] = $baseQuery;
    }
    return $params;
};
/* [slug, label, color-css] */
$tabs = [
    [null,                                      'Todos',        'var(--primary-color)'],
    [RefundConstants::STATUS_AGRUPACION,        'Agrupación',   'var(--text-muted)'],
    [RefundConstants::STATUS_CONTABILIDAD,      'Contabilidad', 'var(--secondary-color)'],
    [RefundConstants::STATUS_TESORERIA,         'Tesorería',    'var(--accent-color)'],
    [RefundConstants::STATUS_AUTORIZACION_PAGO, 'Autorización', 'var(--warning-text)'],
    [RefundConstants::STATUS_PAGADA,            'Pagados',      'var(--primary-color)'],
];
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            <?= h($pageTitle) ?>
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            Período: <?= h($periodLabel) ?> ·
            <span style="color:var(--text-muted);"><?= $totalCount ?> reintegros</span> ·
            <span class="mono" style="color:var(--text-muted);">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if (!empty($userPermissions['refunds']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nuevo Reintegro</span>',
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
</div>

<?php /* ════════════════════════ SEARCH + FILTROS ════════════════════════ */ ?>
<?= $this->Form->create(null, [
    'type'         => 'get',
    'url'          => ['action' => $action ?: 'index'],
    'valueSources' => ['query'],
]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="code"
               value="<?= h($searchValue) ?>"
               placeholder="Buscar por código (REI-2026-…)"
               aria-label="Buscar reintegros">
        <?php if ($searchValue !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => $action ?: 'index'],
                [
                    'escape' => false,
                    'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                    'title'  => 'Limpiar búsqueda',
                ]
            ) ?>
        <?php endif; ?>
    </label>

    <button type="button" class="btn btn-default"
            data-bs-toggle="collapse" data-bs-target="#refundFilters"
            aria-expanded="<?= $filterCount > 0 ? 'true' : 'false' ?>"
            aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($filterCount > 0): ?> · <span style="color:var(--primary-color);font-weight:700;"><?= $filterCount ?></span><?php endif; ?></span>
    </button>

    <?php if ($filterCount > 0): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Limpiar</span>',
            ['action' => $action ?: 'index'],
            ['class' => 'btn btn-ghost', 'escape' => false, 'style' => 'color:var(--danger-color);']
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $filterCount > 0 ? 'show' : '' ?>" id="refundFilters" style="margin-bottom:14px;">
    <div class="sgi-card compact">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="input-label" for="filter-status">Estado</label>
                <?= $this->Form->select('status', $statusLabels, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $activeStatus,
                    'id'    => 'filter-status',
                ]) ?>
            </div>
            <div class="col-md-3">
                <label class="input-label" for="filter-date-from">Desde</label>
                <input type="text" name="date_from" id="filter-date-from"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($this->request->getQuery('date_from', '')) ?>"
                       placeholder="Fecha desde">
            </div>
            <div class="col-md-3">
                <label class="input-label" for="filter-date-to">Hasta</label>
                <input type="text" name="date_to" id="filter-date-to"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($this->request->getQuery('date_to', '')) ?>"
                       placeholder="Fecha hasta">
            </div>
            <div class="col-md-3 d-flex align-items-end justify-content-end">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check2" aria-hidden="true"></i><span>Aplicar filtros</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR ESTADO ════════════════════════ */ ?>
<div class="d-flex" style="gap:4px;margin-bottom:14px;" role="tablist" aria-label="Filtrar por estado">
    <?php foreach ($tabs as [$status, $label, $color]):
        $isActive = ($activeStatus === ($status ?? ''));
    ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:' . $color . ';"></span>' : '') . h($label),
            $tabUrl($status),
            [
                'class'         => 'chip' . ($isActive ? ' is-active' : ''),
                'escape'        => false,
                'role'          => 'tab',
                'aria-selected' => $isActive ? 'true' : 'false',
                'style'         => $isActive ? 'color:' . $color . ';' : '',
            ]
        ) ?>
    <?php endforeach; ?>
</div>

<?php /* ════════════════════════ TABLA DE REINTEGROS ════════════════════════ */ ?>
<?php
/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.3fr 2fr 1.1fr 0.8fr 1fr 1.7fr 36px;gap:14px;align-items:center;';
?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Código</span>
        <span>Beneficiario</span>
        <span style="text-align:right;">Total</span>
        <span style="text-align:center;"># Facturas</span>
        <span>Fecha</span>
        <span>Estado · Pipeline</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($recordsArr as $i => $record):
        $rowCount++;
        $stageIdx = array_search($record->status, $pipelineSteps, true);
        if ($stageIdx === false) {
            $stageIdx = -1;
        }
        $pillClass    = $statusBadge[$record->status] ?? 'pill-muted';
        $invoiceCount = count($record->invoices ?? []);
        $isPaid       = $record->status === RefundConstants::STATUS_PAGADA;
        $beneficiary  = $record->getBeneficiaryName() ?: null;
        $href = $this->Url->build(['action' => 'edit', $record->id]);
    ?>
        <a href="<?= h($href) ?>" role="row"
           style="<?= $gridStyle ?>padding:14px 18px;background:#fff;color:inherit;text-decoration:none;cursor:pointer;transition:background-color var(--t-fast) ease;<?= $i > 0 ? 'border-top:1px solid var(--rule);' : '' ?>"
           onmouseenter="this.style.background='var(--bg-muted)'"
           onmouseleave="this.style.background='#fff'">

            <?php /* 1. Código + tipo */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($record->code ?: '—') ?>
                </div>
                <div style="font-size:9.5px;color:var(--text-faint);letter-spacing:0.5px;font-weight:600;margin-top:2px;text-transform:uppercase;">
                    Reintegro
                </div>
            </div>

            <?php /* 2. Beneficiario + creador */ ?>
            <div style="min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $beneficiary
                        ? h($beneficiary)
                        : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
                <?php if ($record->hasValue('created_by_user')): ?>
                    <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi bi-person" style="font-size:10px;" aria-hidden="true"></i>
                        <span><?= h($record->created_by_user->full_name) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php /* 3. Total */ ?>
            <div class="mono" style="text-align:right;font-size:13.5px;font-weight:700;<?= $isPaid ? 'color:var(--primary-color);' : 'color:var(--text-default);' ?>">
                $ <?= number_format((float)$record->total_amount, 0, ',', '.') ?>
            </div>

            <?php /* 4. # Facturas */ ?>
            <div class="mono" style="text-align:center;font-size:12px;color:var(--text-muted);">
                <?= $invoiceCount ?>
            </div>

            <?php /* 5. Fecha */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-default);">
                <?= $record->created?->format('d/m/Y') ?: '—' ?>
            </div>

            <?php /* 6. Estado · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($stageIdx >= 0): ?>
                    <div class="pipeline-mini" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0, $n = count($pipelineSteps); $s < $n; $s++): ?>
                            <div class="<?= $s <= $stageIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <span class="pill <?= h($pillClass) ?> pill-sm">
                        <?php if ($isPaid): ?><i class="bi bi-check" style="font-size:9px;" aria-hidden="true"></i><?php endif; ?>
                        <?= h(strtoupper($statusLabels[$record->status] ?? $record->status)) ?>
                    </span>
                </div>
            </div>

            <?php /* 7. Chevron */ ?>
            <div style="display:flex;justify-content:flex-end;align-items:center;color:var(--text-faint);">
                <i class="bi bi-chevron-right" style="font-size:14px;" aria-hidden="true"></i>
            </div>
        </a>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-inbox" aria-hidden="true"></i>
            </div>
            <div class="es-title">No hay reintegros en este filtro</div>
            <div class="es-msg">Cambia el filtro o crea un nuevo reintegro.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>
```

Notas:
- La lógica de datos (acción, `$statusBadge`/`$statusLabels`/`$pipelineSteps`,
  materialización de `$records`, `$tabUrl`, `$tabs`) se conserva equivalente a la
  versión anterior — solo cambia el markup. Las columnas del listado (Código,
  Beneficiario, Total, # Facturas, Fecha, Estado·Pipeline) son las mismas.
- `$userPermissions` está disponible globalmente en todas las vistas.
- Tokens usados (`--accent-color`, `--t-fast`, `--rule`, `--bg-subtle`,
  `--bg-muted`, etc.) y clases (`.input`, `.input-label`, `.sgi-card`, `.chip`,
  `.dot`, `.pipeline-mini`, `.pill`/`pill-sm`, `.empty-state`/`.es-*`,
  `.btn-default`/`.btn-ghost`) son los mismos que usa `Invoices/index.php`.

- [ ] **Step 2: Verificar y commitear**

```bash
php -l templates/Refunds/index.php
git add templates/Refunds/index.php
git commit -m "refactor(view): Refunds/index al dialecto de listado de Facturas"
```
El mensaje de commit debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 3: Validación manual**

`php bin/cake server`, abrir `Reintegros`: el listado se ve estilizado (header,
search bar, chips por estado, filas con grid, `.pipeline-mini`, pills); antes se
veía sin estilos. Click en una fila abre `edit`. Búsqueda por código, filtros
colapsables y paginación funcionan. Consola sin errores.

---

## Task 2: `Refunds/view.php` — observaciones al drawer

**Files:**
- Modify: `templates/Refunds/view.php`

- [ ] **Step 1: Reemplazar la sección Soportes + Observaciones**

En `templates/Refunds/view.php` existe un bloque `<div class="sgi-edit-side-grid">`
(comentario `<!-- Soportes + Observaciones (grid lateral) -->`) que contiene dos
cards: **Soportes** y **Observaciones**.

Cambios:
1. Quitar el wrapper `<div class="sgi-edit-side-grid">` … `</div>` — la card de
   **Soportes** queda como hijo directo a ancho completo (igual que en
   `Invoices/edit.php`, donde Soportes va full-width y las observaciones viven en
   el drawer). Conservar íntegra la card de Soportes (su markup interno no cambia).
2. **Eliminar por completo** la card de Observaciones (`<div class="card sgi-obs-card">…</div>`,
   incluido el comentario `<!-- Observaciones -->`).

- [ ] **Step 2: Añadir el drawer de observaciones**

Insertar la llamada al drawer **antes del cierre `</main>`** de la vista (el
drawer es flotante y autocontenido; `Refunds/view.php` no tiene `<form>` propio,
así que no hay restricción de anidamiento):

```php
<?= $this->element('observations/drawer', [
    'observations'    => $record->refund_observations ?? [],
    'count'           => count($record->refund_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $this->getRequest()->getAttribute('identity')?->full_name
        ?? 'Usuario',
]) ?>
```

Notas:
- `$record->refund_observations` es la colección de observaciones (ya la usaba la
  card eliminada, vía `$obsList = $record->refund_observations ?? []`).
- `Refunds/view.php` no declara `$currentUser`; se usa la identidad de la request
  (`getAttribute('identity')`), disponible globalmente.
- La variable `$obsList` (definida al inicio del archivo) puede quedar sin uso
  tras eliminar la card; si es así, eliminar también su asignación.

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/Refunds/view.php
git add templates/Refunds/view.php
git commit -m "refactor(view): Refunds/view usa el drawer de observaciones compartido"
```
El mensaje debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 4: Validación manual**

`Refunds/view` de un reintegro: la card de Soportes se ve a ancho completo; el
disparador del drawer aparece fijo al borde derecho; abrir el drawer muestra las
observaciones; el contador es correcto. Consola sin errores.

---

## Task 3: `Refunds/edit.php` — observaciones al drawer + cosmética

**Files:**
- Modify: `templates/Refunds/edit.php`

- [ ] **Step 1: Reemplazar la sección Soportes + Observaciones**

En `templates/Refunds/edit.php` hay (igual que en view) un `<div class="sgi-edit-side-grid">`
con las cards **Soportes** y **Observaciones**.

Cambios:
1. Quitar el wrapper `<div class="sgi-edit-side-grid">` … `</div>` dejando la card
   de **Soportes** a ancho completo (su markup interno no cambia: conserva
   `#docs-empty-state`, `#docs-list`, las llamadas a `document_row`, el botón de
   subir si lo hay).
2. **Eliminar por completo** la card de Observaciones (`<div class="card sgi-obs-card" …>…</div>`,
   con el comentario `<!-- Observaciones -->` y el `<?php $obsCount = … ?>` que la
   precede). Esa card contiene hoy un `<form id="obs-form">` anidado dentro del
   `<form id="refundEditForm">` — eliminarla resuelve además ese HTML inválido.

- [ ] **Step 2: Quitar `observation_chat_init` y añadir el drawer**

1. Eliminar la línea `<?= $this->element('observation_chat_init') ?>` (el drawer
   es autocontenido: emite su propio `<template>` e inicializa el chat).
2. Insertar el drawer **después de `<?= $this->Form->end() ?>`** del formulario
   principal `refundEditForm` (debe quedar FUERA de ese `<form>`):

```php
<?= $this->element('observations/drawer', [
    'observations'    => $record->refund_observations ?? [],
    'count'           => count($record->refund_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

Notas:
- `Refunds/edit.php` sí declara `$currentUser` (`@var \App\Model\Entity\User|null $currentUser`).
- Los demás elementos del final del archivo (`link_invoices_modal`,
  `document_row_template`, `regress_status_modal`) NO se tocan.

- [ ] **Step 3: Cosmética — alinear divisores y labels**

Reemplazos mecánicos en `templates/Refunds/edit.php`:

1. Cada `<div class="sgi-flex-divider"></div>` → `<div class="hr"></div>`.
   (Son divisores de sección autónomos; `.hr` es el divisor del sistema v2.) Si
   alguna instancia estuviera DENTRO de una fila flex como relleno de espacio
   —no como divisor de sección— dejarla y reportarlo; las 3 conocidas son divs
   autónomos.
2. Cada `class="form-label"` → `class="input-label"` y `class="form-label d-block"`
   → `class="input-label d-block"` (label del sistema v2).

- [ ] **Step 4: Verificar y commitear**

```bash
php -l templates/Refunds/edit.php
git add templates/Refunds/edit.php
git commit -m "refactor(view): Refunds/edit usa el drawer de observaciones + cosmética v2"
```
El mensaje debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 5: Validación manual**

`Refunds/edit` de un reintegro: Soportes a ancho completo; disparador del drawer
fijo al borde derecho; abrir el drawer, publicar una observación y verificar que
aparece y el contador sube; subir/eliminar un soporte. Recorrer las acciones del
pipeline (avanzar/regresar/pagos) y confirmar que siguen operando. Consola sin
errores JS.

---

## Self-review (cobertura del spec)

- `index` → dialecto canónico de `Invoices/index` → Task 1. ✔
- `view` → observaciones al drawer → Task 2. ✔
- `edit` → observaciones al drawer + cosmética (`sgi-flex-divider`→`hr`,
  `form-label`→`input-label`) → Task 3. ✔
- Soportes ya usa `document_row` en view y edit — no requiere cambio (solo se
  saca del grid lateral para ir a ancho completo). ✔
- `add.php` fuera de alcance — no se toca. ✔

Nota: el chat viejo `observation_bubble*` no se elimina en este plan — se retira
al final de la migración completa, cuando ningún módulo lo use (otros módulos aún
lo consumen).
