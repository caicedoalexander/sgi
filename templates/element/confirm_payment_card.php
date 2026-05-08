<?php
/**
 * Tarjeta "Pasar a Pagada" mostrada cuando un registro está en
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

$message ??= 'El pago fue autorizado por el Contador. Confirme cuando el dinero haya salido del banco.';

if (!($canConfirm ?? false) || !($isVerificacionPago ?? false)) {
    return;
}
?>
<div class="card mt-3 mb-4" style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);border-radius:0;">
    <div class="card-body">
        <p class="mb-2" style="font-size:.9rem;"><?= h($message) ?></p>
        <?= $this->Form->postLink(
            '<i class="bi bi-cash-coin me-1"></i>Pasar a Pagada',
            $confirmUrl,
            [
                'class' => 'sgi-btn-primary',
                'escape' => false,
                'confirm' => '¿Confirmar que el pago ya se ejecutó?',
            ],
        ) ?>
    </div>
</div>
