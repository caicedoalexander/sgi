# Migración del módulo Advances — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Alinear el módulo Advances (Anticipos) al diseño de Facturas: listado al dialecto canónico con search bar funcional, `legalization.php` sin markup legacy (tabla cruda, observaciones viejas, estilos inline), `view.php` con banner del sistema.

**Architecture:** `Advances/index.php` se reescribe espejo de `Invoices/index.php`. El `AdvancesController` recibe un filtro `search` por `invoice_number` para que la search bar funcione. `legalization.php` reemplaza su `<table>` Bootstrap por filas con grid CSS, limpia in-situ la sección de Soportes (3 documentos especiales del dominio de firmas — se conserva la estructura, se eliminan estilos inline y bordes decorativos) y migra el chat de observaciones al `observations/drawer` compartido.

**Tech Stack:** CakePHP 5.3 (templates PHP en `templates/Advances/`, controller en `src/Controller/`), CSS del Sistema de Diseño v2 (`webroot/css/components.css`), JS `sgi-observation-chat.js`.

**Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md` (módulo Advances, 5.º del orden de ejecución).

**Política del proyecto:** sin tests automatizados. Cada tarea cierra con `php -l` del archivo tocado y un commit. `composer cs-fix` / `cs-check` NO corren en este entorno (faltan extensiones PHP) — no usarlos. La validación funcional (servidor + navegador) la hace el usuario.

---

## Contexto

- Los elementos compartidos ya existen y están estables: `templates/element/observations/drawer.php`
  (drawer flotante autocontenido; params `observations` / `count` / `formUrl` / `currentUserName`),
  `templates/element/pipeline_sidebar.php`.
- `Advances/index.php` usa hoy el dialecto `.sgi-row-fact*` / `.sgi-status-tab*` —
  **clases sin CSS definido** → la página renderiza casi sin estilos. La estructura
  canónica es la de `Invoices/index.php`.
- **El `AdvancesController` no soporta búsqueda hoy** — `index()`, `all()` y
  `pendingLegalization()` solo aceptan el query param `pipeline_status`. El spec
  pide "añadir search bar"; una search bar que no busca no es aceptable, así que
  esta migración añade el filtro `search` (por `invoice_number`) al controller.
  Es la única ampliación de alcance fuera de templates, y es mínima.
- `Advances/view.php` ya está alineado (usa `pipeline_sidebar`, `.sgi-data-row`,
  pills del sistema) y **no tiene observaciones**. Único retoque: el `alert
  alert-info` de Bootstrap → `.banner` del sistema. El `border-right` del
  divisor de columnas (token `--rule`) es estructural — se deja.
- `Advances/legalization.php` es el archivo pesado: `<table>` Bootstrap cruda,
  chat de observaciones viejo (`observation_bubble` + `observation_chat_init`),
  ~150 líneas de estilos inline en la sección de Soportes, `border:1px solid`
  inline, divisores manuales, `.form-label` y `btn btn-warning` (Bootstrap crudo).
- **No hay `Advances/edit.php`** — `AdvancesController::edit()` redirige a
  `Invoices/edit`. El módulo son 3 templates: `index`, `view`, `legalization`.
  `Advances/add.php` y `Advances/link_candidates.php` quedan fuera de alcance
  (`add` es transversal; `link_candidates` ya delega al element compartido
  `link_invoices_modal`).
- **Decisión de diseño aprobada (Soportes de `legalization.php`):** los 3
  documentos especiales (Relación de facturas, Comprobante de consignación,
  Historial de firmas) **no encajan** en el element `documents_section`
  (reemplazo-subida AJAX, "documento" que no es entidad, estado firmado/pendiente).
  Se limpia **in-situ**: se conserva la estructura, se eliminan los estilos inline
  y los bordes decorativos → tokens y clases del sistema. No se fuerza el element.

## Estructura de archivos

| Archivo | Cambio |
|---|---|
| `src/Controller/AdvancesController.php` | Añadir filtro `search` por `invoice_number` a `index()`, `all()`, `pendingLegalization()`. |
| `templates/Advances/index.php` | Reescritura completa al dialecto de `Invoices/index` + search bar. |
| `templates/Advances/view.php` | `alert alert-info` Bootstrap → `.banner info` del sistema. |
| `templates/Advances/legalization.php` | Tabla de facturas vinculadas → filas del sistema; Soportes limpiado in-situ; observaciones → drawer; divisores/modal/labels/botones/alert → sistema. |

---

## Task 1: `AdvancesController` — filtro de búsqueda

**Files:**
- Modify: `src/Controller/AdvancesController.php` (acciones `index`, `all`, `pendingLegalization`)

- [ ] **Step 1: Añadir el filtro `search` a `index()`**

En `src/Controller/AdvancesController.php`, dentro de `index()`, localizar el bloque
que aplica `pipeline_status`:

```php
        $pipelineStatus = (string)$this->request->getQuery('pipeline_status', '');
        if ($pipelineStatus !== '') {
            $query->where(['Invoices.pipeline_status' => $pipelineStatus]);
        }

        $advances = $this->paginate($query);
