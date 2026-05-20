<?php
/**
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?> | SGI · COPCSA</title>
    <link rel="icon" type="image/png" href="<?= $this->Url->build('/img/copcsa.png') ?>">
    <link href="<?= $this->Url->build('/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= $this->Url->build('/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <?= $this->Html->css('styles') ?>
    <?= $this->Html->css('components') ?>
    <style>
        /* ── Login · SGI · COPCSA — pantalla de acceso centrada ───────── */
        .sgi-login-body {
            background: var(--bg-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            overflow: hidden;
        }
        /* Retícula decorativa sutil (verde al 5%) */
        .sgi-login-grid {
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.05;
            background-image:
                linear-gradient(var(--primary-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--primary-color) 1px, transparent 1px);
            background-size: 48px 48px;
        }
        .sgi-login-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 380px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        /* Marca sobre la tarjeta */
        .sgi-login-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sgi-login-logo {
            width: 32px;
            height: 32px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .sgi-login-brand-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.3px;
            line-height: 1.15;
        }
        .sgi-login-brand-tag {
            font-size: 9.5px;
            font-weight: 600;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 2px;
        }

        /* Tarjeta del formulario */
        .sgi-login-card {
            background: #fff;
            padding: 32px 32px 28px;
        }
        .sgi-login-subtitle {
            font-size: var(--fs-body);
            color: var(--text-muted);
            margin: 4px 0 22px;
            line-height: 1.5;
        }

        /* Campos */
        .sgi-login-fieldset { margin-bottom: 14px; }
        .sgi-login-field-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 6px;
        }
        .sgi-login-fieldset > .sgi-label { display: block; margin-bottom: 6px; }
        .sgi-login-field-head > .sgi-label { margin-bottom: 0; }
        .sgi-login-forgot {
            font-size: 11px;
            font-weight: 600;
            color: var(--primary-color);
        }
        .sgi-login-forgot:hover { color: var(--primary-color-hover); }

        /* Input con icono */
        .sgi-login-input { position: relative; }
        .sgi-login-input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-faint);
            font-size: 14px;
            line-height: 1;
            pointer-events: none;
        }
        .sgi-login-input .form-control { padding-left: 36px; }
        .sgi-login-input .form-control.has-suffix { padding-right: 42px; }
        .sgi-login-toggle {
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--text-faint);
            padding: 8px;
            display: flex;
            cursor: pointer;
        }
        .sgi-login-toggle:hover { color: var(--text-muted); }

        /* Mantener sesión + botón */
        .sgi-login-remember { margin: 2px 0 18px; }
        .sgi-login-remember .form-check-label { font-size: var(--fs-body); }

        /* Pie de página */
        .sgi-login-foot {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            text-align: center;
            padding-top: 4px;
            font-size: 10.5px;
            letter-spacing: 0.3px;
            color: rgba(255, 255, 255, 0.4);
        }
        .sgi-login-foot-link {
            color: rgba(255, 255, 255, 0.55);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .sgi-login-foot-link:hover { color: rgba(255, 255, 255, 0.8); }

        /* Neutraliza el fondo amarillo del autocompletado del navegador */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text-default);
            -webkit-box-shadow: 0 0 0 100px #fff inset;
            caret-color: var(--text-default);
            transition: background-color 9999s ease-in-out 0s;
        }
    </style>
</head>
<body class="sgi-login-body">

    <div class="sgi-login-grid" aria-hidden="true"></div>

    <main class="sgi-login-shell">

        <!-- Marca -->
        <div class="sgi-login-brand">
            <img src="<?= $this->Url->build('/img/copcsa.png') ?>" alt="COPCSA" class="sgi-login-logo">
            <div>
                <div class="sgi-login-brand-name">SGI · COPCSA</div>
                <div class="sgi-login-brand-tag">Sistema de Gestión Interna</div>
            </div>
        </div>

        <!-- Tarjeta -->
        <div class="sgi-login-card">
            <h1 class="sgi-title-page">Iniciar sesión</h1>
            <p class="sgi-login-subtitle">Ingresa con tus credenciales corporativas.</p>

            <?= $this->Flash->render() ?>
            <?= $this->fetch('content') ?>
        </div>

        <!-- Pie -->
        <footer class="sgi-login-foot">
            <a href="#" class="sgi-login-foot-link">
                Contactar a TI <i class="bi bi-chevron-right" style="font-size:9px;" aria-hidden="true"></i>
            </a>
            <span>© 2026 Compañía Operadora Portuaria Cafetera S.A.</span>
        </footer>

    </main>

    <script src="<?= $this->Url->build('/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
    <script>
        document.getElementById('sgi-login-toggle')?.addEventListener('click', function () {
            const input = document.getElementById('password');
            const icon = this.querySelector('i');
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            icon.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
            this.setAttribute('aria-label', reveal ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    </script>
</body>
</html>
