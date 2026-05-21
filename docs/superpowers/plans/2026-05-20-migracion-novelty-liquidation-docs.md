# Migración del módulo NoveltyLiquidationDocs — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Alinear el módulo NoveltyLiquidationDocs (Documentos de Liquidación de novedades) al diseño de Facturas: listado al dialecto canónico con search bar funcional, `view`/`edit` sin `<table>` Bootstrap crudas, soportes con el element `documents_section`, observaciones con el `observations/drawer` compartido.

**Architecture:** `NoveltyLiquidationDocs/index.php` se reescribe espejo de `Invoices/index.php`. El `NoveltyLiquidationDocsController` recibe un filtro `search` por `liquidation_number`. En `view`/`edit` las `<table>` de contenido (Novedades Asociadas, Pagos Registrados) pasan a filas con grid CSS; el documento de liquidación destacado se limpia in-situ en su propia card; la lista de soportes adopta el element `documents_section`; el chat de observaciones viejo se reemplaza por `observations/drawer`.

**Tech Stack:** CakePHP 5.3 (templates PHP en `templates/NoveltyLiquidationDocs/`, controller en `src/Controller/`), CSS del Sistema de Diseño v2 (`webroot/css/components.css`), JS `sgi-observation-chat.js` y `sgi-document-uploader.js`.

**Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md` (módulo NoveltyLiquidationDocs, 6.º del orden de ejecución).

**Política del proyecto:** sin tests automatizados. Cada tarea cierra con `php -l` del archivo tocado y un commit. `composer cs-fix` / `cs-check` NO corren en este entorno — no usarlos. La validación funcional (servidor + navegador) la hace el usuario.

---

## Contexto

- Los elementos compartidos ya existen y están estables: `templates/element/observations/drawer.php`
  (drawer flotante autocontenido), `templates/element/documents_section.php` (sección de
  soportes; conserva los IDs `#docs-list` / `#docs-empty-state` / `#docs-folder-count`
  del contrato de `sgi-document-uploader.js`), `templates/element/document_row.php`,
  `templates/element/pipeline_sidebar.php`.
- `NoveltyLiquidationDocs/index.php` usa hoy `card card-primary` + `<table class="table">`
  Bootstrap. La estructura canónica es la de `Invoices/index.php`.
- **El `NoveltyLiquidationDocsController` no soporta búsqueda hoy** — `index()`,
  `all()`, `rejected()` solo aceptan `pipeline_status`. El usuario aprobó añadir una
  search bar; esta migración añade el filtro `search` por `liquidation_number` al
  controller (igual que se hizo en Advances).
- `view.php` / `edit.php` ya usan `pipeline_sidebar`, `.sgi-data-row`, `document_row`.
  El markup legacy a migrar: `<table>` Bootstrap crudas (Novedades Asociadas, Pagos
  Registrados, Historial), el bloque destacado "D. Liquidación" con estilos inline,
  el chat de observaciones viejo (`observation_bubble` + `observation_chat_init`),
  el grid lateral `sgi-edit-side-grid`, y `.form-label` de Bootstrap en `edit.php`.
- **Decisión de Soportes (consistente con la migración previa):** la lista de
  soportes adjuntos ya se renderiza con `document_row`; se adopta el element
  `documents_section` (igual que `EmployeeNovelties/view.php` e `Invoices/edit.php`).
  El **documento de liquidación destacado** ("D. Liquidación") es un documento
  especial — análogo a la "Relación de facturas" de Advances — que no encaja en
  `documents_section`; se limpia **in-situ** en su propia card "Documento de
  Liquidación".
- **`add.php`** no existe en este módulo (los documentos de liquidación se crean
  agrupando novedades desde `EmployeeNovelties`) — no aplica.

## Decisiones de alcance del plan (confirmadas contra el código)

- **Tabla "Historial de Cambios del Grupo" en `view.php`** → se **difiere** (se deja
  como `<table>`). Es una tabla de auditoría densa de 6 columnas; el módulo
  EmployeeNovelties ya difirió su tabla "Historial de Cambios" análoga por la misma
  razón (decisión ya registrada y aceptada en el doc de progreso). Misma decisión
  aquí, por consistencia.
- **Cards de firma** (`Firmas`, en `view.php` y `edit.php`) → se **dejan como están**.
  Al confirmar contra el código: ya usan superficies de token (`var(--bg-subtle)`),
  no tienen bordes decorativos ni clases Bootstrap crudas, y no existe un componente
  "card de firma" en el Sistema de Diseño. No hay nada roto que migrar; restructurar
  código que no está roto viola la regla de cambios quirúrgicos. Las firmas no se
  tocan (sí se mueven sus `.form-label` internos en `edit.php` — ver Task 5 — porque
  los toggles de firma no tienen `.form-label`, así que esto no aplica; ver detalle).
- **Cabeceras `view`/`edit`** (`sgi-page-title`, `sgi-breadcrumb`, `sgi-edit-id-chip`,
  `btn-ghost-card`) — preexistentes y transversales, fuera del alcance de la
  migración (decisión global registrada en el doc de progreso). No se tocan.

## Estructura de archivos

