<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 */
use Cake\Core\Configure;
use Cake\Error\Debugger;

$this->setLayout('error');

if (Configure::read('debug')) :
    $this->setLayout('dev_error');

    $this->assign('title', $message);
    $this->assign('templateName', 'error500.php');

    $this->start('file');
?>
<?php if ($error instanceof Error) : ?>
    <?php $file = $error->getFile() ?>
    <?php $line = $error->getLine() ?>
    <strong>Error in: </strong>
    <?= $this->Html->link(sprintf('%s, line %s', Debugger::trimPath($file), $line), Debugger::editorUrl($file, $line)); ?>
<?php endif; ?>
<?php
    echo $this->element('auto_table_warning');

    $this->end();
endif;
?>

<div style="
    font-size: 6.5rem;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -.04em;
    color: var(--primary-color);
    font-variant-numeric: tabular-nums;
    margin-bottom: .25rem;
">500</div>

<div style="
    font-size: .55rem;
    font-weight: 600;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: rgba(255,255,255,.25);
    margin-bottom: 1.5rem;
">Error interno del servidor</div>

<div class="spi-error-divider"></div>

<p style="
    font-size: .95rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: .5rem;
    letter-spacing: -.01em;
">Algo salió mal</p>

<p style="
    font-size: .8rem;
    color: rgba(255,255,255,.35);
    line-height: 1.6;
    margin: 0;
">
    El servidor encontró un error inesperado y no pudo completar la solicitud.
    Si el problema persiste, contacta al administrador del sistema.
</p>
