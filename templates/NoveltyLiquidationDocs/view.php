<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyLiquidationDoc $doc
 * @var array $groupErrors
 * @var array $effectiveStatuses
 * @var array $documentsByStatus
 * @var object|null $liquidationDocument
 * @var array $groupHistories
 * @var array $fieldLabels
 * @var string $currentStatus
 */
use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$this->assign('title', 'Liquidación: ' . h($doc->liquidation_number));

$statusLabels = NoveltyConstants::STATUS_LABELS;
$statusIcons = NoveltyPresentation::STATUS_ICONS;
$periodLabels = NoveltyConstants::PERIOD_LABELS;
$signerLabels = NoveltyConstants::SIGNER_LABELS;
$paymentLabels = NoveltyConstants::PAYMENT_LABELS;
$isRejected = $doc->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
$isPaid = $doc->pipeline_status === NoveltyConstants::STATUS_PAGADA;
$currentStatus = $doc->pipeline_status;

$statusBadgeMap = [
    'aprobacion' => 'bg-warning text-dark',
    'rrhh' => 'bg-secondary',
    'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'autorizacion_pago' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];

// Documents prep
$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};
$totalDocs = array_sum(array_map('count', $documentsByStatus));
$badgeColors = NoveltyPresentation::STATUS_BADGES;
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Liquidación</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['novelty_liquidation_docs']['can_edit'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['action' => 'edit', $doc->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Main card -->
<div class="card card-primary mb-4">

    <!-- Header -->
    <div class="card-header d-flex align-items-start justify-content-between gap-3" style="padding:1rem 1.25rem;">
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:var(--primary-color);color:#fff;font-size:1.35rem;">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;">
                    <?= h($doc->liquidation_number) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-secondary"><?= $periodLabels[$doc->period] ?? h($doc->period) ?></span>
                    <span class="badge <?= $statusBadgeMap[$currentStatus] ?? 'bg-dark' ?>">
                        <?= $statusLabels[$currentStatus] ?? ucfirst($currentStatus) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'pipelineStatuses' => $effectiveStatuses,
            'pipelineLabels' => $statusLabels,
            'currentStatus' => $currentStatus,
            'isRejected' => $isRejected,
            'statusIcons' => $statusIcons,
        ]) ?>
    </div>

    <!-- Two-column data: Información | Novedades -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información del Documento</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">No. Liquidación</span>
                <span class="sgi-data-value"><?= h($doc->liquidation_number) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Período</span>
                <span class="sgi-data-value"><?= $periodLabels[$doc->period] ?? h($doc->period) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Documento</span>
                <span class="sgi-data-value"><?= $doc->document_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Elaborado por</span>
                <span class="sgi-data-value"><?= h($doc->performed_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Creado por</span>
                <span class="sgi-data-value"><?= h($doc->created_by_user->full_name ?? '—') ?></span>
            </div>
            <?php if ($doc->passes_for_payment !== null): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Pasa para Pago</span>
                <span class="sgi-data-value">
                    <span class="badge bg-<?= $doc->passes_for_payment ? 'success' : 'secondary' ?>"><?= $doc->passes_for_payment ? 'Sí' : 'No' ?></span>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($doc->payment_status): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado de Pago</span>
                <span class="sgi-data-value"><?= $paymentLabels[$doc->payment_status] ?? h($doc->payment_status) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($doc->payment_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha de Pago</span>
                <span class="sgi-data-value"><?= $doc->payment_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <div class="sgi-section-title">Novedades Asociadas (<?= count($doc->employee_novelties) ?>)</div>
            <?php if (!empty($doc->employee_novelties)): ?>
            <div style="padding:0 1.25rem .875rem;max-height:300px;overflow-y:auto;">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Empleado</th><th>Tipo</th></tr></thead>
                    <tbody>
                        <?php foreach ($doc->employee_novelties as $novelty): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($novelty->custom_name ?: $novelty->employee->full_name ?? '—'),
                                    ['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id],
                                    ['class' => 'text-decoration-none']
                                ) ?>
                            </td>
                            <td style="font-size:.8125rem;"><?= h($novelty->novelty_type->name ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding:.25rem 1.25rem .875rem;">
                <p class="text-muted small mb-0">No hay novedades asociadas.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Signatures (read-only display) -->
    <?php if (!empty($doc->novelty_liquidation_signatures)): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Firmas</div>
        <div style="padding:0 1.25rem .875rem;">
            <div class="row g-3">
                <?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="fw-bold small mb-2"><?= $signerLabels[$sig->signer_type] ?? h($sig->signer_type) ?></div>
                        <?php if ($sig->signature_path): ?>
                            <img src="<?= $this->Url->build('/' . $sig->signature_path) ?>" alt="Firma"
                                 style="max-width:100%;max-height:100px;border:1px solid var(--border-color);">
                            <div class="mt-1 small text-muted">
                                <?= h($sig->signed_by_user->full_name ?? '') ?>
                                <?php if ($sig->approved_at): ?>
                                <br><?= $sig->approved_at->format('d/m/Y H:i') ?>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-success mt-1">Firmado</span>
                        <?php else: ?>
                            <span class="badge bg-secondary mt-2">Pendiente</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?= $this->element('confirm_payment_card', [
        'isVerificacionPago' => $doc->pipeline_status === NoveltyConstants::STATUS_VERIFICACION_PAGO,
        'canConfirm' => in_array(
            $this->getRequest()->getAttribute('identity')?->role?->name ?? null,
            [\App\Constants\RoleConstants::TESORERIA, \App\Constants\RoleConstants::ADMIN],
            true,
        ),
        'confirmUrl' => ['controller' => 'LiquidationDocPayments', 'action' => 'confirmPayment', $doc->id],
    ]) ?>

    <!-- Payments (read-only) -->
    <?php if (!empty($doc->liquidation_doc_payments)): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Pagos Registrados</div>
        <div style="padding:0 1.25rem .875rem;">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Entidad Bancaria</th>
                        <th>Monto</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Registrado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doc->liquidation_doc_payments as $payment): ?>
                    <tr>
                        <td><?= h($payment->banking_entity->name ?? '—') ?></td>
                        <td>$ <?= number_format((float)$payment->amount, 0, ',', '.') ?></td>
                        <td><?= $payment->payment_date?->format('d/m/Y') ?? '—' ?></td>
                        <td>
                            <?php if ($payment->authorized): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Autorizado</span>
                                <?php if ($payment->authorized_by_user): ?>
                                <br><small class="text-muted"><?= h($payment->authorized_by_user->full_name ?? $payment->authorized_by_user->username ?? '') ?> - <?= $payment->authorized_date?->format('d/m/Y') ?? '' ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Observations (read-only) -->
    <?php if (!empty($doc->novelty_observations)): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.5rem 1.25rem .875rem;max-height:400px;overflow-y:auto;">
            <?php foreach ($doc->novelty_observations as $obs): ?>
            <div class="d-flex align-items-start gap-2 mb-3">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;background:var(--primary-color);color:#fff;font-size:.7rem;font-weight:700;">
                    <?php
                    $names = explode(' ', $obs->user->full_name ?? '');
                    echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                    ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:.8rem;font-weight:600;color:#222;">
                            <?= h($obs->user->full_name ?? '') ?>
                        </span>
                        <span style="font-size:.7rem;color:#aaa;">
                            <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                        </span>
                    </div>
                    <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                        <?= nl2br(h($obs->message)) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Contact bar -->
    <div class="sgi-contact-bar">
        <?php if ($doc->performed_by_user): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-person"></i>
            <span>Elaborado por <?= h($doc->performed_by_user->full_name) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($doc->created): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-calendar3"></i>
            <span>Creado: <?= $doc->created->format('d/m/Y') ?></span>
        </div>
        <?php endif; ?>
        <?php if ($doc->modified): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-pencil-square"></i>
            <span>Modificado: <?= $doc->modified->format('d/m/Y') ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Documents (read-only grid) -->
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip"></i>
            Soportes
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
    </div>

    <!-- Liquidation document row (inline, compact) -->
    <div style="padding:.3rem .875rem;background:rgba(70,157,97,.06);border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
        <span class="badge" style="font-size:.6rem;background:var(--primary-color);color:#fff;">D. Liquidación</span>
    </div>
    <?php if ($liquidationDocument ?? null): ?>
    <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);background:rgba(70,157,97,.03);">
        <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi <?= $docIcon($liquidationDocument->mime_type) ?>"
               style="color:<?= $docIconColor($liquidationDocument->mime_type) ?>;font-size:1rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                 title="<?= h($liquidationDocument->file_name) ?>">
                <?= h($liquidationDocument->file_name) ?>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                <span style="font-size:.65rem;color:#bbb;">
                    <i class="bi bi-clock" style="font-size:.6rem;"></i>
                    <?= $liquidationDocument->created?->format('d/m/Y H:i') ?>
                </span>
                <?php if ($liquidationDocument->file_size): ?>
                <span style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($liquidationDocument->file_size) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
            <?= $this->Html->link(
                '<i class="bi bi-box-arrow-up-right"></i>',
                '/' . $liquidationDocument->file_path,
                ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
            ) ?>
        </div>
    </div>
    <?php else: ?>
    <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);text-align:center;color:#c8c8c8;background:rgba(70,157,97,.03);">
        <span style="font-size:.73rem;"><i class="bi bi-file-earmark-x me-1"></i>Sin documento</span>
    </div>
    <?php endif; ?>

    <?php if (empty($documentsByStatus)): ?>
        <div class="p-3 text-center text-muted" style="font-size:.875rem">
            <i class="bi bi-file-earmark-x me-1"></i>Sin soportes adjuntos
        </div>
    <?php else: ?>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach ($documentsByStatus as $status => $docs): ?>
                    <?php foreach ($docs as $docFile): ?>
                    <div class="col">
                        <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                            <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:#fafafa;display:flex;align-items:center;gap:.5rem;min-width:0;">
                                <i class="bi <?= $docIcon($docFile->mime_type) ?> flex-shrink-0"
                                   style="color:<?= $docIconColor($docFile->mime_type) ?>;font-size:1.1rem;"></i>
                                <div style="min-width:0;flex:1;overflow:hidden;">
                                    <span style="font-size:.78rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($docFile->file_name) ?>">
                                        <?= h($docFile->file_name) ?>
                                    </span>
                                </div>
                            </div>
                            <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:#555;display:flex;flex-direction:column;gap:.3rem;">
                                <div>
                                    <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
                                        <?= $statusLabels[$status] ?? $status ?>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#666;">
                                    <i class="bi bi-person" style="font-size:.8rem;"></i>
                                    <span><?= $docFile->has('uploaded_by_user') ? h($docFile->uploaded_by_user->full_name) : '—' ?></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#888;">
                                    <i class="bi bi-clock" style="font-size:.75rem;"></i>
                                    <span><?= $docFile->created?->format('d/m/Y H:i') ?></span>
                                </div>
                                <?php if ($docFile->file_size): ?>
                                <div style="color:#aaa;font-size:.72rem;"><?= $this->Number->toReadableSize($docFile->file_size) ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                                <?= $this->Html->link(
                                    '<i class="bi bi-box-arrow-up-right me-1"></i>Abrir',
                                    '/' . $docFile->file_path,
                                    ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank']
                                ) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Aggregated Change History -->
<?php if (!empty($groupHistories)): ?>
<div class="card">
    <div class="card-header">Historial de Cambios del Grupo</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Novedad</th>
                    <th>Usuario</th>
                    <th>Campo</th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupHistories as $history): ?>
                <tr>
                    <td><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                    <td style="font-size:.8125rem;">
                        <?php if ($history->has('employee_novelty')): ?>
                            <?= $this->Html->link(
                                '#' . $history->employee_novelty->id,
                                ['controller' => 'EmployeeNovelties', 'action' => 'view', $history->employee_novelty->id],
                                ['class' => 'text-decoration-none']
                            ) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                    <td class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