| Archivo | Cambio |
|---|---|
| `src/Controller/NoveltyLiquidationDocsController.php` | Filtro `search` por `liquidation_number` en `index()`, `all()`, `rejected()`. |
| `templates/NoveltyLiquidationDocs/index.php` | Reescritura completa al dialecto de `Invoices/index` + search bar + chips. |
| `templates/NoveltyLiquidationDocs/view.php` | Tablas Novedades/Pagos → filas del sistema; D. Liquidación → card propia in-situ; soportes → `documents_section`; observaciones → drawer. |
| `templates/NoveltyLiquidationDocs/edit.php` | Tabla Novedades → filas del sistema; `.form-label`→`.input-label`; D. Liquidación → card propia in-situ; soportes → `documents_section`; observaciones → drawer. |

---

## Task 1: `NoveltyLiquidationDocsController` — filtro de búsqueda

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (acciones `index`, `all`, `rejected`)

- [ ] **Step 1: Añadir el filtro `search` a `index()`**

En `src/Controller/NoveltyLiquidationDocsController.php`, dentro de `index()`, localizar:

```php
        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $liquidationDocs = $this->paginate($query);
```

Reemplazarlo por:

```php
        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['NoveltyLiquidationDocs.liquidation_number LIKE' => '%' . $search . '%']);
        }

        $liquidationDocs = $this->paginate($query);
```

- [ ] **Step 2: Añadir el filtro `search` a `all()`**

En la acción `all()`, localizar:

```php
        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $liquidationDocs = $this->paginate($query);
```

Reemplazarlo por:

```php
        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['NoveltyLiquidationDocs.liquidation_number LIKE' => '%' . $search . '%']);
        }

        $liquidationDocs = $this->paginate($query);
```

- [ ] **Step 3: Añadir el filtro `search` a `rejected()`**

En la acción `rejected()`, localizar:

```php
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->where(['NoveltyLiquidationDocs.pipeline_status' => NoveltyConstants::STATUS_RECHAZADA])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC']);

        $liquidationDocs = $this->paginate($query);
```

Reemplazarlo por:

```php
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->where(['NoveltyLiquidationDocs.pipeline_status' => NoveltyConstants::STATUS_RECHAZADA])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC']);

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['NoveltyLiquidationDocs.liquidation_number LIKE' => '%' . $search . '%']);
        }

        $liquidationDocs = $this->paginate($query);
```

- [ ] **Step 4: Verificar y commitear**

```bash
php -l src/Controller/NoveltyLiquidationDocsController.php
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat(novelty-liquidation): filtro de búsqueda por número en index/all/rejected"
```

El mensaje de commit debe terminar con una línea en blanco y luego:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 5: Validación manual**

`php bin/cake server`, abrir `Documentos de Liquidación` y añadir `?search=` con parte
de un número de liquidación conocido en la URL: el listado se filtra. La búsqueda se
ejercita de forma visual en la Task 2.

---

## Task 2: Reescribir `NoveltyLiquidationDocs/index.php`

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/index.php` (reescritura completa)

- [ ] **Step 1: Reemplazar el contenido completo de `templates/NoveltyLiquidationDocs/index.php`**

Reescritura espejo de `templates/Invoices/index.php` adaptada a Documentos de
Liquidación. El contenido exacto y completo del archivo debe ser:

```php
<?php
/**
 * Listado de Documentos de Liquidación — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php: header con meta, search bar,
 * chips por estado, tabla con grid CSS inline, pills soft, empty state y paginación.
 *
 * Vista única reutilizada por las acciones index / all / rejected.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\NoveltyLiquidationDoc> $liquidationDocs
 * @var string|null $statusFilter
 * @var array<string> $visibleStatuses
 */

use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$action = $this->request->getParam('action');
$titleMap = [
    'index'    => 'Mis Documentos de Liquidación',
    'all'      => 'Todos los Documentos de Liquidación',
    'rejected' => 'Documentos de Liquidación Rechazados',
];
$pageTitle = $titleMap[$action] ?? 'Documentos de Liquidación';
$this->assign('title', $pageTitle);

$statusLabels = NoveltyConstants::STATUS_LABELS;
$periodLabels = NoveltyConstants::PERIOD_LABELS;
$statusBadge  = NoveltyPresentation::STATUS_BADGES + [
    NoveltyConstants::STATUS_RECHAZADA => 'pill-danger-soft',
];

$query        = $this->request->getQueryParams();
$activeStatus = (string)($statusFilter ?? '');
$searchValue  = (string)($query['search'] ?? '');

// Materializar el ResultSet (PaginatedResultSet no garantiza count() ni rewind).
$docsArr    = is_array($liquidationDocs) ? $liquidationDocs : iterator_to_array($liquidationDocs, false);
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

