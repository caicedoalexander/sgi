<?php
/**
 * Editor de roles — cuerpo del formulario compartido por add y edit.
 *
 * Debe renderizarse DENTRO de un Form->create($role) ... Form->end().
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, string> $modules               key => label
 * @var array<string, list<string>> $moduleGroups    grupo => [keys]
 * @var array<string, array<string, bool>> $permissionsMatrix
 * @var array<string, array<string, bool>> $pipelineMatrix
 * @var array<string, string> $pipelineLabels
 * @var array<string, array<string, string>> $stepLabels
 * @var int $userCount
 * @var bool $isSystem
 */
$isNew = $role->isNew();
$actions = ['can_view' => 'Ver', 'can_create' => 'Crear', 'can_edit' => 'Editar', 'can_delete' => 'Eliminar'];
$submitLabel = $isNew ? 'Crear rol' : 'Actualizar rol';
$pipelineStepTotal = array_sum(array_map('count', $stepLabels));
$moduleTotal = count($modules);
?>

<style>
/* ─── Editor de roles (sgi-rp-*) — estilos locales de la feature ─── */
.sgi-rp-check { display:inline-flex; cursor:pointer; }
.sgi-rp-check input { position:absolute; opacity:0; width:0; height:0; }
.sgi-rp-box {
    width:22px; height:22px; border-radius:var(--radius-sm);
    background:#fff; outline:1px solid var(--border-color); outline-offset:-1px;
    display:inline-flex; align-items:center; justify-content:center;
    transition:background-color var(--t-fast) ease, outline-color var(--t-fast) ease;
}
.sgi-rp-box::after {
    content:''; width:6px; height:10px; margin-top:-2px;
    border:solid #fff; border-width:0 2.5px 2.5px 0;
    transform:rotate(45deg); display:none;
}
.sgi-rp-check input:checked + .sgi-rp-box { background:var(--primary-color); outline-color:var(--primary-color); }
.sgi-rp-check input:checked + .sgi-rp-box::after { display:block; }
.sgi-rp-check input:focus-visible + .sgi-rp-box { outline:2px solid var(--primary-color); outline-offset:1px; }

