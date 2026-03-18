<a href="<?= $this->Url->build('/') ?>"
   class="text-white text-decoration-none d-flex align-items-center mb-3"
   style="gap:0;outline:none;">

    <!-- Marco del logo de empresa -->
    <div style="
        width:38px;
        height:38px;
        flex-shrink:0;
        border:1px solid rgba(255,255,255,.1);
        display:flex;
        align-items:center;
        justify-content:center;
        padding:5px;
        transition:border-color .2s ease;
    ">
        <img src="<?= $this->Url->build('img/copcsa.png') ?>"
             alt="COPCSA"
             style="width:100%;height:100%;object-fit:contain;display:block;">
    </div>

    <!-- Divisor vertical verde (lenguaje del design system) -->
    <div style="
        width:2px;
        height:28px;
        background-color:var(--primary-color);
        flex-shrink:0;
        margin:0 11px;
        transition:opacity .2s ease;
    "></div>

    <!-- Bloque de texto del sistema -->
    <div style="min-width:0;line-height:1;">
        <div style="
            font-size:.975rem;
            font-weight:700;
            color:#fff;
            letter-spacing:-.03em;
            line-height:1;
        ">SGI COPC</div>
        <div style="
            font-size:.565rem;
            font-weight:600;
            letter-spacing:.07em;
            color:rgba(255,255,255,.32);
            text-transform:uppercase;
            margin-top:5px;
            line-height:1;
            white-space:nowrap;
        ">Sistema de Gestión Interna</div>
    </div>

</a>