// Chips por estado — ocultos en la bandeja rejected.
$showTabs  = $action !== 'rejected';
$baseQuery = array_diff_key($query, ['pipeline_status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($action, $baseQuery): array {
    $params = ['action' => $action ?: 'index'];
    if ($status !== null) {
        $params['?'] = $baseQuery + ['pipeline_status' => $status];
    } elseif (!empty($baseQuery)) {
        $params['?'] = $baseQuery;
    }
    return $params;
};
/* [slug, label, color-css] */
$tabs = [
    [null,                                       'Todos',         'var(--primary-color)'],
    [NoveltyConstants::STATUS_CONTABILIDAD,      'Contabilidad',  'var(--secondary-color)'],
    [NoveltyConstants::STATUS_REVISION_FIRMAS,   'Rev. Firmas',   'var(--warning-text)'],
    [NoveltyConstants::STATUS_GDP,               'GDP',           'var(--text-muted)'],
    [NoveltyConstants::STATUS_TESORERIA,         'Tesorería',     'var(--accent-color)'],
    [NoveltyConstants::STATUS_AUTORIZACION_PAGO, 'Autorización',  'var(--warning-text)'],
    [NoveltyConstants::STATUS_PAGADA,            'Pagados',       'var(--primary-color)'],
];

/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.3fr 1.1fr 1.4fr 0.8fr 1.6fr 1fr 36px;gap:14px;align-items:center;';
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            <?= h($pageTitle) ?>
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            Período: <?= h($periodLabel) ?> ·
            <span style="color:var(--text-muted);"><?= $totalCount ?> documentos</span>
        </div>
    </div>
</div>

<?php /* ════════════════════════ SEARCH ════════════════════════ */ ?>
<?= $this->Form->create(null, [
    'type'         => 'get',
    'url'          => ['action' => $action ?: 'index'],
    'valueSources' => ['query'],
]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <?php if ($activeStatus !== ''): ?>
        <input type="hidden" name="pipeline_status" value="<?= h($activeStatus) ?>">
    <?php endif; ?>
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="search"
               value="<?= h($searchValue) ?>"
               placeholder="Buscar por número de liquidación…"
               aria-label="Buscar documentos de liquidación">
        <?php if ($searchValue !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                $tabUrl($activeStatus !== '' ? $activeStatus : null),
                [
                    'escape' => false,
                    'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                    'title'  => 'Limpiar búsqueda',
                ]
            ) ?>
        <?php endif; ?>
    </label>
</div>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR ESTADO ════════════════════════ */ ?>
<?php if ($showTabs): ?>
<div class="d-flex flex-wrap" style="gap:4px;margin-bottom:14px;" role="tablist" aria-label="Filtrar por estado">
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
<?php endif; ?>

<?php /* ════════════════════════ TABLA DE DOCUMENTOS ════════════════════════ */ ?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>No. Liquidación</span>
        <span>Período</span>
        <span>Estado</span>
        <span style="text-align:center;">Novedades</span>
        <span>Elaborado por</span>
        <span>Fecha</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($docsArr as $i => $doc):
        $rowCount++;
        $pillClass = $statusBadge[$doc->pipeline_status] ?? 'pill-muted';
    ?>
        <a href="<?= $this->Url->build(['action' => 'edit', $doc->id]) ?>" role="row"
           style="<?= $gridStyle ?>padding:14px 18px;background:#fff;color:inherit;text-decoration:none;cursor:pointer;transition:background-color var(--t-fast) ease;<?= $i > 0 ? 'border-top:1px solid var(--rule);' : '' ?>"
           onmouseenter="this.style.background='var(--bg-muted)'"
           onmouseleave="this.style.background='#fff'">

            <?php /* 1. No. Liquidación */ ?>
            <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($doc->liquidation_number) ?>
            </div>

            <?php /* 2. Período */ ?>
            <div style="font-size:12px;color:var(--text-default);">
                <?= h($periodLabels[$doc->period] ?? $doc->period) ?>
            </div>

            <?php /* 3. Estado */ ?>
            <div style="min-width:0;">
                <span class="pill <?= h($pillClass) ?> pill-sm">
                    <?= h(strtoupper($statusLabels[$doc->pipeline_status] ?? $doc->pipeline_status)) ?>
                </span>
            </div>

            <?php /* 4. Novedades */ ?>
            <div class="mono" style="text-align:center;font-size:12px;color:var(--text-muted);">
                <?= count($doc->employee_novelties) ?>
            </div>

            <?php /* 5. Elaborado por */ ?>
            <div style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($doc->performed_by_user->full_name ?? '—') ?>
            </div>

            <?php /* 6. Fecha */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-faint);">
                <?= $doc->document_date?->format('d/m/Y') ?: '—' ?>
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
            <div class="es-title">No hay documentos de liquidación en este filtro</div>
            <div class="es-msg">Cambia el filtro o ajusta la búsqueda.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>
```

Notas:
- El `<select>` de filtro de estado se reemplaza por chips `.chip` (espejo de
  `Invoices/index`). El `<input type="hidden" name="pipeline_status">` dentro del
  form de búsqueda preserva el chip activo al buscar; `$tabUrl` (vía `$baseQuery`)
  preserva `search` al cambiar de chip.
- No se renderiza `.pipeline-mini`: el pipeline de un documento de liquidación
  depende del tipo de novedad (`effectiveStatuses`, no fijo) y el controller del
  listado no lo provee — se muestra solo el pill de estado, como hoy.
- `$userPermissions` está disponible globalmente; este listado no tiene botón de
  crear (los documentos de liquidación se generan agrupando novedades).
- Tokens y clases (`.input`, `.sgi-card`, `.chip`, `.dot`, `.pill`/`pill-sm`,
  `.empty-state`/`.es-*`) son los mismos que usa `Invoices/index.php`.

- [ ] **Step 2: Verificar y commitear**

```bash
php -l templates/NoveltyLiquidationDocs/index.php
git add templates/NoveltyLiquidationDocs/index.php
git commit -m "refactor(view): NoveltyLiquidationDocs/index al dialecto de Facturas + search bar"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 3: Validación manual**

`Documentos de Liquidación`: el listado se ve estilizado (header, search bar, chips
por estado, filas con grid, pills soft); antes era una tabla Bootstrap. Buscar por
número filtra y mantiene el chip activo; click en chip mantiene la búsqueda; click
en fila abre `edit`; paginación funciona. Probar `Todos` (chips visibles) y
`Rechazados` (chips ocultos, search bar visible). Consola sin errores.

---

## Task 3: `NoveltyLiquidationDocs/view.php` — tablas internas a filas del sistema

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/view.php`

- [ ] **Step 1: "Novedades Asociadas" — `<table>` → filas con grid**

En `templates/NoveltyLiquidationDocs/view.php`, dentro de la columna derecha de la
card "Información + Novedades", localizar el bloque que renderiza las novedades —
desde `<?php if ($noveltyCount > 0): ?>` hasta su `<?php endif; ?>`, que contiene
`<div style="padding:0 18px 14px;max-height:280px;overflow-y:auto;">` con una
`<table class="table table-sm table-hover mb-0">` y el `else` con "No hay novedades
asociadas.".

Reemplazar ese bloque `if/else` completo por:

```php
                    <?php if ($noveltyCount > 0): ?>
                    <?php $nvGrid = 'display:grid;grid-template-columns:1.5fr 1fr;gap:10px;align-items:center;'; ?>
                    <div style="padding:0 18px 14px;">
                        <div style="max-height:280px;overflow-y:auto;">
                            <div style="<?= $nvGrid ?>padding:8px 12px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
                                <span>Empleado</span>
                                <span>Tipo</span>
                            </div>
                            <?php foreach ($doc->employee_novelties as $nvIdx => $novelty): ?>
                            <div class="clickable-row" role="row"
                                 data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]) ?>"
                                 style="<?= $nvGrid ?>padding:10px 12px;background:#fff;cursor:pointer;<?= $nvIdx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                                <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?>
                                </span>
                                <span style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($novelty->novelty_type->name ?? '—') ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="padding:.5rem 18px 1rem;color:var(--text-disabled);font-size:var(--fs-body-sm);">
                        No hay novedades asociadas.
                    </div>
                    <?php endif; ?>
