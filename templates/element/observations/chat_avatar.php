<?php
/**
 * Avatar `.chat-av` con iniciales + color por hash del nombre.
 *
 * Fuente única del avatar del chat de observaciones compartido: lo usan tanto
 * el render server-side (observations/chat_item.php) como el <template> del JS
 * en observations/drawer.php.
 *
 * @var \App\View\AppView $this
 * @var string $name Nombre completo del autor.
 */
$palette = ['#469D61', '#CD6A15', '#83542B', '#212529', '#5a4a2a', '#4a6f5c', '#7a4c1e'];
$bg = $palette[abs(crc32($name)) % count($palette)];

$initials = '';
foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
    if ($part !== '' && strlen($initials) < 2) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
}
if ($initials === '') {
    $initials = '·';
}
?>
<span class="chat-av" style="background-color:<?= $bg ?>;"><?= h($initials) ?></span>
