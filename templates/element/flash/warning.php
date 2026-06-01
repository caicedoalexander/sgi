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
    'variant' => 'warning',
    'title' => 'Atención',
    'icon' => 'bi-exclamation-circle',
    'message' => $message,
]);
