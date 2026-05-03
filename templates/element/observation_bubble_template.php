<?php
/**
 * Emite <template id="observation-bubble-template"> para clonado por
 * webroot/js/sgi-observation-chat.js.
 *
 * Gemelo estructural de templates/element/observation_bubble.php.
 * Cualquier cambio a data-slot, clases o markup debe aplicarse en ambos.
 */
?>
<template id="observation-bubble-template">
    <div class="sgi-obs-bubble is-mine" data-obs-id="">
        <div class="sgi-obs-bubble-name" data-slot="user_name"></div>
        <div class="sgi-obs-bubble-body" data-slot="message"></div>
        <div class="sgi-obs-bubble-time" data-slot="created"></div>
    </div>
</template>
