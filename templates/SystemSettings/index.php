<?php
/**
 * @var \App\View\AppView $this
 * @var array $smtpSettings
 * @var array $n8nSettings
 * @var array $apiSettings
 */
$this->assign('title', 'Configuración del Sistema');

$smtpHost  = (string)($smtpSettings['smtp_host'] ?? '');
$smtpPort  = (string)($smtpSettings['smtp_port'] ?? '');
$smtpEnc   = (string)($smtpSettings['smtp_encryption'] ?? 'tls');
$smtpUser  = (string)($smtpSettings['smtp_username'] ?? '');
$smtpHasPw = !empty($smtpSettings['smtp_password']);
$smtpFromE = (string)($smtpSettings['smtp_from_email'] ?? '');
$smtpFromN = (string)($smtpSettings['smtp_from_name'] ?? 'SGI');
$smtpOn    = $smtpHost !== '';

$n8nWebhook = (string)($n8nSettings['n8n_webhook_dian_crosscheck'] ?? '');
$n8nOn      = $n8nWebhook !== '';

$apiKey = (string)($apiSettings['notifications_api_key'] ?? '');
$apiOn  = $apiKey !== '';
$apiMasked = $apiOn ? str_repeat('•', 24) . substr($apiKey, -8) : '';

$dotColor = fn(bool $on): string => $on ? 'var(--primary-color)' : 'var(--text-disabled)';
?>

<style>
/* ── Configuración del Sistema · layout rail + panel ─────────────── */
.cfg-grid {
    display: grid;
    grid-template-columns: 264px minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}
@media (max-width: 880px) { .cfg-grid { grid-template-columns: 1fr; } }

