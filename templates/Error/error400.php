<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 * @var \Throwable|null $error
 */

use Cake\Core\Configure;
use Cake\Http\Exception\ForbiddenException;

if (isset($error) && $error instanceof ForbiddenException):
    $this->setLayout('default');
?>
    <div class="spi-forbidden-page">
        <h1>Acceso restringido</h1>
        <p><?= h($error->getMessage()) ?></p>
        <p>Si crees que es un error, contacta al administrador.</p>
        <a href="<?= $this->Url->build(['controller' => 'Dashboard', 'action' => 'index']) ?>"
           class="btn btn-primary">Volver al inicio</a>
    </div>
<?php
    return;
endif;

$this->setLayout('error');

if (Configure::read('debug')) :
    $this->setLayout('dev_error');

    $this->assign('title', $message);
    $this->assign('templateName', 'error400.php');

    $this->start('file');
    echo $this->element('auto_table_warning');
    $this->end();
endif;

$code = isset($error) && method_exists($error, 'getCode') ? (int)$error->getCode() : 404;
?>

<div style="
    font-size: 6.5rem;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -.04em;
    color: var(--primary-color);
    font-variant-numeric: tabular-nums;
    margin-bottom: .25rem;
"><?= $code ?></div>

<div style="
    font-size: .55rem;
    font-weight: 600;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: rgba(255,255,255,.25);
    margin-bottom: 1.5rem;
">Error del cliente</div>

<div class="spi-error-divider"></div>

<p style="
    font-size: .95rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: .5rem;
    letter-spacing: -.01em;
"><?= h($message) ?></p>

<p style="
    font-size: .8rem;
    color: rgba(255,255,255,.35);
    line-height: 1.6;
    margin: 0;
">
    La dirección <code style="
        font-size: var(--fs-body-sm);
        color: var(--primary-color);
        background: rgba(70,157,97,.1);
        padding: .1rem .4rem;
        border: 1px solid rgba(70,157,97,.2);
    "><?= h($url) ?></code>
    no pudo ser encontrada en este servidor.
</p>
