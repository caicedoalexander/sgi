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

$hasFilters = $status !== '' || $eventType !== '' || $from !== '' || $to !== '' || $email !== '';
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Logs de correo</span>
    <?= $this->Form->postLink(
        '<i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Reintentar fallidos',
        ['action' => 'retryAllFailed'],
        [
            'class' => 'btn btn-outline-warning',
            'escape' => false,
            'confirm' => '¿Reintentar todos los correos fallidos? Se procesarán hasta 100 por click.',
        ],
    ) ?>
</div>

<div class="sgi-search-bar mb-3">
    <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
    <div class="d-flex gap-2">
        <div class="flex-grow-1">
            <?= $this->Form->control('email', [
                'label' => false,
                'type' => 'text',
                'class' => 'form-control',
                'placeholder' => 'Buscar por destinatario…',
                'value' => $email,
            ]) ?>
        </div>
        <button type="submit" class="btn btn-primary" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i></button>
        <button type="button" class="btn btn-outline-dark" data-bs-toggle="collapse"
                data-bs-target="#emailLogFilters" title="Filtros avanzados">
            <i class="bi bi-funnel" aria-hidden="true"></i>
        </button>
        <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
                ['action' => 'index'],
                ['class' => 'btn btn-outline-danger', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>

    <div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="emailLogFilters">
        <div class="sgi-filters-section mt-2">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="sgi-filter-label" for="filter-status">Estado</label>
                    <?= $this->Form->select('status', $statusOptions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $status,
                        'id'    => 'filter-status',
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <label class="sgi-filter-label" for="filter-event">Tipo de evento</label>
                    <?= $this->Form->select('event_type', $eventOptions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $eventType,
                        'id'    => 'filter-event',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="sgi-filter-label" for="filter-from">Desde</label>
                            <input type="text" name="from" id="filter-from"
                                   class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($from) ?>" placeholder="YYYY-MM-DD">
                        </div>
                        <div class="col-6">
                            <label class="sgi-filter-label" for="filter-to">Hasta</label>
                            <input type="text" name="to" id="filter-to"
                                   class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($to) ?>" placeholder="YYYY-MM-DD">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:60px;">#</th>
                    <th style="width:140px;">Fecha</th>
                    <th>Tipo</th>
                    <th>Destinatario</th>
                    <th>Asunto</th>
                    <th style="width:130px;">Estado</th>
                    <th style="width:80px;">Intentos</th>
                    <th style="width:90px;" class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emailLogs as $log): ?>
                    <?php
                    $statusBadge = match ($log->status) {
                        EmailLogConstants::STATUS_SENT    => 'pill-primary-soft',
                        EmailLogConstants::STATUS_FAILED  => 'pill-danger-soft',
                        EmailLogConstants::STATUS_PENDING => 'pill-warning-soft',
                        default => 'pill-secondary-soft',
                    };
                    $isFailed = $log->status === EmailLogConstants::STATUS_FAILED;
                    ?>
                    <tr>
                        <td style="font-size:var(--fs-body-lg);color:var(--text-faint);"><?= (int)$log->id ?></td>
                        <td style="font-size:var(--fs-body-lg);color:var(--text-muted);white-space:nowrap;">
                            <?= h($log->created->i18nFormat('dd/MM/yyyy HH:mm')) ?>
                        </td>
                        <td style="font-size:var(--fs-body-lg);">
                            <?= h(EmailLogConstants::EVENT_LABELS[$log->event_type] ?? $log->event_type) ?>
                        </td>
                        <td style="font-size:var(--fs-body-lg);">
                            <?= h($log->to_email) ?>
                        </td>
                        <td style="font-size:var(--fs-body-lg);color:var(--text-muted);">
                            <?= h($log->subject) ?>
                        </td>
                        <td>
                            <span class="pill <?= $statusBadge ?>">
                                <?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
                            </span>
                            <?php if ($isFailed && !empty($log->last_error)): ?>
                                <div class="text-danger mt-1" style="font-size:.7rem;line-height:1.3;">
                                    <?= h(mb_substr($log->last_error, 0, 120)) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:var(--fs-body-lg);text-align:center;">
                            <?= (int)$log->attempts ?>
                        </td>
                        <td class="text-end">
                            <?php if ($isFailed): ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i>',
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

                <?php if ($emailLogs->count() === 0): ?>
                    <tr>
                        <td colspan="8">
                            <div class="sgi-doc-empty">
                                <i class="bi bi-envelope-slash sgi-doc-empty-icon" aria-hidden="true"></i>
                                Sin registros de correo con los filtros actuales.
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
