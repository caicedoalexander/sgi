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
    <title>SGI - <?= $this->fetch('title') ?></title>
    <?= $this->Html->meta('icon') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?= $this->Html->css('styles') ?>
</head>
<body style="background:var(--bg-dark);min-height:100vh;">
    <div class="d-flex" style="min-height:100vh;">

        <!-- Panel izquierdo: branding -->
        <div class="d-flex flex-column justify-content-between p-5"
             style="width:42%;flex-shrink:0;">

            <!-- Logo -->
            <div class="d-flex align-items-center gap-2">
                <div class="d-flex align-items-center justify-content-center"
                     style="width:36px;height:36px;background-color:var(--primary-color);flex-shrink:0;">
                    <i class="bi bi-building text-white" style="font-size:1rem;"></i>
                </div>
                <div>
                    <div class="fw-bold text-white lh-1" style="font-size:1.3rem;letter-spacing:-.02em;">SGI</div>
                    <!-- <div style="font-size:.55rem;letter-spacing:.1em;color:rgba(255,255,255,.3);text-transform:uppercase;margin-top:3px;">Sistema de Gestión Interna</div> -->
                </div>
            </div>

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
                    <h1 class="fw-bold mb-0" style="font-size:1.5rem;letter-spacing:-.03em;color:#111;">
                        Iniciar sesión
                    </h1>
                </div>

                <?= $this->Flash->render() ?>
                <?= $this->fetch('content') ?>

            </div>
        </div>

    </div>

    <?= $this->element('copcsa') ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
