<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Iniciar Sesión');
?>

<?= $this->Form->create(null, ['url' => ['action' => 'login', '?' => ['redirect' => $this->request->getQuery('redirect')]]]) ?>

<!-- Usuario -->
<div class="spi-login-fieldset">
    <label class="spi-label" for="username">Usuario</label>
    <div class="spi-login-input">
        <i class="bi bi-person spi-login-input-icon" aria-hidden="true"></i>
        <?= $this->Form->control('username', [
            'label'       => false,
            'class'       => 'form-control',
            'id'          => 'username',
            'placeholder' => 'Ingresa tu usuario',
            'autofocus'   => true,
            'autocomplete' => 'username',
            'templates'   => ['inputContainer' => '{{content}}'],
        ]) ?>
    </div>
</div>

<!-- Contraseña -->
<div class="spi-login-fieldset">
    <div class="spi-login-field-head">
        <label class="spi-label" for="password">Contraseña</label>
        <a href="#" class="spi-login-forgot">¿Olvidaste?</a>
    </div>
    <div class="spi-login-input">
        <i class="bi bi-shield-lock spi-login-input-icon" aria-hidden="true"></i>
        <?= $this->Form->control('password', [
            'label'       => false,
            'type'        => 'password',
            'class'       => 'form-control has-suffix',
            'id'          => 'password',
            'placeholder' => 'Ingresa tu contraseña',
            'autocomplete' => 'current-password',
            'templates'   => ['inputContainer' => '{{content}}'],
        ]) ?>
        <button type="button" id="spi-login-toggle" class="spi-login-toggle" aria-label="Mostrar contraseña">
            <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
    </div>
</div>

<!-- Mantener sesión -->
<label class="form-check spi-login-remember">
    <input type="checkbox" class="form-check-input" name="remember" checked>
    <span class="form-check-label">Mantener sesión iniciada</span>
</label>

<!-- Botón -->
<?= $this->Form->button('Ingresar', [
    'class' => 'btn btn-primary btn-lg w-100',
]) ?>

<?= $this->Form->end() ?>
