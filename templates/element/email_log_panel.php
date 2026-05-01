<?php
/**
 * Panel inline de logs de correo para una entidad.
 *
 * @var \App\View\AppView $this
 * @var array<\App\Model\Entity\EmailLog> $emailLogs
 */

use App\Constants\EmailLogConstants;
use Cake\I18n\DateTime;

if (empty($emailLogs)) {
    return;
}

$now = new DateTime();
$orphanThreshold = EmailLogConstants::ORPHAN_THRESHOLD_SECONDS;
?>
<div class="card mt-3 sgi-email-log-panel">
    <div class="card-header d-flex align-items-center">
        <i class="bi bi-envelope-paper me-2"></i>
        <strong>Notificaciones de correo</strong>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Destinatario</th>
                    <th>Estado</th>
                    <th>Último intento</th>
                    <th>Intentos</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emailLogs as $log) : ?>
                    <?php
                    $statusBadge = match ($log->status) {
                        EmailLogConstants::STATUS_SENT => 'bg-success',
                        EmailLogConstants::STATUS_FAILED => 'bg-danger',
                        EmailLogConstants::STATUS_PENDING => 'bg-warning text-dark',
                        default => 'bg-secondary',
                    };
                    $statusIcon = match ($log->status) {
                        EmailLogConstants::STATUS_SENT => 'bi-check-circle',
                        EmailLogConstants::STATUS_FAILED => 'bi-x-circle',
                        EmailLogConstants::STATUS_PENDING => 'bi-hourglass-split',
                        default => 'bi-question-circle',
                    };

                    $isOrphanPending = $log->status === EmailLogConstants::STATUS_PENDING
                        && $log->created !== null
                        && $log->created->diffInSeconds($now) > $orphanThreshold;

                    $showRetry = $log->status === EmailLogConstants::STATUS_FAILED || $isOrphanPending;
                    $lastAttempt = $log->last_attempt_at ?? $log->created;
    ?>
                    <tr>
                        <td><?= h($log->to_email) ?></td>
                        <td>
                            <span class="badge <?= $statusBadge ?>">
                                <i class="bi <?= $statusIcon ?>"></i>
                                <?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
                            </span>
                            <?php if ($log->status === EmailLogConstants::STATUS_FAILED && !empty($log->last_error)) : ?>
                                <div class="text-danger small mt-1">
                                    <i class="bi bi-exclamation-triangle me-1"></i><?= h($log->last_error) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $lastAttempt ? h($lastAttempt->i18nFormat('yyyy-MM-dd HH:mm')) : '—' ?></td>
                        <td><?= (int)$log->attempts ?></td>
                        <td class="text-end">
                            <?php if ($showRetry) : ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-arrow-clockwise me-1"></i>Reintentar',
                                    ['controller' => 'EmailLogs', 'action' => 'retry', $log->id],
                                    [
                                        'class' => 'btn btn-sm btn-outline-primary',
                                        'escape' => false,
                                        'confirm' => '¿Reenviar este correo?',
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
