<?php
$unauthorized = $unauthorized ?? false;
$this->assign('title', $unauthorized ? 'Sin Autorización' : 'Enlace Expirado');
?>

<?php if ($unauthorized): ?>
<div class="empty-state">
    <div class="es-icon es-icon-danger"><i class="bi bi-shield-x" aria-hidden="true"></i></div>
    <div class="es-title">Sin autorización</div>
    <div class="es-msg">No tiene autorización para aprobar esta factura. Solo el aprobador asignado puede hacerlo.</div>
</div>
<?php else: ?>
<div class="empty-state">
    <div class="es-icon es-icon-warning"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
    <div class="es-title">Enlace no válido</div>
    <div class="es-msg">Este enlace de aprobación ha expirado, ya fue utilizado o no es válido.</div>
</div>
<?php endif; ?>
