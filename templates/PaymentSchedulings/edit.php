<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PaymentScheduling $record
 * @var string $roleName
 * @var string $currentStatus
 * @var bool $canAdvance
 * @var bool $canReject
 * @var float $total
 * @var array $advanceErrors
 * @var array $pipelineLabels
 * @var string|null $nextStatus
 * @var iterable $bankingEntities
 */
use App\Constants\PaymentSchedulingConstants;
use App\Constants\RoleConstants;

$this->assign('title', 'Programación ' . h($record->code));

$statusBadgeMap = [
    PaymentSchedulingConstants::STATUS_BORRADOR => ['Borrador', 'bg-secondary'],
    PaymentSchedulingConstants::STATUS_TESORERIA => ['Tesorería', 'bg-warning text-dark'],
    PaymentSchedulingConstants::STATUS_AUT_PAGO => ['Aut. Pago', 'bg-info text-dark'],
    PaymentSchedulingConstants::STATUS_PAGADA => ['Pagada', 'bg-success'],
];
$ps = $statusBadgeMap[$currentStatus] ?? ['Desconocido', 'bg-dark'];

$isBorrador = $currentStatus === PaymentSchedulingConstants::STATUS_BORRADOR;
$isTesoreria = $currentStatus === PaymentSchedulingConstants::STATUS_TESORERIA;
$isPagada = $currentStatus === PaymentSchedulingConstants::STATUS_PAGADA;
$itemCount = count($record->payment_scheduling_items ?? []);
?>

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Programación</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            ['action' => 'view', $record->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Alerta de avance pendiente -->
<?php if ($canAdvance && !empty($advanceErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($advanceErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Layout: formulario izquierda + soportes derecha -->
<div class="sgi-invoice-layout">

<!-- ── Columna izquierda: ficha principal ── -->
<div class="sgi-invoice-form">
<div class="card card-primary mb-4">

    <!-- Cabecera: identificador + rol + estado -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-calendar2-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;font-family:monospace;letter-spacing:-.01em;">
                    <?= h($record->code) ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    Rol: <strong style="color:#777;"><?= h($roleName) ?></strong>
                </div>
            </div>
        </div>
        <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <div class="d-flex align-items-center justify-content-between">
            <?php foreach (PaymentSchedulingConstants::PIPELINE_STATUSES as $i => $status): ?>
            <?php
                $currentIdx = array_search($currentStatus, PaymentSchedulingConstants::PIPELINE_STATUSES);
                $thisIdx = array_search($status, PaymentSchedulingConstants::PIPELINE_STATUSES);
                $isCurrent = $status === $currentStatus;
                $isPast = $thisIdx < $currentIdx;
                $icon = PaymentSchedulingConstants::STATUS_ICONS[$status] ?? 'bi-circle';
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
                <?php if ($isCurrent): ?>
                <i class="bi bi-caret-left-fill" style="font-size:.6rem;color:var(--primary-color);"></i>
                <?php endif; ?>
            </div>
            <?php if ($i < count(PaymentSchedulingConstants::PIPELINE_STATUSES) - 1): ?>
            <div style="flex:1;height:2px;margin:0 .75rem;background:<?= $isPast ? 'var(--primary-color)' : '#e0e0e0' ?>;"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Ficha resumen (ledger) -->
    <div style="padding:1rem 1.5rem .75rem;">
        <div class="sgi-ledger">
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Título</div>
                <div class="sgi-ledger-value"><?= h($record->title) ?: '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Facturas</div>
                <div class="sgi-ledger-value"><?= $itemCount ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Monto Total</div>
                <div class="sgi-ledger-value --amount">
                    <?php if ($total > 0): ?>
                        $ <?= number_format($total, 0, ',', '.') ?>
                    <?php else: ?>
                        <span class="--muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Creado por</div>
                <div class="sgi-ledger-value"><?= h($record->created_by_user->full_name ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Fecha Creación</div>
                <div class="sgi-ledger-value"><?= $record->created?->format('d/m/Y H:i') ?? '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Estado</div>
                <div class="sgi-ledger-value"><span class="badge <?= $ps[1] ?>" style="font-size:.7rem;"><?= $ps[0] ?></span></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Última Modificación</div>
                <div class="sgi-ledger-value"><?= $record->modified?->format('d/m/Y H:i') ?? '—' ?></div>
            </div>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">

        <!-- ── Sección: Facturas Vinculadas ── -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-receipt me-1"></i>Facturas Vinculadas
                    <span class="sgi-folder-count ms-1"><?= $itemCount ?></span>
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
                <?php if ($isBorrador): ?>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#add-item-form">
                        <i class="bi bi-plus-lg me-1"></i>Manual
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#importExcelModal">
                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($isBorrador): ?>
            <!-- Formulario agregar item manual -->
            <div class="collapse mb-3" id="add-item-form">
                <div class="card card-body" style="border-top:2px solid var(--primary-color);">
                    <?= $this->Form->create(null, ['url' => ['action' => 'addItem', $record->id]]) ?>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label mb-1" style="font-size:.75rem;">Factura (ID)</label>
                            <input type="number" name="invoice_id" class="form-control form-control-sm" required placeholder="ID de factura">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" style="font-size:.75rem;">Banco</label>
                            <select name="banking_entity_id" class="form-select form-select-sm" required>
                                <option value="">-- Seleccione --</option>
                                <?php foreach ($bankingEntities as $beId => $beName): ?>
                                <option value="<?= $beId ?>"><?= h($beName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" style="font-size:.75rem;">Monto (COP)</label>
                            <input type="text" name="amount" class="form-control form-control-sm currency-input" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
                        </div>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($record->payment_scheduling_items)): ?>
            <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>N. Factura</th>
                            <th>Proveedor</th>
                            <th>Banco</th>
                            <th class="text-end">Monto</th>
                            <?php if ($isBorrador): ?><th class="text-end">Acciones</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($record->payment_scheduling_items as $item): ?>
                        <tr>
                            <td style="font-family:monospace;">
                                <?= $this->Html->link(
                                    h($item->invoice->invoice_number ?? 'ID:' . $item->invoice_id),
                                    ['controller' => 'Invoices', 'action' => 'view', $item->invoice_id],
                                    ['class' => 'text-decoration-none']
                                ) ?>
                            </td>
                            <td><?= h($item->invoice->provider->name ?? '—') ?></td>
                            <td><?= h($item->banking_entity->name ?? '—') ?></td>
                            <td class="text-end fw-bold">$ <?= number_format((float)$item->amount, 0, ',', '.') ?></td>
                            <?php if ($isBorrador): ?>
                            <td class="text-end">
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'removeItem', $record->id, $item->id],
                                    ['confirm' => '¿Desvincular esta factura?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false]
                                ) ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3">Total</th>
                            <th class="text-end">$ <?= number_format($total, 0, ',', '.') ?></th>
                            <?php if ($isBorrador): ?><th></th><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
            <div class="text-muted text-center py-3" style="font-size:.85rem;border:1px dashed var(--border-color);">
                <i class="bi bi-receipt me-1"></i>No hay facturas vinculadas
            </div>
            <?php endif; ?>
        </div>

        <!-- Acciones pipeline -->
        <?php if ($canAdvance || $canReject || !empty($canRegress)): ?>
        <div class="sgi-sticky-actions">
            <?php if ($canAdvance && empty($advanceErrors) && $nextStatus): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-arrow-right-circle me-1"></i>Avanzar a ' . h($pipelineLabels[$nextStatus] ?? ''),
                ['action' => 'advance', $record->id],
                ['confirm' => '¿Avanzar la programación?', 'class' => 'btn btn-primary', 'escape' => false]
            ) ?>
            <?php endif; ?>

            <?php if ($canReject): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-arrow-counterclockwise me-1"></i>Devolver a Tesorería',
                ['action' => 'reject', $record->id],
                ['confirm' => '¿Devolver la programación a Tesorería?', 'class' => 'btn btn-outline-warning', 'escape' => false]
            ) ?>
            <?php endif; ?>

            <?php if (!empty($canRegress)):
                $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
                $isLocked = !empty($regressLockMessage);
            ?>
                <?php if ($isLocked): ?>
                    <button type="button" class="btn btn-outline-secondary"
                            disabled title="<?= h($regressLockMessage) ?>">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Regresar al paso anterior
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>
</div><!-- /columna izquierda -->

<!-- ── Columna derecha: soportes + observaciones ── -->
<div class="sgi-invoice-sidebar">

<?php
$attachments = $record->payment_scheduling_attachments ?? [];
$totalDocs = count($attachments);
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

<!-- Soportes -->
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if (!$isPagada): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadAttachmentModal">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($attachments)): ?>
    <div style="padding:2rem 1rem;text-align:center;color:#c8c8c8;">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <?php else: ?>
    <div style="max-height:420px;overflow-y:auto;">
        <?php foreach ($attachments as $att): ?>
        <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
            <!-- Icono tipo archivo -->
            <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
                <i class="bi <?= $docIcon($att->file_name) ?>"
                   style="color:<?= $docIconColor($att->file_name) ?>;font-size:1rem;"></i>
            </div>
            <!-- Info -->
            <div style="flex:1;min-width:0;">
                <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                     title="<?= h($att->file_name) ?>">
                    <?= h($att->file_name) ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                    <span style="font-size:.65rem;color:#888;">
                        <i class="bi bi-person" style="font-size:.6rem;"></i>
                        <?= h($att->uploaded_by_user->full_name ?? '—') ?>
                    </span>
                    <span style="font-size:.65rem;color:#bbb;">
                        <i class="bi bi-clock" style="font-size:.6rem;"></i>
                        <?= $att->created?->format('d/m/Y H:i') ?>
                    </span>
                </div>
            </div>
            <!-- Acciones -->
            <div style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
                <?= $this->Html->link(
                    '<i class="bi bi-box-arrow-up-right"></i>',
                    '/' . $att->file_path,
                    ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                ) ?>
                <?php if (!$isPagada): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-trash"></i>',
                    ['action' => 'deleteAttachment', $record->id, $att->id],
                    ['confirm' => '¿Eliminar este soporte?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'title' => 'Eliminar']
                ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Observaciones: chat -->
<?php $obsCount = count($record->payment_scheduling_observations ?? []); ?>
<div class="card card-primary" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <?php if ($obsCount > 0): ?>
        <span class="sgi-folder-count ms-auto"><?= $obsCount ?></span>
        <?php endif; ?>
    </div>

    <!-- Mensajes -->
    <div id="obs-chat-scroll" style="min-height:100px;max-height:340px;overflow-y:auto;padding:1rem .875rem;background:#f9fafb;display:flex;flex-direction:column;gap:.875rem;">
        <?php if (empty($record->payment_scheduling_observations)): ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem 0;color:#c5c5c5;gap:.5rem;">
            <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
            <span style="font-size:.78rem;">Sin observaciones aún</span>
        </div>
        <?php else: ?>
        <?php foreach ($record->payment_scheduling_observations as $obs):
            $isMine   = $currentUser && $obs->user_id === $currentUser->id;
            $names    = explode(' ', trim($obs->user->full_name ?? ''));
            $initials = strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[array_key_last($names)] ?? '', 0, 1));
        ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>;gap:.2rem;">
            <div style="font-size:.63rem;color:#aaa;font-weight:500;letter-spacing:.01em;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $isMine ? 'Tú' : h($obs->user->full_name ?? '') ?>
            </div>
            <div style="max-width:92%;padding:.55rem .8rem;font-size:.81rem;line-height:1.5;word-break:break-word;
                        background:<?= $isMine ? 'var(--primary-color)' : '#fff' ?>;
                        color:<?= $isMine ? '#fff' : '#2d2d2d' ?>;
                        border:1px solid <?= $isMine ? 'var(--primary-color)' : 'var(--border-color)' ?>;
                        border-radius:<?= $isMine ? '10px 10px 2px 10px' : '10px 10px 10px 2px' ?>;">
                <?= nl2br(h($obs->message)) ?>
            </div>
            <div style="font-size:.61rem;color:#c0c0c0;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $obs->created?->format('d/m/Y H:i') ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Input -->
    <div style="border-top:1px solid var(--border-color);padding:.75rem .875rem;background:#fff;">
        <form id="obs-form" data-url="<?= $this->Url->build(['action' => 'addObservation', $record->id]) ?>">
        <div class="d-flex gap-2 align-items-end">
            <textarea id="obs-message" name="message" class="form-control auto-resize" rows="1"
                      style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" id="obs-send-btn" class="btn btn-primary flex-shrink-0"
                    style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                <i class="bi bi-send" style="font-size:.85rem;"></i>
            </button>
        </div>
        </form>
    </div>
</div>

</div><!-- /columna derecha -->

</div><!-- /layout dos columnas -->

<?php if ($isBorrador): ?>
<!-- Modal: Importar Excel -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'importExcel', $record->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-excel me-2"></i>Importar Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.85rem;">
                    Formato: Preprogramación de pagos (columnas: Banco, Proveedor, Razón Social, Saldo, Programado).
                </p>
                <div class="mb-3">
                    <?= $this->Form->control('excel_file', [
                        'type' => 'file',
                        'class' => 'form-control',
                        'label' => ['text' => 'Archivo Excel', 'class' => 'form-label'],
                        'required' => true,
                        'accept' => '.xls,.xlsx',
                    ]) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Importar</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$isPagada): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadAttachmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadAttachment', $record->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?= $this->Form->control('file', [
                        'type' => 'file',
                        'class' => 'form-control',
                        'label' => ['text' => 'Archivo', 'class' => 'form-label'],
                        'required' => true,
                        'accept' => '.pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx',
                    ]) ?>
                    <div class="form-text">Máximo 20 MB — PDF, imágenes, Word o Excel.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php $this->append('script') ?>
