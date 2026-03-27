/**
 * SGI ePadLink Integration — VP9801 signature pad support via SigCaptureWeb SDK.
 *
 * Replaces the default canvas signature flow with a method selector:
 *   - ePadLink: capture via VP9801 hardware pad
 *   - Imagen: upload a signature image file
 *
 * Response field: "imageData" (base64 PNG), "isSigned" (bool), "errorMsg" (string|null).
 */
(function () {
    'use strict';

    var CAPTURE_DEFAULTS = {
        firstName: '',
        lastName: '',
        eMail: '',
        location: '',
        imageFormat: 2,
        imageX: 650,
        imageY: 200,
        imageTransparency: false,
        imageScaling: false,
        maxUpScalePercent: 0.0,
        rawDataFormat: 'ENC',
        minSigPoints: 25,
        penThickness: 3,
        penColor: '#000000'
    };

    var activePadElement = null;
    var activeStatusEl = null;

    // ── SDK Communication ──

    function startCapture(padElement, statusEl) {
        activePadElement = padElement;
        activeStatusEl = statusEl;

        if (statusEl) {
            statusEl.textContent = 'Firme en el dispositivo...';
            statusEl.style.display = 'inline';
        }

        var message = Object.assign({}, CAPTURE_DEFAULTS);
        var messageData = JSON.stringify(message);

        var element = document.createElement('SigCaptureWeb_ExtnDataElem');
        element.setAttribute('SigCaptureWeb_MsgAttribute', messageData);
        document.documentElement.appendChild(element);

        var evt = document.createEvent('Events');
        evt.initEvent('SigCaptureWeb_SignStartEvent', true, false);
        element.dispatchEvent(evt);
    }

    function onSignResponse(event) {
        var str = event.target.getAttribute('SigCaptureWeb_msgAttri');
        if (!str) {
            str = event.target.getAttribute('SigCaptureWeb_MsgAttribute');
        }
        if (!str) return;

        var data;
        try {
            data = JSON.parse(str);
        } catch (e) {
            resetStatus();
            return;
        }

        if (data.isSigned && data.imageData && activePadElement) {
            var dataUrl = 'data:image/png;base64,' + data.imageData;
            injectSignature(activePadElement, dataUrl);
        } else if (data.errorMsg && activeStatusEl) {
            activeStatusEl.textContent = 'Error: ' + data.errorMsg;
            setTimeout(resetStatus, 3000);
            return;
        }

        resetStatus();
    }

    function resetStatus() {
        if (activeStatusEl) {
            activeStatusEl.textContent = '';
            activeStatusEl.style.display = 'none';
        }
        activePadElement = null;
        activeStatusEl = null;
    }

    // ── Inject signature into pad + hidden input ──

    function injectSignature(container, dataUrl) {
        var targetSelector = container.dataset.target;
        if (targetSelector) {
            var scope = container.closest('form') || document;
            var input = scope.querySelector(targetSelector);
            if (input) input.value = dataUrl;
        }

        // Update preview area
        var preview = container.querySelector('.sgi-sig-preview');
        if (preview) {
            preview.innerHTML = '';
            var img = document.createElement('img');
            img.src = dataUrl;
            img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;';
            preview.appendChild(img);
            preview.style.borderColor = 'var(--primary-color, #198754)';
            preview.style.borderStyle = 'solid';
            preview.style.background = '#fff';
        }
    }

    // ── Build UI for each signature pad ──

    function enhancePad(container) {
        var signerLabel = container.dataset.signerLabel || 'Firma';

        // Prevent the default canvas click handler from sgi-signature.js
        container.addEventListener('click', function (e) {
            e.stopImmediatePropagation();
        }, true);

        // Clear default placeholder content
        container.innerHTML = '';
        container.style.cssText += ';cursor:default;border:none;background:transparent;min-height:auto;padding:0;';

        // ── Method selector row ──
        var selectorRow = document.createElement('div');
        selectorRow.style.cssText = 'display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap;';

        var label = document.createElement('span');
        label.style.cssText = 'font-size:.8rem;font-weight:600;color:#495057;';
        label.textContent = signerLabel + ':';

        var btnEpad = document.createElement('button');
        btnEpad.type = 'button';
        btnEpad.className = 'btn btn-outline-success btn-sm';
        btnEpad.innerHTML = '<i class="bi bi-pencil-square me-1"></i>ePadLink';

        var btnImage = document.createElement('button');
        btnImage.type = 'button';
        btnImage.className = 'btn btn-outline-secondary btn-sm';
        btnImage.innerHTML = '<i class="bi bi-image me-1"></i>Imagen';

        var status = document.createElement('span');
        status.className = 'sgi-epadlink-status';
        status.style.cssText = 'font-size:.75rem;color:#6c757d;display:none;';

        selectorRow.appendChild(label);
        selectorRow.appendChild(btnEpad);
        selectorRow.appendChild(btnImage);
        selectorRow.appendChild(status);

        // ── Hidden file input ──
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/png,image/jpeg';
        fileInput.style.display = 'none';

        // ── Preview area ──
        var preview = document.createElement('div');
        preview.className = 'sgi-sig-preview';
        preview.style.cssText = [
            'width:100%', 'height:90px', 'border:2px dashed #ddd', 'border-radius:8px',
            'display:flex', 'align-items:center', 'justify-content:center',
            'background:#fafafa', 'overflow:hidden', 'transition:border-color .2s',
        ].join(';');

        var phText = document.createElement('span');
        phText.style.cssText = 'font-size:.72rem;color:#bbb;';
        phText.textContent = 'Sin firma';
        preview.appendChild(phText);

        container.appendChild(selectorRow);
        container.appendChild(fileInput);
        container.appendChild(preview);

        // ── Events ──

        btnEpad.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            startCapture(container, status);
        });

        btnImage.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fileInput.click();
        });

        fileInput.addEventListener('change', function () {
            if (!fileInput.files || !fileInput.files[0]) return;
            var reader = new FileReader();
            reader.onload = function (ev) {
                injectSignature(container, ev.target.result);
            };
            reader.readAsDataURL(fileInput.files[0]);
        });
    }

    // ── Init ──

    function init() {
        document.documentElement.addEventListener('SigCaptureWeb_SignResponse', onSignResponse, false);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1 && node.getAttribute && node.getAttribute('SigCaptureWeb_msgAttri')) {
                        onSignResponse({ target: node });
                    }
                });
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['SigCaptureWeb_msgAttri'] });

        document.querySelectorAll('.sgi-signature-pad').forEach(enhancePad);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.SgiEpadlink = {
        capture: startCapture,
        init: init
    };

})();
