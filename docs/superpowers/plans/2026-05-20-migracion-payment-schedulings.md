# Migración del módulo PaymentSchedulings — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Alinear el módulo PaymentSchedulings (Programación de Pagos) al diseño de Facturas: listado al dialecto canónico, observaciones al drawer compartido.

**Architecture:** `PaymentSchedulings/index.php` se reescribe espejo de `Invoices/index.php`. `PaymentSchedulings/view.php` y `edit.php` reemplazan su tarjeta inline de observaciones por `element('observations/drawer', …)`.

**Tech Stack:** CakePHP 5.3 (templates PHP en `templates/PaymentSchedulings/`), CSS del Sistema de Diseño v2 (`webroot/css/components.css`), JS `sgi-observation-chat.js`.

**Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md` (módulo PaymentSchedulings).

**Política del proyecto:** sin tests automatizados. Cada tarea cierra con `php -l` del archivo tocado y un commit. `composer cs-fix` NO corre en este entorno — no usarlo. La validación funcional (servidor + navegador) la hace el usuario.

---

## Contexto

- Elementos compartidos estables: `templates/element/observations/drawer.php` (drawer
  flotante autocontenido, params `observations`/`count`/`formUrl`/`currentUserName`),
  `templates/element/document_row.php`, `templates/element/pipeline_sidebar.php`.
- `PaymentSchedulings/index.php` usa el dialecto `.sgi-row-fact*` / `.sgi-status-tab*` /
  `.sgi-search-bar` / `.sgi-page-title` / `.sgi-pipeline-mini` — **clases sin CSS** →
  renderiza casi sin estilos. La estructura canónica es la de `Invoices/index.php`.
- `view.php` y `edit.php` muestran las observaciones con el chat viejo
  (`element('observation_bubble', …)`, clases `.sgi-obs-*`). `edit.php` **no** tiene
  un `<form>` principal único — usa formularios pequeños sueltos (`addItem`,
  `addObservation`, `importExcel`); por eso el drawer puede colocarse al final del
  archivo sin riesgo de anidamiento.
- `$currentUser` está disponible globalmente en todas las vistas (lo expone
  `AppController::beforeRender`).

## Estructura de archivos

| Archivo | Cambio |
|---|---|
| `templates/PaymentSchedulings/index.php` | Reescritura completa al dialecto de `Invoices/index`. |
| `templates/PaymentSchedulings/view.php` | Tarjeta de observaciones inline → `observations/drawer`; Soportes a ancho completo. |
| `templates/PaymentSchedulings/edit.php` | Ídem; quitar `observation_chat_init`. |

`PaymentSchedulings/add.php` no se toca (fuera de alcance del spec).

---

## Task 1: Reescribir `PaymentSchedulings/index.php`

**Files:**
- Modify: `templates/PaymentSchedulings/index.php` (reescritura completa)

- [ ] **Step 1: Reemplazar el contenido completo de `templates/PaymentSchedulings/index.php`**

Reescritura espejo de `templates/Invoices/index.php` adaptada a los datos de
PaymentSchedulings. Contenido exacto:

```php
<?php
/**
 * Listado de Programaciones de Pago — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php: header con meta, search +
 * filtros colapsables, chips por estado, tabla con grid CSS inline, .pipeline-mini
 * y pills soft, empty state y paginación.
 *
 * @var \App\View\AppView $this
 * @var iterable $records
 * @var string $roleName
 */

use App\Constants\PaymentSchedulingConstants;
use App\View\Presentation\PaymentSchedulingPresentation;

$pageTitle = 'Programación de Pagos';
$this->assign('title', $pageTitle);

$statusBadge   = PaymentSchedulingPresentation::STATUS_BADGES;
$statusLabels  = PaymentSchedulingConstants::STATUS_LABELS;
$pipelineSteps = PaymentSchedulingConstants::PIPELINE_STATUSES;

