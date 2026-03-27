# ePadLink VP9801 Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Integrate ePadLink VP9801 signature pads as an optional capture method in the SGI signature workflow (novelties and liquidations).

**Architecture:** The SigCaptureWeb browser extension communicates with the local VP9801 via Native Messaging. A new JS module (`sgi-epadlink.js`) detects the extension, initiates capture, and injects the resulting base64 PNG into the existing `SgiSignature.injectSignature()` flow. No backend changes needed.

**Tech Stack:** JavaScript (vanilla), SigCaptureWeb SDK (Chrome Native Messaging extension), existing `sgi-signature.js` API.

---

### Task 1: Create `sgi-epadlink.js` — Core Module

**Files:**
- Create: `webroot/js/sgi-epadlink.js`

**Step 1: Create the ePadLink integration module**

This module detects the SigCaptureWeb extension, adds "Firmar con ePadLink" buttons to all `.sgi-signature-pad` elements, and handles the capture flow.

```javascript
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
        if (!str) return;

        var data;
        try {
            data = JSON.parse(str);
        } catch (e) {
            resetStatus();
            return;
        }

        if (data.isSigned && data.imgData && activePadElement) {
            // Build data URL from the base64 image data
            var dataUrl = 'data:image/png;base64,' + data.imgData;

            // Inject into the existing signature pad flow
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
        // Create wrapper for button + status
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

        // Insert after the signature pad container
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
        if (!isExtensionInstalled()) return;

        // Listen for signature responses (once, globally)
        document.addEventListener('SigCaptureWeb_SignResponse', onSignResponse, false);

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
```

**Step 2: Verify the file was created**

Run: `ls -la webroot/js/sgi-epadlink.js`
Expected: File exists with the content above.

**Step 3: Commit**

```bash
git add webroot/js/sgi-epadlink.js
git commit -m "feat: add sgi-epadlink.js for VP9801 signature pad integration"
```

---

### Task 2: Load `sgi-epadlink.js` in Layout

**Files:**
- Modify: `templates/layout/default.php:480` (after `sgi-common` script)

**Step 1: Add the script tag after sgi-common.js**

In `templates/layout/default.php`, find line 480:

```php
    <?= $this->Html->script('sgi-common', ['block' => false]) ?>
```

Add immediately after it:

```php
    <?= $this->Html->script('sgi-signature', ['block' => false]) ?>
    <?= $this->Html->script('sgi-epadlink', ['block' => false]) ?>
```

Note: `sgi-signature.js` must load before `sgi-epadlink.js` since the epadlink module uses `SgiSignature.injectSignature()`. Check if `sgi-signature` is already loaded in the layout or only in specific templates. If it's only in specific templates via the `script` block, then only add `sgi-epadlink` in the layout and ensure load order is correct in those templates.

**Step 2: Verify sgi-signature.js loading**

Check how `sgi-signature.js` is currently loaded. If it's loaded per-template (not globally), then `sgi-epadlink.js` should also be loaded per-template, right after `sgi-signature.js`. In that case, modify the two templates instead (Task 3 will handle this).

Search for: `sgi-signature` in templates to determine loading strategy.

**Step 3: Commit**

```bash
git add templates/layout/default.php
git commit -m "feat: load sgi-epadlink.js in layout"
```

---

### Task 3: Verify Template Integration

**Files:**
- Possibly modify: `templates/EmployeeNovelties/add.php`
- Possibly modify: `templates/NoveltyLiquidationDocs/edit.php`

**Step 1: Check where sgi-signature.js is loaded**

Search all templates for references to `sgi-signature`:

```bash
grep -r "sgi-signature" templates/
```

If `sgi-signature.js` is loaded per-template (via `$this->Html->script()` or inline `<script>` in the `script` block), then `sgi-epadlink.js` must be loaded in the same templates, right after it.

**Step 2: Add sgi-epadlink.js to templates if needed**

If per-template loading, add after the `sgi-signature` script in each template:

```php
<?php $this->Html->script('sgi-epadlink', ['block' => true]); ?>
```

This ensures load order: `sgi-common.js` → `sgi-signature.js` → `sgi-epadlink.js`.

**Step 3: Test the integration visually**

1. Open SGI in Chrome on a workstation WITH the SigCaptureWeb extension installed
2. Navigate to EmployeeNovelties → Add
3. Verify: a "Firmar con ePadLink" button appears below the signature pad
4. Navigate to NoveltyLiquidationDocs → Edit (one with pending signatures)
5. Verify: "Firmar con ePadLink" buttons appear below each signature pad

On a workstation WITHOUT the extension:
1. Open the same pages
2. Verify: NO "Firmar con ePadLink" buttons appear
3. Verify: canvas and image upload still work normally

**Step 4: Commit if template changes were needed**

```bash
git add templates/EmployeeNovelties/add.php templates/NoveltyLiquidationDocs/edit.php
git commit -m "feat: load sgi-epadlink.js in signature templates"
```

---

### Task 4: End-to-End Testing with Device

**Prerequisites:** Workstation with VP9801 connected, drivers installed, SigCaptureWeb SDK installed, Chrome extension enabled.

**Step 1: Test capture flow on EmployeeNovelties**

1. Open SGI → EmployeeNovelties → Add (fill required fields)
2. In the Firma section, click "Firmar con ePadLink"
3. Verify: status text shows "Firme en el dispositivo..."
4. Sign on the VP9801 pad
5. Verify: signature image appears in the preview area
6. Submit the form
7. Verify: signature is saved correctly (check `webroot/uploads/novelties/`)

**Step 2: Test capture flow on NoveltyLiquidationDocs**

1. Open SGI → NoveltyLiquidationDocs → Edit (one with pending signatures)
2. Click "Firmar con ePadLink" on a pending signer
3. Sign on the VP9801 pad
4. Verify: signature appears in preview
5. Click "Firmar" to submit
6. Verify: signature is stored and signer is marked as signed

**Step 3: Test cancellation**

1. Click "Firmar con ePadLink"
2. Cancel on the VP9801 device (close the capture window without signing)
3. Verify: status resets, no error, pad is still usable

**Step 4: Test fallback**

1. On same page, click the signature pad area (canvas method)
2. Verify: canvas overlay opens and works normally
3. Upload an image via file input
4. Verify: image upload still works

**Step 5: Commit final state**

```bash
git add -A
git commit -m "feat: ePadLink VP9801 integration complete"
```

---

## Summary

| Task | Description | Estimated Steps |
|------|-------------|-----------------|
| 1 | Create `sgi-epadlink.js` core module | 3 steps |
| 2 | Load script in layout | 3 steps |
| 3 | Verify/adjust template integration | 4 steps |
| 4 | End-to-end device testing | 5 steps |

**Total files changed:** 1 new (`sgi-epadlink.js`), 1-3 modified (layout + possibly 2 templates)
**Backend changes:** None
**Database changes:** None
