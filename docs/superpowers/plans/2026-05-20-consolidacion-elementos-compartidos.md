# Consolidación de elementos compartidos — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Dejar un solo elemento compartido por concern (drawer de observaciones, `pipeline_sidebar`, sección de soportes) con la estructura y estilos de Facturas, y refactorizar Facturas para que los consuma.

**Architecture:** Se generaliza el drawer de observaciones de Facturas a un element compartido; se reescribe el element `pipeline_sidebar` al markup/clases v2 de Facturas; se extrae la sección de Soportes a un element nuevo. Luego `Invoices/edit`, `Invoices/view` y `PettyCashRecords/view` se refactorizan para consumir esos elementos.

**Tech Stack:** CakePHP 5.3 (templates PHP en `templates/element/` y `templates/`), CSS del Sistema de Diseño v2 (`webroot/css/components.css`), JS `sgi-observation-chat.js` / `sgi-document-uploader.js`.

**Spec:** `docs/superpowers/specs/2026-05-20-consolidacion-elementos-compartidos-design.md`

**Política del proyecto:** sin tests automatizados. Cada tarea cierra con **validación manual** (checklist que ejecuta el usuario levantando `php bin/cake server`) y un commit. Antes de cada commit, correr `composer cs-fix`.

---

## Hallazgos previos (afectan el plan)

1. **`pipeline_sidebar.php` usa clases CSS inexistentes.** Las clases `.sgi-hero-*` y
   `.sgi-pipeline-v*` que usa el element actual **no están definidas en ningún CSS**
   (`grep -rn "sgi-hero\|sgi-pipeline-v" webroot/` no devuelve nada). Los 6 módulos
   que hoy consumen el element renderizan el hero+pipeline sin estilos. Reescribir el
   element a `.pipeline-v` / `.pv-step` / `.sgi-card` (clases que SÍ existen,
   `components.css:1287-1339`) **también corrige ese bug**. Por eso el spec mencionaba
   "limpieza CSS de `.sgi-pipeline-v*`/`.sgi-hero-*`": **no hay nada que eliminar** —
   esas clases nunca existieron. La Tarea 9 solo verifica que no quedó CSS muerto.

