<?php
/**
 * Panel inline de logs de correo para una entidad.
 *
 * @var \App\View\AppView $this
 * @var array<\App\Model\Entity\EmailLog> $emailLogs
 */

use App\Constants\EmailLogConstants;
use App\View\Presentation\EmailLogPresentation;
use Cake\I18n\DateTime;

if (empty($emailLogs)) {
    return;
}

$now = new DateTime();
$orphanThreshold = EmailLogConstants::ORPHAN_THRESHOLD_SECONDS;
?>
<div class="spi-card">
    <div class="d-flex align-items-center" style="margin-bottom:12px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-envelope-paper" aria-hidden="true"></i>
            Notificaciones de correo
            <span class="spi-folder-count"><?= count($emailLogs) ?></span>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Destinatario</th>
                    <th style="width:120px;">Estado</th>
                    <th style="width:140px;white-space:nowrap;">Último intento</th>
                    <th style="width:80px;text-align:center;">Intentos</th>
                    <th style="width:110px;" class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emailLogs as $log): ?>
                    <?php
                    $statusBadge = EmailLogPresentation::STATUS_BADGES[$log->status] ?? EmailLogPresentation::DEFAULT_BADGE;
                    $statusIcon = EmailLogPresentation::STATUS_ICONS[$log->status] ?? EmailLogPresentation::DEFAULT_ICON;

                    $isOrphanPending = $log->status === EmailLogConstants::STATUS_PENDING
                        && $log->created !== null
                        && $log->created->diffInSeconds($now) > $orphanThreshold;

                    $showRetry  = $log->status === EmailLogConstants::STATUS_FAILED || $isOrphanPending;
                    $lastAttempt = $log->last_attempt_at ?? $log->created;
                    ?>
                    <tr>
                        <td><?= h($log->to_email) ?></td>
                        <td>
                            <span class="pill <?= $statusBadge ?>">
                                <i class="bi <?= $statusIcon ?> me-1" aria-hidden="true"></i><?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
                            </span>
                            <?php if ($log->status === EmailLogConstants::STATUS_FAILED && !empty($log->last_error)): ?>
                                <div class="spi-fg-danger mt-1" style="font-size:var(--fs-meta);line-height:1.3;">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= h($log->last_error) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="mono" style="white-space:nowrap;color:var(--text-muted);">
                            <?= $lastAttempt ? h($lastAttempt->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?>
                        </td>
                        <td class="mono" style="text-align:center;color:var(--text-muted);"><?= (int)$log->attempts ?></td>
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
