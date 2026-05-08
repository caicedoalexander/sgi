<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PaymentScheduling $record
 * @var string $roleName
 * @var float $total
 * @var array $pipelineLabels
 */
use App\Constants\PaymentSchedulingConstants;
use App\View\Presentation\PaymentSchedulingPresentation;

$this->assign('title', 'Programación ' . h($record->code));

$statusBadge = [
    PaymentSchedulingConstants::STATUS_BORRADOR => ['Borrador', 'bg-secondary'],
    PaymentSchedulingConstants::STATUS_TESORERIA => ['Tesorería', 'bg-warning text-dark'],
    PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => ['Autorización de pago', 'bg-info text-dark'],
    PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => ['Verificación de pago', 'bg-warning text-dark'],
    PaymentSchedulingConstants::STATUS_PAGADA => ['Pagada', 'bg-success'],
];
$ps = $statusBadge[$record->pipeline_status] ?? ['Desconocido', 'bg-dark'];
?>

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Programación</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['action' => 'edit', $record->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Tarjeta principal del documento -->
<div class="card card-primary mb-4">

    <!-- Cabecera: código + monto -->
    <div class="card-header d-flex align-items-start justify-content-between gap-3"
         style="padding:1rem 1.25rem;">
        <div class="d-flex align-items-start gap-3">
            <!-- Ícono -->
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:var(--primary-color);color:#fff;font-size:1.35rem;">
                <i class="bi bi-calendar2-check"></i>
            </div>
            <!-- Código, título y badges -->
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;font-family:monospace;">
                    <?= h($record->code) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
                </div>
                <div class="mt-1" style="font-size:.8rem;color:#777;font-weight:500;">
                    <?= h($record->title) ?: '<span class="text-muted">Sin título</span>' ?>
                </div>
            </div>
        </div>
        <!-- Monto destacado -->
        <div class="text-end flex-shrink-0">
            <div style="font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#bbb;margin-bottom:.2rem;">Total</div>
            <div style="font-size:1.55rem;font-weight:700;letter-spacing:-.04em;color:var(--primary-color);line-height:1;white-space:nowrap;">
                $ <?= $this->Number->format($total, ['places' => 0]) ?>
            </div>
        </div>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <div class="d-flex align-items-center justify-content-between">
            <?php foreach (PaymentSchedulingConstants::PIPELINE_STATUSES as $i => $status): ?>
            <?php
                $currentIdx = array_search($record->pipeline_status, PaymentSchedulingConstants::PIPELINE_STATUSES);
                $thisIdx = array_search($status, PaymentSchedulingConstants::PIPELINE_STATUSES);
                $isCurrent = $status === $record->pipeline_status;
                $isPast = $thisIdx < $currentIdx;
                $icon = PaymentSchedulingPresentation::STATUS_ICONS[$status] ?? 'bi-circle';
            ?>
            <div class="d-flex align-items-center gap-2" style="<?= !$isCurrent && !$isPast ? 'opacity:.4;' : '' ?>">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;border:2px solid <?= $isPast || $isCurrent ? 'var(--primary-color)' : '#ddd' ?>;background:<?= $isPast || $isCurrent ? 'var(--primary-color)' : '#fff' ?>;color:<?= $isPast || $isCurrent ? '#fff' : '#bbb' ?>;font-size:.85rem;">
                    <?php if ($isPast): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <i class="bi <?= $icon ?>"></i>
                    <?php endif; ?>
                </div>
                <span style="font-size:.75rem;font-weight:<?= $isCurrent ? '700' : '500' ?>;color:<?= $isCurrent ? '#111' : ($isPast ? 'var(--primary-color)' : '#aaa') ?>;">
                    <?= $pipelineLabels[$status] ?? $status ?>
                </span>
            </div>
            <?php if ($i < count(PaymentSchedulingConstants::PIPELINE_STATUSES) - 1): ?>
            <div style="flex:1;height:2px;margin:0 .75rem;background:<?= $isPast ? 'var(--primary-color)' : '#e0e0e0' ?>;"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Sección: Información General -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Código</span>
                <span class="sgi-data-value" style="font-family:monospace;"><?= h($record->code) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Título</span>
                <span class="sgi-data-value"><?= h($record->title) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
                </span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Detalles</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Creado por</span>
                <span class="sgi-data-value"><?= h($record->created_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Facturas</span>
                <span class="sgi-data-value"><?= count($record->payment_scheduling_items ?? []) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Monto Total</span>
                <span class="sgi-data-value fw-bold" style="color:var(--primary-color);">$ <?= number_format($total, 0, ',', '.') ?></span>
            </div>
        </div>
    </div>

    <!-- Facturas Vinculadas -->
    <?php if (!empty($record->payment_scheduling_items)): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">
            Facturas Vinculadas
            <span class="sgi-folder-count ms-1"><?= count($record->payment_scheduling_items) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="padding-left:1.25rem;">N. Factura</th>
                        <th>Proveedor</th>
                        <th>Banco</th>
                        <th class="text-end" style="padding-right:1.25rem;">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($record->payment_scheduling_items as $item): ?>
                    <tr>
                        <td style="padding-left:1.25rem;font-family:monospace;">
                            <?= $this->Html->link(
                                h($item->invoice->invoice_number ?? 'ID:' . $item->invoice_id),
                                ['controller' => 'Invoices', 'action' => 'view', $item->invoice_id],
                                ['class' => 'text-decoration-none']
                            ) ?>
                        </td>
                        <td><?= h($item->invoice->provider->name ?? '—') ?></td>
                        <td><?= h($item->banking_entity->name ?? '—') ?></td>
                        <td class="text-end fw-bold" style="padding-right:1.25rem;">$ <?= number_format((float)$item->amount, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="3" style="padding-left:1.25rem;">Total</th>
                        <th class="text-end" style="padding-right:1.25rem;">$ <?= number_format($total, 0, ',', '.') ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?= $this->element('confirm_payment_card', [
        'isVerificacionPago' => $record->pipeline_status === PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO,
        'canConfirm' => in_array(
            $roleName ?? null,
            [\App\Constants\RoleConstants::TESORERIA, \App\Constants\RoleConstants::ADMIN],
            true,
        ),
        'confirmUrl' => ['action' => 'confirmPayment', $record->id],
        'message' => 'Los pagos fueron autorizados por el Contador. Confirme cuando el dinero haya salido del banco.',
    ]) ?>

    <!-- Barra de registro -->
    <div class="sgi-contact-bar">
        <?php if (!empty($record->created_by_user)): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-person"></i>
            <span>Creado por <?= h($record->created_by_user->full_name) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($record->created): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-calendar3"></i>
            <span>Creado: <?= $record->created?->format('d/m/Y') ?? '' ?></span>
        </div>
        <?php endif; ?>
        <?php if ($record->modified): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-pencil-square"></i>
            <span>Modificado: <?= $record->modified?->format('d/m/Y') ?? '' ?></span>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Soportes -->
<?php
$documents = $record->payment_scheduling_documents ?? [];
$totalDocs = count($documents);
$docIcon = fn(?string $name): string => match(true) {
    str_contains($name ?? '', '.pdf') => 'bi-file-earmark-pdf',
    (bool)preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $name ?? '') => 'bi-file-earmark-image',
    (bool)preg_match('/\.(doc|docx)$/i', $name ?? '') => 'bi-file-earmark-word',
    (bool)preg_match('/\.(xls|xlsx|csv)$/i', $name ?? '') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $name): string => match(true) {
    str_contains($name ?? '', '.pdf') => '#dc3545',
    (bool)preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $name ?? '') => '#0dcaf0',
    (bool)preg_match('/\.(doc|docx)$/i', $name ?? '') => '#0d6efd',
    (bool)preg_match('/\.(xls|xlsx|csv)$/i', $name ?? '') => 'var(--primary-color)',
    default => '#aaa',
};
?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip"></i>
            Soportes
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
    </div>

    <?php if (empty($documents)): ?>
        <div class="p-3 text-center text-muted" style="font-size:.875rem">
            <i class="bi bi-file-earmark-x me-1"></i>Sin soportes adjuntos
        </div>
    <?php else: ?>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach ($documents as $att): ?>
                <div class="col">
                    <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                        <!-- Card header: icono + nombre -->
                        <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:#fafafa;display:flex;align-items:center;gap:.5rem;min-width:0;">
                            <i class="bi <?= $docIcon($att->file_name) ?> flex-shrink-0"
                               style="color:<?= $docIconColor($att->file_name) ?>;font-size:1.1rem;"></i>
                            <div style="min-width:0;flex:1;overflow:hidden;">
                                <span style="font-size:.78rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($att->file_name) ?>">
                                    <?= h($att->file_name) ?>
                                </span>
                            </div>
                        </div>
                        <!-- Card body: usuario + fecha -->
                        <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:#555;display:flex;flex-direction:column;gap:.3rem;">
                            <div style="display:flex;align-items:center;gap:.35rem;color:#666;">
                                <i class="bi bi-person" style="font-size:.8rem;"></i>
                                <span><?= h($att->uploaded_by_user->full_name ?? '—') ?></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:.35rem;color:#888;">
                                <i class="bi bi-clock" style="font-size:.75rem;"></i>
                                <span><?= $att->created?->format('d/m/Y H:i') ?></span>
                            </div>
                        </div>
                        <!-- Card footer: botón abrir -->
                        <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                            <?= $this->Html->link(
                                '<i class="bi bi-box-arrow-up-right me-1"></i>Abrir',
                                '/' . $att->file_path,
                                ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank']
                            ) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Observaciones -->
<?php $observations = $record->payment_scheduling_observations ?? []; ?>
<?php if (!empty($observations)): ?>
<?php $statusLabels = \App\Constants\PaymentSchedulingConstants::STATUS_LABELS; ?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-chat-left-text"></i>
            Observaciones
            <span class="sgi-folder-count"><?= count($observations) ?></span>
        </span>
    </div>
    <div style="padding:.5rem 1.25rem .875rem;max-height:400px;overflow-y:auto;">
        <?php foreach ($observations as $obs): ?>
        <?php
            $isRegression = ($obs->type ?? null) === \App\Constants\PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION;
            $meta = $obs->metadata ?? [];
            $fromLbl = $statusLabels[$meta['from_status'] ?? ''] ?? null;
            $toLbl = $statusLabels[$meta['to_status'] ?? ''] ?? null;
        ?>
        <div class="d-flex align-items-start gap-2 mb-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:32px;height:32px;background:<?= $isRegression ? '#CD6A15' : 'var(--primary-color)' ?>;color:#fff;font-size:.7rem;font-weight:700;">
                <?php
                $names = explode(' ', $obs->user->full_name ?? '');
                echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span style="font-size:.8rem;font-weight:600;color:#222;">
                        <?= h($obs->user->full_name ?? '') ?>
                    </span>
                    <?php if ($isRegression): ?>
                        <span class="badge bg-warning text-dark" style="font-size:.65rem;">Regresión</span>
                    <?php endif; ?>
                    <span style="font-size:.7rem;color:#aaa;">
                        <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                    </span>
                </div>
                <?php if ($isRegression && $fromLbl && $toLbl): ?>
                    <div style="font-size:.74rem;color:#666;margin-top:.1rem;">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        <?= h($fromLbl) ?> &rarr; <?= h($toLbl) ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                    <?= nl2br(h($obs->message)) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
