<?php
/**
 * Sidebar reutilizable para vistas edit/view de módulos con pipeline.
 *
 * Renderiza cards apiladas: Hero (icono + ID + estado + entidad + monto),
 * Pipeline vertical, Acciones (opcional) y Registro (opcional). Usa las clases
 * v2 del sistema de diseño (.spi-card, .pipeline-v / .pv-step).
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
<div class="spi-card" style="position:relative;">
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
    <div class="spi-label"><?= h($entityLabel) ?></div>
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
    <div class="spi-label"><?= h($amountLabel) ?></div>
    <div class="d-flex align-items-baseline" style="gap:4px;margin-top:4px;">
        <?php if ($amount !== null && $amount > 0): ?>
            <span class="spi-display">$ <?= $amountInt ?></span>
            <span style="font-size:13px;color:var(--text-faint);font-weight:500;"><?= $amountDec ?></span>
        <?php else: ?>
            <span class="spi-display" style="color:var(--text-disabled);">$ —</span>
        <?php endif; ?>
    </div>
    <?= $amountExtraHtml ?>
    <?php endif; ?>

    <?= $heroExtraHtml ?>
</div>

<!-- Pipeline vertical -->
<?php if (!empty($pipelineSteps)): ?>
<div class="spi-card compact">
    <span class="spi-label">Pipeline</span>
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
<div class="spi-card compact">
    <span class="spi-label">Acciones</span>
    <div class="d-flex flex-column gap-1" style="margin-top:10px;">
        <?= $actionsHtml ?>
    </div>
</div>
<?php endif; ?>

<!-- Registro / Auditoría (opcional) -->
<?php if (!empty($registryLines)): ?>
<div class="spi-card compact">
    <span class="spi-label" style="margin-bottom:8px;display:block;">Registro</span>
    <?php foreach ($registryLines as $line): ?>
    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
        <i class="bi <?= h($line['icon'] ?? 'bi-info-circle') ?> spi-fg-faint" aria-hidden="true"></i>
        <span><?= $line['html'] ?? '' ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
