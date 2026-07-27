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

// Fecha · Tipo·Destinatario · Asunto · Estado · Intentos · Acción
$gridCols = '140px 1.6fr 1.7fr 1.1fr 70px 70px';
?>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:18px;">
    <div>
        <span class="spi-page-title">Logs de correo</span>
        <div class="spi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="spi-fg-muted"><?= $this->Paginator->counter('{{count}} correos') ?></span>
        </div>
    </div>
    <?= $this->Form->postLink(
        '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i><span>Reintentar fallidos</span>',
        ['action' => 'retryAllFailed'],
        [
            'class' => 'btn btn-default',
            'escape' => false,
            'confirm' => '¿Reintentar todos los correos fallidos? Se procesarán hasta 100 por click.',
        ],
    ) ?>
</div>

<!-- ═══ Búsqueda + Filtros ═══ -->
<?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="email" value="<?= h($email) ?>"
               placeholder="Buscar por destinatario…" aria-label="Buscar correos">
        <?php if ($email !== ''): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x" aria-hidden="true"></i>',
            ['action' => 'index'],
            [
                'escape' => false,
                'style' => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                'title' => 'Limpiar búsqueda',
            ]
        ) ?>
        <?php endif; ?>
    </label>

    <button type="button" class="btn btn-default"
            data-filter-drawer-open aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i><span>Filtros</span>
    </button>
</div>

<?php ob_start(); ?>
    <div class="filter-field">
        <label class="input-label" for="filter-status">Estado</label>
        <?= $this->Form->select('status', $statusOptions, [
            'empty' => 'Todos',
            'class' => 'form-select form-select-sm',
            'value' => $status,
            'id'    => 'filter-status',
        ]) ?>
    </div>
    <div class="filter-field">
        <label class="input-label" for="filter-event">Tipo de evento</label>
        <?= $this->Form->select('event_type', $eventOptions, [
            'empty' => 'Todos',
            'class' => 'form-select form-select-sm',
            'value' => $eventType,
            'id'    => 'filter-event',
        ]) ?>
    </div>
    <div class="filter-field">
        <label class="input-label" for="el-range">Período</label>
        <?= $this->element('date_range_filter', [
            'id' => 'el-range',
            'fromName' => 'from',
            'toName' => 'to',
            'from' => $from,
            'to' => $to,
            'inputStyle' => 'width:100%;',
        ]) ?>
    </div>
<?php $filterFields = ob_get_clean(); ?>
<?= $this->element('filter_drawer', [
    'body' => $filterFields,
    'count' => $hasFilters ? 1 : 0,
    'clearUrl' => ['action' => 'index'],
]) ?>
<?= $this->Form->end() ?>

<!-- ═══ Tabla ═══ -->
<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Fecha</span>
        <span>Tipo · Destinatario</span>
        <span>Asunto</span>
        <span>Estado</span>
        <span style="text-align:center;">Intentos</span>
        <span style="text-align:right;">Acción</span>
    </div>

    <?php if ($emailLogs->count() === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral">
            <i class="bi bi-envelope-slash" aria-hidden="true"></i>
        </div>
        <div class="es-title">Sin registros</div>
        <div class="es-msg">Sin registros de correo con los filtros actuales.</div>
    </div>
    <?php else: ?>
    <?php foreach ($emailLogs as $log):
        $statusBadge = match ($log->status) {
            EmailLogConstants::STATUS_SENT    => 'pill-primary-soft',
            EmailLogConstants::STATUS_FAILED  => 'pill-danger-soft',
            EmailLogConstants::STATUS_PENDING => 'pill-warning-soft',
            default => 'pill-muted',
        };
        $isFailed = $log->status === EmailLogConstants::STATUS_FAILED;
    ?>
    <div class="row-fact" style="grid-template-columns:<?= $gridCols ?>;cursor:default;" role="row">
        <!-- Fecha -->
        <div class="mono" style="font-size:11.5px;color:var(--text-default);white-space:nowrap;">
            <?= h($log->created->i18nFormat('dd/MM/yyyy HH:mm')) ?>
        </div>

        <!-- Tipo · Destinatario -->
        <div style="min-width:0;">
            <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h(EmailLogConstants::EVENT_LABELS[$log->event_type] ?? $log->event_type) ?>
            </div>
            <div class="mono" style="font-size:10.5px;color:var(--text-faint);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($log->to_email) ?>
            </div>
        </div>

        <!-- Asunto -->
        <div style="min-width:0;font-size:12px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= h($log->subject) ?>
        </div>

        <!-- Estado -->
        <div style="min-width:0;">
            <span class="pill pill-sm <?= $statusBadge ?>">
                <?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
            </span>
            <?php if ($isFailed && !empty($log->last_error)): ?>
            <div class="text-danger" style="font-size:10px;line-height:1.3;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($log->last_error) ?>">
                <?= h(mb_substr($log->last_error, 0, 80)) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Intentos -->
        <div class="mono" style="text-align:center;font-size:12.5px;color:var(--text-default);">
            <?= (int)$log->attempts ?>
        </div>

        <!-- Acción -->
        <div style="text-align:right;">
            <?php if ($isFailed): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i>',
                ['action' => 'retry', $log->id],
                [
                    'class' => 'btn btn-default btn-sm btn-icon',
                    'escape' => false,
                    'confirm' => '¿Reenviar este correo?',
                    'title' => 'Reintentar',
                ],
            ) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?= $this->element('pagination') ?>
</div>
