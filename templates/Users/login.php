<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Iniciar Sesión');
?>
<div class="card shadow-lg border-0 rounded-4">
    <div class="card-body p-5">
        <div class="text-center mb-4">
            <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
                <i class="bi bi-building text-white fs-3"></i>
            </div>
            <h4 class="fw-bold mb-1">SGI</h4>
            <p class="text-muted small">Sistema de Gestión Interna</p>
        </div>

        <?= $this->Form->create(null, ['url' => ['action' => 'login']]) ?>

        <div class="mb-3">
            <label for="username" class="form-label fw-semibold">Usuario</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0">
                    <i class="bi bi-person text-muted"></i>
                </span>
                <?= $this->Form->control('username', [
                    'label' => false,
                    'class' => 'form-control border-start-0 ps-0',
                    'id' => 'username',
                    'placeholder' => 'Ingrese su usuario',
                    'autofocus' => true,
                    'templates' => ['inputContainer' => '{{content}}'],
                ]) ?>
            </div>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label fw-semibold">Contraseña</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0">
                    <i class="bi bi-lock text-muted"></i>
                </span>
                <?= $this->Form->control('password', [
                    'label' => false,
                    'type' => 'password',
                    'class' => 'form-control border-start-0 ps-0',
                    'id' => 'password',
                    'placeholder' => '••••••••',
                    'templates' => ['inputContainer' => '{{content}}'],
                ]) ?>
            </div>
        </div>

        <?= $this->Form->button('Ingresar', [
            'class' => 'btn btn-primary w-100 py-2 fw-semibold',
        ]) ?>

        <?= $this->Form->end() ?>
    </div>
</div>
<p class="text-center text-white-50 small mt-3">
    Compañía Operadora Portuaria Cafetera S.A.
</p>
