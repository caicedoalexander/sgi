<?php
/**
 * External layout - minimal, no sidebar, no auth required
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SGI - <?= $this->fetch('title') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $this->Url->build('/favicon.svg') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?= $this->Html->css('styles') ?>
    <style>
        body {
            background: var(--background-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div style="background:var(--bg-dark);padding:.75rem 1.5rem;">
        <div class="d-flex align-items-center gap-2">
            <div class="d-flex align-items-center justify-content-center"
                 style="width:32px;height:32px;background-color:var(--primary-color);flex-shrink:0;">
                <i class="bi bi-building text-white" style="font-size:.85rem;"></i>
            </div>
            <div>
                <div class="fw-bold text-white" style="font-size:.95rem;letter-spacing:-.02em;">SGI</div>
                <div style="font-size:.5rem;letter-spacing:.1em;color:rgba(255,255,255,.3);text-transform:uppercase;">Aprobación Externa</div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <main class="flex-grow-1 d-flex align-items-start justify-content-center" style="padding:2rem 1rem;">
        <div style="max-width:600px;width:100%;">
            <?= $this->fetch('content') ?>
        </div>
    </main>

    <!-- Flash -->
    <div id="sgi-flash-container">
        <?= $this->Flash->render() ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
