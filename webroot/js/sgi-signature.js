/**
 * SGI Signature Pad — Reusable signature capture with overlay.
 *
 * Supports mouse, touch, and digitizer pen devices (Pointer Events API).
 * Pen devices report pressure for variable-width strokes.
 *
 * Usage (auto-init):
 *   <div class="sgi-signature-pad"
 *        data-target=".my-hidden-input"
 *        data-signer-label="Firma del Gerente">
 *   </div>
 *   <input type="hidden" name="signature_base64" class="my-hidden-input">
 *
 * Usage (programmatic):
 *   SgiSignature.open({
 *       title: 'Firma',
 *       onAccept: function(dataUrl) { ... }
 *   });
 *
 * Digitizer integration:
 *   Modern signature pads (Wacom STU, Topaz, Surface Pen) that present
 *   as HID pen devices work automatically via Pointer Events API.
 *   The script detects pointerType === 'pen' and uses e.pressure
 *   for variable-width strokes. For proprietary SDK-based devices,
 *   use SgiSignature.injectSignature(padElement, dataUrl) to feed
 *   captured data from the vendor SDK.
 */
(function () {
    'use strict';

    // ────────────────────────────────────────
    // State
    // ────────────────────────────────────────
    var drawing = false;
    var hasDrawn = false;
    var lastPoint = null;
    var currentCallback = null;
    var overlayEl = null;

    // ────────────────────────────────────────
    // Overlay (singleton, created on first use)
    // ────────────────────────────────────────
    function getOverlay() {
        if (overlayEl) return overlayEl;

        // Overlay backdrop
        var overlay = document.createElement('div');
        overlay.id = 'sgi-sig-overlay';
        overlay.style.cssText = [
            'position:fixed', 'inset:0', 'z-index:9999',
            'background:rgba(0,0,0,.6)', 'display:none',
            'align-items:center', 'justify-content:center',
            'backdrop-filter:blur(3px)', '-webkit-backdrop-filter:blur(3px)',
        ].join(';');

        // Card — excepción documentada al lenguaje "radio 0-2px + sin box-shadow"
        // (ver .claude/rules/design.md sección "Excepciones permitidas").
        // Modal temporal que flota sobre fondo desenfocado (backdrop-filter:blur);
        // los bordes serían insuficientes contra el blur — la sombra da la separación.
        var card = document.createElement('div');
        card.style.cssText = [
            'background:#fff', 'border-radius:10px', 'padding:1.5rem',
            'width:92vw', 'max-width:680px',
            'box-shadow:0 16px 48px rgba(0,0,0,.25)',
            'animation:sgiSigFadeIn .2s ease',
        ].join(';');

        // ── Header ──
        var header = document.createElement('div');
        header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;';

        var title = document.createElement('div');
        title.id = 'sgi-sig-title';
        title.style.cssText = 'font-size:1rem;font-weight:700;color:#111;';
        title.textContent = 'Firma';

        var deviceBadge = document.createElement('span');
        deviceBadge.id = 'sgi-sig-device';
        deviceBadge.style.cssText = 'font-size:.68rem;padding:.2rem .55rem;border-radius:4px;background:#f0f0f0;color:#888;font-weight:600;';
        deviceBadge.textContent = 'Mouse';

        header.appendChild(title);
        header.appendChild(deviceBadge);
        card.appendChild(header);

        // ── Canvas wrapper ──
        var canvasWrap = document.createElement('div');
        canvasWrap.id = 'sgi-sig-canvas-wrap';
        canvasWrap.style.cssText = [
            'border:2px solid #e0e0e0', 'border-radius:8px', 'overflow:hidden',
            'background:#fff', 'touch-action:none', 'transition:border-color .3s',
        ].join(';');

        var canvas = document.createElement('canvas');
        canvas.id = 'sgi-sig-canvas';
        canvas.style.cssText = 'display:block;width:100%;height:auto;cursor:crosshair;';
        canvasWrap.appendChild(canvas);
        card.appendChild(canvasWrap);

        // ── Hint ──
        var hint = document.createElement('div');
        hint.style.cssText = 'font-size:.7rem;color:#aaa;margin-top:.5rem;text-align:center;';
        hint.innerHTML = '<i class="bi bi-pen" style="margin-right:.25rem;"></i>Dibuje su firma con el mouse, pantalla táctil o dispositivo digitalizador';
        card.appendChild(hint);

        // ── Buttons ──
        var buttons = document.createElement('div');
        buttons.style.cssText = 'display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;';

        var btnClear = makeButton('sgi-sig-clear', 'btn btn-outline-secondary btn-sm', '<i class="bi bi-eraser me-1"></i>Limpiar');
        var btnCancel = makeButton('sgi-sig-cancel', 'btn btn-outline-dark btn-sm', '<i class="bi bi-x-lg me-1"></i>Cancelar');
        var btnAccept = makeButton('sgi-sig-accept', 'btn btn-primary btn-sm', '<i class="bi bi-check-lg me-1"></i>Aceptar');

        buttons.appendChild(btnClear);
        buttons.appendChild(btnCancel);
        buttons.appendChild(btnAccept);
        card.appendChild(buttons);

        overlay.appendChild(card);

        // Inject fade-in animation
        if (!document.getElementById('sgi-sig-style')) {
            var style = document.createElement('style');
            style.id = 'sgi-sig-style';
            style.textContent = '@keyframes sgiSigFadeIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}';
            document.head.appendChild(style);
        }

        document.body.appendChild(overlay);

        // ── Event wiring ──
        btnClear.addEventListener('click', clearCanvas);
        btnCancel.addEventListener('click', closeOverlay);
        btnAccept.addEventListener('click', acceptSignature);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlayEl && overlayEl.style.display === 'flex') closeOverlay();
        });

        // Pointer events on canvas (unified mouse + touch + pen)
        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', onPointerUp);
        canvas.addEventListener('pointerleave', onPointerUp);
        // Prevent default touch scroll inside canvas
        canvas.addEventListener('touchstart', function (e) { e.preventDefault(); }, { passive: false });

        overlayEl = overlay;
        return overlay;
    }

    function makeButton(id, cls, html) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = id;
        btn.className = cls;
        btn.innerHTML = html;
        return btn;
    }

    // ────────────────────────────────────────
    // Canvas drawing (Pointer Events API)
    // ────────────────────────────────────────
    function getCanvasPos(canvas, e) {
        var rect = canvas.getBoundingClientRect();
        var scaleX = canvas.width / rect.width;
        var scaleY = canvas.height / rect.height;
        return {
            x: (e.clientX - rect.left) * scaleX,
            y: (e.clientY - rect.top) * scaleY,
        };
    }

    function onPointerDown(e) {
        e.preventDefault();
        var canvas = e.currentTarget;
        canvas.setPointerCapture(e.pointerId);
        drawing = true;

        var pos = getCanvasPos(canvas, e);
        lastPoint = pos;

        var ctx = canvas.getContext('2d');
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);

        updateDeviceBadge(e.pointerType);
    }

    function onPointerMove(e) {
        if (!drawing) return;
        e.preventDefault();
        hasDrawn = true;

        var canvas = e.currentTarget;
        var ctx = canvas.getContext('2d');
        var pos = getCanvasPos(canvas, e);

        // Pressure-sensitive line width (pen digitizers report 0–1)
        var pressure = e.pressure > 0 ? e.pressure : 0.5;
        ctx.lineWidth = 1 + pressure * 3.5;

        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);

        lastPoint = pos;
    }

    function onPointerUp() {
        drawing = false;
        lastPoint = null;
    }

    function updateDeviceBadge(pointerType) {
        var badge = document.getElementById('sgi-sig-device');
        if (!badge) return;
        var map = {
            mouse: { label: 'Mouse', bg: '#f0f0f0', color: '#888' },
            touch: { label: 'Pantalla Táctil', bg: '#e8f4fd', color: '#0d6efd' },
            pen:   { label: 'Digitalizador', bg: '#e8fde8', color: '#198754' },
        };
        var info = map[pointerType] || map.mouse;
        badge.textContent = info.label;
        badge.style.background = info.bg;
        badge.style.color = info.color;
    }

    // ────────────────────────────────────────
    // Overlay actions
    // ────────────────────────────────────────
    function setupCtx(ctx) {
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
    }

    function clearCanvas() {
        var canvas = document.getElementById('sgi-sig-canvas');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        setupCtx(ctx);
        hasDrawn = false;
        // Reset border if it was red
        document.getElementById('sgi-sig-canvas-wrap').style.borderColor = '#e0e0e0';
    }

    function closeOverlay() {
        if (overlayEl) overlayEl.style.display = 'none';
        currentCallback = null;
        drawing = false;
        hasDrawn = false;
    }

    function acceptSignature() {
        if (!hasDrawn) {
            // Visual feedback: flash red border
            var wrap = document.getElementById('sgi-sig-canvas-wrap');
            wrap.style.borderColor = '#dc3545';
            setTimeout(function () { wrap.style.borderColor = '#e0e0e0'; }, 1200);
            return;
        }

        var canvas = document.getElementById('sgi-sig-canvas');
        var dataUrl = canvas.toDataURL('image/png');

        if (currentCallback) currentCallback(dataUrl);
        closeOverlay();
    }

    /**
     * Open the signature overlay.
     *
     * @param {Object} options
     * @param {string}   options.title    - Overlay title (default: 'Firma')
     * @param {Function} options.onAccept - Called with base64 dataUrl on accept
     */
    function openOverlay(options) {
        var overlay = getOverlay();
        var canvas = document.getElementById('sgi-sig-canvas');
        var title = document.getElementById('sgi-sig-title');

        title.textContent = options.title || 'Firma';

        // High-res canvas for quality signatures
        canvas.width = 650;
        canvas.height = 200;

        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        setupCtx(ctx);

        hasDrawn = false;
        drawing = false;
        currentCallback = options.onAccept || null;

        overlay.style.display = 'flex';
        updateDeviceBadge('mouse');
    }

    // ────────────────────────────────────────
    // Pad auto-initialization
    // ────────────────────────────────────────

    /**
     * Initialize a signature pad container.
     * The container shows a clickable preview; clicking opens the overlay.
     *
     * @param {HTMLElement} container - Element with class .sgi-signature-pad
     */
    function initPad(container) {
        var targetSelector = container.dataset.target;
        var signerLabel = container.dataset.signerLabel || 'Firma';
        var existingImage = container.dataset.existingImage || '';

        // Base styles
        container.style.cssText += [
            ';cursor:pointer', 'border:2px dashed #ddd', 'border-radius:8px',
            'display:flex', 'align-items:center', 'justify-content:center',
            'min-height:80px', 'transition:border-color .2s,box-shadow .2s',
            'position:relative', 'overflow:hidden', 'background:#fafafa',
        ].join(';');

        if (existingImage) {
            showPreviewImage(container, existingImage);
            container.style.borderStyle = 'solid';
            container.style.borderColor = 'var(--primary-color, #198754)';
        } else {
            showPlaceholder(container);
        }

        // Hover effect
        container.addEventListener('mouseenter', function () {
            container.style.borderColor = 'var(--primary-color, #198754)';
            container.style.boxShadow = '0 0 0 3px rgba(25,135,84,.12)';
        });
        container.addEventListener('mouseleave', function () {
            if (!container.dataset.signed) {
                container.style.borderColor = '#ddd';
                container.style.boxShadow = 'none';
            }
        });

        // Click → open overlay
        container.addEventListener('click', function () {
            openOverlay({
                title: signerLabel,
                onAccept: function (dataUrl) {
                    // Store base64 in target hidden input
                    if (targetSelector) {
                        var scope = container.closest('form') || document;
                        var input = scope.querySelector(targetSelector);
                        if (input) input.value = dataUrl;
                    }

                    // Update preview
                    container.innerHTML = '';
                    showPreviewImage(container, dataUrl);
                    container.style.borderStyle = 'solid';
                    container.style.borderColor = 'var(--primary-color, #198754)';
                    container.dataset.signed = '1';
                },
            });
        });
    }

    function showPlaceholder(container) {
        var ph = document.createElement('div');
        ph.style.cssText = 'text-align:center;color:#bbb;pointer-events:none;';
        ph.innerHTML = '<i class="bi bi-pen" style="font-size:1.25rem;display:block;margin-bottom:.25rem;"></i>'
            + '<span style="font-size:.72rem;">Clic para firmar</span>';
        container.appendChild(ph);
    }

    function showPreviewImage(container, src) {
        var img = document.createElement('img');
        img.src = src;
        img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;pointer-events:none;';
        container.appendChild(img);
    }

    /**
     * Inject a signature into a pad programmatically.
     * Useful for integrating vendor SDKs (Topaz, Wacom STU SDK, etc.)
     * that capture signatures outside the browser canvas.
     *
     * @param {HTMLElement} padElement - The .sgi-signature-pad container
     * @param {string}      dataUrl   - Base64 PNG data URL of the signature
     */
    function injectSignature(padElement, dataUrl) {
        var targetSelector = padElement.dataset.target;
        if (targetSelector) {
            var scope = padElement.closest('form') || document;
            var input = scope.querySelector(targetSelector);
            if (input) input.value = dataUrl;
        }

        padElement.innerHTML = '';
        showPreviewImage(padElement, dataUrl);
        padElement.style.borderStyle = 'solid';
        padElement.style.borderColor = 'var(--primary-color, #198754)';
        padElement.dataset.signed = '1';
    }

    // ────────────────────────────────────────
    // Auto-discover on DOM ready
    // ────────────────────────────────────────
    function autoInit() {
        document.querySelectorAll('.sgi-signature-pad').forEach(initPad);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    // ────────────────────────────────────────
    // Public API
    // ────────────────────────────────────────
    window.SgiSignature = {
        /** Initialize a single pad element */
        init: initPad,
        /** Open the overlay programmatically */
        open: openOverlay,
        /** Inject a signature from an external device/SDK */
        injectSignature: injectSignature,
    };

})();
