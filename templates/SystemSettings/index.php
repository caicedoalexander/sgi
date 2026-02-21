<?php
/**
 * @var \App\View\AppView $this
 * @var array $smtpSettings
 */
$this->assign('title', 'Configuración del Sistema');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Configuración del Sistema</span>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
            <i class="bi bi-envelope"></i>
        </div>
        <div>
            <div style="font-size:.95rem;font-weight:700;color:#111;">Configuración SMTP</div>
            <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                Servidor de correo para notificaciones del sistema
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Host SMTP</label>
                <input type="text" name="smtp_host" class="form-control"
                       value="<?= h($smtpSettings['smtp_host'] ?? '') ?>"
                       placeholder="smtp.gmail.com">
            </div>
            <div class="col-md-3">
                <label class="form-label">Puerto</label>
                <input type="text" name="smtp_port" class="form-control"
                       value="<?= h($smtpSettings['smtp_port'] ?? '587') ?>"
                       placeholder="587">
            </div>
            <div class="col-md-3">
                <label class="form-label">Encriptación</label>
                <select name="smtp_encryption" class="form-select">
                    <option value="tls" <?= ($smtpSettings['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= ($smtpSettings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="" <?= empty($smtpSettings['smtp_encryption'] ?? 'tls') ? 'selected' : '' ?>>Ninguna</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Usuario</label>
                <input type="text" name="smtp_username" class="form-control"
                       value="<?= h($smtpSettings['smtp_username'] ?? '') ?>"
                       placeholder="usuario@ejemplo.com">
            </div>
            <div class="col-md-6">
                <label class="form-label">Contraseña</label>
                <input type="password" name="smtp_password" class="form-control"
                       placeholder="<?= !empty($smtpSettings['smtp_password']) ? '********' : '' ?>">
                <?php if (!empty($smtpSettings['smtp_password'])): ?>
                    <small class="text-muted">Dejar vacío para mantener la contraseña actual</small>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email Remitente</label>
                <input type="email" name="smtp_from_email" class="form-control"
                       value="<?= h($smtpSettings['smtp_from_email'] ?? '') ?>"
                       placeholder="noreply@ejemplo.com">
            </div>
            <div class="col-md-6">
                <label class="form-label">Nombre Remitente</label>
                <input type="text" name="smtp_from_name" class="form-control"
                       value="<?= h($smtpSettings['smtp_from_name'] ?? 'SGI') ?>"
                       placeholder="SGI">
            </div>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Guardar Configuración
            </button>
        </div>

        <?= $this->Form->end() ?>

        <?= $this->Form->create(null, ['url' => ['action' => 'testSmtp']]) ?>
        <div class="mt-3">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-send me-1"></i>Probar Conexión SMTP
            </button>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