2. **`Invoices/view` tiene observaciones y soportes en variantes read-only inline.**
   `Invoices/view.php` muestra observaciones como tarjeta inline enriquecida (pills de
   regresión / aprobación externa, metadata `from→to`) y soportes como lista inline
   read-only (con descarga + `uploaded_by`, sin `document_row`). Ninguna mapea
   limpiamente al drawer ni a `document_row`. Conforme al spec ("documents_section
   *si aplica*"), **`Invoices/view` solo adopta `pipeline_sidebar` en esta ronda**;
   su tarjeta de observaciones y su sección de soportes quedan inline sin cambios.

---

## Unificación cosmética aceptada

Un solo element no puede reproducir a la vez las micro-diferencias que hoy existen
entre `Invoices/view` y `Invoices/edit`. El `pipeline_sidebar` reescrito toma como
canónico el hero de `Invoices/view` (el más completo). Por tanto `Invoices/edit`
converge a esos detalles — son cambios cosméticos mínimos, **esperados y
aceptables**, y no cuentan como regresión en la validación:

- Caja de icono del hero: `var(--primary-soft)` → `var(--primary-soft-strong)`.
- Marcador de paso completado: `bi-check2` → `bi-check`.
- Monto del hero: `Invoices/edit` gana el sufijo de decimales (`,00`), como en `view`.
- Pill "Aprobada": se renderiza sin el ícono de check (texto plano).
- Pill de tipo de documento en `Invoices/edit`: `pill-secondary` → `pill-secondary-soft`
  (el element usa `pill-secondary-soft`, como `Invoices/view`).
- Monto del hero en estado `pagada` (`Invoices/view`): pierde el color verde
  (`var(--primary-color)`) y queda con el color por defecto de `.sgi-display`, como
  en `Invoices/edit`. La línea "Pagado · fecha" bajo el monto sigue señalando el pago.

Cualquier otra diferencia visual SÍ es regresión y debe corregirse.

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---|---|
| `templates/element/observations/chat_avatar.php` | **Crear.** Avatar `.chat-av` con iniciales + color por hash. |
| `templates/element/observations/chat_item.php` | **Crear.** Un `.chat-item` server-side del timeline. |
| `templates/element/observations/drawer.php` | **Crear.** Drawer flotante autocontenido, parametrizado. |
| `templates/element/pipeline_sidebar.php` | **Reescribir.** Hero + pipeline + acciones + registro con clases v2 de Facturas. |
| `templates/element/documents_section.php` | **Crear.** Card de Soportes (header + empty state + lista de `document_row`). |
| `templates/Invoices/edit.php` | **Modificar.** Consume los 3 elementos. |
| `templates/Invoices/view.php` | **Modificar.** Consume `pipeline_sidebar`. |
| `templates/PettyCashRecords/view.php` | **Modificar.** Consume `pipeline_sidebar`. |

Los elementos viejos `observation_bubble.php`, `observation_bubble_template.php` y
`observation_chat_init.php` **NO se borran en esta ronda** — siguen en uso por otros
módulos; su retiro es de la fase siguiente.

En cambio, los 3 archivos de `invoice_edit/` (`observations_drawer.php`,
`observation_chat_item.php`, `_chat_avatar.php`) los usa **únicamente** `Invoices/edit`;
quedan obsoletos en cuanto se complete la Tarea 4 y se borran ahí mismo. (Los demás
elementos de `invoice_edit/` — `_approver_chip.php`, `modify_approvers_modal.php`,
`upload_doc_modal.php`, `scripts.php` — siguen en uso y NO se tocan.)

---

## Task 1: Crear los 3 elementos de observaciones

**Files:**
- Create: `templates/element/observations/chat_avatar.php`
- Create: `templates/element/observations/chat_item.php`
- Create: `templates/element/observations/drawer.php`

Son una generalización de los archivos de `templates/element/invoice_edit/`
(`_chat_avatar.php`, `observation_chat_item.php`, `observations_drawer.php`). Los
originales NO se tocan; se crean copias generalizadas en `observations/`.

- [ ] **Step 1: Crear `templates/element/observations/chat_avatar.php`**

```php
<?php
/**
 * Avatar `.chat-av` con iniciales + color por hash del nombre.
 *
 * Fuente única del avatar del chat de observaciones compartido: lo usan tanto
 * el render server-side (observations/chat_item.php) como el <template> del JS
 * en observations/drawer.php.
 *
 * @var \App\View\AppView $this
 * @var string $name Nombre completo del autor.
 */
$palette = ['#469D61', '#CD6A15', '#83542B', '#212529', '#5a4a2a', '#4a6f5c', '#7a4c1e'];
$bg = $palette[abs(crc32($name)) % count($palette)];

$initials = '';
foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
    if ($part !== '' && strlen($initials) < 2) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
}
if ($initials === '') {
    $initials = '·';
}
?>
<span class="chat-av" style="background-color:<?= $bg ?>;"><?= h($initials) ?></span>
```

- [ ] **Step 2: Crear `templates/element/observations/chat_item.php`**

```php
<?php
/**
 * Un `.chat-item` del timeline de observaciones (drawer compartido).
 *
 * Gemelo estructural del <template id="sgi-obs-chat-item"> en
 * observations/drawer.php — los `data-slot` (user_name, message, created) y las
 * clases deben mantenerse sincronizados con ese template y con el contrato de
 * webroot/js/sgi-observation-chat.js.
 *
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $observation Entidad con id, message,
 *      created, user (full_name / username).
 */
$authorName = $observation->user->full_name
    ?? ($observation->user->username ?? 'Usuario');
?>
<div class="chat-item" data-obs-id="<?= h($observation->id) ?>">
    <?= $this->element('observations/chat_avatar', ['name' => $authorName]) ?>
    <div class="chat-body">
        <div class="chat-meta">
            <span class="chat-meta-author" data-slot="user_name"><?= h($authorName) ?></span>
            <span class="chat-meta-time" data-slot="created"><?= h($observation->created?->format('d/m/Y H:i')) ?></span>
        </div>
        <div class="chat-text" data-slot="message"><?= nl2br(h($observation->message)) ?></div>
    </div>
</div>
```

- [ ] **Step 3: Crear `templates/element/observations/drawer.php`**

```php
<?php
/**
 * Drawer flotante de Observaciones — element compartido para módulos con chat
 * de observaciones (Facturas, Anticipos, Novedades, Caja Menor, Reintegros,
 * Programaciones de Pago).
 *
 * Disparador fijo al borde derecho del viewport + Bootstrap Offcanvas con el
 * chat renderizado con el componente `.chat` del sistema de diseño.
 *
 * Autocontenido: emite su propio <template> e inicializa SgiObservationChat.
 * Conserva los IDs estándar (#obs-form, #obs-chat-scroll, #obs-empty-state,
 * #obs-count) del contrato de webroot/js/sgi-observation-chat.js.
 *
 * Restricciones de uso: incluir este element FUERA del formulario principal de
 * la vista (tiene su propio <form>); la vista anfitriona no debe renderizar
 * además otra tarjeta de Observaciones — los IDs #obs-form y #obs-count deben
 * existir una sola vez en el DOM.
 *
 * @var \App\View\AppView $this
 * @var iterable $observations  Entidades de observación (id, message, created, user).
 * @var int      $count         Número de observaciones.
 * @var array|string $formUrl   URL del POST de addObservation del módulo.
 * @var string   $currentUserName  Nombre del usuario actual (avatar del <template>).
 * @var ?string  $emptyMessage  Texto del empty state. Default: "Sin observaciones aún".
 */
$emptyMessage = $emptyMessage ?? 'Sin observaciones aún';
$observations = $observations ?? [];
$count        = $count ?? 0;
?>
<button type="button" class="sgi-obs-trigger"
        data-bs-toggle="offcanvas" data-bs-target="#obsDrawer"
        aria-label="Abrir observaciones">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
    <span id="obs-count" class="sgi-obs-trigger-badge"
          <?= $count === 0 ? 'style="display:none;"' : '' ?>><?= $count ?></span>
</button>

<div class="offcanvas offcanvas-end sgi-obs-drawer" id="obsDrawer" tabindex="-1"
     aria-labelledby="obsDrawerTitle">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="obsDrawerTitle">
            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
            Observaciones
            <span id="obs-head-count" class="chat-head-count"><?= $count ?></span>
        </h2>
        <button type="button" class="btn-icon" data-bs-dismiss="offcanvas" aria-label="Cerrar">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <div id="obs-chat-scroll" class="chat-list">
            <?php foreach ($observations as $obs): ?>
                <?= $this->element('observations/chat_item', ['observation' => $obs]) ?>
            <?php endforeach; ?>
        </div>

        <div id="obs-empty-state" class="empty-state" <?= $count > 0 ? 'hidden' : '' ?>>
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-chat-square-dots" aria-hidden="true"></i>
            </div>
            <div class="es-msg"><?= h($emptyMessage) ?></div>
        </div>

        <div class="chat-composer">
            <?= $this->Form->create(null, ['url' => $formUrl, 'id' => 'obs-form']) ?>
            <div class="chat-composer-box">
                <textarea id="obs-message" name="message" class="auto-resize chat-composer-input"
                          rows="1" placeholder="Escriba una observación..."></textarea>
                <div class="chat-composer-toolbar">
                    <button type="submit" class="btn btn-primary btn-sm">Publicar</button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php /* Gemelo estructural de observations/chat_item.php; el avatar es del
         usuario actual (SgiObservationChat marca cada mensaje nuevo como propio). */ ?>
<template id="sgi-obs-chat-item">
    <div class="chat-item" data-obs-id="">
        <?= $this->element('observations/chat_avatar', ['name' => $currentUserName]) ?>
        <div class="chat-body">
            <div class="chat-meta">
                <span class="chat-meta-author" data-slot="user_name"></span>
                <span class="chat-meta-time" data-slot="created"></span>
            </div>
            <div class="chat-text" data-slot="message"></div>
        </div>
    </div>
</template>

<?= $this->Html->script('sgi-observation-chat', ['block' => true]) ?>

<?php $this->append('script') ?>
<script>
(function () {
    var drawer = document.getElementById('obsDrawer');
    var scroll = document.getElementById('obs-chat-scroll');

    document.querySelectorAll('#obsDrawer textarea.auto-resize').forEach(function (el) {
        function sync() { el.style.height = '0px'; el.style.height = (el.scrollHeight + 2) + 'px'; }
        el.style.overflow = 'hidden';
        el.style.resize = 'none';
        sync();
        el.addEventListener('input', sync);
    });

    var box = document.querySelector('#obsDrawer .chat-composer-box');
    var ta = document.getElementById('obs-message');
    if (box && ta) {
        ta.addEventListener('focus', function () { box.classList.add('focus'); });
        ta.addEventListener('blur', function () { box.classList.remove('focus'); });
    }

    var triggerCount = document.getElementById('obs-count');
    var headCount = document.getElementById('obs-head-count');
    if (triggerCount && headCount && window.MutationObserver) {
        new MutationObserver(function () {
            var m = triggerCount.textContent.match(/(\d+)/);
            headCount.textContent = m ? m[1] : '0';
        }).observe(triggerCount, { childList: true, characterData: true, subtree: true });
    }

    if (window.SgiObservationChat) {
        SgiObservationChat.init({
            formSelector:           '#obs-form',
            listSelector:           '#obs-chat-scroll',
            emptySelector:          '#obs-empty-state',
            counterSelector:        '#obs-count',
            bubbleTemplateSelector: '#sgi-obs-chat-item',
            csrfToken:              <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>,
        });
    }

    if (drawer && scroll) {
        drawer.addEventListener('shown.bs.offcanvas', function () {
            scroll.scrollTop = scroll.scrollHeight;
        });
    }
})();
</script>
<?php $this->end() ?>
```

- [ ] **Step 4: `composer cs-fix` y commit**

Validación: los 3 archivos existen; `php -l` de cada uno sin error de sintaxis
(`php -l templates/element/observations/drawer.php`, etc.). Aún no los consume
ninguna vista — render se valida en la Tarea 4.

```bash
composer cs-fix
git add templates/element/observations/
git commit -m "feat(element): drawer de observaciones compartido"
```

---

## Task 2: Reescribir `pipeline_sidebar.php` al markup v2 de Facturas

**Files:**
- Modify: `templates/element/pipeline_sidebar.php` (reescritura completa)

Se conserva la interfaz pública actual del element (mismos parámetros) y se
**añade** un parámetro `$heroExtraHtml`. Solo cambia el markup/clases: de
`.card` / `.sgi-hero-*` / `.sgi-pipeline-v*` (inexistentes en CSS) a `.sgi-card` /
`.pipeline-v` / `.pv-step` (clases v2 reales).

- [ ] **Step 1: Reemplazar el contenido completo de `templates/element/pipeline_sidebar.php`**

```php
<?php
/**
 * Sidebar reutilizable para vistas edit/view de módulos con pipeline.
 *
 * Renderiza cards apiladas: Hero (icono + ID + estado + entidad + monto),
 * Pipeline vertical, Acciones (opcional) y Registro (opcional). Usa las clases
 * v2 del sistema de diseño (.sgi-card, .pipeline-v / .pv-step).
 *
 * El element es agnóstico a la grilla: la vista anfitriona aporta la columna.
 *
 * @var \App\View\AppView $this
 * @var string   $icon            Clase de Bootstrap Icons sin "bi bi-" (e.g. 'wallet2')
 * @var string   $idLabel         ID o código (e.g. 'CM-2026-0042')
 * @var ?string  $typeLabel       Tipo de documento (pill secondary). null para omitir.
 * @var string   $statusPill      Clase pill del estado (e.g. 'pill-warning-soft')
 * @var string   $statusLabel     Texto del estado
 * @var bool     $isRejected      Si está rechazado (pill-danger-soft "Rechazada")
 * @var ?string  $extraPillHtml   HTML adicional de pills. null para omitir.
 * @var string   $entityLabel     Label de la entidad asociada (e.g. 'Proveedor')
 * @var string   $entityValue     Nombre de la entidad
 * @var ?string  $entitySubLabel  Línea secundaria. null para omitir.
 * @var ?string  $entitySubIcon   Icono (clase bi) para entitySubLabel
 * @var ?string  $amountLabel     Label del monto. null = no muestra monto.
 * @var ?float   $amount          Monto numérico. null/0 = muestra "$ —"
 * @var ?string  $amountExtraHtml HTML pequeño bajo el monto. null para omitir.
 * @var ?string  $heroExtraHtml   HTML libre al pie del hero (fechas, pagado/saldo). null para omitir.
 * @var array    $pipelineSteps   Claves de pasos del pipeline en orden
 * @var array    $pipelineLabels  Map paso → label
 * @var string   $currentStatus   Paso actual
 * @var bool     $isTerminal      Si el estado actual es terminal
 * @var ?\DateTimeInterface $modifiedAt
 * @var array    $registryLines   Array de ['icon'=>'bi-...', 'html'=>'string'] para auditoría
 * @var ?string  $actionsHtml     HTML para la card de Acciones. null = no card.
 */
$icon            = $icon            ?? 'file-earmark-text';
$typeLabel       = $typeLabel       ?? null;
$statusPill      = $statusPill      ?? 'pill-muted';
$statusLabel     = $statusLabel     ?? '—';
$isRejected      = $isRejected      ?? false;
$extraPillHtml   = $extraPillHtml   ?? null;
$entityLabel     = $entityLabel     ?? null;
$entityValue     = $entityValue     ?? null;
$entitySubLabel  = $entitySubLabel  ?? null;
$entitySubIcon   = $entitySubIcon   ?? 'bi-geo-alt';
$amountLabel     = $amountLabel     ?? null;
$amount          = $amount          ?? null;
$amountExtraHtml = $amountExtraHtml ?? null;
$heroExtraHtml   = $heroExtraHtml   ?? null;
$pipelineSteps   = $pipelineSteps   ?? [];
$pipelineLabels  = $pipelineLabels  ?? [];
$isTerminal      = $isTerminal      ?? false;
$modifiedAt      = $modifiedAt      ?? null;
$registryLines   = $registryLines   ?? [];
$actionsHtml     = $actionsHtml     ?? null;

$currentIdx = array_search($currentStatus, $pipelineSteps, true);
if ($currentIdx === false) {
    $currentIdx = count($pipelineSteps);
}

$amountInt = $amount !== null ? number_format(floor((float)$amount), 0, ',', '.') : null;
$amountDec = $amount !== null
    ? sprintf(',%02d', (int)round(((float)$amount - floor((float)$amount)) * 100))
    : null;
?>

<!-- Hero -->
<div class="sgi-card" style="position:relative;">
    <div class="d-flex align-items-start" style="gap:12px;margin-bottom:16px;">
        <div style="width:40px;height:40px;background:var(--primary-soft-strong);color:var(--primary-color);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-<?= h($icon) ?>" aria-hidden="true" style="font-size:18px;"></i>
        </div>
        <div style="min-width:0;flex:1;">
            <div class="mono" style="font-size:16px;font-weight:700;color:var(--text-strong);line-height:1.15;">
                <?= h($idLabel) ?>
            </div>
            <div class="d-flex flex-wrap" style="gap:4px;margin-top:6px;">
                <?php if ($typeLabel): ?>
                    <span class="pill pill-secondary-soft"><?= h($typeLabel) ?></span>
                <?php endif; ?>
                <?php if ($isRejected): ?>
                    <span class="pill pill-danger-soft">Rechazada</span>
                <?php else: ?>
                    <span class="pill <?= h($statusPill) ?>"><?= h($statusLabel) ?></span>
                <?php endif; ?>
                <?= $extraPillHtml ?>
            </div>
        </div>
    </div>

    <?php if (!empty($entityLabel)): ?>
    <div class="sgi-label"><?= h($entityLabel) ?></div>
    <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);margin-top:4px;line-height:1.3;">
        <?= h($entityValue ?? '—') ?>
    </div>
    <?php if ($entitySubLabel): ?>
    <div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
        <i class="bi <?= h($entitySubIcon) ?>" aria-hidden="true" style="font-size:11px;"></i>
        <span><?= h($entitySubLabel) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($amountLabel !== null): ?>
    <div class="hr"></div>
    <div class="sgi-label"><?= h($amountLabel) ?></div>
    <div class="d-flex align-items-baseline" style="gap:4px;margin-top:4px;">
        <?php if ($amount !== null && $amount > 0): ?>
            <span class="sgi-display">$ <?= $amountInt ?></span>
            <span style="font-size:13px;color:var(--text-faint);font-weight:500;"><?= $amountDec ?></span>
        <?php else: ?>
            <span class="sgi-display" style="color:var(--text-disabled);">$ —</span>
        <?php endif; ?>
    </div>
    <?= $amountExtraHtml ?>
    <?php endif; ?>

    <?= $heroExtraHtml ?>
</div>

<!-- Pipeline vertical -->
<?php if (!empty($pipelineSteps)): ?>
<div class="sgi-card compact">
    <span class="sgi-label">Pipeline</span>
    <div class="pipeline-v" style="margin-top:8px;">
        <?php foreach ($pipelineSteps as $idx => $stepKey):
            $isDone    = $idx < $currentIdx || ($isTerminal && $idx === $currentIdx);
            $isCurrent = !$isTerminal && $idx === $currentIdx;
            $stepLabel = $pipelineLabels[$stepKey] ?? $stepKey;

            $cls = 'pv-step';
            if ($isCurrent && $isRejected) { $cls .= ' is-rejected'; }
            elseif ($isDone)               { $cls .= ' is-done'; }
            elseif ($isCurrent)            { $cls .= ' is-current'; }
            else                           { $cls .= ' is-pending'; }

            $stepMeta = null;
            if (($isCurrent || ($isTerminal && $idx === $currentIdx)) && $modifiedAt) {
                $stepMeta = $modifiedAt->format('d/m H:i');
            } elseif (!$isDone) {
                $stepMeta = 'Pendiente';
            }
        ?>
        <div class="<?= $cls ?>">
            <div class="pv-marker">
                <?php if ($isCurrent && $isRejected): ?>
                    <i class="bi bi-x" aria-hidden="true"></i>
                <?php elseif ($isDone): ?>
                    <i class="bi bi-check" aria-hidden="true"></i>
                <?php elseif ($isCurrent): ?>
                    <span class="dot"></span>
                <?php endif; ?>
            </div>
            <div style="flex:1;min-width:0;padding-top:1px;">
                <div class="pv-label"><?= h($stepLabel) ?></div>
                <?php if ($stepMeta): ?>
                    <div class="pv-meta"><?= h($stepMeta) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Acciones (opcional) -->
<?php if ($actionsHtml): ?>
<div class="sgi-card compact">
    <span class="sgi-label">Acciones</span>
    <div class="d-flex flex-column gap-1" style="margin-top:10px;">
        <?= $actionsHtml ?>
    </div>
</div>
<?php endif; ?>

<!-- Registro / Auditoría (opcional) -->
<?php if (!empty($registryLines)): ?>
<div class="sgi-card compact">
    <span class="sgi-label" style="margin-bottom:8px;display:block;">Registro</span>
    <?php foreach ($registryLines as $line): ?>
    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
        <i class="bi <?= h($line['icon'] ?? 'bi-info-circle') ?>" aria-hidden="true"></i>
        <span><?= $line['html'] ?? '' ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 2: `composer cs-fix` y commit**

```bash
composer cs-fix
git add templates/element/pipeline_sidebar.php
git commit -m "refactor(element): pipeline_sidebar con markup y clases v2 de Facturas"
```

- [ ] **Step 3: Validación manual**

`php bin/cake server` y abrir un módulo que YA consume el element (p. ej.
`Refunds/view` de un reintegro existente, o `PaymentSchedulings/view`):
1. El hero (icono, código, pills) y el pipeline vertical ahora se ven **con
   estilos** (caja de icono verde, pasos con marcador circular, líneas).
2. Antes de esta tarea se veían sin estilo — confirmar la mejora.
3. Consola del navegador sin errores.

---

## Task 3: Crear `documents_section.php`

**Files:**
- Create: `templates/element/documents_section.php`

`document_row.php` y `document_row_template.php` NO cambian. El host arma la
estructura `$groups` y la pasa lista.

- [ ] **Step 1: Crear `templates/element/documents_section.php`**

```php
<?php
/**
 * Sección de Soportes — element compartido. Card `.sgi-card` con header
 * (contador + botón "Subir" opcional), empty state (dropzone si hay subida
 * habilitada, si no `.empty-state`) y lista de `document_row` agrupada
 * opcionalmente por estado.
 *
 * Conserva los IDs #docs-list, #docs-empty-state, #docs-folder-count (contrato
 * de webroot/js/sgi-document-uploader.js). El host emite aparte el
 * `document_row_template` cuando hay subida.
 *
 * @var \App\View\AppView $this
 * @var array   $groups        Lista de grupos. Cada grupo:
 *                              ['label'=>?string, 'pillKind'=>?string, 'rows'=>array].
 *                              Cada row es el array de params de element('document_row').
 * @var int     $totalDocs     Conteo total para el .sgi-folder-count.
 * @var bool    $canUpload     true → empty state como .dropzone; false → .empty-state.
 * @var ?string $uploadModalId Id del modal de subida (target del botón y la dropzone).
 * @var ?string $emptyTitle    Título del empty state. Default: "Sin soportes adjuntos".
 */
$groups        = $groups ?? [];
$totalDocs     = (int)($totalDocs ?? 0);
$canUpload     = $canUpload ?? false;
$uploadModalId = $uploadModalId ?? null;
$emptyTitle    = $emptyTitle ?? 'Sin soportes adjuntos';

$hasDocs = false;
foreach ($groups as $g) {
    if (!empty($g['rows'])) { $hasDocs = true; break; }
}
$showUpload = $canUpload && $uploadModalId !== null;
?>
<div class="sgi-card d-flex flex-column">
    <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
        <span class="sgi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            Soportes
            <span id="docs-folder-count" class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUpload): ?>
        <button type="button" class="btn btn-default btn-sm"
                data-bs-toggle="modal" data-bs-target="#<?= h($uploadModalId) ?>">
            <i class="bi bi-upload" aria-hidden="true"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <?php if ($showUpload): ?>
    <div id="docs-empty-state" class="dropzone"
         data-bs-toggle="modal" data-bs-target="#<?= h($uploadModalId) ?>"
         style="cursor:pointer;<?= $hasDocs ? 'display:none;' : '' ?>">
        <i class="bi bi-paperclip" aria-hidden="true"></i>
        <div>Arrastra archivos o <a class="dz-link">examina</a></div>
        <div class="dz-hint">PDF, JPG, PNG · máximo 10 MB por archivo</div>
    </div>
    <?php else: ?>
    <div id="docs-empty-state" class="empty-state" <?= $hasDocs ? 'style="display:none;"' : '' ?>>
        <div class="es-icon es-icon-neutral">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
        </div>
        <div class="es-title"><?= h($emptyTitle) ?></div>
    </div>
    <?php endif; ?>

    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php foreach ($groups as $group): ?>
            <?php if (!empty($group['label'])): ?>
            <div class="d-flex align-items-center gap-2"
                 style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
                <span class="pill <?= h($group['pillKind'] ?? 'pill-muted') ?>"><?= h($group['label']) ?></span>
                <span style="font-size:var(--fs-label);color:var(--text-faint);">
                    <?= count($group['rows'] ?? []) ?> archivo<?= count($group['rows'] ?? []) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <?php endif; ?>
            <?php foreach ($group['rows'] ?? [] as $rowParams): ?>
                <?= $this->element('document_row', $rowParams) ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>
