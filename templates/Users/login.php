<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Iniciar Sesión');
?>

<?= $this->Form->create(null, ['url' => ['action' => 'login']]) ?>

<!-- Usuario -->
<div class="mb-3">
    <label class="d-block text-uppercase fw-semibold mb-1"
           style="font-size:.6rem;letter-spacing:.12em;color:#999;">
        Usuario
    </label>
    <div class="sgi-input-group d-flex align-items-center">
        <span class="px-3" style="color:#bbb;flex-shrink:0;">
            <i class="bi bi-person" style="font-size:.95rem;"></i>
        </span>
        <?= $this->Form->control('username', [
            'label'     => false,
            'class'     => 'form-control border-0 shadow-none',
            'id'        => 'username',
            'placeholder' => 'Ingrese su usuario',
            'autofocus' => true,
            'style'     => 'border-radius:0;font-size:.875rem;',
            'templates'  => ['inputContainer' => '{{content}}'],
        ]) ?>
    </div>
</div>

<!-- Contraseña -->
<div class="mb-4">
    <label class="d-block text-uppercase fw-semibold mb-1"
           style="font-size:.6rem;letter-spacing:.12em;color:#999;">
        Contraseña
    </label>
    <div class="sgi-input-group d-flex align-items-center">
        <span class="px-3" style="color:#bbb;flex-shrink:0;">
            <i class="bi bi-lock" style="font-size:.95rem;"></i>
        </span>
        <?= $this->Form->control('password', [
            'label'     => false,
            'type'      => 'password',
            'class'     => 'form-control border-0 shadow-none',
            'id'        => 'password',
            'placeholder' => '••••••••',
            'style'     => 'border-radius:0;font-size:.875rem;',
            'templates'  => ['inputContainer' => '{{content}}'],
        ]) ?>
    </div>
</div>

<!-- Botón -->
<?= $this->Form->button('Ingresar', [
    'class' => 'btn sgi-btn-primary w-100 py-2',
]) ?>

<?= $this->Form->end() ?>
