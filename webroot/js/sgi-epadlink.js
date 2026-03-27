/**
 * SGI ePadLink Integration — VP9801 signature pad support via SigCaptureWeb SDK.
 *
 * Requires: SigCaptureWeb SDK installed on the workstation + Chrome extension enabled.
 * Extension ID: idldbjenlmipmpigmfamdlfifkkeaplc
 *
 * Communication uses Custom HTML Events:
 *   - Dispatch: "SigCaptureWeb_SignStartEvent" with JSON message
 *   - Listen:   "SigCaptureWeb_SignResponse" with signature data
 *
 * Response field: "imageData" (base64 PNG), "isSigned" (bool), "errorMsg" (string|null).
 *
 * If the extension is not detected, no buttons are shown (silent degradation).
 */
(function () {
    'use strict';

    var EXTENSION_ATTR = 'SigCaptureWebExtension-installed';

    // Default capture settings
    var CAPTURE_DEFAULTS = {
        firstName: '',
        lastName: '',
        eMail: '',
        location: '',
        imageFormat: 2,          // PNG
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

    // Track which pad element initiated the current capture
    var activePadElement = null;
    var activeStatusEl = null;

    /**
     * Check if the SigCaptureWeb extension is installed.
     * The extension sets an attribute on <html> when loaded.
     */
    function isExtensionInstalled() {
        return document.documentElement.getAttribute(EXTENSION_ATTR) !== null;
    }

    /**
     * Initiate signature capture on the VP9801.
     */
    function startCapture(padElement, statusEl) {
        activePadElement = padElement;
        activeStatusEl = statusEl;

        if (statusEl) {
            statusEl.textContent = 'Firme en el dispositivo...';
            statusEl.style.display = 'block';
        }

        var message = Object.assign({}, CAPTURE_DEFAULTS);
        var messageData = JSON.stringify(message);

        // Create the data element and dispatch the event per SDK protocol
        var element = document.createElement('SigCaptureWeb_ExtnDataElem');
        element.setAttribute('SigCaptureWeb_MsgAttribute', messageData);
        document.documentElement.appendChild(element);

        var evt = document.createEvent('Events');
        evt.initEvent('SigCaptureWeb_SignStartEvent', true, false);
        element.dispatchEvent(evt);
    }

    /**
     * Handle the signature response from the extension.
     */
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

            if (window.SgiSignature && typeof window.SgiSignature.injectSignature === 'function') {
                window.SgiSignature.injectSignature(activePadElement, dataUrl);
            }
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

    /**
     * Add the ePadLink button to a .sgi-signature-pad container.
     */
    function enhancePad(container) {
        var wrapper = document.createElement('div');
        wrapper.style.cssText = 'display:flex;align-items:center;gap:.5rem;margin-top:.4rem;';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-success btn-sm sgi-epadlink-btn';
        btn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Firmar con ePadLink';

        var status = document.createElement('span');
        status.className = 'sgi-epadlink-status';
        status.style.cssText = 'font-size:.75rem;color:#6c757d;display:none;';

        wrapper.appendChild(btn);
        wrapper.appendChild(status);

        container.parentNode.insertBefore(wrapper, container.nextSibling);

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            startCapture(container, status);
        });
    }

    /**
     * Initialize: add ePadLink buttons to all signature pads if extension is present.
     */
    function init() {
        // Listen for signature responses on <html> where the extension dispatches
        document.documentElement.addEventListener('SigCaptureWeb_SignResponse', onSignResponse, false);

        // MutationObserver fallback for extensions that set attributes instead of dispatching events
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

        // Enhance all signature pad elements
        document.querySelectorAll('.sgi-signature-pad').forEach(enhancePad);
    }

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Public API
    window.SgiEpadlink = {
        isAvailable: isExtensionInstalled,
        capture: startCapture,
        init: init
    };

})();
