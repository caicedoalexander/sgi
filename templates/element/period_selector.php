<?php
/**
 * Period selector element for dashboard
 * @var \App\View\AppView $this
 * @var string $currentPeriod
 * @var string|null $dateFrom
 * @var string|null $dateTo
 */
$currentPeriod = $currentPeriod ?? 'month';
$dateFrom = $dateFrom ?? '';
$dateTo = $dateTo ?? '';

$periods = [
    'month' => 'Mes actual',
    'quarter' => 'Trimestre',
    'year' => 'Año actual',
    'all' => 'Todo',
    'custom' => 'Personalizado',
];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-4" id="period-selector">
    <span style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);">Período:</span>
    <div class="btn-group btn-group-sm" role="group">
        <?php foreach ($periods as $key => $label): ?>
            <?php if ($key === 'custom') continue; ?>
            <a href="<?= $this->Url->build(['?' => ['period' => $key]]) ?>"
               class="btn <?= $currentPeriod === $key ? 'btn-dark' : 'btn-outline-secondary' ?>"
               style="font-size:.75rem;font-weight:500;">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="d-flex align-items-center gap-1">
        <input type="text" class="form-control form-control-sm flatpickr-date" id="period-from"
               placeholder="Desde" value="<?= h($dateFrom) ?>"
               style="width:110px;font-size:.75rem;">
        <span style="font-size:.75rem;color:var(--text-muted);">—</span>
        <input type="text" class="form-control form-control-sm flatpickr-date" id="period-to"
               placeholder="Hasta" value="<?= h($dateTo) ?>"
               style="width:110px;font-size:.75rem;">
        <button type="button" class="btn btn-sm btn-outline-dark" id="period-custom-btn"
                style="font-size:.75rem;font-weight:500;">
            <i class="bi bi-funnel" aria-hidden="true"></i>
        </button>
    </div>
</div>
<script>
document.getElementById('period-custom-btn')?.addEventListener('click', function() {
    var from = document.getElementById('period-from').value;
    var to = document.getElementById('period-to').value;
    if (from && to) {
        window.location.href = '<?= $this->Url->build(['controller' => 'Dashboard', 'action' => 'index']) ?>' +
            '?period=custom&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    }
});
</script>
