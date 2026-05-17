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
<div class="card card-primary mt-3">
    <div class="card-header d-flex align-items-center gap-2" style="background:#fff;border-bottom:1px solid var(--border-color);padding:.6rem 1rem;">
        <i class="bi bi-envelope-paper" style="color:var(--primary-color);" aria-hidden="true"></i>
        <span style="font-size:var(--fs-title-card);font-weight:600;letter-spacing:-.01em;">Notificaciones de correo</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:var(--fs-body-lg);">
            <thead>
                <tr>
                    <th>Destinatario</th>
                    <th style="width:120px;">Estado</th>
                    <th style="width:140px;white-space:nowrap;">Último intento</th>
                    <th style="width:80px;">Intentos</th>
                    <th style="width:110px;" class="text-end">Acción</th>
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
                    $statusIcon = match ($log->status) {
                        EmailLogConstants::STATUS_SENT    => 'bi-check-circle',
                        EmailLogConstants::STATUS_FAILED  => 'bi-x-circle',
                        EmailLogConstants::STATUS_PENDING => 'bi-hourglass-split',
                        default => 'bi-question-circle',
                    };

                    $isOrphanPending = $log->status === EmailLogConstants::STATUS_PENDING
                        && $log->created !== null
                        && $log->created->diffInSeconds($now) > $orphanThreshold;

                    $showRetry  = $log->status === EmailLogConstants::STATUS_FAILED || $isOrphanPending;
                    $lastAttempt = $log->last_attempt_at ?? $log->created;
                    ?>
                    <tr>
                        <td style="color:var(--text-muted);"><?= h($log->to_email) ?></td>
                        <td>
                            <span class="pill <?= $statusBadge ?>">
                                <i class="bi <?= $statusIcon ?> me-1" aria-hidden="true"></i><?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
                            </span>
                            <?php if ($log->status === EmailLogConstants::STATUS_FAILED && !empty($log->last_error)): ?>
                                <div class="text-danger mt-1" style="font-size:.7rem;line-height:1.3;">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= h($log->last_error) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--text-muted);white-space:nowrap;">
                            <?= $lastAttempt ? h($lastAttempt->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?>
                        </td>
                        <td style="color:var(--text-muted);text-align:center;"><?= (int)$log->attempts ?></td>
                        <td class="text-end">
                            <?php if ($showRetry): ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Reintentar',
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