<script>
(function(){
    // Auto-scroll chat
    var chat = document.getElementById('obs-chat-scroll');
    if (chat) chat.scrollTop = chat.scrollHeight;

    // Auto-resize textareas
    function syncHeight(el) {
        el.style.height = '0px';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('textarea.auto-resize').forEach(function(el) {
        el.style.overflow  = 'hidden';
        el.style.resize    = 'none';
        el.style.minHeight = '0px';
        syncHeight(el);
        el.addEventListener('input', function(){ syncHeight(el); });
    });

    // AJAX observations
    var form = document.getElementById('obs-form');
    var textarea = document.getElementById('obs-message');
    var btn = document.getElementById('obs-send-btn');
    var emptyState = chat ? chat.querySelector('[style*="align-items:center"][style*="justify-content:center"]') : null;
    var obsCountBadge = document.querySelector('.card .sgi-folder-count.ms-auto');

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var message = textarea.value.trim();
            if (!message) return;

            btn.disabled = true;

            fetch(form.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
                },
                body: 'message=' + encodeURIComponent(message)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    if (emptyState) { emptyState.remove(); emptyState = null; }

                    var html = '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:.2rem;">'
                        + '<div style="font-size:.63rem;color:#aaa;font-weight:500;letter-spacing:.01em;padding-right:.3rem">Tú</div>'
                        + '<div style="max-width:92%;padding:.55rem .8rem;font-size:.81rem;line-height:1.5;word-break:break-word;'
                        + 'background:var(--primary-color);color:#fff;border:1px solid var(--primary-color);border-radius:10px 10px 2px 10px;">'
                        + data.observation.message.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')
                        + '</div>'
                        + '<div style="font-size:.61rem;color:#c0c0c0;padding-right:.3rem">' + data.observation.created + '</div>'
                        + '</div>';

                    chat.insertAdjacentHTML('beforeend', html);
                    chat.scrollTop = chat.scrollHeight;

                    var currentCount = obsCountBadge ? parseInt(obsCountBadge.textContent) || 0 : 0;
                    if (obsCountBadge) {
                        obsCountBadge.textContent = currentCount + 1;
                    } else {
                        var header = form.closest('.card').querySelector('.card-header');
                        if (header) {
                            var badge = document.createElement('span');
                            badge.className = 'sgi-folder-count ms-auto';
                            badge.textContent = '1';
                            header.appendChild(badge);
                            obsCountBadge = badge;
                        }
                    }

                    textarea.value = '';
                    syncHeight(textarea);
                } else {
                    alert(data.error || 'Error al agregar observación.');
                }
            })
            .catch(function() {
                alert('Error de conexión. Intente nuevamente.');
            })
            .finally(function() {
                btn.disabled = false;
            });
        });
    }
})();
</script>

