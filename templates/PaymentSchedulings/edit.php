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

$statusBadge = [
    PaymentSchedulingConstants::STATUS_BORRADOR => 'bg-secondary',
    PaymentSchedulingConstants::STATUS_TESORERIA => 'bg-warning text-dark',
    PaymentSchedulingConstants::STATUS_AUT_PAGO => 'bg-info text-dark',
    PaymentSchedulingConstants::STATUS_PAGADA => 'bg-success',
];

$isBorrador = $currentStatus === PaymentSchedulingConstants::STATUS_BORRADOR;
$isTesoreria = $currentStatus === PaymentSchedulingConstants::STATUS_TESORERIA;
$isPagada = $currentStatus === PaymentSchedulingConstants::STATUS_PAGADA;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <div>
        <span class="sgi-page-title"><?= h($record->code) ?></span>
        <span class="badge <?= $statusBadge[$currentStatus] ?? 'bg-dark' ?> ms-2">
            <?= $pipelineLabels[$currentStatus] ?? $currentStatus ?>
        </span>
    </div>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            ['action' => 'view', $record->id],
            ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Pipeline Progress -->
<div class="card card-primary mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between">
            <?php foreach (PaymentSchedulingConstants::PIPELINE_STATUSES as $i => $status): ?>
            <?php
                $isCurrent = $status === $currentStatus;
                $isPast = array_search($status, PaymentSchedulingConstants::PIPELINE_STATUSES) < array_search($currentStatus, PaymentSchedulingConstants::PIPELINE_STATUSES);
                $icon = PaymentSchedulingConstants::STATUS_ICONS[$status] ?? 'bi-circle';
            ?>
            <div class="d-flex align-items-center gap-2 <?= $isCurrent ? 'fw-bold' : '' ?>" style="<?= !$isCurrent && !$isPast ? 'opacity:.4;' : '' ?>">
                <i class="bi <?= $icon ?>" style="font-size:1.1rem;color:<?= $isPast ? 'var(--primary-color)' : ($isCurrent ? 'var(--primary-color)' : '#aaa') ?>;"></i>
                <span style="font-size:.78rem;"><?= $pipelineLabels[$status] ?? $status ?></span>
                <?php if ($isCurrent): ?>
                <i class="bi bi-caret-left-fill" style="font-size:.6rem;color:var(--primary-color);"></i>
                <?php endif; ?>
            </div>
            <?php if ($i < count(PaymentSchedulingConstants::PIPELINE_STATUSES) - 1): ?>
            <div style="flex:1;height:2px;margin:0 .5rem;background:<?= $isPast ? 'var(--primary-color)' : '#e0e0e0' ?>;"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4">