```

- [ ] **Step 2: "Pagos Registrados" — `<table>` → filas con grid**

Localizar la card de "Pagos Registrados" — el bloque
`<?php if (!empty($doc->liquidation_doc_payments)): ?> … <?php endif; ?>` que
contiene `<div class="table-responsive">` con una `<table>` de columnas Entidad
Bancaria / Monto / Fecha / Estado / Registrado por.

Reemplazar **todo ese bloque `if`** por:

```php
        <!-- Payments (read-only) -->
        <?php if (!empty($doc->liquidation_doc_payments)): ?>
        <?php $payGrid = 'display:grid;grid-template-columns:1.4fr 1fr 0.9fr 1.5fr 1.2fr;gap:12px;align-items:center;'; ?>
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Pagos Registrados
                </span>
            </div>
            <div class="sgi-card" style="padding:0;">
                <div style="<?= $payGrid ?>padding:9px 14px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
                    <span>Entidad Bancaria</span>
                    <span style="text-align:right;">Monto</span>
                    <span>Fecha</span>
                    <span>Estado</span>
                    <span>Registrado por</span>
                </div>
                <?php foreach ($doc->liquidation_doc_payments as $payIdx => $payment): ?>
                <div role="row"
                     style="<?= $payGrid ?>padding:11px 14px;background:#fff;<?= $payIdx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($payment->banking_entity->name ?? '—') ?>
                    </span>
                    <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--text-default);">
                        $ <?= number_format((float)$payment->amount, 0, ',', '.') ?>
                    </span>
                    <span class="mono" style="font-size:11.5px;color:var(--text-muted);">
                        <?= $payment->payment_date?->format('d/m/Y') ?? '—' ?>
                    </span>
                    <span style="min-width:0;">
                        <?php if ($payment->authorized): ?>
                            <span class="pill pill-primary-soft pill-sm"><i class="bi bi-check-circle" aria-hidden="true"></i>Autorizado</span>
                            <?php if ($payment->authorized_by_user): ?>
                            <div style="font-size:var(--fs-meta);color:var(--text-muted);margin-top:2px;">
                                <?= h($payment->authorized_by_user->full_name ?? $payment->authorized_by_user->username ?? '') ?><?php if ($payment->authorized_date): ?> · <span class="mono"><?= $payment->authorized_date->format('d/m/Y') ?></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="pill pill-warning-soft pill-sm"><i class="bi bi-clock" aria-hidden="true"></i>Pendiente</span>
                        <?php endif; ?>
                    </span>
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
```

Notas:
- Las filas de pago no son navegables (no son `clickable-row`) — igual que la
  `<table>` legacy, que no tenía `data-href`.
- El `border-top:1px solid var(--rule)` entre filas es el separador canónico del
  listado — no viola la regla "sin bordes".
- La tabla "Historial de Cambios del Grupo" al final del archivo **no se toca**
  (se difiere — ver "Decisiones de alcance del plan"). Las cards de "Firmas"
  tampoco se tocan.

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/NoveltyLiquidationDocs/view.php
git add templates/NoveltyLiquidationDocs/view.php
git commit -m "refactor(view): NoveltyLiquidationDocs/view — tablas Novedades y Pagos a filas del sistema"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 4: Validación manual**

`NoveltyLiquidationDocs/view` de un documento con novedades asociadas y pagos: la
lista de "Novedades Asociadas" se ve como filas con grid (click abre la novedad);
"Pagos Registrados" se ve como filas con grid (entidad, monto, fecha, estado,
registrado por). Sin tablas Bootstrap crudas en esas dos secciones. Consola sin
errores.

---

## Task 4: `NoveltyLiquidationDocs/view.php` — Soportes (`documents_section`) + drawer

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/view.php`