$query        = $this->request->getQueryParams();
$activeStatus = (string)($query['status'] ?? '');
$searchValue  = (string)($query['code'] ?? '');
$activeFilters = array_filter(
    $query,
    fn ($v, $k) => $k !== 'page' && $v !== '' && $v !== null,
    ARRAY_FILTER_USE_BOTH
);
$filterCount = count($activeFilters);

$recordsArr = is_array($records) ? $records : iterator_to_array($records, false);
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

$baseQuery = array_diff_key($query, ['status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($baseQuery): array {
    $params = ['action' => 'index'];
    if ($status !== null) {
        $params['?'] = $baseQuery + ['status' => $status];
    } elseif (!empty($baseQuery)) {
        $params['?'] = $baseQuery;
    }
    return $params;
};
/* [slug, label, color-css] */
$tabs = [
    [null,                                                 'Todas',        'var(--primary-color)'],
    [PaymentSchedulingConstants::STATUS_BORRADOR,          'Borrador',     'var(--text-muted)'],
    [PaymentSchedulingConstants::STATUS_TESORERIA,         'Tesorería',    'var(--accent-color)'],
    [PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO, 'Autorización', 'var(--warning-text)'],
    [PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO, 'Verificación', 'var(--warning-text)'],
    [PaymentSchedulingConstants::STATUS_PAGADA,            'Pagadas',      'var(--primary-color)'],
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
            <span style="color:var(--text-muted);"><?= $totalCount ?> programaciones</span>
        </div>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if (!empty($userPermissions['payment_schedulings']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nueva Programación</span>',
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
</div>

<?php /* ════════════════════════ SEARCH + FILTROS ════════════════════════ */ ?>
<?= $this->Form->create(null, [
    'type'         => 'get',
    'url'          => ['action' => 'index'],
    'valueSources' => ['query'],
]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="code"
               value="<?= h($searchValue) ?>"
               placeholder="Buscar por código (PRO-…)"
               aria-label="Buscar programaciones">
        <?php if ($searchValue !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => 'index'],
                [
                    'escape' => false,
                    'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                    'title'  => 'Limpiar búsqueda',
                ]
            ) ?>
        <?php endif; ?>
    </label>

    <button type="button" class="btn btn-default"
            data-bs-toggle="collapse" data-bs-target="#scheduleFilters"
            aria-expanded="<?= $filterCount > 0 ? 'true' : 'false' ?>"
            aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($filterCount > 0): ?> · <span style="color:var(--primary-color);font-weight:700;"><?= $filterCount ?></span><?php endif; ?></span>
    </button>

    <?php if ($filterCount > 0): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Limpiar</span>',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost', 'escape' => false, 'style' => 'color:var(--danger-color);']
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $filterCount > 0 ? 'show' : '' ?>" id="scheduleFilters" style="margin-bottom:14px;">
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
            <div class="col-md-3 d-flex align-items-end">
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

<?php /* ════════════════════════ TABLA DE PROGRAMACIONES ════════════════════════ */ ?>
<?php
/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.3fr 2.2fr 0.7fr 1.5fr 1fr 1.7fr 36px;gap:14px;align-items:center;';
?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Código</span>
        <span>Título</span>
        <span style="text-align:center;"># Items</span>
        <span>Creado por</span>
        <span>Fecha</span>
        <span>Estado · Pipeline</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($recordsArr as $i => $record):
        $rowCount++;
        $stageIdx = array_search($record->pipeline_status, $pipelineSteps, true);
        if ($stageIdx === false) {
            $stageIdx = -1;
        }
        $pillClass = $statusBadge[$record->pipeline_status] ?? 'pill-muted';
        $itemCount = count($record->payment_scheduling_items ?? []);
        $isPaid    = $record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA;
        $href = $this->Url->build(['action' => 'edit', $record->id]);
    ?>
        <a href="<?= h($href) ?>" role="row"
           style="<?= $gridStyle ?>padding:14px 18px;background:#fff;color:inherit;text-decoration:none;cursor:pointer;transition:background-color var(--t-fast) ease;<?= $i > 0 ? 'border-top:1px solid var(--rule);' : '' ?>"
           onmouseenter="this.style.background='var(--bg-muted)'"
           onmouseleave="this.style.background='#fff'">

            <?php /* 1. Código */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($record->code ?: '—') ?>
                </div>
                <div style="font-size:9.5px;color:var(--text-faint);letter-spacing:0.5px;font-weight:600;margin-top:2px;text-transform:uppercase;">
                    Programación
                </div>
            </div>

            <?php /* 2. Título */ ?>
            <div style="min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= !empty($record->title)
                        ? h($record->title)
                        : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
            </div>

            <?php /* 3. # Items */ ?>
            <div class="mono" style="text-align:center;font-size:12px;color:var(--text-muted);">
                <?= $itemCount ?>
            </div>

            <?php /* 4. Creado por */ ?>
            <div style="min-width:0;font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $record->hasValue('created_by_user')
                    ? h($record->created_by_user->full_name)
                    : '<span style="color:var(--text-faint);">—</span>' ?>
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
                        <?= h(strtoupper($statusLabels[$record->pipeline_status] ?? $record->pipeline_status)) ?>
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
            <div class="es-title">No hay programaciones en este filtro</div>
            <div class="es-msg">Cambia el filtro o crea una nueva programación.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>
```

Notas:
- La lógica de datos (constantes, materialización de `$records`, `$tabUrl`, `$tabs`)
  es equivalente a la versión anterior; solo cambia el markup. Columnas iguales
  (Código, Título, # Items, Creado por, Fecha, Estado·Pipeline).
- `$record->pipeline_status` es el estado (no `->status`); `payment_scheduling_items`
  es la colección de ítems. `$userPermissions` está disponible globalmente.
- Tokens y clases son los mismos que usa `Invoices/index.php` / `Refunds/index.php`.

- [ ] **Step 2: Verificar y commitear**

```bash
php -l templates/PaymentSchedulings/index.php
git add templates/PaymentSchedulings/index.php
git commit -m "refactor(view): PaymentSchedulings/index al dialecto de listado de Facturas"
```
El mensaje de commit debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 3: Validación manual**

`php bin/cake server`, abrir `Programación de Pagos`: el listado se ve estilizado
(header, search bar, chips por estado, filas con grid, `.pipeline-mini`, pills);
antes se veía sin estilos. Click en una fila abre `edit`. Búsqueda, filtros
colapsables y paginación funcionan. Consola sin errores.

---

## Task 2: `PaymentSchedulings/view.php` — observaciones al drawer

**Files:**
- Modify: `templates/PaymentSchedulings/view.php`

Lee primero `templates/PaymentSchedulings/view.php` completo para ubicar las secciones.

- [ ] **Step 1: Reemplazar la sección Soportes + Observaciones**

En `templates/PaymentSchedulings/view.php` existe un bloque `<div class="sgi-edit-side-grid">`
que contiene dos cards: **Soportes** y **Observaciones** (`<div class="card sgi-obs-card" …>`).

Cambios:
1. Quitar el wrapper `<div class="sgi-edit-side-grid">` y su `</div>` de cierre, de
   modo que la card de **Soportes** quede como hijo directo a **ancho completo**. El
   markup interno de la card de Soportes NO cambia.
2. **Eliminar por completo** la card de **Observaciones** (todo el
   `<div class="card sgi-obs-card" …>…</div>` y su comentario `<!-- Observaciones -->`
   si lo tuviera).

- [ ] **Step 2: Añadir el drawer de observaciones**

`PaymentSchedulings/view.php` no tiene `<form>` propio. Insertar la llamada al
drawer como **última instrucción del markup de la página** (al final del archivo,
tras todo el contenido de la vista):

```php
<?= $this->element('observations/drawer', [
    'observations'    => $record->payment_scheduling_observations ?? [],
    'count'           => count($record->payment_scheduling_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

Notas:
- `$record->payment_scheduling_observations` es la colección de observaciones (la
  variable `$observations`, definida al inicio del archivo, la apunta).
- `$currentUser` está disponible globalmente en todas las vistas.
- La variable `$observations` (definida al inicio) puede quedar sin uso tras
  eliminar la card; verifícalo con `grep -n 'observations' templates/PaymentSchedulings/view.php`
  y, si solo aparece en su línea de asignación, elimínala.

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/PaymentSchedulings/view.php
git add templates/PaymentSchedulings/view.php
git commit -m "refactor(view): PaymentSchedulings/view usa el drawer de observaciones compartido"
```
El mensaje debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 4: Validación manual**

`PaymentSchedulings/view` de una programación: la card de Soportes a ancho completo;
el disparador del drawer fijo al borde derecho; abrir el drawer muestra las
observaciones; el contador es correcto. Consola sin errores.

---

## Task 3: `PaymentSchedulings/edit.php` — observaciones al drawer

**Files:**
- Modify: `templates/PaymentSchedulings/edit.php`

Lee primero `templates/PaymentSchedulings/edit.php` completo para ubicar las secciones.

- [ ] **Step 1: Reemplazar la sección Soportes + Observaciones**

En `templates/PaymentSchedulings/edit.php` hay un bloque `<div class="sgi-edit-side-grid">`
con las cards **Soportes** y **Observaciones**.

Cambios:
1. Quitar el wrapper `<div class="sgi-edit-side-grid">` y su `</div>` de cierre,
   dejando la card de **Soportes** a ancho completo. El markup interno de Soportes
   NO cambia (conserva `#docs-empty-state` / `#docs-list` si los tiene, las llamadas
   a `document_row`, el botón de subir si lo hay).
2. **Eliminar por completo** la card de **Observaciones**: el comentario y la línea
   `<?php $obsCount = count($record->payment_scheduling_observations ?? []); ?>`, y
   todo el `<div class="card sgi-obs-card" …>…</div>` (que contiene `#obs-count`,
   `#obs-chat-scroll`, las llamadas a `element('observation_bubble', …)`,
   `#obs-empty-state` y el `<form id="obs-form">` interno).

- [ ] **Step 2: Sustituir `observation_chat_init` por el drawer**

`PaymentSchedulings/edit.php` NO tiene un `<form>` principal único — usa formularios
pequeños sueltos (`addItem`, `addObservation`, `importExcel`), todos cerrados antes
del final del archivo. La línea `<?= $this->element('observation_chat_init') ?>`
está cerca del final, fuera de cualquier `<form>`.

**Reemplazar** la línea `<?= $this->element('observation_chat_init') ?>` por la
llamada al drawer:

```php
<?= $this->element('observations/drawer', [
    'observations'    => $record->payment_scheduling_observations ?? [],
    'count'           => count($record->payment_scheduling_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

NO toques los demás elementos del final del archivo (`document_row_template`,
`regress_status_modal`).

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/PaymentSchedulings/edit.php
git add templates/PaymentSchedulings/edit.php
git commit -m "refactor(view): PaymentSchedulings/edit usa el drawer de observaciones compartido"
```
El mensaje debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 4: Validación manual**

`PaymentSchedulings/edit` de una programación: Soportes a ancho completo; abrir el
drawer, publicar una observación y verificar que aparece y el contador sube;
subir/eliminar un soporte; recorrer las acciones del pipeline. Consola sin errores JS.

---

## Self-review (cobertura del spec)

- `index` → dialecto canónico de `Invoices/index` → Task 1. ✔
- `view` → observaciones al drawer → Task 2. ✔
- `edit` → observaciones al drawer → Task 3. ✔
- Soportes ya usa `document_row` en view y edit — solo se saca del grid lateral a
  ancho completo. ✔
- `add.php` fuera de alcance — no se toca. ✔

Nota: el chat viejo `observation_bubble*` no se elimina en este plan — se retira al
final de la migración completa, cuando ningún módulo lo use.