```

- [ ] **Step 2: `composer cs-fix` y commit**

```bash
composer cs-fix
git add templates/element/documents_section.php
git commit -m "feat(element): sección de soportes compartida"
```

Validación: `php -l templates/element/documents_section.php` sin error. El render
se valida en la Tarea 5 (lo consume `Invoices/edit`).

---

## Task 4: `Invoices/edit.php` — adoptar el drawer de observaciones compartido

**Files:**
- Modify: `templates/Invoices/edit.php` (cerca de la línea 1039)
- Delete: `templates/element/invoice_edit/observations_drawer.php`
- Delete: `templates/element/invoice_edit/observation_chat_item.php`
- Delete: `templates/element/invoice_edit/_chat_avatar.php`

- [ ] **Step 1: Reemplazar la invocación del drawer**

Buscar la llamada actual al drawer (cerca de la línea 1039):

```php
<?= $this->element('invoice_edit/observations_drawer', [
    'invoice' => $invoice,
    'currentUser' => $currentUser,
]) ?>
```

Reemplazarla por:

```php
<?= $this->element('observations/drawer', [
    'observations'    => $invoice->invoice_observations ?? [],
    'count'           => is_array($invoice->invoice_observations ?? null)
        ? count($invoice->invoice_observations) : 0,
    'formUrl'         => ['action' => 'addObservation', $invoice->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

Nota: el comentario adyacente (`El chat de observaciones es autocontenido…`) se
mantiene.

- [ ] **Step 2: Borrar los 3 elementos de `invoice_edit/` ahora obsoletos**

Tras el Step 1, `Invoices/edit` ya no es consumidor de ellos y ninguna otra vista
los usa (verificable con `grep -rn "invoice_edit/observations_drawer\|invoice_edit/observation_chat_item\|invoice_edit/_chat_avatar" templates/`, que debe quedar sin resultados).

```bash
git rm templates/element/invoice_edit/observations_drawer.php \
       templates/element/invoice_edit/observation_chat_item.php \
       templates/element/invoice_edit/_chat_avatar.php
```

- [ ] **Step 3: `composer cs-fix` y commit**

```bash
composer cs-fix
git add templates/Invoices/edit.php
git commit -m "refactor(view): Invoices/edit usa el drawer de observaciones compartido"
```

- [ ] **Step 4: Validación manual**

`php bin/cake server`, abrir `Invoices/edit` de una factura:
1. El disparador de observaciones aparece fijo al borde derecho.
2. Abrir el drawer: lista de observaciones existentes con avatares, autor, fecha.
3. Publicar una observación nueva → aparece en el chat, el badge del disparador y
   el contador del header suben, el empty state se oculta si estaba.
4. Consola del navegador sin errores JS.

---

## Task 5: `Invoices/edit.php` — adoptar `documents_section`

**Files:**
- Modify: `templates/Invoices/edit.php` (bloque de Soportes, ~líneas 909-971)

- [ ] **Step 1: Reemplazar el bloque inline de Soportes**

Localizar el bloque que empieza en el comentario `<?php /* ── Soportes (ancho
completo; …) ── */ ?>` y la `<div class="sgi-card d-flex flex-column">` siguiente,
hasta su `</div>` de cierre (antes del comentario `── Log de correos ──`).

Reemplazar **todo ese `<div class="sgi-card …">…</div>`** por: primero la
construcción de `$groups`, luego la llamada al element. El bloque hoy itera
`$documentsByStatus` y construye filas con los mismos parámetros que ya pasa a
`element('document_row', [...])`. Trasladar esa lógica a un array `$groups`:

```php
<?php /* ── Soportes (ancho completo; Observaciones vive en el drawer) ── */ ?>
<?php
$docGroups = [];
foreach ($documentsByStatus as $status => $docs) {
    $rows = [];
    foreach ($docs as $doc) {
        $rows[] = [
            'doc'          => $doc,
            'canDelete'    => $viewModel->canDeleteDocuments
                && $doc->pipeline_status === $currentStatus,
            'deleteUrl'    => $this->Url->build(
                ['action' => 'deleteDocument', $invoice->id, $doc->id]
            ),
            'showBadge'    => !$multipleDocStatuses,
            'badgeColors'  => $badgeColors,
            'statusLabels' => $statusLabels,
        ];
    }
    $docGroups[] = [
        'label'    => $multipleDocStatuses ? ($statusLabels[$status] ?? $status) : null,
        'pillKind' => $multipleDocStatuses ? ($statusPills[$status] ?? 'pill-muted') : null,
        'rows'     => $rows,
    ];
}
?>
<?= $this->element('documents_section', [
    'groups'        => $docGroups,
    'totalDocs'     => $totalDocs,
    'canUpload'     => $showUploadSection,
    'uploadModalId' => 'uploadInvoiceDocModal',
    'emptyTitle'    => 'Sin soportes adjuntos',
]) ?>
```

Notas:
- Las variables `$documentsByStatus`, `$multipleDocStatuses`, `$badgeColors`,
  `$statusLabels`, `$statusPills`, `$totalDocs`, `$showUploadSection`,
  `$currentStatus`, `$viewModel` ya existen en `Invoices/edit.php`. No se crean
  nuevas; solo se reorganiza el render.
- `document_row_template` se sigue emitiendo aparte (cerca de la línea 1044) — NO
  se toca.
- El modal `uploadInvoiceDocModal` se sigue incluyendo aparte — NO se toca.

- [ ] **Step 2: `composer cs-fix` y commit**

```bash
composer cs-fix
git add templates/Invoices/edit.php
git commit -m "refactor(view): Invoices/edit usa el element documents_section"
```

- [ ] **Step 3: Validación manual**

`Invoices/edit` de una factura:
1. La sección "Soportes" se ve idéntica a antes: header con contador, botón
   "Subir" (si aplica), dropzone o empty state, filas de documentos.
2. Subir un soporte → aparece la fila, el `.sgi-folder-count` se actualiza, el
   empty state se oculta.
3. Eliminar un soporte → la fila desaparece.
4. Consola sin errores.

---

## Task 6: `Invoices/edit.php` — adoptar `pipeline_sidebar`

**Files:**
- Modify: `templates/Invoices/edit.php` (columna izquierda `<aside>`, ~líneas 253-388)

- [ ] **Step 1: Reemplazar el contenido del `<aside>` izquierdo**

El `<aside class="col-lg-3 sgi-edit-col d-flex flex-column gap-3">` contiene hoy
tres cards inline: Hero (`── Hero: resumen … ──`), Pipeline (`── Pipeline
vertical ──`) y Acciones de etapa (`── Acciones de etapa ──`, condicional a
`$canRegress`). Reemplazar **los tres** por: la construcción de `$heroExtraHtml`
y `$stageActionsHtml`, y una sola llamada al element. El `<aside>` y su `</aside>`
se conservan.

```php
<aside class="col-lg-3 sgi-edit-col d-flex flex-column gap-3">

    <?php
    // Bloque Pagado/Saldo bajo el monto del hero (cuando aplica).
    $heroExtraHtml = '';
    if ($totalPagado > 0 || $invoiceAmount > 0) {
        ob_start(); ?>
        <div class="d-flex" style="gap:18px;margin-top:12px;">
            <div>
                <div class="sgi-label" style="font-size:var(--fs-micro);">Pagado</div>
                <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;color:var(--primary-color);margin-top:2px;">
                    $ <?= number_format($totalPagado, 0, ',', '.') ?>
                </div>
            </div>
            <div>
                <div class="sgi-label" style="font-size:var(--fs-micro);">Saldo</div>
                <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;margin-top:2px;color:<?= $saldo > 0 ? 'var(--secondary-color)' : 'var(--primary-color)' ?>;">
                    $ <?= number_format(max(0, $saldo), 0, ',', '.') ?>
                </div>
            </div>
        </div>
        <?php
        $heroExtraHtml = ob_get_clean();
    }

    // Acciones de etapa (regresión) — solo si $canRegress.
    $stageActionsHtml = null;
    if ($canRegress) {
        $prevLabel     = $viewModel->pipelineLabels[$viewModel->previousStatus]
            ?? $viewModel->previousStatus;
        $regressLocked = !empty($viewModel->regressLockMessage);
        ob_start(); ?>
        <?php if ($regressLocked): ?>
            <button type="button" class="btn btn-ghost btn-sm w-100 justify-content-start"
                    disabled title="<?= h($viewModel->regressLockMessage) ?>">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar al paso anterior
            </button>
        <?php else: ?>
            <button type="button" class="btn btn-ghost btn-sm w-100 justify-content-start"
                    data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
            </button>
        <?php endif;
        $stageActionsHtml = ob_get_clean();
    }

    // Pill de estado: preserva las 3 variantes del hero actual
    // (rechazada → la maneja el element vía isRejected; aprobada; estado del pipeline).
    // El hero de Invoices/edit no muestra pill "Pago Parcial" (esa info va en el
    // bloque Pagado/Saldo de $heroExtraHtml).
    $heroStatusPill  = $statusPill;
    $heroStatusLabel = $currentLabel;
    if (!$viewModel->isRejected && $viewModel->isApproved) {
        $heroStatusPill  = 'pill-primary-soft';
        $heroStatusLabel = 'Aprobada';
    }
    ?>

    <?= $this->element('pipeline_sidebar', [
        'icon'          => $isAdvance ? 'cash-coin' : 'file-earmark-text',
        'idLabel'       => $idLabel,
        'typeLabel'     => $invoice->document_type,
        'statusPill'    => $heroStatusPill,
        'statusLabel'   => $heroStatusLabel,
        'isRejected'    => $viewModel->isRejected,
        'entityLabel'   => $isAdvance ? 'Beneficiario' : 'Proveedor',
        'entityValue'   => $beneficiaryName,
        'entitySubLabel' => $invoice->hasValue('operation_center')
            ? $invoice->operation_center->name : null,
        'amountLabel'   => $isAdvance ? 'Valor Anticipo' : 'Valor Factura',
        'amount'        => $invoiceAmount,
        'heroExtraHtml' => $heroExtraHtml,
        'pipelineSteps'  => $pipelineSteps,
        'pipelineLabels' => $viewModel->pipelineLabels,
        'currentStatus'  => $currentStatus,
        'isTerminal'     => $isTerminal,
        'modifiedAt'     => $invoice->modified,
        'actionsHtml'    => $stageActionsHtml,
    ]) ?>

</aside>
```

Notas:
- La pill "Aprobada" del hero se preserva con el precómputo
  `$heroStatusPill`/`$heroStatusLabel` de arriba (el ícono de check se omite — ver
  "Unificación cosmética aceptada").
- Variables ya existentes en `Invoices/edit.php`: `$isAdvance`, `$idLabel`,
  `$invoice`, `$statusPill`, `$currentLabel`, `$viewModel`, `$beneficiaryName`,
  `$invoiceAmount`, `$totalPagado`, `$saldo`, `$pipelineSteps`, `$currentStatus`,
  `$isTerminal`, `$canRegress`, `$currentStatus`. No se crean nuevas.
- El element renderiza el hero con `var(--primary-soft-strong)` en la caja de
  icono (unifica una diferencia cosmética mínima preexistente entre view y edit;
  es aceptable).

- [ ] **Step 2: `composer cs-fix` y commit**

```bash
composer cs-fix
git add templates/Invoices/edit.php
git commit -m "refactor(view): Invoices/edit usa el element pipeline_sidebar"
```

- [ ] **Step 3: Validación manual**

`Invoices/edit` de varias facturas (una normal, una rechazada, una en pago
parcial, una legalización):
1. Hero: icono, código, pills de tipo/estado, proveedor/beneficiario, centro de
   operación, monto, y bloque Pagado/Saldo cuando aplica — idénticos a antes.
2. Pipeline vertical: pasos done/current/pending/rejected con sus marcadores.
3. Card "Acciones" con el botón de regresión solo cuando `$canRegress`.
4. Consola sin errores.

---

## Task 7: `Invoices/view.php` — adoptar `pipeline_sidebar`

**Files:**
- Modify: `templates/Invoices/view.php` (columna izquierda `<aside>`, ~líneas 167-342)

`Invoices/view` solo adopta `pipeline_sidebar`. Su tarjeta de observaciones y su
sección de soportes (read-only inline) **no se tocan** (ver Hallazgo 2).

- [ ] **Step 1: Reemplazar el contenido del `<aside>` izquierdo**

El `<aside>` izquierdo (`── COLUMNA IZQUIERDA ──`) contiene Hero card, Pipeline
card y "Acciones rápidas". Reemplazar las tres cards por la construcción de los
slots y la llamada al element, conservando `<aside>`/`</aside>`.

```php
<aside style="display:flex;flex-direction:column;gap:14px;min-width:0;">

    <?php
    // Slot del hero: divisor + fechas (Emisión / Vencimiento / Registro).
    ob_start(); ?>
    <div class="hr"></div>
    <div class="field-row">
        <span class="k">Emisión</span>
        <span class="v mono"><?= $invoice->issue_date?->format('d/m/Y') ?? '—' ?></span>
    </div>
    <div class="field-row">
        <span class="k">Vencimiento</span>
        <?php
        $isOverdue = $invoice->due_date && $invoice->due_date < new \DateTimeImmutable('today')
            && $currentStatus !== InvoiceConstants::STATUS_PAGADA;
        ?>
        <span class="v mono" style="<?= $isOverdue ? 'color:var(--danger-color);' : '' ?>">
            <?= $invoice->due_date?->format('d/m/Y') ?? '—' ?>
        </span>
    </div>
    <div class="field-row is-last">
        <span class="k">Registro</span>
        <span class="v mono"><?= $invoice->registration_date?->format('d/m/Y') ?? '—' ?></span>
    </div>
    <?php
    $heroExtraHtml = ob_get_clean();

    // Línea pequeña bajo el monto (pago completo / parcial).
    $amountExtraHtml = '';
    if ($currentStatus === InvoiceConstants::STATUS_PAGADA && $invoice->full_payment_date) {
        $amountExtraHtml = '<div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">'
            . '<i class="bi bi-check-circle sgi-fg-primary" aria-hidden="true" style="font-size:11px;"></i>'
            . '<span>Pagado · <span class="mono">' . h($invoice->full_payment_date->format('d/m/Y')) . '</span></span></div>';
    } elseif ($invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL && $pagosCount > 0) {
        $amountExtraHtml = '<div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">'
            . '<i class="bi bi-clock sgi-fg-warning" aria-hidden="true" style="font-size:11px;"></i>'
            . '<span>Pago parcial · <span class="mono">$ ' . number_format($pagosTotal, 0, ',', '.') . '</span></span></div>';
    }

    // Acciones rápidas (Editar / PDF / Volver).
    $quickActions = [];
    if ($canShowEdit) {
        $quickActions[] = ['icon' => 'bi-pencil', 'label' => 'Editar factura',
            'url' => $this->Url->build(['action' => 'edit', $invoice->id])];
    }
    $quickActions[] = ['icon' => 'bi-file-pdf', 'label' => 'Descargar PDF', 'url' => '#'];
    $quickActions[] = ['icon' => 'bi-arrow-left', 'label' => 'Volver al listado',
        'url' => $this->Url->build(['action' => 'index'])];
    ob_start();
    foreach ($quickActions as $a) {
        echo $this->Html->link(
            '<i class="bi ' . h($a['icon']) . '" aria-hidden="true"></i><span>' . h($a['label']) . '</span>',
            $a['url'],
            ['class' => 'btn btn-ghost btn-sm', 'escape' => false,
             'style' => 'justify-content:flex-start;width:100%;gap:8px;']
        );
    }
    $quickActionsHtml = ob_get_clean();

    // Pill extra del hero (Pago Parcial).
    $heroExtraPill = null;
    if ($currentStatus === InvoiceConstants::STATUS_TESORERIA
        && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL) {
        $heroExtraPill = '<span class="pill pill-warning-soft">Pago Parcial</span>';
    }

    // Pill de estado: preserva las 3 variantes del hero actual
    // (rechazada → la maneja el element vía isRejected; aprobada; estado del pipeline).
    $heroStatusPill  = $statusPill;
    $heroStatusLabel = $statusLabel;
    if (!$isRejected && $isApproved) {
        $heroStatusPill  = 'pill-primary-soft';
        $heroStatusLabel = 'Aprobada';
    }
    ?>

    <?= $this->element('pipeline_sidebar', [
        'icon'          => 'file-earmark-text',
        'idLabel'       => $invoice->invoice_number ?? ('#' . $invoice->id),
        'typeLabel'     => $invoice->document_type,
        'statusPill'    => $heroStatusPill,
        'statusLabel'   => $heroStatusLabel,
        'isRejected'    => $isRejected,
        'extraPillHtml' => $heroExtraPill,
        'entityLabel'   => 'Proveedor',
        'entityValue'   => $providerName,
        'entitySubLabel' => $invoice->hasValue('operation_center')
            ? $invoice->operation_center->name : null,
        'amountLabel'   => 'Valor Factura',
        'amount'        => $amountFmt,
        'amountExtraHtml' => $amountExtraHtml,
        'heroExtraHtml' => $heroExtraHtml,
        'pipelineSteps'  => $pipelineSteps,
        'pipelineLabels' => $pipelineLabels,
        'currentStatus'  => $currentStatus,
        'isTerminal'     => in_array($currentStatus,
            [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA], true),
        'modifiedAt'     => $invoice->modified,
        'actionsHtml'    => $quickActionsHtml,
    ]) ?>

</aside>
```

Notas:
- `$amountFmt` (= `(float)$invoice->amount`, definida en "Datos derivados de
  presentación", `Invoices/view.php:50`) es el monto numérico crudo; el element
  calcula entero/decimales.
- La pill "Aprobada" se preserva con el precómputo `$heroStatusPill`/
  `$heroStatusLabel` (el ícono de check se omite — ver "Unificación cosmética
  aceptada").
- El `<aside>` izquierdo cierra justo después de "Acciones rápidas"
  (`Invoices/view.php:338`); no hay card de "Registro" que conservar.
- Variables ya existentes: `$invoice`, `$currentStatus`, `$statusPill`,
  `$statusLabel`, `$isRejected`, `$isApproved`, `$providerName`, `$pipelineSteps`,
  `$pipelineLabels`, `$canShowEdit`, `$pagosCount`, `$pagosTotal`, `$amountFmt`.

- [ ] **Step 2: `composer cs-fix` y commit**

```bash
composer cs-fix
git add templates/Invoices/view.php
git commit -m "refactor(view): Invoices/view usa el element pipeline_sidebar"
```

- [ ] **Step 3: Validación manual**

`Invoices/view` de varias facturas (normal, pagada, rechazada):
1. Columna izquierda: hero (con fechas Emisión/Vencimiento/Registro y línea de
   pago), pipeline vertical y "Acciones rápidas" — idénticos a antes.
2. Las secciones de Observaciones e Historial del panel derecho **siguen igual**.
3. Consola sin errores.

---

## Task 8: `PettyCashRecords/view.php` — adoptar `pipeline_sidebar`

**Files:**
- Modify: `templates/PettyCashRecords/view.php` (columna izquierda `<aside>`, ~líneas 100-222)

- [ ] **Step 1: Reemplazar Hero card + Pipeline card + Acciones rápidas**

El `<aside>` izquierdo contiene Hero card (~103-146), Pipeline card (~149-189),
"Acciones rápidas" (~191-222) y, después, la card "Registro / auditoría" (~225).
Reemplazar **solo las tres primeras** por la llamada al element; la card
"Registro / auditoría" y el `</aside>` se conservan **tras** la llamada.

```php
<aside style="display:flex;flex-direction:column;gap:14px;min-width:0;">

    <?php
    // Acciones rápidas (Editar registro / Volver).
    $quickActions = [];
    if ($canEdit) {
        $quickActions[] = ['icon' => 'bi-pencil', 'label' => 'Editar registro',
            'url' => $this->Url->build(['action' => 'edit', $record->id])];
    }
    $quickActions[] = ['icon' => 'bi-arrow-left', 'label' => 'Volver al listado',
        'url' => $this->Url->build(['action' => 'index'])];
    ob_start();
    foreach ($quickActions as $a) {
        echo $this->Html->link(
            '<i class="bi ' . h($a['icon']) . '" aria-hidden="true"></i><span>' . h($a['label']) . '</span>',
            $a['url'],
            ['class' => 'btn btn-ghost btn-sm', 'escape' => false,
             'style' => 'justify-content:flex-start;width:100%;gap:8px;']
        );
    }
    $quickActionsHtml = ob_get_clean();

    // Línea "Pagado" bajo el monto, cuando el registro está en estado terminal.
    $amountExtraHtml = '';
    if ($isTerminal && $record->payment_date) {
        $amountExtraHtml = '<div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:6px;">'
            . '<i class="bi bi-check-circle sgi-fg-primary" aria-hidden="true" style="font-size:11px;"></i>'
            . '<span>Pagado · <span class="mono">' . h($record->payment_date->format('d/m/Y')) . '</span></span></div>';
    }
    ?>

    <?= $this->element('pipeline_sidebar', [
        'icon'          => 'wallet2',
        'idLabel'       => $record->code,
        'typeLabel'     => 'Caja Menor',
        'statusPill'    => $pcStatusPill,
        'statusLabel'   => $pcStatusLabel,
        'isRejected'    => false,
        'entityLabel'   => 'Centro de Operación',
        'entityValue'   => $record->operation_center->name ?? '—',
        'entitySubLabel' => $invoiceCount . ' factura' . ($invoiceCount !== 1 ? 's' : ''),
        'entitySubIcon'  => 'bi-receipt',
        'amountLabel'   => 'Total',
        'amount'        => $amountFmt,
        'amountExtraHtml' => $amountExtraHtml,
        'pipelineSteps'  => $pipelineSteps,
        'pipelineLabels' => $statusLabels,
        'currentStatus'  => $currentStatus,
        'isTerminal'     => $isTerminal,
        'modifiedAt'     => $record->modified,
        'actionsHtml'    => $quickActionsHtml,
    ]) ?>

    <?php /* La card "Registro / auditoría" existente y el </aside> se conservan
             tal cual a continuación de esta llamada. */ ?>
```

Notas:
- `typeLabel` "Caja Menor" produce `pill pill-secondary-soft` (el element usa esa
  clase para `typeLabel`); el inline actual también usa `pill-secondary-soft`.
- `$amountFmt` (= `(float)$record->total_amount`, `PettyCashRecords/view.php:42`)
  es el monto numérico crudo; el element calcula entero/decimales.
- El element calcula su propio índice de paso a partir de `pipelineSteps` +
  `currentStatus`; `$currentStatus` (= `$record->status`) existe en
  `PettyCashRecords/view.php:24`.
- La card "Registro / auditoría" (`PettyCashRecords/view.php:225`) y el `</aside>`
  se conservan tras la llamada al element.
- Variables ya existentes: `$record`, `$canEdit`, `$isTerminal`, `$pcStatusPill`,
  `$pcStatusLabel`, `$invoiceCount`, `$pipelineSteps`, `$statusLabels`,
  `$currentStatus`, `$amountFmt`.

- [ ] **Step 2: `composer cs-fix` y commit**

```bash
composer cs-fix
git add templates/PettyCashRecords/view.php
git commit -m "refactor(view): PettyCashRecords/view usa el element pipeline_sidebar"
```

- [ ] **Step 3: Validación manual**

`PettyCashRecords/view` de varios registros:
1. Hero (icono wallet, código, pills, centro de operación, conteo de facturas,
   total, línea "Pagado" si terminal), pipeline vertical y "Acciones rápidas" —
   idénticos a antes.
2. La card "Registro / auditoría" sigue igual.
3. Consola sin errores.

---

## Task 9: Verificación final y cierre

**Files:** ninguno (solo verificación).

- [ ] **Step 1: Verificar que no quedó CSS muerto introducido**

```bash
grep -rn "sgi-pipeline-v\|sgi-hero-" webroot/css/ templates/
```
Esperado: sin resultados en `templates/` (el element reescrito ya no usa esas
clases) y sin resultados en `webroot/css/` (nunca existieron). Si aparecen en
otros templates de módulos, son consumidores indirectos vía `pipeline_sidebar` y
ya quedaron resueltos al reescribir el element — no requieren acción aquí.

- [ ] **Step 2: Recorrido manual completo**

`php bin/cake server` y verificar, sin errores de consola:
- `Invoices/index` — sin cambios, se ve igual.
- `Invoices/view` — hero/pipeline/acciones vía element; observaciones e historial
  intactos.
- `Invoices/edit` — hero/pipeline/acciones, soportes y drawer de observaciones
  vía elementos; publicar observación y subir/eliminar soporte funcionan.
- `PettyCashRecords/view` — hero/pipeline/acciones vía element.
- Un módulo consumidor preexistente del element (`Refunds/view` o
  `PaymentSchedulings/view`) — el pipeline ahora se ve correctamente estilizado.

- [ ] **Step 3: Confirmar estado del repositorio**

```bash
git log --oneline -9
git status
```
Esperado: 8 commits de las tareas 1-8, working tree limpio.

---

## Self-review (cobertura del spec)

- Elemento 1 (drawer de observaciones) → Tareas 1 y 4. ✔
- Elemento 2 (`pipeline_sidebar` unificado + `$heroExtraHtml`) → Tareas 2, 6, 7, 8. ✔
- Elemento 3 (`documents_section`) → Tareas 3 y 5. ✔
- Refactor de Facturas (`edit` + `view`) → Tareas 4, 5, 6, 7. ✔
- Adopción en `PettyCashRecords/view` → Tarea 8. ✔
- Limpieza CSS → Tarea 9 (resultó innecesaria; ver Hallazgo 1). ✔
- Criterios de validación manual del spec → pasos de validación de cada tarea +
  Tarea 9. ✔

Desviaciones respecto al spec, justificadas en "Hallazgos previos":
- No hay CSS que eliminar (`.sgi-pipeline-v*`/`.sgi-hero-*` nunca existieron).
- `Invoices/view` no adopta `documents_section` ni convierte sus observaciones a
  drawer: ambas son variantes read-only inline que no mapean a los elementos
  compartidos; el spec ya lo preveía con "documents_section *si aplica*".