- [ ] **Step 1: Reemplazar el bloque "Soportes + Observaciones"**

En `templates/NoveltyLiquidationDocs/view.php`, localizar el bloque completo que
empieza con el comentario `<!-- Soportes + Observaciones (grid lateral) -->`
seguido de `<div class="sgi-edit-side-grid">`, y termina en
`</div><!-- /sgi-edit-side-grid -->`. Contiene la card de **Soportes** (con el
bloque destacado "D. Liquidación" + la lista de soportes) y la card de
**Observaciones** (chat viejo con `observation_bubble`).

Reemplazar **todo ese bloque** por: la card "Documento de Liquidación" limpiada
in-situ + el element `documents_section` para la lista de soportes:

```php
        <!-- Documento de Liquidación (destacado) -->
        <div class="sgi-card d-flex flex-column">
            <div class="d-flex align-items-center" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    Documento de Liquidación
                </span>
            </div>
            <?php if ($liquidationDocument ?? null): ?>
            <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);">
                <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                    <i class="bi <?= h($this->DocumentIcon->iconClass($liquidationDocument->mime_type)) ?>"
                       style="color:<?= h($this->DocumentIcon->iconColor($liquidationDocument->mime_type)) ?>;font-size:18px;" aria-hidden="true"></i>
                </div>
                <div class="grow">
                    <div title="<?= h($liquidationDocument->file_name) ?>"
                         style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($liquidationDocument->file_name) ?>
                    </div>
                    <div class="row-flex gap-6 mono sgi-body-faint" style="margin-top:2px;">
                        <span><?= $liquidationDocument->created?->format('d/m/Y H:i') ?></span>
                        <?php if ($liquidationDocument->file_size): ?>
                        <span>· <?= $this->Number->toReadableSize($liquidationDocument->file_size) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row-flex gap-4" style="flex-shrink:0;">
                    <?= $this->Html->link(
                        '<i class="bi bi-eye" aria-hidden="true"></i>',
                        '/' . $liquidationDocument->file_path,
                        ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                    ) ?>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="es-icon es-icon-neutral">
                    <i class="bi bi-file-earmark-x" aria-hidden="true"></i>
                </div>
                <div class="es-title">Sin documento de liquidación</div>
            </div>
            <?php endif; ?>
        </div>

        <?php /* ── Soportes ──────────────────────────────────── */ ?>
        <?php
        $docGroups = [];
        $multipleDocStatuses = count($documentsByStatus) > 1;
        foreach ($documentsByStatus as $status => $docs) {
            $rows = [];
            foreach ($docs as $docFile) {
                $rows[] = [
                    'doc'          => $docFile,
                    'canDelete'    => false,
                    'deleteUrl'    => null,
                    'showBadge'    => !$multipleDocStatuses,
                    'badgeColors'  => $badgeColors,
                    'statusLabels' => $statusLabels,
                ];
            }
            $docGroups[] = [
                'label'    => $multipleDocStatuses ? ($statusLabels[$status] ?? $status) : null,
                'pillKind' => $multipleDocStatuses ? ($badgeColors[$status] ?? 'pill-muted') : null,
                'rows'     => $rows,
            ];
        }
        ?>
        <?= $this->element('documents_section', [
            'groups'        => $docGroups,
            'totalDocs'     => $totalDocs,
            'canUpload'     => false,
            'uploadModalId' => null,
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>
```

- [ ] **Step 2: Añadir el drawer de observaciones**

Localizar el cierre del grid principal `</div><!-- /sgi-invoice-view-grid -->`
(la última línea del archivo). Inmediatamente **después** de esa línea, añadir:

```php
<?= $this->element('observations/drawer', [
    'observations'    => $doc->novelty_observations ?? [],
    'count'           => count($doc->novelty_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $doc->id],
    'currentUserName' => $currentUser->full_name ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

Notas:
- `$doc->novelty_observations` ya viene cargado por `view()`
  (`contain: ['NoveltyObservations' => ['Users', ...]]`) — era la colección que
  consumía la card de observaciones eliminada (`$obsList`).
- `$currentUser` está disponible globalmente en todas las vistas.
- `addObservation` vive en `NoveltyLiquidationDocsController` — `formUrl` usa
  `['action' => 'addObservation', $doc->id]` (mismo controller).
- La card "Documento de Liquidación" y `documents_section` quedan como hijos
  directos de `<main>`, a ancho completo (se eliminó el grid lateral
  `sgi-edit-side-grid`). La card "Historial de Cambios del Grupo" sigue después,
  intacta.
- `documents_section` se renderiza con `canUpload=false` (la vista `view` es
  read-only); el host arma `$docGroups` con el mismo patrón de
  `EmployeeNovelties/view.php`.
- Las clases `.doc-row`, `.row-flex`, `.grow`, `.gap-*`, `.doc-icon`, `.btn-icon`,
  `.sgi-body-faint`, `.sgi-card`, `.empty-state`/`.es-*` son del Sistema de Diseño,
  ya en uso en el proyecto.

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/NoveltyLiquidationDocs/view.php
git add templates/NoveltyLiquidationDocs/view.php
git commit -m "refactor(view): NoveltyLiquidationDocs/view — soportes a documents_section + drawer"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 4: Validación manual**

`NoveltyLiquidationDocs/view`: la card "Documento de Liquidación" se ve a ancho
completo (con la fila del documento o el empty state); la sección "Soportes" se
renderiza con `documents_section` (contador, agrupación por estado si aplica, filas
`document_row`); el disparador del drawer de observaciones aparece fijo al borde
derecho y abre el chat con las observaciones. Consola sin errores.

---

## Task 5: `NoveltyLiquidationDocs/edit.php` — tabla Novedades + labels

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php`

