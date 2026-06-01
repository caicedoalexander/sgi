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
    'variant' => 'success',
    'title' => 'Listo',
    'icon' => 'bi-check-lg',
    'message' => $message,
]);
