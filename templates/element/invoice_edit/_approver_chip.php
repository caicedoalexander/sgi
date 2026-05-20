<?php
/**
 * Chip de aprobador (avatar + name + cargo·timestamp + status pill + X).
 * Render server-side, reutilizado tanto desde el widget como desde tests.
 *
 * @var string $name       Nombre completo
 * @var string $role       Cargo / rol (opcional, '' si no se conoce)
 * @var string $status     Uno de: approved, pending, rejected
 * @var ?string $timestamp Fecha respuesta formateada (ej: '13/05 09:35') o null
 * @var bool $removable    Mostrar botón X
 * @var ?int $userId       ID para acciones JS (opcional)
 */

// Paleta de avatares (7 tonos cálidos) — alineada a shared.jsx:107.
$palette = ['#469D61', '#CD6A15', '#83542B', '#212529', '#5a4a2a', '#4a6f5c', '#7a4c1e'];
// Hash estable basado en crc32 (PHP) — el spec usa hash JS, basta con que sea determinista.
$idx = abs(crc32($name)) % count($palette);
$bg = $palette[$idx];

// Iniciales (hasta 2 letras).
$initials = '';
foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
    if ($part !== '' && strlen($initials) < 2) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
}
if ($initials === '') { $initials = '·'; }

// Status → pill kind + icono + label.
$statusMap = [
    'approved' => ['pill' => 'pill-primary-soft', 'icon' => 'bi-check2',          'label' => 'Aprobado'],
    'pending'  => ['pill' => 'pill-warning-soft', 'icon' => 'bi-clock',           'label' => 'Pendiente'],
    'rejected' => ['pill' => 'pill-danger-soft',  'icon' => 'bi-x',               'label' => 'Rechazado'],
];
$st = $statusMap[$status] ?? $statusMap['pending'];

// Meta line: cargo + timestamp.
$metaParts = [];
if ($role !== '') { $metaParts[] = $role; }
$metaParts[] = $timestamp ?? 'sin respuesta';
$meta = implode(' · ', $metaParts);
?>
<div class="sgi-approver-chip" <?= $userId !== null ? 'data-user-id="' . (int)$userId . '"' : '' ?>>
    <span class="av av-sm" style="background:<?= $bg ?>;"><?= h($initials) ?></span>
    <div class="sgi-approver-chip-info">
        <span class="sgi-approver-chip-name"><?= h($name) ?></span>
        <?php if ($meta !== ''): ?>
        <span class="sgi-approver-chip-meta"><?= h($meta) ?></span>
        <?php endif; ?>
    </div>
    <span class="pill <?= $st['pill'] ?>">
        <i class="bi <?= $st['icon'] ?>" aria-hidden="true" style="font-size:9px;"></i><?= $st['label'] ?>
    </span>
    <?php if ($removable): ?>
        <button type="button" class="sgi-approver-chip-remove" data-action="remove-approver" aria-label="Quitar aprobador">
            <i class="bi bi-x" aria-hidden="true" style="font-size:11px;"></i>
        </button>
    <?php endif; ?>
</div>
