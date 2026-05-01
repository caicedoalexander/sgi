<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\ResultSetInterface<\App\Model\Entity\EmailLog> $emailLogs
 * @var string $status
 * @var string $eventType
 * @var string $from
 * @var string $to
 * @var string $email
 * @var array<string,string> $statusOptions
 * @var array<string,string> $eventOptions
 */

use App\Constants\EmailLogConstants;

$this->assign('title', 'Logs de correo');
?>
<div class="container-fluid py-3">
    <div class="d-flex align-items-center mb-3">
        <h2 class="me-auto mb-0">
            <i class="bi bi-envelope-exclamation me-2"></i>Logs de correo
        </h2>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end']) ?>
                <div class="col-md-2">
                    <?= $this->Form->control('status', [
                        'type' => 'select',
                        'label' => 'Estado',
                        'empty' => 'Todos',
                        'options' => $statusOptions,
                        'value' => $status,
                        'class' => 'form-select',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('event_type', [
                        'type' => 'select',
                        'label' => 'Tipo',
                        'empty' => 'Todos',
                        'options' => $eventOptions,
                        'value' => $eventType,
                        'class' => 'form-select',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $this->Form->control('from', [
                        'type' => 'text',
                        'label' => 'Desde',
                        'value' => $from,
                        'class' => 'form-control flatpickr-date',
                        'placeholder' => 'YYYY-MM-DD',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $this->Form->control('to', [
                        'type' => 'text',
                        'label' => 'Hasta',
                        'value' => $to,
                        'class' => 'form-control flatpickr-date',
                        'placeholder' => 'YYYY-MM-DD',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $this->Form->control('email', [
                        'type' => 'text',
                        'label' => 'Destinatario',
                        'value' => $email,
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i></button>
                </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Destinatario</th>
                        <th>Asunto</th>
                        <th>Estado</th>
                        <th>Intentos</th>
                        <th class="text-end">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($emailLogs->count() === 0) : ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Sin resultados.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($emailLogs as $log) : ?>
                        <?php
                        $statusBadge = match ($log->status) {
                            EmailLogConstants::STATUS_SENT => 'bg-success',
                            EmailLogConstants::STATUS_FAILED => 'bg-danger',
                            EmailLogConstants::STATUS_PENDING => 'bg-warning text-dark',
                            default => 'bg-secondary',
                        };
    ?>
                        <tr>
                            <td><?= (int)$log->id ?></td>
                            <td><?= h($log->created->i18nFormat('yyyy-MM-dd HH:mm')) ?></td>
                            <td><?= h(EmailLogConstants::EVENT_LABELS[$log->event_type] ?? $log->event_type) ?></td>
                            <td><?= h($log->to_email) ?></td>
                            <td><?= h($log->subject) ?></td>
                            <td>
                                <span class="badge <?= $statusBadge ?>">
                                    <?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
                                </span>
                                <?php
                                $isFailed = $log->status === EmailLogConstants::STATUS_FAILED;
                                if ($isFailed && !empty($log->last_error)) :
                                    ?>
                                    <div class="text-danger small mt-1">
                                        <?= h(mb_substr($log->last_error, 0, 200)) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$log->attempts ?></td>
                            <td class="text-end">
                                <?php if ($log->status === EmailLogConstants::STATUS_FAILED) : ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-arrow-clockwise"></i>',
                                        ['action' => 'retry', $log->id],
                                        [
                                            'class' => 'btn btn-sm btn-outline-primary',
                                            'escape' => false,
                                            'confirm' => '¿Reenviar este correo?',
                                            'title' => 'Reintentar',
                                        ],
                                    ) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted small">
            <?= $this->Paginator->counter('Página {{page}} de {{pages}} — {{count}} registros') ?>
        </div>
        <ul class="pagination mb-0">
            <?= $this->Paginator->prev('« Anterior') ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next('Siguiente »') ?>
        </ul>
    </div>
</div>