```

Reemplazarlo por (añade el filtro `search` justo antes de `paginate`):

```php
        $pipelineStatus = (string)$this->request->getQuery('pipeline_status', '');
        if ($pipelineStatus !== '') {
            $query->where(['Invoices.pipeline_status' => $pipelineStatus]);
        }

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['Invoices.invoice_number LIKE' => '%' . $search . '%']);
        }

        $advances = $this->paginate($query);
```

- [ ] **Step 2: Añadir el filtro `search` a `all()`**

En la acción `all()`, localizar el mismo bloque:

```php
        $pipelineStatus = (string)$this->request->getQuery('pipeline_status', '');
        if ($pipelineStatus !== '') {
            $query->where(['Invoices.pipeline_status' => $pipelineStatus]);
        }

        $advances = $this->paginate($query);
        $visibleStatuses = [];
```

Reemplazarlo por:

```php
        $pipelineStatus = (string)$this->request->getQuery('pipeline_status', '');
        if ($pipelineStatus !== '') {
            $query->where(['Invoices.pipeline_status' => $pipelineStatus]);
        }

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['Invoices.invoice_number LIKE' => '%' . $search . '%']);
        }

        $advances = $this->paginate($query);
        $visibleStatuses = [];
```

- [ ] **Step 3: Añadir el filtro `search` a `pendingLegalization()`**

En la acción `pendingLegalization()`, localizar:

```php
            ->orderBy(['Invoices.created' => 'DESC']);

        $advances = $this->paginate($query);
        $visibleStatuses = [];
```

Reemplazarlo por:

```php
            ->orderBy(['Invoices.created' => 'DESC']);

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['Invoices.invoice_number LIKE' => '%' . $search . '%']);
        }

        $advances = $this->paginate($query);
        $visibleStatuses = [];
```

- [ ] **Step 4: Verificar y commitear**

```bash
php -l src/Controller/AdvancesController.php
git add src/Controller/AdvancesController.php
git commit -m "feat(advances): filtro de búsqueda por número en index/all/pendingLegalization"
```

El mensaje de commit debe terminar con una línea en blanco y luego:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 5: Validación manual**

`php bin/cake server`, abrir `Anticipos` y añadir `?search=` con parte de un número
de anticipo conocido en la URL: el listado se filtra. Sin `search`, lista completa.
La búsqueda se ejercita de forma visual en la Task 2 (search bar).

---

## Task 2: Reescribir `Advances/index.php`

**Files:**
- Modify: `templates/Advances/index.php` (reescritura completa)

- [ ] **Step 1: Reemplazar el contenido completo de `templates/Advances/index.php`**

Reescritura espejo de `templates/Invoices/index.php`, adaptada a Anticipos.
Conserva las dos columnas de pipeline propias del módulo (pago + legalización).
Contenido exacto del archivo:

```php
<?php
/**
 * Listado de Anticipos — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php adaptada a Anticipos:
 * header con meta, search bar, chips por estado, tabla con grid CSS inline,
 * .pipeline-mini y pills soft, empty state y paginación. Conserva las dos
 * columnas de pipeline propias de Anticipos (pago + legalización).
 *
 * Vista única reutilizada por las acciones index / all / pendingLegalization.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $advances
 * @var string[] $visibleStatuses
 */

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\View\Presentation\AdvancePresentation;
use App\View\Presentation\InvoicePresentation;

$action = $this->request->getParam('action');
$pageTitles = [
    'all'                 => 'Todos los Anticipos',
    'pendingLegalization' => 'Pendientes de Legalización',
];
$pageTitle = $pageTitles[$action] ?? 'Anticipos';
$this->assign('title', $pageTitle);

$pipelineBadge        = InvoicePresentation::STATUS_BADGES;
$pipelineLabels       = InvoiceConstants::STATUS_LABELS;
$legalizationBadge    = AdvancePresentation::STATUS_BADGES;
$legalizationLabels   = AdvanceConstants::STATUS_LABELS;
$invoicePipelineSteps = InvoiceConstants::PIPELINE_STATUSES;
$legalizationSteps    = AdvanceConstants::PIPELINE_STATUSES;

$query        = $this->request->getQueryParams();
$activeStatus = (string)($query['pipeline_status'] ?? '');
$searchValue  = (string)($query['search'] ?? '');

// Materializar el ResultSet (PaginatedResultSet no garantiza count() ni rewind).
$advancesArr = is_array($advances) ? $advances : iterator_to_array($advances, false);
$pageTotal = 0.0;
foreach ($advancesArr as $a) {
    $pageTotal += (float)$a->amount;
}
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

// Chips por estado — ocultos en la bandeja pendingLegalization.
$showTabs  = $action !== 'pendingLegalization';
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
    [InvoiceConstants::STATUS_APROBACION,        'En aprobación', 'var(--warning-text)'],
    [InvoiceConstants::STATUS_CONTABILIDAD,      'Contabilidad',  'var(--secondary-color)'],
    [InvoiceConstants::STATUS_TESORERIA,         'Tesorería',     'var(--accent-color)'],
    [InvoiceConstants::STATUS_AUTORIZACION_PAGO, 'Autorización',  'var(--warning-text)'],
    [InvoiceConstants::STATUS_PAGADA,            'Pagados',       'var(--primary-color)'],
];

