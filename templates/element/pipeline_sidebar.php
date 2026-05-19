<?php
/**
 * Sidebar reutilizable para vistas edit/view de módulos con pipeline.
 *
 * Renderiza tres cards apiladas: Hero (icono + ID + estado + entidad + monto),
 * Pipeline vertical, y Registro/Auditoría.
 *
 * Llamado desde Invoices, PettyCashRecords, Refunds, PaymentSchedulings,
 * Advances y EmployeeNovelties. Espera la siguiente interfaz:
 *
 * @var string   $icon            Clase de Bootstrap Icons (sin "bi bi-", e.g. 'wallet2')
 * @var string   $idLabel         ID o código a mostrar como hero (e.g. 'CM-2026-0042')
 * @var ?string  $typeLabel       Tipo de documento (pill secondary). null para omitir.
 * @var string   $statusPill      Clase pill del estado (e.g. 'pill-warning-soft')
 * @var string   $statusLabel     Texto del estado
 * @var bool     $isRejected      Si está rechazado (pinta pill-danger-soft "Rechazada")
 * @var ?string  $extraPillHtml   HTML adicional de pills (ej. "Pago Parcial"). null para omitir.
 * @var string   $entityLabel     Label para la entidad asociada (e.g. 'Proveedor', 'Beneficiario')
 * @var string   $entityValue     Nombre de la entidad
 * @var ?string  $entitySubLabel  Línea secundaria (e.g. centro de operación). null para omitir.
 * @var ?string  $entitySubIcon   Icono para entitySubLabel
 * @var ?string  $amountLabel     Label del monto (e.g. 'Valor Factura'). null = no muestra monto.
 * @var ?float   $amount          Monto numérico. null = muestra "$ —"
 * @var ?string  $amountExtraHtml HTML adicional bajo el monto (e.g. pagado/saldo). null para omitir.
 * @var array    $pipelineSteps   Array de claves de pasos del pipeline (en orden)
 * @var array    $pipelineLabels  Map paso → label
 * @var string   $currentStatus   Paso actual
 * @var bool     $isTerminal      Si el estado actual es terminal (último paso renderiza como done)
 * @var ?\DateTimeInterface|\Cake\I18n\FrozenTime|null $modifiedAt
 * @var array    $registryLines   Array de ['icon'=>'bi-...', 'html'=>'string|HTML'] para auditoría
 * @var ?string  $actionsHtml     HTML opcional para la card de Acciones (botones de regresión, etc.). null = no card.
 */
$icon            = $icon            ?? 'file-earmark-text';
$typeLabel       = $typeLabel       ?? null;
$statusPill      = $statusPill      ?? 'pill-muted';
$statusLabel     = $statusLabel     ?? '—';
$isRejected      = $isRejected      ?? false;
$extraPillHtml   = $extraPillHtml   ?? null;
$entitySubLabel  = $entitySubLabel  ?? null;
$entitySubIcon   = $entitySubIcon   ?? 'bi-geo-alt';
$amountLabel     = $amountLabel     ?? null;
$amount          = $amount          ?? null;
$amountExtraHtml = $amountExtraHtml ?? null;
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
$amountDec = $amount !== null ? sprintf(',%02d', (int)round(((float)$amount - floor((float)$amount)) * 100)) : null;
?>

<!-- Hero summary -->
<div class="card" style="padding:20px;">
    <div class="d-flex align-items-start" style="gap:12px;margin-bottom:16px;">
        <div class="sgi-hero-icon">
            <i class="bi bi-<?= h($icon) ?>" aria-hidden="true"></i>
        </div>
        <div style="min-width:0;flex:1;">
            <div class="sgi-hero-id"><?= h($idLabel) ?></div>
            <div class="d-flex flex-wrap" style="gap:4px;margin-top:6px;">
                <?php if ($typeLabel): ?>
                    <span class="pill pill-secondary"><?= h($typeLabel) ?></span>
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
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;" class="d-flex align-items-center gap-1">
        <i class="bi <?= h($entitySubIcon) ?>" aria-hidden="true" style="font-size:11px;"></i>
        <span><?= h($entitySubLabel) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($amountLabel !== null): ?>
    <div class="sgi-hero-divider">
        <div class="sgi-label"><?= h($amountLabel) ?></div>
        <div class="d-flex align-items-baseline" style="gap:4px;margin-top:4px;">
            <?php if ($amount !== null && $amount > 0): ?>
                <span class="sgi-hero-display">$ <?= $amountInt ?></span>
                <span class="sgi-hero-decimals"><?= $amountDec ?></span>
            <?php else: ?>
                <span class="sgi-hero-display" style="color:var(--text-disabled);">$ —</span>
            <?php endif; ?>
        </div>
        <?= $amountExtraHtml ?>
    </div>
    <?php endif; ?>
</div>

<!-- Pipeline vertical -->
<?php if (!empty($pipelineSteps)): ?>
<div class="card" style="padding:20px;">
    <div class="sgi-section-head"><span class="sgi-label">Pipeline</span></div>
    <div class="sgi-pipeline-v">
        <?php foreach ($pipelineSteps as $idx => $stepKey):
            $isDone = $idx < $currentIdx || ($isTerminal && $idx === $currentIdx);
            $isCurrent = !$isTerminal && $idx === $currentIdx;
            $stepLabel = $pipelineLabels[$stepKey] ?? $stepKey;
            $stepClasses = 'sgi-pipeline-v-step';
            if ($isDone) { $stepClasses .= ' is-done'; }
            if ($isCurrent) { $stepClasses .= ' is-current'; }
            if ($isCurrent && $isRejected) { $stepClasses .= ' is-rejected'; }

            $stepMeta = null;
            if (($isCurrent || ($isTerminal && $idx === $currentIdx)) && $modifiedAt) {
                $stepMeta = $modifiedAt->format('d/m H:i');
            } elseif (!$isDone) {
                $stepMeta = 'Pendiente';
            }
        ?>
        <div class="<?= $stepClasses ?>">
            <div class="sgi-pipeline-v-marker">
                <?php if ($isCurrent && $isRejected): ?>
                    <i class="bi bi-x" aria-hidden="true"></i>
                <?php elseif ($isDone): ?>
                    <i class="bi bi-check2" aria-hidden="true"></i>
                <?php elseif ($isCurrent): ?>
                    <span class="dot"></span>
                <?php endif; ?>
            </div>
            <div class="sgi-pipeline-v-content">
                <div class="sgi-pipeline-v-label"><?= h($stepLabel) ?></div>
                <?php if ($stepMeta): ?>
                    <div class="sgi-pipeline-v-meta"><?= h($stepMeta) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Acciones (opcional) -->
<?php if ($actionsHtml): ?>
<div class="card" style="padding:16px 20px;">
    <div class="sgi-section-head" style="margin-bottom:10px;">
        <span class="sgi-label">Acciones</span>
    </div>
    <div class="sgi-edit-actions-list">
        <?= $actionsHtml ?>
    </div>
</div>
<?php endif; ?>

<!-- Registro / Auditoría -->
<?php if (!empty($registryLines)): ?>
<div class="card" style="padding:16px 20px;">
    <div class="sgi-section-head" style="margin-bottom:10px;">
        <span class="sgi-label">Registro</span>
    </div>
    <?php foreach ($registryLines as $line): ?>
    <div style="font-size:var(--fs-body-sm);color:var(--text-muted);" class="d-flex align-items-center gap-2 mb-1">
        <i class="bi <?= h($line['icon'] ?? 'bi-info-circle') ?> sgi-fg-faint" aria-hidden="true"></i>
        <span><?= $line['html'] ?? '' ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