.sgi-rp-tri {
    width:18px; height:18px; padding:0; border:0; cursor:pointer;
    border-radius:2px; background:#fff; outline:1px solid var(--border-color); outline-offset:-1px;
    display:inline-flex; align-items:center; justify-content:center;
}
.sgi-rp-tri[data-state="all"] { background:var(--primary-color); outline-color:var(--primary-color); }
.sgi-rp-tri[data-state="some"] { background:var(--primary-soft); outline-color:var(--primary-color); }
.sgi-rp-tri-mark { display:none; }
.sgi-rp-tri[data-state="all"] .sgi-rp-tri-mark--check { display:block; width:5px; height:9px; border:solid #fff; border-width:0 2px 2px 0; transform:rotate(45deg) translateY(-1px); }
.sgi-rp-tri[data-state="some"] .sgi-rp-tri-mark--dash { display:block; width:8px; height:2px; background:var(--primary-color); }

.sgi-rp-preset {
    padding:4px 10px; font-size:11px; font-weight:600; white-space:nowrap;
    background:#fff; color:var(--text-muted); border:0;
    outline:1px solid var(--border-color); outline-offset:-1px;
    border-radius:var(--radius-sm); cursor:pointer; transition:all var(--t-fast) ease;
}
.sgi-rp-preset:hover { color:var(--text-default); }
.sgi-rp-preset.is-active { background:var(--primary-color); color:#fff; outline-color:var(--primary-color); }

.sgi-rp-group {
    display:flex; align-items:center; gap:8px; padding:10px 22px;
    background:var(--bg-subtle); cursor:pointer;
    font-size:11px; font-weight:700; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:0.9px;
    border-bottom:1px solid var(--rule);
}
.sgi-rp-modrow { border-bottom:1px solid var(--rule); transition:background-color var(--t-fast) ease; }
.sgi-rp-modrow:hover { background:var(--bg-muted); }

.sgi-rp-step {
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 10px; font-size:11.5px; font-weight:600;
    background:#fff; color:var(--text-muted); border:0;
    outline:1px solid var(--border-color); outline-offset:-1px;
    border-radius:var(--radius-sm); cursor:pointer; transition:all var(--t-fast) ease;
}
.sgi-rp-step.is-on { background:var(--primary-color); color:#fff; outline-color:var(--primary-color); }
.sgi-rp-step-box {
    width:14px; height:14px; border-radius:2px; flex-shrink:0;
    background:#fff; outline:1px solid var(--border-color); outline-offset:-1px;
    display:inline-flex; align-items:center; justify-content:center;
}
.sgi-rp-step.is-on .sgi-rp-step-box { background:rgba(255,255,255,0.18); outline-color:rgba(255,255,255,0.4); }
.sgi-rp-step-box::after {
    content:''; width:4px; height:8px; margin-top:-1px;
    border:solid #fff; border-width:0 2px 2px 0; transform:rotate(45deg); display:none;
}
.sgi-rp-step.is-on .sgi-rp-step-box::after { display:block; }

.sgi-rp-footer {
    position:sticky; bottom:0; z-index:5;
    margin:24px -24px -24px; padding:12px 24px;
    background:#fff; border-top:1px solid var(--rule);
    display:flex; justify-content:space-between; align-items:center;
}
.sgi-rp-input {
    width:100%; margin-top:6px; padding:9px 12px;
    border:0; outline:1px solid var(--border-color); outline-offset:-1px;
    background:#fff; border-radius:var(--radius-sm); font-family:inherit; color:var(--text-default);
}
.sgi-rp-input:focus { outline:2px solid var(--primary-color); }
</style>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:16px;">
    <div>
        <div class="d-flex align-items-center" style="gap:8px;font-size:12px;color:var(--text-muted);font-weight:600;margin-bottom:4px;">
            <?= $this->Html->link('Roles', ['action' => 'index'], ['style' => 'color:var(--text-muted);text-decoration:none;']) ?>
            <i class="bi bi-chevron-right" style="font-size:9px;color:var(--text-disabled);" aria-hidden="true"></i>
            <span style="color:var(--text-default);"><?= $isNew ? 'Nuevo rol' : h($role->name) ?></span>
        </div>
        <span class="sgi-title-page"><?= $isNew ? 'Nuevo rol' : h($role->name) ?></span>
    </div>
    <div class="d-flex align-items-center" style="gap:10px;">
        <span id="sgi-rp-dirty" class="pill pill-sm pill-warning-soft" style="display:none;">Cambios sin guardar</span>
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= h($submitLabel) ?>
        </button>
    </div>
</div>

<!-- ═══ Tarjeta de identidad ═══ -->
<div class="sgi-card" style="margin-bottom:14px;display:grid;grid-template-columns:60px 1fr 260px;gap:22px;align-items:center;">
    <span aria-hidden="true" style="width:60px;height:60px;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius-md);
        <?= $isSystem ? 'background:var(--primary-color);color:#fff;' : 'background:var(--primary-soft);color:var(--primary-color);' ?>">
        <i class="bi bi-shield-check" style="font-size:28px;"></i>
    </span>
    <div style="display:grid;grid-template-columns:1fr 1.6fr;gap:16px;min-width:0;">
        <div>
            <label class="sgi-label" for="sgi-rp-name" style="margin:0;">Nombre del rol</label>
            <input type="text" id="sgi-rp-name" name="name" class="sgi-rp-input" required
                   style="font-size:14px;font-weight:600;color:var(--text-strong);"
                   value="<?= h($role->name) ?>">
        </div>
        <div>
            <label class="sgi-label" for="sgi-rp-desc" style="margin:0;">Descripción</label>
            <input type="text" id="sgi-rp-desc" name="description" class="sgi-rp-input"
                   style="font-size:12.5px;" placeholder="¿Qué hace este rol?"
                   value="<?= h($role->description) ?>">
        </div>
    </div>
    <div class="d-flex flex-column" style="gap:8px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div>
                <div class="sgi-label" style="margin:0;">Usuarios</div>
                <div class="mono" style="font-size:18px;font-weight:800;color:var(--text-strong);margin-top:2px;line-height:1.1;">
                    <?= (int)$userCount ?>
                </div>
            </div>
            <div>
                <div class="sgi-label" style="margin:0;">Permisos</div>
                <div class="mono" style="font-size:18px;font-weight:800;color:var(--primary-color);margin-top:2px;line-height:1.1;">
                    <span id="sgi-rp-kpi-mods">0</span><span style="color:var(--text-faint);font-size:13px;font-weight:600;">/<?= $moduleTotal ?></span>
                </div>
            </div>
        </div>
        <div class="mono" style="font-size:10.5px;color:var(--text-faint);">
            <?php if ($isNew): ?>
                Rol nuevo · sin guardar
            <?php else: ?>
                Modificado <?= $role->modified?->format('d/m/Y H:i') ?: '—' ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══ Permisos por Módulo ═══ -->
<div class="sgi-card" style="padding:0;margin-bottom:14px;overflow:hidden;">
    <div class="d-flex justify-content-between align-items-start" style="gap:16px;padding:16px 22px 14px;border-bottom:1px solid var(--rule);">
        <div>
            <div style="font-size:14px;font-weight:700;color:var(--text-strong);" class="d-flex align-items-center gap-2">
                <i class="bi bi-shield-check" style="color:var(--primary-color);" aria-hidden="true"></i> Permisos por Módulo
            </div>
            <div style="font-size:11.5px;color:var(--text-faint);margin-top:4px;line-height:1.5;max-width:640px;">
                Controla las acciones disponibles en cada catálogo o módulo. Activar Crear, Editar o Eliminar habilita automáticamente Ver.
            </div>
        </div>
        <div class="d-flex" style="gap:6px;">
            <button type="button" class="sgi-rp-preset" data-global-preset="none">Sin acceso</button>
            <button type="button" class="sgi-rp-preset" data-global-preset="read">Solo lectura</button>
            <button type="button" class="sgi-rp-preset" data-global-preset="full">Acceso completo</button>
        </div>
    </div>

    <!-- Cabecera de columnas -->
    <div class="row-fact head" style="grid-template-columns:2.6fr repeat(4,100px) 230px;gap:12px;">
        <span>Módulo</span>
        <?php foreach ($actions as $key => $label): ?>
        <span class="d-inline-flex align-items-center justify-content-center" style="gap:8px;">
            <button type="button" class="sgi-rp-tri" data-tri="<?= h($key) ?>" data-state="none"
                    title="Activar/desactivar la columna <?= h($label) ?>" aria-label="Columna <?= h($label) ?>">
                <span class="sgi-rp-tri-mark sgi-rp-tri-mark--check"></span>
                <span class="sgi-rp-tri-mark sgi-rp-tri-mark--dash"></span>
            </button>
            <?= h($label) ?>
        </span>
        <?php endforeach; ?>
        <span style="text-align:right;">Preset rápido</span>
    </div>

    <?php foreach ($moduleGroups as $groupName => $moduleKeys): ?>
    <div class="sgi-rp-group" data-group="<?= h($groupName) ?>" role="button" tabindex="0">
        <i class="bi bi-chevron-down sgi-rp-group-caret" style="font-size:10px;" aria-hidden="true"></i>
        <span><?= h($groupName) ?></span>
        <span class="mono" style="color:var(--text-faint);font-size:10px;font-weight:500;">
            · <span data-group-count="<?= h($groupName) ?>">0</span>/<?= count($moduleKeys) ?>
        </span>
        <span style="flex:1;"></span>
        <span style="color:var(--text-faint);font-size:10px;font-weight:500;text-transform:none;letter-spacing:0;">
            <?= count($moduleKeys) ?> módulos
        </span>
    </div>
    <div data-group-rows="<?= h($groupName) ?>">
        <?php foreach ($moduleKeys as $modKey):
            $perm = $permissionsMatrix[$modKey] ?? [];
        ?>
        <div class="sgi-rp-modrow" data-module="<?= h($modKey) ?>"
             style="display:grid;grid-template-columns:2.6fr repeat(4,100px) 230px;gap:12px;align-items:center;padding:10px 22px;font-size:12.5px;color:var(--text-default);">
            <div class="d-flex align-items-center" style="gap:10px;min-width:0;">
                <span style="font-weight:600;"><?= h($modules[$modKey] ?? $modKey) ?></span>
                <span class="sgi-rp-rowpill"></span>
            </div>
            <?php foreach ($actions as $key => $label): ?>
            <div class="d-flex justify-content-center">
                <label class="sgi-rp-check" title="<?= h($label) ?>">
                    <input type="checkbox" class="sgi-rp-cb" data-action="<?= h($key) ?>"
                           name="permissions[<?= h($modKey) ?>][<?= h($key) ?>]" value="1"
                           <?= !empty($perm[$key]) ? 'checked' : '' ?>
                           aria-label="<?= h($label) ?> · <?= h($modules[$modKey] ?? $modKey) ?>">
                    <span class="sgi-rp-box"></span>
                </label>
            </div>
            <?php endforeach; ?>
            <div class="d-flex justify-content-end" style="gap:4px;">
                <button type="button" class="sgi-rp-preset" data-row-preset="none">Ninguno</button>
                <button type="button" class="sgi-rp-preset" data-row-preset="read">Lectura</button>
                <button type="button" class="sgi-rp-preset" data-row-preset="full">Completo</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══ Permisos de Pipeline ═══ -->
<div class="sgi-card" style="margin-bottom:14px;">
    <div class="d-flex justify-content-between align-items-start" style="gap:16px;margin-bottom:18px;">
        <div>
            <div style="font-size:14px;font-weight:700;color:var(--text-strong);" class="d-flex align-items-center gap-2">
                <i class="bi bi-diagram-3" style="color:var(--primary-color);" aria-hidden="true"></i> Permisos de Pipeline
            </div>
            <div style="font-size:11.5px;color:var(--text-faint);margin-top:4px;line-height:1.5;max-width:720px;">
                Cada paso autoriza al rol a operar esa etapa del flujo: avanzar o regresar la pieza,
                editar los campos definidos para ese paso y ver la sección correspondiente del formulario.
            </div>
        </div>
        <div class="d-flex align-items-center" style="gap:6px;font-size:11px;color:var(--text-default);white-space:nowrap;">
            <span style="width:8px;height:8px;background:var(--primary-color);border-radius:2px;"></span>
            <span><strong id="sgi-rp-kpi-steps">0</strong> de <?= $pipelineStepTotal ?> pasos activos</span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;">
        <?php foreach ($pipelineLabels as $pipeline => $pipelineLabel):
            $steps = $stepLabels[$pipeline] ?? [];
        ?>
        <div class="sgi-rp-pipe" data-pipeline="<?= h($pipeline) ?>"
             style="background:var(--bg-subtle);padding:14px 16px;min-width:0;border-left:3px solid var(--border-color);">
            <div class="d-flex justify-content-between align-items-center" style="gap:10px;margin-bottom:12px;">
                <div class="d-flex align-items-center" style="gap:8px;min-width:0;">
                    <span style="font-size:12.5px;font-weight:700;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($pipelineLabel) ?>
                    </span>
                    <span class="mono sgi-rp-pipe-count" style="font-size:10.5px;color:var(--text-faint);flex-shrink:0;">
                        0/<?= count($steps) ?>
                    </span>
                </div>
                <button type="button" class="sgi-rp-pipe-all"
                        style="background:transparent;border:0;color:var(--primary-color);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;cursor:pointer;flex-shrink:0;">
                    Activar todos
                </button>
            </div>
            <div class="d-flex flex-wrap" style="gap:6px;">
                <?php foreach ($steps as $step => $stepLabel):
                    $on = !empty($pipelineMatrix[$pipeline][$step]);
                ?>
                <button type="button" class="sgi-rp-step<?= $on ? ' is-on' : '' ?>" data-step="<?= h($step) ?>">
                    <span class="sgi-rp-step-box"></span>
                    <span><?= h($stepLabel) ?></span>
                    <input type="checkbox" class="sgi-rp-step-cb" hidden
                           name="pipeline_permissions[<?= h($pipeline) ?>][<?= h($step) ?>]" value="1"
                           <?= $on ? 'checked' : '' ?>>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══ Footer fijo ═══ -->
<div class="sgi-rp-footer">
    <div class="d-flex align-items-center" style="gap:16px;font-size:11.5px;color:var(--text-muted);">
        <span class="d-inline-flex align-items-center" style="gap:6px;">
            <span style="width:7px;height:7px;background:var(--primary-color);border-radius:50%;"></span>
            <strong style="color:var(--text-default);" id="sgi-rp-foot-mods">0</strong>&nbsp;/ <?= $moduleTotal ?> módulos
        </span>
        <span style="color:var(--text-disabled);">·</span>
        <span class="d-inline-flex align-items-center" style="gap:6px;">
            <span style="width:7px;height:7px;background:var(--secondary-color);border-radius:50%;"></span>
            <strong style="color:var(--text-default);" id="sgi-rp-foot-steps">0</strong>&nbsp;/ <?= $pipelineStepTotal ?> pasos
        </span>
        <span style="color:var(--text-disabled);">·</span>
        <span>Afecta a <strong style="color:var(--text-default);"><?= (int)$userCount ?></strong> usuario<?= (int)$userCount === 1 ? '' : 's' ?></span>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-ghost btn-sm', 'escape' => false]) ?>
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= h($submitLabel) ?>
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sgi-rp-name').closest('form');
    if (!form) { return; }

    var ACTIONS = ['can_view', 'can_create', 'can_edit', 'can_delete'];
    var modRows = Array.prototype.slice.call(form.querySelectorAll('.sgi-rp-modrow'));
    var moduleTotal = modRows.length;

    // ─── Helpers de fila de módulo ───
    function rowCb(row, action) { return row.querySelector('.sgi-rp-cb[data-action="' + action + '"]'); }
    function rowHasAny(row) { return ACTIONS.some(function (a) { return rowCb(row, a).checked; }); }
    function rowIsFull(row) { return ACTIONS.every(function (a) { return rowCb(row, a).checked; }); }
    function rowIsReadOnly(row) {
        return rowCb(row, 'can_view').checked
            && !rowCb(row, 'can_create').checked
            && !rowCb(row, 'can_edit').checked
            && !rowCb(row, 'can_delete').checked;
    }

    function paintRowPill(row) {
        var pill = row.querySelector('.sgi-rp-rowpill');
        if (rowIsFull(row)) {
            pill.className = 'sgi-rp-rowpill pill pill-sm pill-primary-soft';
            pill.textContent = 'Completo';
        } else if (rowIsReadOnly(row)) {
            pill.className = 'sgi-rp-rowpill pill pill-sm pill-info-soft';
            pill.textContent = 'Solo lectura';
        } else if (!rowHasAny(row)) {
            pill.className = 'sgi-rp-rowpill pill pill-sm pill-muted';
            pill.textContent = 'Sin acceso';
        } else {
            pill.className = 'sgi-rp-rowpill pill pill-sm pill-warning-soft';
            pill.textContent = 'Parcial';
        }
        Array.prototype.slice.call(row.querySelectorAll('.sgi-rp-preset[data-row-preset]')).forEach(function (btn) {
            var k = btn.dataset.rowPreset;
            var active = (k === 'none' && !rowHasAny(row))
                || (k === 'read' && rowIsReadOnly(row))
                || (k === 'full' && rowIsFull(row));
            btn.classList.toggle('is-active', active);
        });
    }

    // ─── Cabeceras tri-estado por columna ───
    function paintColumns() {
        ACTIONS.forEach(function (action) {
            var tri = form.querySelector('.sgi-rp-tri[data-tri="' + action + '"]');
            var on = modRows.filter(function (r) { return rowCb(r, action).checked; }).length;
            tri.dataset.state = on === 0 ? 'none' : (on === moduleTotal ? 'all' : 'some');
        });
    }

    // ─── Contadores (KPI tarjeta + footer + grupos) ───
    function paintCounts() {
        var modCount = modRows.filter(rowHasAny).length;
        document.getElementById('sgi-rp-kpi-mods').textContent = modCount;
        document.getElementById('sgi-rp-foot-mods').textContent = modCount;

        Array.prototype.slice.call(form.querySelectorAll('[data-group-count]')).forEach(function (el) {
            var group = el.getAttribute('data-group-count');
            var rows = modRows.filter(function (r) { return r.closest('[data-group-rows="' + group + '"]'); });
            el.textContent = rows.filter(rowHasAny).length;
        });

        var stepOn = form.querySelectorAll('.sgi-rp-step-cb:checked').length;
        document.getElementById('sgi-rp-kpi-steps').textContent = stepOn;
        document.getElementById('sgi-rp-foot-steps').textContent = stepOn;
    }

    // ─── Estado de los presets globales ───
    function paintGlobalPresets() {
        var allNone = modRows.every(function (r) { return !rowHasAny(r); });
        var allRead = modRows.every(rowIsReadOnly);
        var allFull = modRows.every(rowIsFull);
        Array.prototype.slice.call(form.querySelectorAll('.sgi-rp-preset[data-global-preset]')).forEach(function (btn) {
            var k = btn.dataset.globalPreset;
            btn.classList.toggle('is-active',
                (k === 'none' && allNone) || (k === 'read' && allRead) || (k === 'full' && allFull));
        });
    }

    function refresh() {
        modRows.forEach(paintRowPill);
        paintColumns();
        paintCounts();
        paintGlobalPresets();
        checkDirty();
    }

    function applyRowPreset(row, kind) {
        rowCb(row, 'can_view').checked = (kind === 'read' || kind === 'full');
        rowCb(row, 'can_create').checked = (kind === 'full');
        rowCb(row, 'can_edit').checked = (kind === 'full');
        rowCb(row, 'can_delete').checked = (kind === 'full');
    }

    // ─── Eventos: checkboxes de módulo ───
    modRows.forEach(function (row) {
        ACTIONS.forEach(function (action) {
            rowCb(row, action).addEventListener('change', function () {
                // Activar Crear/Editar/Eliminar habilita Ver automáticamente.
                if (action !== 'can_view' && this.checked) {
                    rowCb(row, 'can_view').checked = true;
                }
                refresh();
            });
        });
        Array.prototype.slice.call(row.querySelectorAll('.sgi-rp-preset[data-row-preset]')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyRowPreset(row, btn.dataset.rowPreset);
                refresh();
            });
        });
    });

    // ─── Eventos: tri-estado de columna ───
    ACTIONS.forEach(function (action) {
        var tri = form.querySelector('.sgi-rp-tri[data-tri="' + action + '"]');
        tri.addEventListener('click', function () {
            var target = tri.dataset.state !== 'all';
            modRows.forEach(function (row) {
                rowCb(row, action).checked = target;
                if (action !== 'can_view' && target) { rowCb(row, 'can_view').checked = true; }
            });
            refresh();
        });
    });

    // ─── Eventos: presets globales ───
    Array.prototype.slice.call(form.querySelectorAll('.sgi-rp-preset[data-global-preset]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
            modRows.forEach(function (row) { applyRowPreset(row, btn.dataset.globalPreset); });
            refresh();
        });
    });

    // ─── Grupos colapsables ───
    Array.prototype.slice.call(form.querySelectorAll('.sgi-rp-group')).forEach(function (header) {
        function toggle() {
            var group = header.dataset.group;
            var rows = form.querySelector('[data-group-rows="' + group + '"]');
            var caret = header.querySelector('.sgi-rp-group-caret');
            var hidden = rows.style.display === 'none';
            rows.style.display = hidden ? '' : 'none';
            caret.className = 'bi sgi-rp-group-caret ' + (hidden ? 'bi-chevron-down' : 'bi-chevron-right');
        }
        header.addEventListener('click', toggle);
        header.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
        });
    });

    // ─── Pasos de pipeline ───
    Array.prototype.slice.call(form.querySelectorAll('.sgi-rp-pipe')).forEach(function (pipe) {
        var steps = Array.prototype.slice.call(pipe.querySelectorAll('.sgi-rp-step'));
        var countEl = pipe.querySelector('.sgi-rp-pipe-count');
        var allBtn = pipe.querySelector('.sgi-rp-pipe-all');
        var total = steps.length;

        function paintPipe() {
            var on = steps.filter(function (s) { return s.querySelector('.sgi-rp-step-cb').checked; }).length;
            countEl.textContent = on + '/' + total;
            allBtn.textContent = on === total ? 'Quitar todos' : 'Activar todos';
            pipe.style.borderLeftColor = on === total
                ? 'var(--primary-color)'
                : (on === 0 ? 'var(--border-color)' : 'var(--warning-color)');
        }

        steps.forEach(function (step) {
            var cb = step.querySelector('.sgi-rp-step-cb');
            step.addEventListener('click', function (e) {
                if (e.target === cb) { return; }
                cb.checked = !cb.checked;
                step.classList.toggle('is-on', cb.checked);
                paintPipe();
                paintCounts();
                checkDirty();
            });
        });
        allBtn.addEventListener('click', function () {
            var target = steps.some(function (s) { return !s.querySelector('.sgi-rp-step-cb').checked; });
            steps.forEach(function (s) {
                s.querySelector('.sgi-rp-step-cb').checked = target;
                s.classList.toggle('is-on', target);
            });
            paintPipe();
            paintCounts();
            checkDirty();
        });
        paintPipe();
    });

    // ─── Badge "Cambios sin guardar" ───
    var dirtyBadge = document.getElementById('sgi-rp-dirty');
    function snapshot() {
        var cbs = Array.prototype.slice.call(form.querySelectorAll('.sgi-rp-cb, .sgi-rp-step-cb'))
            .map(function (c) { return c.checked ? '1' : '0'; }).join('');
        return cbs + '|' + form.querySelector('[name="name"]').value + '|' + form.querySelector('[name="description"]').value;
    }
    var initialSnapshot = snapshot();
    function checkDirty() {
        dirtyBadge.style.display = snapshot() === initialSnapshot ? 'none' : '';
    }
    form.querySelector('[name="name"]').addEventListener('input', checkDirty);
    form.querySelector('[name="description"]').addEventListener('input', checkDirty);

    refresh();
});
</script>