/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.2fr 2fr 1.2fr 1.1fr 1.7fr 1.7fr 36px;gap:14px;align-items:center;';
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            <?= h($pageTitle) ?>
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            Período: <?= h($periodLabel) ?> ·
            <span style="color:var(--text-muted);"><?= $totalCount ?> anticipos</span> ·
            <span class="mono" style="color:var(--text-muted);">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if (!empty($userPermissions['advances']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nuevo Anticipo</span>',
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
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
               placeholder="Buscar por número de anticipo…"
               aria-label="Buscar anticipos">
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
<?php endif; ?>

<?php /* ════════════════════════ TABLA DE ANTICIPOS ════════════════════════ */ ?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Anticipo</span>
        <span>Beneficiario</span>
        <span>Centro Op.</span>
        <span style="text-align:right;">Monto</span>
        <span>Pago · Pipeline</span>
        <span>Legalización</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($advancesArr as $i => $a):
        $rowCount++;
        $pipelineIdx = array_search($a->pipeline_status, $invoicePipelineSteps, true);
        if ($pipelineIdx === false) {
            $pipelineIdx = -1;
        }
        $pillClass   = $pipelineBadge[$a->pipeline_status] ?? 'pill-muted';
        $isPaid      = $a->pipeline_status === InvoiceConstants::STATUS_PAGADA;
        $beneficiary = $a->provider->name ?? ($a->employee->full_name ?? null);

        $legalization    = $a->advance_legalization ?? null;
        $legalizationIdx = $legalization
            ? array_search($legalization->status, $legalizationSteps, true)
            : false;
        if ($legalizationIdx === false) {
            $legalizationIdx = -1;
        }
    ?>
        <a href="<?= $this->Url->build(['action' => 'view', $a->id]) ?>" role="row"
           style="<?= $gridStyle ?>padding:14px 18px;background:#fff;color:inherit;text-decoration:none;cursor:pointer;transition:background-color var(--t-fast) ease;<?= $i > 0 ? 'border-top:1px solid var(--rule);' : '' ?>"
           onmouseenter="this.style.background='var(--bg-muted)'"
           onmouseleave="this.style.background='#fff'">

            <?php /* 1. Anticipo: código + tipo */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($a->invoice_number ?: '#' . $a->id) ?>
                </div>
                <div style="font-size:9.5px;color:var(--text-faint);letter-spacing:0.5px;font-weight:600;margin-top:2px;text-transform:uppercase;">
                    Anticipo
                </div>
            </div>

            <?php /* 2. Beneficiario */ ?>
            <div style="min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $beneficiary ? h($beneficiary) : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
            </div>

            <?php /* 3. Centro de operación */ ?>
            <div style="min-width:0;">
                <div style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $a->hasValue('operation_center')
                        ? h($a->operation_center->name)
                        : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
            </div>

            <?php /* 4. Monto */ ?>
            <div class="mono" style="text-align:right;font-size:13.5px;font-weight:700;<?= $isPaid
                ? 'color:var(--primary-color);'
                : 'color:var(--text-default);' ?>">
                $ <?= number_format((float)$a->amount, 0, ',', '.') ?>
            </div>

            <?php /* 5. Pago · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($pipelineIdx >= 0): ?>
                    <div class="pipeline-mini" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0, $n = count($invoicePipelineSteps); $s < $n; $s++): ?>
                            <div class="<?= $s <= $pipelineIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <span class="pill <?= h($pillClass) ?> pill-sm">
                        <?php if ($isPaid): ?><i class="bi bi-check" style="font-size:9px;" aria-hidden="true"></i><?php endif; ?>
                        <?= h(strtoupper($pipelineLabels[$a->pipeline_status] ?? $a->pipeline_status)) ?>
                    </span>
                </div>
            </div>

            <?php /* 6. Legalización */ ?>
            <div style="min-width:0;">
                <?php if ($legalization): ?>
                    <?php if ($legalizationIdx >= 0): ?>
                        <div class="pipeline-mini" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                            <?php for ($s = 0, $n = count($legalizationSteps); $s < $n; $s++): ?>
                                <div class="<?= $s <= $legalizationIdx ? 'on' : '' ?>"></div>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <span class="pill <?= h($legalizationBadge[$legalization->status] ?? 'pill-muted') ?> pill-sm">
                            <?= h(strtoupper($legalizationLabels[$legalization->status] ?? $legalization->status)) ?>
                        </span>
                    </div>
                <?php else: ?>
                    <span style="font-size:11px;color:var(--text-faint);">Sin legalización</span>
                <?php endif; ?>
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
            <div class="es-title">No hay anticipos en este filtro</div>
            <div class="es-msg">Cambia el filtro o crea un nuevo anticipo.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>
```

Notas:
- La lógica de datos (acción, badges/labels, pasos de pipeline, materialización
  de `$advances`, `$tabUrl`, `$tabs`) se conserva equivalente a la versión
  anterior — cambia el markup y se añade `search`.
- El `<input type="hidden" name="pipeline_status">` dentro del form de búsqueda
  preserva el chip activo al buscar; `$tabUrl` (vía `$baseQuery`) preserva
  `search` al cambiar de chip.
- `$userPermissions` está disponible globalmente en todas las vistas.
- Tokens (`--accent-color`, `--t-fast`, `--rule`, `--bg-subtle`, `--bg-muted`,
  `--warning-text`, `--secondary-color`, etc.) y clases (`.input`, `.sgi-card`,
  `.chip`, `.dot`, `.pipeline-mini`, `.pill`/`pill-sm`, `.empty-state`/`.es-*`)
  son los mismos que usa `Invoices/index.php`.

- [ ] **Step 2: Verificar y commitear**

```bash
php -l templates/Advances/index.php
git add templates/Advances/index.php
git commit -m "refactor(view): Advances/index al dialecto de listado de Facturas + search bar"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 3: Validación manual**

`php bin/cake server`, abrir `Anticipos`: el listado se ve estilizado (header,
search bar, chips por estado, filas con grid, dos `.pipeline-mini`, pills soft);
antes se veía sin estilos. Escribir en la search bar y pulsar Enter filtra por
número; el chip de estado activo se mantiene. Click en chip mantiene la búsqueda.
Click en fila abre `view`. Paginación funciona. Probar también `Todos los
Anticipos` (chips visibles) y `Pendientes de Legalización` (chips ocultos, search
bar visible). Consola del navegador sin errores.

---

## Task 3: `Advances/view.php` — banner del sistema

**Files:**
- Modify: `templates/Advances/view.php`

- [ ] **Step 1: Reemplazar el `alert alert-info` de Bootstrap por `.banner info`**

En `templates/Advances/view.php`, localizar el bloque:

```php
        <div class="alert alert-info d-flex align-items-center gap-2 mb-0">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <span>La legalización iniciará automáticamente cuando este anticipo llegue al estado <strong>Pagada</strong>.</span>
        </div>
```

Reemplazarlo por el banner del sistema (`docs/design/overlays.md` § Banner inline):

```php
        <div class="banner info">
            <div class="banner-icon"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            <div class="banner-body">
                <div class="banner-msg">La legalización iniciará automáticamente cuando este anticipo llegue al estado <strong>Pagada</strong>.</div>
            </div>
        </div>
```

No se toca nada más del archivo. El `border-right:1px solid var(--rule)` del
divisor entre las dos columnas de la card "Beneficiario / Detalle" es un divisor
estructural (token `--rule`) — se deja tal cual.

- [ ] **Step 2: Verificar y commitear**

```bash
php -l templates/Advances/view.php
git add templates/Advances/view.php
git commit -m "refactor(view): Advances/view usa el banner del sistema"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 3: Validación manual**

`Advances/view` de un anticipo **sin legalización iniciada** (uno que no esté
pagado): el aviso "La legalización iniciará automáticamente…" se ve como banner
del sistema (franja izquierda, icono soft), no como alerta Bootstrap. El resto de
la vista no cambia. Consola sin errores.

> Nota: si el anticipo ya tiene legalización, `view()` redirige a `legalization()`
> y este banner no se muestra — usar un anticipo en estado temprano para validar.

---

## Task 4: `Advances/legalization.php` — tabla de facturas vinculadas

**Files:**
- Modify: `templates/Advances/legalization.php`

- [ ] **Step 1: Reemplazar el bloque "Facturas vinculadas"**

En `templates/Advances/legalization.php`, localizar el bloque completo que empieza
con el comentario `<!-- Sección: Facturas vinculadas -->` y su `<div class="mb-4">`,
y termina en el `</div>` que cierra ese `mb-4` (justo antes del comentario
`<!-- Sección: Acciones del estado -->`). Es el bloque con el header de sección
(divisor manual + botón "Vincular"), el empty state y la `<table class="table
table-sm table-hover">`.

Reemplazar **todo ese bloque** por:

```php
        <!-- Sección: Facturas vinculadas -->
        <?php $liGrid = 'display:grid;grid-template-columns:1.1fr 1.8fr 0.9fr 1fr 1.2fr 32px;gap:12px;align-items:center;'; ?>
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i>Facturas vinculadas
                </span>
                <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>Vincular
                </button>
                <?php endif; ?>
            </div>

            <?php if ($linkedCount === 0): ?>
            <div class="empty-state">
                <div class="es-icon es-icon-neutral">
                    <i class="bi bi-inbox" aria-hidden="true"></i>
                </div>
                <div class="es-title">Sin facturas vinculadas</div>
            </div>
            <?php else: ?>
            <div class="sgi-card" style="padding:0;">
                <div style="<?= $liGrid ?>padding:9px 14px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
                    <span># Factura</span>
                    <span>Beneficiario</span>
                    <span>Fecha</span>
                    <span style="text-align:right;">Monto</span>
                    <span>Estado</span>
                    <span aria-hidden="true"></span>
                </div>
                <?php foreach ($linkedInvoices as $idx => $li): ?>
                <div class="clickable-row" role="row"
                     data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $li->id]) ?>"
                     style="<?= $liGrid ?>padding:11px 14px;background:#fff;cursor:pointer;<?= $idx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                    <span class="mono" style="font-size:12px;font-weight:700;color:var(--text-strong);">
                        <?= h($li->invoice_number ?: '#' . $li->id) ?>
                    </span>
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($li->provider->name ?? ($li->employee->full_name ?? '—')) ?>
                    </span>
                    <span class="mono" style="font-size:11.5px;color:var(--text-muted);">
                        <?= $li->issue_date?->format('d/m/Y') ?? '—' ?>
                    </span>
                    <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--text-default);">
                        $ <?= number_format((float)$li->amount, 0, ',', '.') ?>
                    </span>
                    <span>
                        <span class="pill <?= InvoicePresentation::STATUS_BADGES[$li->pipeline_status] ?? 'pill-muted' ?> pill-sm">
                            <?= h(strtoupper(InvoiceConstants::STATUS_LABELS[$li->pipeline_status] ?? $li->pipeline_status)) ?>
                        </span>
                    </span>
                    <span style="display:flex;justify-content:flex-end;">
                        <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                            ['action' => 'unlinkInvoice', $invoice->id, $li->id],
                            ['class' => 'btn-icon', 'escape' => false, 'confirm' => '¿Desvincular esta factura?', 'title' => 'Desvincular']
                        ) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <div style="<?= $liGrid ?>padding:10px 14px;background:var(--bg-subtle);border-top:1px solid var(--rule);">
                    <span style="grid-column:1 / 4;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">
                        Total vinculado
                    </span>
                    <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--primary-color);">
                        $ <?= number_format($linkedTotal, 0, ',', '.') ?>
                    </span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
```

Notas:
- El divisor manual del header (`text-uppercase fw-semibold` + barra `height:1px`)
  se sustituye por `.sgi-label` dentro de un `d-flex justify-content-between`
  (label izquierda, botón derecha) — patrón del header de `documents_section`.
- La fila es un `<div class="clickable-row" data-href="…">` (no un `<a>`) para
  poder anidar el `postLink` de desvincular sin HTML inválido; `sgi-common.js`
  maneja el click en `.clickable-row`. Es el mismo patrón que usaba la `<table>`
  legacy (`<tr class="clickable-row">` con `postLink` dentro).
- El monto pasa a formato COP entero (`number_format(..., 0, ',', '.')`) para
  alinearse al dialecto del listado; la `<table>` legacy usaba 2 decimales.
- `btn-outline-danger` (Bootstrap) → `.btn-icon` del sistema (botón-icono 28×28).
- `btn btn-sm btn-primary` del botón "Vincular" → `btn btn-default btn-sm`
  (acción secundaria sobre card; `btn-primary` se reserva a una por sección).
- El `border-top:1px solid var(--rule)` entre filas es el separador canónico del
  listado (`Invoices/index.php` lo usa) — no viola la regla "sin bordes", que
  aplica a bordes decorativos de card.

- [ ] **Step 2: Verificar y commitear**

```bash
php -l templates/Advances/legalization.php
git add templates/Advances/legalization.php
git commit -m "refactor(view): Advances/legalization — tabla de vinculadas a filas del sistema"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 3: Validación manual**

`Advances/legalization` de un anticipo con legalización: la lista de facturas
vinculadas se ve como filas con grid (no tabla Bootstrap); el total vinculado en
el footer; click en una fila abre la factura; en estado `validacion` el botón
"Vincular" abre el modal y el botón-icono × desvincula. Si no hay vinculadas, se
ve el empty state. Consola sin errores.

---

## Task 5: `Advances/legalization.php` — Soportes limpiado + observaciones al drawer

**Files:**
- Modify: `templates/Advances/legalization.php`

- [ ] **Step 1: Reemplazar el bloque "Soportes + Observaciones"**

En `templates/Advances/legalization.php`, localizar el bloque completo que empieza
con el comentario `<!-- Soportes + Observaciones -->` seguido de
`<div class="sgi-edit-side-grid">`, y termina en `</div><!-- /sgi-edit-side-grid -->`.
Contiene la card de **Soportes** y la card de **Observaciones** (chat viejo).

Reemplazar **todo ese bloque** por la card de Soportes a ancho completo, limpiada
in-situ (sin el grid lateral, sin estilos inline pesados, sin bordes decorativos):

```php
    <!-- Soportes -->
    <div class="sgi-card d-flex flex-column">
        <div class="d-flex align-items-center" style="margin-bottom:12px;">
            <span class="sgi-label d-inline-flex align-items-center gap-2">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
                Soportes
            </span>
        </div>

        <div style="max-height:420px;overflow-y:auto;">

        <!-- Documento especial: Relación de facturas -->
        <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);">
            <span class="pill pill-primary-soft">Relación de facturas</span>
        </div>
        <?php if ($relationDocument): ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi <?= h($this->DocumentIcon->iconClass($relationDocument->mime_type ?? null)) ?>"
                   style="color:<?= h($this->DocumentIcon->iconColor($relationDocument->mime_type ?? null)) ?>;font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <div title="<?= h($relationDocument->file_name ?? '') ?>"
                     style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($relationDocument->file_name ?? 'Documento') ?>
                </div>
                <div class="row-flex gap-6" style="margin-top:4px;flex-wrap:wrap;">
                    <?php if ($relationDocument->isSigned()): ?>
                    <span class="pill pill-primary-soft pill-sm">
                        <i class="bi bi-check-circle" aria-hidden="true"></i>Firmado<?php if ($relationDocument->signed_by_user): ?> · <?= h($relationDocument->signed_by_user->full_name ?? '') ?><?php endif; ?>
                    </span>
                    <?php else: ?>
                    <span class="pill pill-warning-soft pill-sm">
                        <i class="bi bi-clock" aria-hidden="true"></i>Pendiente de firma
                    </span>
                    <?php endif; ?>
                    <?php if ($relationDocument->created): ?>
                    <span class="mono sgi-body-faint" style="font-size:var(--fs-label);">
                        <?= $relationDocument->created->format('d/m/Y H:i') ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row-flex gap-4" style="flex-shrink:0;">
                <?php if (in_array($leg->status, [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS], true)): ?>
                <form id="rel-doc-update-form" class="d-inline"
                      data-upload-url="<?= $this->Url->build(['action' => 'uploadRelationDocument', $leg->advance_invoice_id]) ?>">
                <input type="file" name="relation_document" id="rel-doc-file-update" required
                       accept=".pdf,.jpg,.jpeg,.png" style="display:none;" data-rel-doc-trigger>
                <label for="rel-doc-file-update" class="btn-icon" style="cursor:pointer;" title="Reemplazar">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                </label>
                </form>
                <?php endif; ?>
                <?php if (!empty($relationDocument->file_path)): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-eye" aria-hidden="true"></i>',
                    '/' . $relationDocument->file_path,
                    ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <span class="sgi-body-faint" style="font-size:var(--fs-body-sm);">Sin documento adjunto</span>
            </div>
            <form id="rel-doc-upload-form" class="d-inline flex-shrink-0"
                  data-upload-url="<?= $this->Url->build(['action' => 'uploadRelationDocument', $leg->advance_invoice_id]) ?>">
            <input type="file" name="relation_document" id="rel-doc-file-new" required
                   accept=".pdf,.jpg,.jpeg,.png" style="display:none;" data-rel-doc-trigger>
            <label for="rel-doc-file-new" class="btn btn-default btn-sm" style="cursor:pointer;" title="Subir">
                <i class="bi bi-upload" aria-hidden="true"></i>Subir
            </label>
            </form>
        </div>
        <?php else: ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <span class="sgi-body-faint" style="font-size:var(--fs-body-sm);">Sin documento</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documento especial: Comprobante de consignación (caso faltante) -->
        <?php if ($leg->case_type === AdvanceConstants::CASE_FALTANTE && $leg->shortage_receipt_path): ?>
        <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
            <span class="pill pill-orange-soft">Comprobante de consignación</span>
        </div>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi bi-file-earmark-pdf" style="color:var(--danger-color);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($leg->shortage_receipt_number ?: 'Comprobante') ?>
                </div>
                <?php if ($leg->shortage_received_at): ?>
                <div class="mono sgi-body-faint" style="font-size:var(--fs-label);margin-top:4px;">
                    <?= h(date('d/m/Y', strtotime((string)$leg->shortage_received_at))) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="row-flex gap-4" style="flex-shrink:0;">
                <?= $this->Html->link(
                    '<i class="bi bi-eye" aria-hidden="true"></i>',
                    '/' . $leg->shortage_receipt_path,
                    ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                ) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documento especial: Historial de firmas rechazadas -->
        <?php if (!empty($signatureHistory)): ?>
        <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
            <span class="pill pill-muted">Historial de firmas</span>
        </div>
        <?php foreach ($signatureHistory as $sig): ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;opacity:.7;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi <?= h($this->DocumentIcon->iconClass($sig->mime_type ?? null)) ?>"
                   style="color:var(--text-faint);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <div title="<?= h($sig->file_name ?? '') ?>"
                     style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($sig->file_name ?? '—') ?>
                </div>
                <div class="row-flex gap-6" style="margin-top:4px;flex-wrap:wrap;">
                    <span class="pill pill-danger-soft pill-sm">Rechazado</span>
                    <?php if ($sig->rejection_reason): ?>
                    <span class="sgi-body-faint" style="font-size:var(--fs-label);"><?= h($sig->rejection_reason) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row-flex gap-4" style="flex-shrink:0;">
                <?php if (!empty($sig->file_path)): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-eye" aria-hidden="true"></i>',
                    '/' . $sig->file_path,
                    ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$relationDocument && empty($signatureHistory) && !$leg->shortage_receipt_path): ?>
        <div class="empty-state">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
            </div>
            <div class="es-title">Sin soportes adjuntos</div>
        </div>
        <?php endif; ?>

        </div>
    </div>