<?php if (!empty($canRegress) && empty($regressLockMessage)):
    $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
    $currLabel = $pipelineLabels[$currentStatus] ?? $currentStatus;
?>
<!-- Modal: Regresar al paso anterior -->
<div class="modal fade" id="regressStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post"
              action="<?= $this->Url->build(['action' => 'regressStatus', $record->id]) ?>"
              id="regressStatusForm">
            <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Regresar al paso anterior
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Esta programación volverá del paso
                        <strong><?= h($currLabel) ?></strong>
                        al paso
                        <strong><?= h($prevLabel) ?></strong>.
                    </p>
                    <div class="mb-2">
                        <label for="regressReason" class="form-label">
                            Motivo de la regresión <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason" id="regressReason"
                                  class="form-control" rows="4"
                                  required minlength="10" maxlength="500"
                                  placeholder="Describa por qué está regresando esta programación..."></textarea>
                        <div class="form-text">Mín. 10 caracteres · Máx. 500.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" id="regressConfirmBtn" class="btn btn-warning" disabled>
                        Confirmar regreso
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var ta = document.getElementById('regressReason');
    var btn = document.getElementById('regressConfirmBtn');
    if (!ta || !btn) return;
    ta.addEventListener('input', function () {
        btn.disabled = ta.value.trim().length < 10;
    });
})();
</script>
<?php endif; ?>
<?php $this->end() ?>
