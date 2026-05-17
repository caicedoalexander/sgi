<?php
/**
 * @var \App\View\AppView $this
 * @var array $smtpSettings
 * @var array $n8nSettings
 * @var array $apiSettings
 */
$this->assign('title', 'Configuración del Sistema');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Configuración del Sistema</span>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="sgi-icon-chip">
            <i class="bi bi-envelope" aria-hidden="true"></i>
        </div>
        <div>
            <div class="sgi-card-title">Configuración SMTP</div>
            <div class="sgi-card-subtitle mt-1">
                Servidor de correo para notificaciones del sistema
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>
        <input type="hidden" name="_form_type" value="smtp">

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
                <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Configuración
            </button>
        </div>

        <?= $this->Form->end() ?>

        <?= $this->Form->create(null, ['url' => ['action' => 'testSmtp']]) ?>
        <div class="mt-3">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-send me-1" aria-hidden="true"></i>Probar Conexión SMTP
            </button>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="sgi-icon-chip accent-secondary">
            <i class="bi bi-diagram-3" aria-hidden="true"></i>
        </div>
        <div>
            <div class="sgi-card-title">Integración n8n</div>
            <div class="sgi-card-subtitle mt-1">
                URLs de webhooks para automatizaciones n8n
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>
        <input type="hidden" name="_form_type" value="n8n">

        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Webhook Cruce DIAN</label>
                <input type="url" name="n8n_webhook_dian_crosscheck" class="form-control"
                       value="<?= h($n8nSettings['n8n_webhook_dian_crosscheck'] ?? '') ?>"
                       placeholder="https://n8n.example.com/webhook/dian-crosscheck">
                <small class="text-muted">URL del webhook n8n para procesar archivos Excel de cruce DIAN</small>
            </div>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Configuración n8n
            </button>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<?php $apiKey = $apiSettings['notifications_api_key'] ?? ''; ?>
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="sgi-icon-chip accent-dark">
            <i class="bi bi-key" aria-hidden="true"></i>
        </div>
        <div>
            <div class="sgi-card-title">API Key de Notificaciones</div>
            <div class="sgi-card-subtitle mt-1">
                Token usado por n8n para consultar <code>/api/notifications/pending</code> (header <code>X-Api-Key</code>)
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <?php if ($apiKey === ''): ?>
            <p class="text-muted mb-3">Aún no hay API key generada. Generá una para activar el workflow de notificaciones en n8n.</p>
            <?= $this->Form->create(null, ['url' => ['action' => 'regenerateApiKey']]) ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-magic me-1" aria-hidden="true"></i>Generar API Key
            </button>
            <?= $this->Form->end() ?>
        <?php else: ?>
            <label class="form-label">API Key actual</label>
            <div class="sgi-input-group d-flex">
                <input type="text" id="api-key-value" class="form-control border-0 shadow-none"
                       value="<?= h($apiKey) ?>" readonly
                       class="mono" style="font-size:.85rem;">
                <button type="button" class="btn btn-light border-0" id="btn-copy-api-key"
                        title="Copiar al portapapeles">
                    <i class="bi bi-clipboard" aria-hidden="true"></i>
                </button>
            </div>
            <small class="text-muted d-block mt-2">
                Regenerar invalida la actual. Tras regenerar, actualizá la credencial en n8n.
            </small>

            <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
                <?= $this->Form->create(null, ['url' => ['action' => 'regenerateApiKey']]) ?>
                <button type="submit" class="btn btn-outline-secondary"
                        data-sgi-confirm="¿Regenerar la API key? Tendrás que actualizar la credencial en n8n.">
                    <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Regenerar
                </button>
                <?= $this->Form->end() ?>
            </div>

            <script>
            (function() {
                var btn = document.getElementById('btn-copy-api-key');
                var input = document.getElementById('api-key-value');
                if (!btn || !input) return;
                btn.addEventListener('click', function() {
                    navigator.clipboard.writeText(input.value).then(function() {
                        var icon = btn.querySelector('i');
                        icon.classList.remove('bi-clipboard');
                        icon.classList.add('bi-check2');
                        setTimeout(function() {
                            icon.classList.remove('bi-check2');
                            icon.classList.add('bi-clipboard');
                        }, 1500);
                    });
                });
            })();
            </script>
        <?php endif; ?>
    </div>
</div>