```

- [ ] **Step 2: Añadir el drawer de observaciones**

Localizar el cierre del grid principal `</div><!-- /sgi-invoice-view-grid -->`.
Inmediatamente **después** de esa línea, insertar el drawer compartido (queda
fuera de `<main>` y de cualquier `<form>`):

```php
<?= $this->element('observations/drawer', [
    'observations'    => $invoice->invoice_observations ?? [],
    'count'           => count($invoice->invoice_observations ?? []),
    'formUrl'         => ['controller' => 'Invoices', 'action' => 'addObservation', $invoice->id],
    'currentUserName' => $currentUser->full_name ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

- [ ] **Step 3: Eliminar `observation_chat_init`**

Localizar y **eliminar por completo** la línea:

```php
<?= $this->element('observation_chat_init') ?>
```

El drawer es autocontenido: emite su propio `<template>`, carga
`sgi-observation-chat.js` e inicializa `SgiObservationChat`. Dejar
`observation_chat_init` causaría una doble inicialización.

Notas:
- `$invoice->invoice_observations` ya viene cargado por `legalization()`
  (`contain: ['InvoiceObservations' => ['Users']]`) — era la colección que
  consumía la card de observaciones eliminada.
- `$currentUser` está declarado en el `@var` del template.
- `addObservation` vive en `InvoicesController` (el anticipo es un `Invoice`) —
  por eso `formUrl` apunta a `['controller' => 'Invoices', …]`, igual que la
  card vieja.
- El bloque `<script>` final del template (fetch de relación de facturas y de
  confirmar consignación, vía `$this->append('script')`) **no se toca**: el
  drawer también hace `append('script')` y CakePHP concatena ambos sin conflicto.
- Las clases `.doc-row`, `.row-flex`, `.col-flex`, `.grow`, `.gap-*`, `.doc-icon`,
  `.btn-icon`, `.sgi-body-faint` son las del element `document_row.php` ya en uso
  en el proyecto. Las group-label (`d-flex` + `background:var(--bg-subtle)` +
  pill) replican el patrón de `documents_section.php`.
- Cambios respecto al markup legacy: se eliminan los `border:1px solid` de las
  cajas de icono, el divisor `height:2px;background:var(--primary-color)`, los
  fondos `rgba(70,157,97,…)`, el grid lateral `sgi-edit-side-grid` (Soportes pasa
  a ancho completo) y los `btn btn-sm btn-outline-*` (Bootstrap) → `.btn-icon` /
  `.btn-default btn-sm`. Los pills sólidos pasan a variantes `-soft` (regla del
  sistema: soft en listas).

- [ ] **Step 4: Verificar y commitear**

```bash
php -l templates/Advances/legalization.php
git add templates/Advances/legalization.php
git commit -m "refactor(view): Advances/legalization — Soportes limpiado + drawer de observaciones"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 5: Validación manual**

`Advances/legalization` de un anticipo con legalización: la card de Soportes se ve
a ancho completo, con las etiquetas de grupo (Relación de facturas / Comprobante /
Historial) y filas de documento estilizadas, sin bordes de caja ni fondos verdes;
el disparador del drawer de observaciones aparece fijo al borde derecho; abrir el
drawer, publicar una observación y verificar que aparece y el contador sube. En
estado `validacion` / `revision_firmas`, el botón-icono de reemplazo y el de subir
relación de facturas siguen funcionando (suben el archivo vía AJAX y recargan).
Consola del navegador sin errores JS (sin doble init del chat).

---

## Task 6: `Advances/legalization.php` — divisores, modal, labels y botones

**Files:**
- Modify: `templates/Advances/legalization.php`

- [ ] **Step 1: Divisor manual de "Confirmar consignación" → `.sgi-label` + `.hr`**

Localizar, dentro del bloque de acciones del estado `tesoreria` / caso faltante,
el header con divisor manual:

```php
                <div class="d-flex align-items-center gap-2">
                    <span class="text-uppercase fw-semibold flex-shrink-0"
                          style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">
                        <i class="bi bi-bank me-1" aria-hidden="true"></i>Confirmar consignación
                    </span>
                    <div style="flex:1;height:1px;background:var(--border-color);"></div>
                </div>
```

Reemplazarlo por:

```php
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Confirmar consignación
                </span>
                <div class="hr"></div>
```

- [ ] **Step 2: Modal `advReturnModal` — labels y botón**

Dentro del modal `advReturnModal`, reemplazar el label Bootstrap:

```php
                        <label class="form-label">Motivo *</label>
```

por:

```php
                        <label class="input-label">Motivo *</label>
```

y el botón de envío:

```php
                        <button type="submit" class="btn btn-warning">Devolver</button>
```

por:

```php
                        <button type="submit" class="btn btn-primary">Devolver</button>
```

No se toca la estructura Bootstrap del modal (`modal` / `modal-dialog` /
`modal-content` / `modal-header` / `modal-body` / `modal-footer`): el modal se
abre con JS de Bootstrap y convertirlo al `.modal` de mockup de `overlays.md`
rompería esa integración.

- [ ] **Step 3: Resto de `.form-label` → `.input-label`**

En el resto de `templates/Advances/legalization.php` (formularios de registrar
faltante, registrar sobrante y confirmar consignación) reemplazar **todas** las
ocurrencias restantes de:

```
class="form-label"
```

por:

```
class="input-label"
```

`.form-label` es el label de Bootstrap; `.input-label` es el label del Sistema de
Diseño v2.

- [ ] **Step 4: `btn btn-warning` → `btn btn-primary`**

Reemplazar las ocurrencias restantes de `btn btn-warning` por `btn btn-primary`.
La esperada es el botón "Registrar faltante" (`btn btn-warning w-100`) → debe
quedar `btn btn-primary w-100`. (`btn-warning` no existe en el Sistema de Diseño
—solo `btn-primary/secondary/default/ghost/subtle/dashed/danger`— y renderiza con
el amarillo crudo de Bootstrap.) El `btn btn-danger` de "Registrar sobrante" se
deja: `btn-danger` **sí** es una variante del sistema.

- [ ] **Step 5: `alert alert-success` → `.banner`**

Localizar el aviso del estado legalizada:

```php
        <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
            <i class="bi bi-check-circle-fill fs-5" aria-hidden="true"></i>
            <span>
                <strong>Legalizada</strong>
                <?php if ($leg->legalized_at): ?> el <?= h(date('d/m/Y H:i', strtotime((string)$leg->legalized_at))) ?><?php endif; ?>
                <?php if ($leg->case_type): ?> — caso <strong><?= h($caseLabels[$leg->case_type] ?? $leg->case_type) ?></strong><?php endif; ?>.
            </span>
        </div>
```

Reemplazarlo por el banner del sistema (sin clase = nivel success, franja primary):

```php
        <div class="banner">
            <div class="banner-icon" style="background:var(--primary-soft);color:var(--primary-color);">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
            </div>
            <div class="banner-body">
                <div class="banner-msg">
                    <strong>Legalizada</strong>
                    <?php if ($leg->legalized_at): ?> el <?= h(date('d/m/Y H:i', strtotime((string)$leg->legalized_at))) ?><?php endif; ?>
                    <?php if ($leg->case_type): ?> — caso <strong><?= h($caseLabels[$leg->case_type] ?? $leg->case_type) ?></strong>.<?php else: ?>.<?php endif; ?>
                </div>
            </div>
        </div>
```

(El nivel success del banner no tiene regla CSS para `.banner-icon`; se le da el
soft primary inline, igual de explícito que las variantes warning/danger/info que
sí tienen regla.)

- [ ] **Step 6: Verificar y commitear**

```bash
php -l templates/Advances/legalization.php
git add templates/Advances/legalization.php
git commit -m "refactor(view): Advances/legalization — divisores, labels y botones al sistema"
```

El mensaje debe terminar con la línea `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

- [ ] **Step 7: Validación manual**

`Advances/legalization` recorriendo los estados del pipeline de legalización:
- `revision_firmas`: el modal "Devolver a Validación" abre, el label se ve como
  label del sistema y el botón "Devolver" es primario; enviarlo con motivo
  funciona.
- `contabilidad` con diferencia: el formulario de "Registrar faltante" muestra el
  label del sistema y el botón primario; "Registrar sobrante" mantiene el botón
  `danger`.
- `tesoreria` / caso faltante: el header "Confirmar consignación" se ve como
  `.sgi-label` + regla `.hr`; el formulario con sus labels del sistema.
- `legalizada`: el aviso final se ve como banner del sistema, no alerta Bootstrap.

Consola sin errores. Confirmar que las acciones de avance/retroceso del pipeline
siguen operando.

---

## Self-review (cobertura del spec)

Sección "Advances" del spec (`docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md`):

- `index` → dialecto `.sgi-row-fact` → canónico de `Invoices/index`; **añadir
  search bar** → Task 2 (template) + Task 1 (controller, para que la búsqueda
  funcione). ✔
- `view` → "ligero; observaciones si las tuviera → drawer": `view.php` no tiene
  observaciones; único cambio real = `alert-info` Bootstrap → banner → Task 3. ✔
- `legalization.php`:
  - `border:1px solid` inline → eliminados en Task 5 (cajas de icono de Soportes). ✔
  - tablas Bootstrap crudas → Task 4 (facturas vinculadas → filas del sistema). ✔
  - ~150 líneas de estilos inline en Soportes → Task 5 (limpieza in-situ). ✔
  - observaciones → drawer → Task 5. ✔
  - divisores manuales → `.sgi-label` + `.hr` → Task 4 (header vinculadas) +
    Task 6 (header "Confirmar consignación"). ✔
  - variantes de botón Bootstrap crudas → sistema → Task 4 (`btn-outline-danger`)
    + Task 5 (`btn-outline-primary/secondary`) + Task 6 (`btn-warning`). ✔
  - "modales con markup inline": el modal `advReturnModal` se mantiene como modal
    Bootstrap (su JS lo requiere); se alinean label y botón internos (Task 6).
    El shell de `advanceLinkModal` ya delega a `link_invoices_modal` compartido —
    no se toca. ✔

Consistencia de tipos / nombres:
- `$liGrid` se define en Task 4 y se usa solo dentro de ese bloque. ✔
- IDs del drawer (`#obs-form`, `#obs-count`, `#obs-chat-scroll`,
  `#obs-empty-state`) quedan una sola vez: la card vieja de observaciones se
  elimina en Task 5 antes de insertar el drawer. ✔
- `$invoice`, `$leg`, `$linkedInvoices`, `$linkedCount`, `$linkedTotal`,
  `$relationDocument`, `$signatureHistory`, `$currentUser`, `$caseLabels` son
  variables ya provistas por `AdvanceLegalizationViewModel::build()` /
  `legalization()` — usadas tal como en el template actual.

Decisiones de alcance:
- `add.php` y `link_candidates.php` fuera de alcance — no se tocan. ✔
- No existe `Advances/edit.php` — no aplica. ✔
- Cabeceras `sgi-page-title` / `sgi-page-header` / `sgi-edit-id-chip` /
  `btn-ghost-card`: preexistentes y transversales, **fuera de alcance** de esta
  migración (decisión registrada en el doc de progreso) — no se tocan.

Cierre de la migración: el trío `observation_bubble*` **no** se elimina en este
plan — sigue en uso por `NoveltyLiquidationDocs` y `Employees/view`. Se retira al
final, tras el último módulo del orden de ejecución.
