<?php
$unauthorized = $unauthorized ?? false;
$this->assign('title', $unauthorized ? 'Sin Autorización' : 'Enlace Expirado');
?>

<div class="card card-primary">
    <div class="card-body text-center p-5">
        <?php if ($unauthorized): ?>
            <i class="bi bi-shield-x" style="font-size:3rem;color:var(--danger-color);" aria-hidden="true"></i>
            <h4 class="mt-3" style="font-weight:700;color:#333;">Sin autorización</h4>
            <p style="color:#777;font-size:.9rem;">
                No tiene autorización para aprobar esta factura. Solo el aprobador asignado puede hacerlo.
            </p>
        <?php else: ?>
            <i class="bi bi-clock-history" style="font-size:3rem;color:var(--danger-color);" aria-hidden="true"></i>
            <h4 class="mt-3" style="font-weight:700;color:#333;">Enlace no válido</h4>
            <p style="color:#777;font-size:.9rem;">
                Este enlace de aprobación ha expirado, ya fue utilizado o no es válido.
            </p>
        <?php endif; ?>
    </div>
</div>