- [ ] **Step 1: "Novedades Asociadas" — `<table>` → filas con grid**

En `templates/NoveltyLiquidationDocs/edit.php`, dentro de la card "Novedades
Asociadas", localizar el bloque `<?php if (!empty($doc->employee_novelties)): ?> …
<?php endif; ?>` que contiene `<div style="max-height:280px;overflow-y:auto;">` con
una `<table class="table table-sm table-hover mb-0">` y el `else` con el empty
state "No hay novedades asociadas.".

Reemplazar ese bloque `if/else` completo por:

```php
            <?php if (!empty($doc->employee_novelties)): ?>
            <?php $nvGrid = 'display:grid;grid-template-columns:1.5fr 1fr;gap:10px;align-items:center;'; ?>
            <div style="max-height:280px;overflow-y:auto;">
                <div style="<?= $nvGrid ?>padding:8px 12px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
                    <span>Empleado</span>
                    <span>Tipo</span>
                </div>
                <?php foreach ($doc->employee_novelties as $nvIdx => $novelty): ?>
                <div class="clickable-row" role="row"
                     data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]) ?>"
                     style="<?= $nvGrid ?>padding:10px 12px;background:#fff;cursor:pointer;<?= $nvIdx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?>
                    </span>
                    <span style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($novelty->novelty_type->name ?? '—') ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center sgi-fg-faint py-3" style="font-size:var(--fs-body);">
                <i class="bi bi-inbox me-1" aria-hidden="true"></i>No hay novedades asociadas.
            </div>
            <?php endif; ?>
```

- [ ] **Step 2: `.form-label` → `.input-label`**

En `templates/NoveltyLiquidationDocs/edit.php`, reemplazar **todas** las
ocurrencias de `class="form-label"` por `class="input-label"`. Las esperadas son
3: dos en los formularios de acción de paso (`Pasa para Pago` en el estado GDP y en
REVISION_FIRMAS, `Fecha Documento` en CONTABILIDAD) y una en el modal de subida
(`Archivo`). `.form-label` es el label de Bootstrap; `.input-label` es el label
del Sistema de Diseño v2. No se tocan los `class="form-control"` /
`class="form-select"` de los inputs (el Sistema de Diseño los estiliza; igual que
en `Invoices`).

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/NoveltyLiquidationDocs/edit.php
git add templates/NoveltyLiquidationDocs/edit.php
git commit -m "refactor(view): NoveltyLiquidationDocs/edit — tabla Novedades a filas + labels al sistema"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 4: Validación manual**

`NoveltyLiquidationDocs/edit`: la card "Novedades Asociadas" se ve como filas con
grid (click abre la novedad). Recorriendo los estados, los formularios de acción
del paso (Pasa para Pago / Fecha Documento) y el modal de subir soporte muestran
los labels con el estilo del sistema. Consola sin errores.

---

## Task 6: `NoveltyLiquidationDocs/edit.php` — Soportes (`documents_section`) + drawer

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php`

- [ ] **Step 1: Reemplazar el bloque "Soportes + Observaciones"**

En `templates/NoveltyLiquidationDocs/edit.php`, localizar el bloque que empieza con
el comentario `<!-- Soportes + Observaciones (grid lateral) -->` (seguido del
`<?php $canUploadLiqDoc = … ?>` / `<?php $canUpdateLiqDoc = … ?>` y de
`<div class="sgi-edit-side-grid">`) y termina en `</div><!-- /sgi-edit-side-grid -->`.
Contiene la card de **Soportes** (bloque destacado "D. Liquidación" + lista con
`document_row`) y la card de **Observaciones** (chat viejo).

Reemplazar **todo ese bloque** (desde el comentario `<!-- Soportes + Observaciones (grid lateral) -->`
hasta `</div><!-- /sgi-edit-side-grid -->` inclusive) por: la card "Documento de
Liquidación" limpiada in-situ (con sus formularios de subida/reemplazo) + el element
`documents_section`:

