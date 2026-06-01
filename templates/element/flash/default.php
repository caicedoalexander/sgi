<?php
/**
 * @var \App\View\AppView $this
 * @var array $params
 * @var string $message
 */
if (!isset($params['escape']) || $params['escape'] !== false) {
    $message = h($message);
}
echo $this->element('flash/_toast', [
    'variant' => 'info',
    'title' => 'Aviso',
    'icon' => 'bi-info-circle',
    'message' => $message,
]);
