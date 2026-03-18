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
    <title>SGI COPC — <?= $this->fetch('title') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $this->Url->build('/favicon.svg') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?= $this->Html->css('styles') ?>
    <style>
        body {
            background-color: var(--bg-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            /* Patrón de puntos sutil */
            background-image: radial-gradient(rgba(255,255,255,.04) 1px, transparent 1px);
            background-size: 24px 24px;
        }
        .sgi-error-wrapper {
            width: 100%;
            max-width: 520px;
            padding: 2rem;
            text-align: center;
        }
        .sgi-error-logo {
            display: inline-flex;
            justify-content: center;
            margin-bottom: 3rem;
        }
        /* Anula el mb-3 del element para este contexto */
        .sgi-error-logo a {
            margin-bottom: 0 !important;
        }
        .sgi-error-divider {
            width: 40px;
            height: 2px;
            background-color: var(--primary-color);
            margin: 1.5rem auto;
        }
        .sgi-error-action {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            margin-top: 2rem;
            padding: .625rem 1.25rem;
            background-color: var(--primary-color);
            color: #fff;
            font-size: .875rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            letter-spacing: .01em;
            transition: background-color .15s ease;
        }
        .sgi-error-action:hover {
            background-color: #3a8752;
            color: #fff;
        }
        .sgi-error-footer {
            margin-top: 3rem;
            font-size: .6rem;
            letter-spacing: .08em;
            color: rgba(255,255,255,.15);
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="sgi-error-wrapper">

        <div class="sgi-error-logo">
            <?= $this->element('sgi_logo') ?>
        </div>

        <?= $this->fetch('content') ?>

        <a href="<?= $this->Url->build('/') ?>" class="sgi-error-action">
            <i class="bi bi-arrow-left"></i>
            Volver al inicio
        </a>

        <div class="sgi-error-footer">
            Compañía Operadora Portuaria Cafetera S.A.
        </div>

    </div>
</body>
</html>