/* Rail de secciones */
.cfg-rail { overflow: hidden; }
.cfg-rail-head { padding: 14px 16px 8px; }
.cfg-nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border-left: 3px solid transparent;
    color: var(--text-default);
    transition: background-color var(--t-fast) ease;
}
.cfg-nav-item:hover { background: var(--bg-muted); }
.cfg-nav-item.active {
    background: var(--primary-soft);
    border-left-color: var(--primary-color);
}
.cfg-nav-icon {
    width: 30px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    background: var(--bg-subtle);
    color: var(--text-muted);
    border-radius: var(--radius-sm);
    flex-shrink: 0;
    font-size: 14px;
}
.cfg-nav-item.active .cfg-nav-icon { background: #fff; color: var(--primary-color); }
.cfg-nav-body { flex: 1; min-width: 0; }
.cfg-nav-label {
    font-size: 12.5px; font-weight: 600; color: var(--text-default);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cfg-nav-item.active .cfg-nav-label { font-weight: 700; color: var(--text-strong); }
.cfg-nav-meta {
    font-size: 10.5px; font-family: var(--font-mono); color: var(--text-faint);
    margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cfg-nav-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* Panel de formulario */
.cfg-pane { display: none; overflow: hidden; }
.cfg-pane.active { display: block; animation: fadeIn var(--t-view) ease; }
.cfg-pane-head {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 18px 22px;
    border-bottom: 1px solid var(--rule);
}
.cfg-pane-icon {
    width: 40px; height: 40px;
    display: flex; align-items: center; justify-content: center;
    background: var(--primary-soft); color: var(--primary-color);
    border-radius: var(--radius-md); flex-shrink: 0;
    font-size: 18px;
}
.cfg-pane-headings { flex: 1; min-width: 0; }
.cfg-pane-title {
    font-size: 16px; font-weight: 700; color: var(--text-strong);
    letter-spacing: -0.2px;
}
.cfg-pane-sub { font-size: var(--fs-body); color: var(--text-muted); margin-top: 4px; }
.cfg-pane-body { padding: 22px; }
.cfg-pane-head form { margin: 0; flex-shrink: 0; }

/* Subtítulo de bloque dentro del panel */
.cfg-subhead { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.cfg-subhead::after { content: ''; flex: 1; height: 1px; background: var(--rule); }
.cfg-block { margin-bottom: 22px; }
.cfg-block:last-child { margin-bottom: 0; }

/* Grids de campos */
.cfg-row-3 { display: grid; grid-template-columns: minmax(0, 1fr) 140px 180px; gap: 14px; }
.cfg-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 720px) {
    .cfg-row-3, .cfg-row-2 { grid-template-columns: 1fr; }
}
.cfg-field > label { display: block; margin-bottom: 6px; }
.cfg-label-row {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 6px;
}
.cfg-label-row > label { margin-bottom: 0; }
.cfg-hint { font-size: var(--fs-meta); color: var(--text-faint); }

/* Input con icono prefijo */
.cfg-input { position: relative; }
.cfg-input .form-control.has-prefix { padding-left: 34px; }
.cfg-input-prefix {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: var(--text-faint); font-size: 13px; line-height: 1; pointer-events: none;
}

/* Pie de panel */
.cfg-footer {
    display: flex; align-items: center; justify-content: space-between; gap: 18px;
    padding: 14px 22px;
    background: var(--bg-subtle);
    border-top: 1px solid var(--rule);
}
.cfg-footer-note {
    display: flex; align-items: center; gap: 6px;
    font-size: 11.5px; color: var(--text-muted);
}
.cfg-footer-note .bi { color: var(--text-disabled); flex-shrink: 0; }
.cfg-footer-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* Webhook n8n */
.cfg-webhook-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 3px;
}
.cfg-webhook-name { font-size: 13px; font-weight: 700; color: var(--text-strong); }
.cfg-webhook-sub { font-size: 11.5px; color: var(--text-muted); margin-bottom: 8px; }

/* API key — endpoint */
.cfg-endpoint {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: var(--bg-subtle);
    border-radius: var(--radius-md);
    padding: 12px 14px;
    font-family: var(--font-mono); font-size: 12px; color: var(--text-default);
}
.cfg-endpoint-path { flex: 1; min-width: 0; }
.cfg-endpoint-header {
    background: #fff; color: var(--secondary-color);
    padding: 3px 7px; border-radius: var(--radius-sm); font-size: 11px;
}

/* API key — token */
.cfg-token {
    display: flex; align-items: center; gap: 10px;
    background: #fff;
    outline: 1px solid var(--border-color); outline-offset: -1px;
    border-radius: var(--radius-md);
    padding: 9px 12px;
}
.cfg-token-value {
    flex: 1; min-width: 0;
    font-family: var(--font-mono); font-size: 12.5px; color: var(--text-default);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cfg-token-btn {
    background: var(--bg-subtle); border: none;
    border-radius: var(--radius-sm); padding: 5px 9px;
    font-size: 10.5px; font-weight: 600; color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 5px; cursor: pointer;
    transition: background-color var(--t-fast) ease, color var(--t-fast) ease;
}
.cfg-token-btn:hover { background: var(--bg-muted); }
.cfg-token-btn.copied { background: var(--primary-soft); color: var(--primary-color); }

/* API key — zona de peligro */
.cfg-danger {
    position: relative;
    display: flex; align-items: flex-start; gap: 14px;
    background: var(--bg-subtle);
    padding: 16px 18px;
    overflow: hidden;
}
.cfg-danger::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--danger-color);
}
.cfg-danger-icon {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    background: var(--danger-soft); color: var(--danger-color);
    border-radius: var(--radius-sm); flex-shrink: 0;
}
.cfg-danger-body { flex: 1; min-width: 0; }
.cfg-danger-actions { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }
</style>

<?php /* ════════════════════════ ENCABEZADO ════════════════════════ */ ?>
<div class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span style="width:3px;height:12px;background:var(--primary-color);"></span>
        <span class="sgi-label">Administración</span>
        <i class="bi bi-chevron-right" style="font-size:8px;color:var(--text-disabled);" aria-hidden="true"></i>
        <span class="sgi-label">Configuración</span>
    </div>
    <div class="sgi-title-page">Configuración del Sistema</div>
    <div class="mt-2" style="font-size:var(--fs-body);color:var(--text-muted);">
        Servidor de correo, webhooks de n8n y credenciales de la API interna.
    </div>
</div>

<div class="cfg-grid">

    <?php /* ──────────────── RAIL DE SECCIONES ──────────────── */ ?>
    <div class="card cfg-rail">
        <div class="cfg-rail-head">
            <span class="sgi-label">Secciones</span>
        </div>

        <a href="#smtp" class="cfg-nav-item" data-section="smtp">
            <span class="cfg-nav-icon"><i class="bi bi-envelope" aria-hidden="true"></i></span>
            <span class="cfg-nav-body">
                <span class="cfg-nav-label d-block">Correo (SMTP)</span>
                <span class="cfg-nav-meta d-block"><?= $smtpOn ? h($smtpHost . ' · ' . $smtpPort) : 'Sin configurar' ?></span>
            </span>
            <span class="cfg-nav-dot" style="background:<?= $dotColor($smtpOn) ?>;"></span>
        </a>

        <a href="#n8n" class="cfg-nav-item" data-section="n8n">
            <span class="cfg-nav-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
            <span class="cfg-nav-body">
                <span class="cfg-nav-label d-block">Integración n8n</span>
                <span class="cfg-nav-meta d-block"><?= $n8nOn ? 'Webhook configurado' : 'Sin configurar' ?></span>
            </span>
            <span class="cfg-nav-dot" style="background:<?= $dotColor($n8nOn) ?>;"></span>
        </a>

        <a href="#apikey" class="cfg-nav-item" data-section="apikey">
            <span class="cfg-nav-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
            <span class="cfg-nav-body">
                <span class="cfg-nav-label d-block">API Key</span>
                <span class="cfg-nav-meta d-block"><?= $apiOn ? 'Token activo' : 'Sin generar' ?></span>
            </span>
            <span class="cfg-nav-dot" style="background:<?= $dotColor($apiOn) ?>;"></span>
        </a>
    </div>

    <?php /* ──────────────── PANEL DE FORMULARIO ──────────────── */ ?>
    <div>

        <?php /* ─── SMTP ─── */ ?>
        <section class="card cfg-pane" id="pane-smtp" data-section="smtp">
            <div class="cfg-pane-head">
                <span class="cfg-pane-icon"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                <div class="cfg-pane-headings">
                    <div class="cfg-pane-title">Configuración SMTP</div>
                    <div class="cfg-pane-sub">Servidor de correo saliente para las notificaciones del sistema.</div>
                </div>
                <?= $this->Form->create(null, ['url' => ['action' => 'testSmtp']]) ?>
                <button type="submit" class="btn btn-default btn-sm">
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Probar conexión
                </button>
                <?= $this->Form->end() ?>
            </div>

            <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>
            <input type="hidden" name="_form_type" value="smtp">
            <div class="cfg-pane-body">

                <div class="cfg-block">
                    <div class="cfg-subhead"><span class="sgi-label">Servidor</span></div>
                    <div class="cfg-row-3">
                        <div class="cfg-field">
                            <label class="sgi-label" for="smtp_host">Host SMTP</label>
                            <input type="text" id="smtp_host" name="smtp_host" class="form-control mono"
                                   value="<?= h($smtpHost) ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="cfg-field">
                            <label class="sgi-label" for="smtp_port">Puerto</label>
                            <input type="text" id="smtp_port" name="smtp_port" class="form-control mono"
                                   value="<?= h($smtpPort) ?>" placeholder="587">
                        </div>
                        <div class="cfg-field">
                            <label class="sgi-label" for="smtp_encryption">Encriptación</label>
                            <select id="smtp_encryption" name="smtp_encryption" class="form-select">
                                <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS</option>
                                <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                <option value="" <?= $smtpEnc === '' ? 'selected' : '' ?>>Sin encriptación</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="cfg-block">
                    <div class="cfg-subhead"><span class="sgi-label">Autenticación</span></div>
                    <div class="cfg-row-2">
                        <div class="cfg-field">
                            <label class="sgi-label" for="smtp_username">Usuario</label>
                            <div class="cfg-input">
                                <i class="bi bi-person cfg-input-prefix" aria-hidden="true"></i>
                                <input type="text" id="smtp_username" name="smtp_username"
                                       class="form-control has-prefix" value="<?= h($smtpUser) ?>"
                                       placeholder="usuario@ejemplo.com" autocomplete="off">
                            </div>
                        </div>
                        <div class="cfg-field">
                            <div class="cfg-label-row">
                                <label class="sgi-label" for="smtp_password">Contraseña</label>
                                <span class="cfg-hint">Vacío = mantener actual</span>
                            </div>
                            <input type="password" id="smtp_password" name="smtp_password"
                                   class="form-control" placeholder="<?= $smtpHasPw ? '••••••••' : 'Ingresa la contraseña' ?>"
                                   autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <div class="cfg-block">
                    <div class="cfg-subhead"><span class="sgi-label">Remitente</span></div>
                    <div class="cfg-row-2">
                        <div class="cfg-field">
                            <label class="sgi-label" for="smtp_from_email">Email remitente</label>
                            <div class="cfg-input">
                                <i class="bi bi-envelope cfg-input-prefix" aria-hidden="true"></i>
                                <input type="email" id="smtp_from_email" name="smtp_from_email"
                                       class="form-control has-prefix" value="<?= h($smtpFromE) ?>"
                                       placeholder="noreply@ejemplo.com">
                            </div>
                        </div>
                        <div class="cfg-field">
                            <label class="sgi-label" for="smtp_from_name">Nombre remitente</label>
                            <input type="text" id="smtp_from_name" name="smtp_from_name"
                                   class="form-control" value="<?= h($smtpFromN) ?>" placeholder="SGI">
                        </div>
                    </div>
                </div>

            </div>
            <div class="cfg-footer">
                <div class="cfg-footer-note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Los cambios se aplican a todas las notificaciones automáticas (facturas, anticipos, novedades).
                </div>
                <div class="cfg-footer-actions">
                    <button type="reset" class="btn btn-ghost">Descartar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar cambios
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </section>

        <?php /* ─── n8n ─── */ ?>
        <section class="card cfg-pane" id="pane-n8n" data-section="n8n">
            <div class="cfg-pane-head">
                <span class="cfg-pane-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                <div class="cfg-pane-headings">
                    <div class="cfg-pane-title">Integración n8n</div>
                    <div class="cfg-pane-sub">URL del webhook que el SGI invoca para automatizaciones externas.</div>
                </div>
            </div>

            <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>
            <input type="hidden" name="_form_type" value="n8n">
            <div class="cfg-pane-body">
                <div class="cfg-block">
                    <div class="cfg-webhook-head">
                        <span class="cfg-webhook-name">Cruce DIAN</span>
                        <?php if ($n8nOn): ?>
                            <span class="pill pill-primary-soft">
                                <span class="dot" style="background:var(--primary-color);"></span> CONFIGURADO
                            </span>
                        <?php else: ?>
                            <span class="pill" style="background:var(--bg-subtle);color:var(--text-faint);">
                                SIN CONFIGURAR
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="cfg-webhook-sub">
                        Procesa los archivos Excel del cruce con la DIAN al recibirlos por correo.
                    </div>
                    <input type="url" id="n8n_webhook_dian_crosscheck" name="n8n_webhook_dian_crosscheck"
                           class="form-control mono" value="<?= h($n8nWebhook) ?>"
                           placeholder="https://n8n.example.com/webhook/dian-crosscheck">
                </div>
            </div>
            <div class="cfg-footer">
                <div class="cfg-footer-note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    La URL apunta a un flujo n8n interno. Verificá que el flujo esté activo antes de guardar.
                </div>
                <div class="cfg-footer-actions">
                    <button type="reset" class="btn btn-ghost">Descartar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar webhook
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </section>

        <?php /* ─── API Key ─── */ ?>
        <section class="card cfg-pane" id="pane-apikey" data-section="apikey">
            <div class="cfg-pane-head">
                <span class="cfg-pane-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <div class="cfg-pane-headings">
                    <div class="cfg-pane-title">API Key de Notificaciones</div>
                    <div class="cfg-pane-sub">Token usado por n8n para consultar el endpoint de notificaciones pendientes.</div>
                </div>
            </div>

            <div class="cfg-pane-body">
                <div class="cfg-block">
                    <div class="cfg-subhead"><span class="sgi-label">Endpoint</span></div>
                    <div class="cfg-endpoint">
                        <span class="pill pill-primary-soft">GET</span>
                        <span class="cfg-endpoint-path">/api/notifications/pending</span>
                        <span style="color:var(--text-faint);font-size:11px;">header</span>
                        <span class="cfg-endpoint-header">X-Api-Key</span>
                    </div>
                </div>

                <?php if (!$apiOn): ?>
                    <div class="cfg-block">
                        <div class="cfg-subhead"><span class="sgi-label">Token</span></div>
                        <p class="sgi-body-muted mb-3">
                            Aún no hay API key generada. Generá una para activar el flujo de notificaciones en n8n.
                        </p>
                        <?= $this->Form->create(null, ['url' => ['action' => 'regenerateApiKey']]) ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-magic" aria-hidden="true"></i> Generar API Key
                        </button>
                        <?= $this->Form->end() ?>
                    </div>
                <?php else: ?>
                    <div class="cfg-block">
                        <div class="cfg-subhead"><span class="sgi-label">Token activo</span></div>
                        <div class="cfg-token">
                            <i class="bi bi-shield-lock" style="color:var(--text-faint);" aria-hidden="true"></i>
                            <span class="cfg-token-value" id="cfg-token-value"
                                  data-full="<?= h($apiKey) ?>" data-masked="<?= h($apiMasked) ?>"><?= h($apiMasked) ?></span>
                            <button type="button" class="cfg-token-btn" id="cfg-token-show">
                                <i class="bi bi-eye" aria-hidden="true"></i><span>Mostrar</span>
                            </button>
                            <button type="button" class="cfg-token-btn" id="cfg-token-copy">
                                <i class="bi bi-clipboard" aria-hidden="true"></i><span>Copiar</span>
                            </button>
                        </div>
                        <div class="mt-2" style="font-size:11.5px;color:var(--text-faint);">
                            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                            El token solo se usa en integraciones de confianza. Regenerarlo invalida el anterior.
                        </div>
                    </div>

                    <div class="cfg-block">
                        <div class="cfg-danger">
                            <span class="cfg-danger-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
                            <div class="cfg-danger-body">
                                <div class="sgi-title-card">Regenerar API Key</div>
                                <div class="mt-1" style="font-size:11.5px;color:var(--text-muted);line-height:1.5;">
                                    La key actual se invalida de inmediato. Toda integración que la use dejará de
                                    funcionar hasta actualizar la credencial en n8n.
                                </div>
                            </div>
                            <div class="cfg-danger-actions">
                                <button type="button" class="btn btn-default btn-sm" id="cfg-regen-trigger">
                                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Regenerar
                                </button>
                                <div id="cfg-regen-confirm" class="cfg-danger-actions" style="display:none;">
                                    <button type="button" class="btn btn-ghost btn-sm" id="cfg-regen-cancel">Cancelar</button>
                                    <?= $this->Form->create(null, ['url' => ['action' => 'regenerateApiKey']]) ?>
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Sí, regenerar
                                    </button>
                                    <?= $this->Form->end() ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

    </div>
</div>

<script>
(function () {
    'use strict';
    var VALID = ['smtp', 'n8n', 'apikey'];
    var STORE_KEY = 'sgi-cfg-section';
    var items = document.querySelectorAll('.cfg-nav-item');
    var panes = document.querySelectorAll('.cfg-pane');

    function activate(id) {
        if (VALID.indexOf(id) < 0) { id = 'smtp'; }
        items.forEach(function (i) { i.classList.toggle('active', i.dataset.section === id); });
        panes.forEach(function (p) { p.classList.toggle('active', p.dataset.section === id); });
        try { sessionStorage.setItem(STORE_KEY, id); } catch (e) {}
    }

    var initial = (location.hash || '').replace('#', '');
    if (VALID.indexOf(initial) < 0) {
        try { initial = sessionStorage.getItem(STORE_KEY) || ''; } catch (e) { initial = ''; }
    }
    activate(initial);

    items.forEach(function (item) {
        item.addEventListener('click', function (e) {
            e.preventDefault();
            activate(this.dataset.section);
        });
    });

    // ── API key: mostrar / ocultar ──
    var tokenEl = document.getElementById('cfg-token-value');
    var showBtn = document.getElementById('cfg-token-show');
    if (tokenEl && showBtn) {
        showBtn.addEventListener('click', function () {
            var shown = tokenEl.textContent === tokenEl.dataset.full;
            tokenEl.textContent = shown ? tokenEl.dataset.masked : tokenEl.dataset.full;
            this.querySelector('i').className = shown ? 'bi bi-eye' : 'bi bi-eye-slash';
            this.querySelector('span').textContent = shown ? 'Mostrar' : 'Ocultar';
        });
    }

    // ── API key: copiar ──
    var copyBtn = document.getElementById('cfg-token-copy');
    if (copyBtn && tokenEl) {
        copyBtn.addEventListener('click', function () {
            var btn = this;
            navigator.clipboard.writeText(tokenEl.dataset.full).then(function () {
                btn.classList.add('copied');
                btn.querySelector('i').className = 'bi bi-check-lg';
                btn.querySelector('span').textContent = 'Copiado';
                setTimeout(function () {
                    btn.classList.remove('copied');
                    btn.querySelector('i').className = 'bi bi-clipboard';
                    btn.querySelector('span').textContent = 'Copiar';
                }, 1600);
            }).catch(function () {});
        });
    }

    // ── API key: confirmación de regeneración ──
    var regenTrigger = document.getElementById('cfg-regen-trigger');
    var regenConfirm = document.getElementById('cfg-regen-confirm');
    var regenCancel = document.getElementById('cfg-regen-cancel');
    if (regenTrigger && regenConfirm && regenCancel) {
        regenTrigger.addEventListener('click', function () {
            regenTrigger.style.display = 'none';
            regenConfirm.style.display = 'flex';
        });
        regenCancel.addEventListener('click', function () {
            regenConfirm.style.display = 'none';
            regenTrigger.style.display = '';
        });
    }
})();
</script>
