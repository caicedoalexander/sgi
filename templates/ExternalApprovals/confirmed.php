<?php
/**
 * @var bool $success
 * @var string $action
 */
$this->assign('title', 'Acción Confirmada');
$actionLabel = $action === 'approve' ? 'aprobada' : 'rechazada';
?>

<div class="card card-primary">
    <div class="card-body text-center p-5">
        <?php if ($success): ?>
            <i class="bi bi-check-circle" style="font-size:3rem;color:var(--primary-color);"></i>
            <h4 class="mt-3" style="font-weight:700;color:#333;">Acción Completada</h4>
            <p style="color:#777;font-size:.9rem;">
                La solicitud ha sido <strong><?= $actionLabel ?></strong> exitosamente.
            </p>
        <?php else: ?>
            <i class="bi bi-exclamation-circle" style="font-size:3rem;color:#dc3545;"></i>
            <h4 class="mt-3" style="font-weight:700;color:#333;">Error</h4>
            <p style="color:#777;font-size:.9rem;">
                No se pudo procesar la acción. El enlace puede haber expirado.
            </p>
        <?php endif; ?>
    </div>
</div>
