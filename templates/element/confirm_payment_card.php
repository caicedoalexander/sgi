<?php
/**
 * Tarjeta "Cerrar flujo" mostrada cuando un registro está en
 * `verificacion_pago` y el usuario actual puede confirmar (Tesorería/Admin).
 *
 * Se renderiza desde la vista de cada uno de los 5 módulos del feature
 * `verificacion_pago`. Centraliza el bloque para evitar drift entre vistas.
 *
 * @var \App\View\AppView $this
 * @var array $confirmUrl Cake URL array para Form->postLink (controller/action/id).
 * @var bool $canConfirm True si el rol actual puede confirmar.
 * @var bool $isVerificacionPago True si el registro está en el estado correcto.
 * @var string|null $message Texto opcional sobre el botón.
 */

$message ??= 'El pago fue autorizado por el Contador. Verifique que todos los soportes estén cargados y cierre el flujo.';

if (!($canConfirm ?? false) || !($isVerificacionPago ?? false)) {
    return;
}
?>
<div class="sgi-action-card">
    <div class="sgi-action-card-label">Cierre de flujo</div>
    <p class="sgi-action-card-message"><?= h($message) ?></p>
    <?= $this->Form->postLink(
        '<i class="bi bi-check2-circle me-1"></i>Cerrar flujo',
        $confirmUrl,
        [
            'class' => 'btn sgi-btn-primary',
            'escape' => false,
            'confirm' => '¿Confirmar que los soportes están completos y desea cerrar el flujo?',
        ],
    ) ?>
</div>
