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
</head>
<body style="background:var(--bg-dark);min-height:100vh;">
    <div class="d-flex" style="min-height:100vh;">

        <!-- Panel izquierdo: branding -->
        <div class="d-flex flex-column justify-content-between p-5"
             style="width:42%;flex-shrink:0;">

            <?= $this->element('sgi_logo') ?>

            <!-- Tagline central -->
            <div>
                <p class="mb-2 text-uppercase fw-semibold"
                   style="font-size:.6rem;letter-spacing:.16em;color:var(--primary-color);">
                    Sistema de Gestión Interna
                </p>
                <h2 class="fw-bold text-white mb-3" style="font-size:2rem;letter-spacing:-.04em;line-height:1.15;">
                    Compañía Operadora<br>Portuaria Cafetera
                </h2>
                <p style="font-size:.82rem;color:rgba(255,255,255,.35);line-height:1.6;">
                    Plataforma interna para la gestión de facturas,<br>
                    empleados y operaciones portuarias.
                </p>
            </div>

            <!-- Footer del panel -->
            <div style="font-size:.65rem;color:rgba(255,255,255,.2);letter-spacing:.04em;">
                Compañía Operadora Portuaria Cafetera S.A. · Todos los derechos reservados
            </div>
        </div>

        <!-- Panel derecho: formulario -->
        <div class="flex-grow-1 d-flex align-items-center justify-content-center p-4"
             style="background:#fff;border-left:2px solid var(--primary-color);">
            <div style="width:100%;max-width:380px;">

                <!-- Encabezado del formulario -->
                <div class="mb-4">
                    <p class="mb-1 text-uppercase fw-semibold"
                       style="font-size:.6rem;letter-spacing:.14em;color:var(--primary-color);">
                        Acceso al sistema
                    </p>
                    <span class="sgi-page-title">
                        Iniciar sesión
                    </span>
                </div>

                <?= $this->Flash->render() ?>
                <?= $this->fetch('content') ?>

            </div>
        </div>

    </div>

    <script src="<?= $this->Url->build('/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