<!-- Columna izquierda: Info + Items -->
<div class="col-lg-8">

    <!-- Info general -->
    <div class="card card-primary mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Código</label>
                    <input type="text" class="form-control" disabled value="<?= h($record->code) ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Título</label>
                    <input type="text" class="form-control" disabled value="<?= h($record->title) ?>">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Creado por</label>
                    <input type="text" class="form-control" disabled value="<?= h($record->created_by_user->full_name ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha</label>
                    <input type="text" class="form-control" disabled value="<?= $record->created?->format('d/m/Y H:i') ?? '' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Monto Total</label>
                    <input type="text" class="form-control fw-bold" disabled value="$ <?= number_format($total, 0, ',', '.') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Facturas vinculadas -->
    <div class="card card-primary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span style="font-size:.85rem;font-weight:600;">
                <i class="bi bi-receipt me-1"></i>Facturas Vinculadas
                <span class="badge bg-secondary ms-1"><?= count($record->payment_scheduling_items ?? []) ?></span>
            </span>
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
        <div class="collapse" id="add-item-form">
            <div class="card-body" style="border-bottom:1px solid var(--border-color);background:#f9fafb;">
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

        <div class="table-responsive">
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
                    <?php if (empty($record->payment_scheduling_items)): ?>
                    <tr>
                        <td colspan="<?= $isBorrador ? 5 : 4 ?>" class="text-center text-muted py-3">
                            <i class="bi bi-receipt me-1"></i>No hay facturas vinculadas
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($record->payment_scheduling_items as $item): ?>
                    <tr>
                        <td style="font-family:monospace;"><?= h($item->invoice->invoice_number ?? 'ID:' . $item->invoice_id) ?></td>
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
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($record->payment_scheduling_items)): ?>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="3">Total</th>
                        <th class="text-end">$ <?= number_format($total, 0, ',', '.') ?></th>
                        <?php if ($isBorrador): ?><th></th><?php endif; ?>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Soportes -->
    <div class="card card-primary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span style="font-size:.85rem;font-weight:600;">
                <i class="bi bi-paperclip me-1"></i>Soportes
                <span class="badge bg-secondary ms-1"><?= count($record->payment_scheduling_attachments ?? []) ?></span>
            </span>
            <?php if (!$isPagada): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadAttachmentModal">
                <i class="bi bi-upload me-1"></i>Subir
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($record->payment_scheduling_attachments)): ?>
            <div class="text-muted text-center py-2" style="font-size:.85rem;">
                <i class="bi bi-paperclip me-1"></i>Sin soportes
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($record->payment_scheduling_attachments as $att): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <div>
                        <a href="<?= $this->Url->build('/' . $att->file_path) ?>" target="_blank" style="font-size:.85rem;">
                            <i class="bi bi-file-earmark me-1"></i><?= h($att->file_name) ?>
                        </a>
                        <br><small class="text-muted"><?= h($att->uploaded_by_user->full_name ?? '') ?> — <?= $att->created?->format('d/m/Y H:i') ?></small>
                    </div>
                    <?php if (!$isPagada): ?>
                    <?= $this->Form->postLink(
                        '<i class="bi bi-trash"></i>',
                        ['action' => 'deleteAttachment', $record->id, $att->id],
                        ['confirm' => '¿Eliminar este soporte?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false]
                    ) ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Columna derecha: Acciones + Observaciones -->
<div class="col-lg-4">

    <!-- Acciones -->
    <?php if ($canAdvance || $canReject): ?>
    <div class="card card-primary mb-4">
        <div class="card-header">
            <span style="font-size:.85rem;font-weight:600;"><i class="bi bi-lightning me-1"></i>Acciones</span>
        </div>
        <div class="card-body">
            <?php if ($canAdvance): ?>
                <?php if (!empty($advanceErrors)): ?>
                <div class="alert alert-warning" style="font-size:.82rem;">
                    <strong>Para avanzar se requiere:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($advanceErrors as $err): ?>
                        <li><?= h($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-right-circle me-1"></i>Avanzar a ' . h($pipelineLabels[$nextStatus] ?? ''),
                    ['action' => 'advance', $record->id],
                    ['confirm' => '¿Avanzar la programación?', 'class' => 'btn btn-primary w-100 mb-2', 'escape' => false]
                ) ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($canReject): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-arrow-counterclockwise me-1"></i>Devolver a Tesorería',
                ['action' => 'reject', $record->id],
                ['confirm' => '¿Devolver la programación a Tesorería?', 'class' => 'btn btn-outline-warning w-100', 'escape' => false]
            ) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

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
            <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $record->id]]) ?>
            <div class="d-flex gap-2 align-items-end">
                <textarea name="message" class="form-control auto-resize" rows="1"
                          style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                          placeholder="Escriba una observación..." required></textarea>
                <button type="submit" class="btn btn-primary flex-shrink-0"
                        style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                    <i class="bi bi-send" style="font-size:.85rem;"></i>
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

</div><!-- /col-lg-4 -->
</div><!-- /row -->

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
                    El archivo debe tener las columnas: <strong>N. Factura</strong>, <strong>NIT Proveedor</strong>, <strong>Monto</strong>, <strong>Banco</strong>
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
                <button type="submit" class="btn btn-success"><i class="bi bi-upload me-1"></i>Importar</button>
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
                    <div class="form-text">Máximo 10 MB — PDF, imágenes, Word o Excel.</div>
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
})();
</script>
<?php $this->end() ?>