```php
        <!-- Documento de Liquidación (destacado) -->
        <?php
        $canUploadLiqDoc = $currentStatus === NoveltyConstants::STATUS_CONTABILIDAD && !$liquidationDocument;
        $canUpdateLiqDoc = $liquidationDocument && in_array($currentStatus, [
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ]);
        ?>
        <div class="sgi-card d-flex flex-column">
            <div class="d-flex align-items-center" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    Documento de Liquidación
                </span>
            </div>
            <?php if ($liquidationDocument): ?>
            <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);">
                <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                    <i class="bi <?= h($this->DocumentIcon->iconClass($liquidationDocument->mime_type)) ?>"
                       style="color:<?= h($this->DocumentIcon->iconColor($liquidationDocument->mime_type)) ?>;font-size:18px;" aria-hidden="true"></i>
                </div>
                <div class="grow">
                    <div title="<?= h($liquidationDocument->file_name) ?>"
                         style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($liquidationDocument->file_name) ?>
                    </div>
                    <div class="row-flex gap-6 mono sgi-body-faint" style="margin-top:2px;">
                        <span><?= $liquidationDocument->created?->format('d/m/Y H:i') ?></span>
                        <?php if ($liquidationDocument->file_size): ?>
                        <span>· <?= $this->Number->toReadableSize($liquidationDocument->file_size) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row-flex gap-4" style="flex-shrink:0;">
                    <?php if ($canUpdateLiqDoc): ?>
                    <?= $this->Form->create(null, [
                        'url' => ['action' => 'updateLiquidationDocument', $doc->id],
                        'type' => 'file',
                        'id' => 'liq-doc-update-form',
                        'class' => 'd-inline',
                    ]) ?>
                    <input type="file" name="liquidation_file" id="liq-doc-file" required
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"
                           style="display:none;" data-liq-trigger="liq-doc-update-form">
                    <label for="liq-doc-file" class="btn-icon" style="cursor:pointer;" title="Reemplazar">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    </label>
                    <?= $this->Form->end() ?>
                    <?php endif; ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-eye" aria-hidden="true"></i>',
                        '/' . $liquidationDocument->file_path,
                        ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                    ) ?>
                </div>
            </div>
            <?php elseif ($canUploadLiqDoc): ?>
            <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);">
                <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                    <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:18px;" aria-hidden="true"></i>
                </div>
                <div class="grow">
                    <span class="sgi-body-faint" style="font-size:var(--fs-body-sm);">Sin documento</span>
                </div>
                <?= $this->Form->create(null, [
                    'url' => ['action' => 'uploadLiquidationDocument', $doc->id],
                    'type' => 'file',
                    'id' => 'liq-doc-upload-form',
                    'class' => 'd-inline flex-shrink-0',
                ]) ?>
                <input type="file" name="liquidation_file" id="liq-doc-file-new" required
                       accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"
                       style="display:none;" data-liq-trigger="liq-doc-upload-form">
                <label for="liq-doc-file-new" class="btn btn-default btn-sm" style="cursor:pointer;" title="Subir documento">
                    <i class="bi bi-upload" aria-hidden="true"></i>Subir
                </label>
                <?= $this->Form->end() ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="es-icon es-icon-neutral">
                    <i class="bi bi-file-earmark-x" aria-hidden="true"></i>
                </div>
                <div class="es-title">Sin documento de liquidación</div>
            </div>
            <?php endif; ?>
        </div>

        <?php /* ── Soportes ──────────────────────────────────── */ ?>
        <?php
        $docGroups = [];
        $multipleDocStatuses = count($documentsByStatus) > 1;
        foreach ($documentsByStatus as $status => $docs) {
            $rows = [];
            foreach ($docs as $docFile) {
                $rows[] = [
                    'doc'          => $docFile,
                    'canDelete'    => $showUploadSection && $docFile->pipeline_status === $currentStatus,
                    'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $doc->id, $docFile->id]),
                    'showBadge'    => !$multipleDocStatuses,
                    'badgeColors'  => $badgeColors,
                    'statusLabels' => $statusLabels,
                ];
            }
            $docGroups[] = [
                'label'    => $multipleDocStatuses ? ($statusLabels[$status] ?? $status) : null,
                'pillKind' => $multipleDocStatuses ? ($badgeColors[$status] ?? 'pill-muted') : null,
                'rows'     => $rows,
            ];
        }
        ?>
        <?= $this->element('documents_section', [
            'groups'        => $docGroups,
            'totalDocs'     => $totalDocs,
            'canUpload'     => $showUploadSection,
            'uploadModalId' => 'uploadDocModal',
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>
```

- [ ] **Step 2: Añadir el drawer y eliminar `observation_chat_init`**

1. Localizar el cierre del grid principal `</div><!-- /sgi-invoice-view-grid -->`.
   Inmediatamente **después** de esa línea, insertar el drawer:

```php
<?= $this->element('observations/drawer', [
    'observations'    => $doc->novelty_observations ?? [],
    'count'           => count($doc->novelty_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $doc->id],
    'currentUserName' => $currentUser->full_name ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

2. Localizar y **eliminar por completo** la línea:

```php
<?= $this->element('observation_chat_init') ?>
```

El drawer es autocontenido (emite su `<template>`, carga `sgi-observation-chat.js`
e inicializa `SgiObservationChat`); dejar `observation_chat_init` causaría una doble
inicialización.

Notas:
- **No se toca** el modal `#uploadDocModal` (al final del archivo), ni el
  `<?= $this->element('document_row_template', ['showBadge' => true]) ?>`, ni el
  `<?= $this->Html->script('sgi-document-uploader', …) ?>`, ni el bloque
  `$this->append('script')` con `SgiDocumentUploader.init(...)` y el handler de
  `data-liq-trigger`. `documents_section` conserva los IDs `#docs-list` /
  `#docs-empty-state` / `#docs-folder-count` que ese JS consume — el uploader sigue
  funcionando. El handler `data-liq-trigger` (subida/reemplazo del documento de
  liquidación) sigue operando sobre los `<form>` `liq-doc-update-form` /
  `liq-doc-upload-form` que se conservan dentro de la card "Documento de Liquidación".
