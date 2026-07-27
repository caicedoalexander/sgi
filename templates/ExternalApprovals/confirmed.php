<?php
/**
 * @var bool $success
 * @var string $action
 */
$this->assign('title', 'Acción Confirmada');
$actionLabel = $action === 'approve' ? 'aprobada' : 'rechazada';
?>

<?php if ($success): ?>
<div class="empty-state">
    <div class="es-icon es-icon-primary"><i class="bi bi-check-circle" aria-hidden="true"></i></div>
    <div class="es-title">Acción completada</div>
    <div class="es-msg">La solicitud ha sido <strong><?= $actionLabel ?></strong> exitosamente.</div>
</div>
<?php else: ?>
<div class="empty-state">
    <div class="es-icon es-icon-danger"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></div>
    <div class="es-title">Error</div>
    <div class="es-msg">No se pudo procesar la acción. El enlace puede haber expirado.</div>
</div>
<?php endif; ?>
