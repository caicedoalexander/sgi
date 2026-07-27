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
    <title>SPI COPC — <?= $this->fetch('title') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $this->Url->build('/favicon.svg') ?>">
    <link href="<?= $this->Url->build('/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= $this->Url->build('/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <?= $this->Html->css('styles') ?>
    <?= $this->Html->css('components') ?>
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
        .spi-error-wrapper {
            width: 100%;
            max-width: 520px;
            padding: 2rem;
            text-align: center;
        }
        .spi-error-logo {
            display: inline-flex;
            justify-content: center;
            margin-bottom: 3rem;
        }
        /* Anula el mb-3 del element para este contexto */
        .spi-error-logo a {
            margin-bottom: 0 !important;
        }
        .spi-error-divider {
            width: 40px;
            height: 2px;
            background-color: var(--primary-color);
            margin: 1.5rem auto;
        }
        .spi-error-action {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            margin-top: 2rem;
            padding: .625rem 1.25rem;
            background-color: var(--primary-color);
            color: #fff;
            font-size: var(--fs-title-card);
            font-weight: 600;
            text-decoration: none;
            border: none;
            letter-spacing: .01em;
            transition: background-color .15s ease;
        }
        .spi-error-action:hover {
            background-color: var(--primary-color-hover);
            color: #fff;
        }
        .spi-error-footer {
            margin-top: 3rem;
            font-size: var(--fs-micro);
            letter-spacing: .08em;
            color: rgba(255,255,255,.15);
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="spi-error-wrapper">

        <div class="spi-error-logo">
            <?= $this->element('spi_logo') ?>
        </div>

        <?= $this->fetch('content') ?>

        <a href="<?= $this->Url->build('/') ?>" class="spi-error-action">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Volver al inicio
        </a>

        <div class="spi-error-footer">
            Compañía Operadora Portuaria Cafetera S.A.
        </div>

    </div>
</body>
</html>