- La card vieja de Observaciones (con `#obs-form`, `#obs-count`, `#obs-chat-scroll`,
  `#obs-empty-state`) se elimina al reemplazar el bloque del Paso 1 — el drawer
  reusa esos mismos IDs, por lo que no deben quedar duplicados.
- `$currentStatus`, `$liquidationDocument`, `$documentsByStatus`, `$badgeColors`,
  `$statusLabels`, `$totalDocs`, `$showUploadSection`, `$doc`, `$currentUser` ya
  están desempaquetados del `$viewModel` al inicio del template.
- El bloque destacado conserva su lógica de 3 estados (documento presente con
  reemplazo / sin documento con subida / sin documento sin permiso) y los
  `data-liq-trigger` para el JS de subida AJAX; solo cambian los estilos inline y
  los `btn btn-sm btn-ghost-card` / `btn btn-sm btn-primary` por `.btn-icon` /
  `.btn btn-default btn-sm` del sistema.

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/NoveltyLiquidationDocs/edit.php
git add templates/NoveltyLiquidationDocs/edit.php
git commit -m "refactor(view): NoveltyLiquidationDocs/edit — soportes a documents_section + drawer"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 4: Validación manual**

`NoveltyLiquidationDocs/edit`: la card "Documento de Liquidación" se ve a ancho
completo; en estados `contabilidad`/`revision_firmas`/`gdp` el botón-icono de
reemplazo (o el de subir, si no hay documento) funciona vía AJAX y recarga. La
sección "Soportes" se renderiza con `documents_section`; con permiso de subida, el
botón "Subir" abre `#uploadDocModal` y el uploader añade la fila sin recargar; el
contador sube; eliminar un soporte de la etapa actual funciona. El disparador del
drawer de observaciones aparece fijo al borde derecho; publicar una observación la
muestra y sube el contador. Recorrer las acciones de pipeline (avanzar, firmas,
pagos) y confirmar que siguen operando. Consola sin errores JS (sin doble init del
chat).

---

## Self-review (cobertura del spec)

Sección "NoveltyLiquidationDocs" del spec:

- `index` → reescritura completa: `<table>` Bootstrap dentro de `.card-primary` →
  estructura de `Invoices/index` → Task 2 (template) + Task 1 (controller, para que
  la search bar funcione; search bar aprobada por el usuario). ✔
- `view` → varias `<table>` crudas → filas del sistema → Task 3 (Novedades
  Asociadas + Pagos Registrados). La tabla "Historial de Cambios del Grupo" se
  **difiere** (tabla de auditoría densa; mismo criterio que el diferido aceptado
  en EmployeeNovelties). ✔ (con diferido documentado)
- `view` → bloques de soporte con estilos inline → `documents_section`/`document_row`
  → Task 4 (lista de soportes a `documents_section`; documento destacado "D.
  Liquidación" limpiado in-situ en card propia). ✔
- `view` → cards de firma ad-hoc → patrón del sistema → al confirmar contra el
  código, las cards de firma ya usan tokens, sin bordes ni clases Bootstrap crudas,
  y no existe componente de firma en el Sistema de Diseño → **no se tocan**
  (decisión documentada; restructurar código no roto violaría la regla de cambios
  quirúrgicos). ✔ (evaluado, sin cambio)
- `view` → observaciones → drawer → Task 4. ✔
- `edit` → cards de firma ad-hoc → patrón del sistema → mismo criterio que en
  `view`: no se tocan. ✔ (evaluado, sin cambio)
- `edit` → `.form-label`→`.input-label` → Task 5. ✔
- `edit` → bloque "D. Liquidación" con estilos inline → tokens/clases → Task 6
  (card propia limpiada in-situ). ✔
- `edit` → observaciones → drawer → Task 6. ✔

Consistencia de tipos / nombres:
- `$nvGrid` se define dentro de su bloque en Task 3 y Task 5; `$payGrid` dentro de
  su bloque en Task 3. `$docGroups` / `$multipleDocStatuses` se definen en el host
  inmediatamente antes de cada llamada a `documents_section` (Task 4 y Task 6),
  patrón idéntico al de `EmployeeNovelties/view.php` e `Invoices/edit.php`. ✔
- IDs del drawer (`#obs-form`, `#obs-count`, `#obs-chat-scroll`, `#obs-empty-state`)
  quedan una sola vez: la card vieja de observaciones se elimina en Task 4 (view) y
  Task 6 (edit) antes de insertar el drawer. ✔
- IDs del uploader (`#docs-list`, `#docs-empty-state`, `#docs-folder-count`) los
  emite `documents_section`; el `SgiDocumentUploader.init(...)` del bloque
  `append('script')` de `edit.php` los consume sin cambios. ✔
- Variables del `view`/`edit` (`$doc`, `$documentsByStatus`, `$liquidationDocument`,
  `$badgeColors`, `$statusLabels`, `$totalDocs`, `$currentStatus`, `$showUploadSection`,
  `$currentUser`, `$noveltyCount`) son las ya provistas por el controller / el
  `NoveltyLiquidationDocEditViewModel` — usadas tal como en el template actual.

Cierre de la migración: tras este módulo solo queda **Employees/view** (último
consumidor del chat viejo). El trío `observation_bubble*` se retira en ese plan
final, no en este.
