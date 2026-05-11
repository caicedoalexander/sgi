/**
 * Leave Template Visual Editor
 * Drag-and-drop field placement over a template image/PDF.
 */
(function () {
    'use strict';

    var canvas = document.getElementById('template-canvas');
    if (!canvas) return;

    var pageWidthMm = parseFloat(canvas.dataset.pageWidth);
    var pageHeightMm = parseFloat(canvas.dataset.pageHeight);
    var saveUrl = canvas.dataset.saveUrl;
    var mimeType = canvas.dataset.mimeType || '';
    var isPdf = mimeType === 'application/pdf';
    var bgImage = document.getElementById('template-bg-image');
    var bgPdf = document.getElementById('template-bg-pdf');

    var placedFields = [];
    var selectedField = null;
    var dragging = null;
    var dragOffset = { x: 0, y: 0 };
    var initialized = false;

    // --- Scale helpers ---
    function getScale() {
        return {
            x: pageWidthMm / canvas.offsetWidth,
            y: pageHeightMm / canvas.offsetHeight
        };
    }

    function pxToMm(px, axis) {
        var s = getScale();
        return axis === 'x' ? px * s.x : px * s.y;
    }

    function mmToPx(mm, axis) {
        var s = getScale();
        return axis === 'x' ? mm / s.x : mm / s.y;
    }

    // Scale factor: approximate ratio of screen px to mm text rendering
    function fontSizePxFromPt(pt) {
        // Approximate: in the canvas, 1mm ≈ canvas.offsetWidth/pageWidthMm px
        // TCPDF pt is roughly 0.353mm, so pt * 0.353 gives mm, then convert to px
        var mmPerPt = 0.353;
        var pxPerMm = canvas.offsetWidth / pageWidthMm;
        return Math.max(8, Math.round(pt * mmPerPt * pxPerMm));
    }

    // --- Apply visual font style to field element ---
    function applyFieldStyle(el, fieldData) {
        var pxSize = fontSizePxFromPt(fieldData.fontSize);
        el.style.fontSize = pxSize + 'px';
        el.style.fontWeight = (fieldData.fontStyle.indexOf('B') !== -1) ? '700' : '400';
        el.style.fontStyle = (fieldData.fontStyle.indexOf('I') !== -1) ? 'italic' : 'normal';
    }

    // --- Initialize canvas dimensions ---
    function initCanvas() {
        var aspect = pageHeightMm / pageWidthMm;
        var wrapper = canvas.parentElement;
        var wrapperStyle = window.getComputedStyle(wrapper);
        var paddingLeft = parseFloat(wrapperStyle.paddingLeft) || 0;
        var paddingRight = parseFloat(wrapperStyle.paddingRight) || 0;
        var availableW = wrapper.clientWidth - paddingLeft - paddingRight;
        var maxW = Math.min(availableW, 900);

        canvas.style.width = maxW + 'px';
        canvas.style.height = (maxW * aspect) + 'px';
    }

    // --- Load existing fields ---
    function loadExistingFields() {
        if (initialized) return;
        initialized = true;

        var fieldsJson = canvas.dataset.fields;
        if (!fieldsJson) return;

        var fields;
        try { fields = JSON.parse(fieldsJson); } catch (e) { return; }

        fields.forEach(function (f) {
            addFieldToCanvas({
                fieldKey: f.field_key,
                label: f.label || f.field_key,
                fieldType: f.field_type || 'text',
                x: parseFloat(f.x),
                y: parseFloat(f.y),
                width: f.width ? parseFloat(f.width) : null,
                height: f.height ? parseFloat(f.height) : null,
                fontSize: f.font_size || 10,
                fontStyle: f.font_style || '',
                alignment: f.alignment || 'L',
                format: f.format || ''
            });
        });
    }

    // --- Add field to canvas ---
    function addFieldToCanvas(opts) {
        var div = document.createElement('div');
        div.className = 'template-field';

        // Text node for label
        var textNode = document.createTextNode(opts.label || opts.fieldKey);
        div.appendChild(textNode);

        var xPx = mmToPx(opts.x, 'x');
        var yPx = mmToPx(opts.y, 'y');
        div.style.left = xPx + 'px';
        div.style.top = yPx + 'px';

        if (opts.width) {
            div.style.width = mmToPx(opts.width, 'x') + 'px';
        }

        var closeBtn = document.createElement('span');
        closeBtn.className = 'template-field-close';
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            removeField(fieldData);
        });
        div.appendChild(closeBtn);

        canvas.appendChild(div);

        var fieldData = {
            el: div,
            fieldKey: opts.fieldKey,
            label: opts.label || opts.fieldKey,
            fieldType: opts.fieldType || 'text',
            xMm: opts.x,
            yMm: opts.y,
            width: opts.width,
            height: opts.height,
            fontSize: opts.fontSize || 10,
            fontStyle: opts.fontStyle || '',
            alignment: opts.alignment || 'L',
            format: opts.format || ''
        };

        applyFieldStyle(div, fieldData);
        placedFields.push(fieldData);

        div.addEventListener('mousedown', function (e) {
            e.preventDefault();
            selectField(fieldData);
            startDrag(e, fieldData);
        });

        return fieldData;
    }

    // --- Remove field ---
    function removeField(fieldData) {
        canvas.removeChild(fieldData.el);
        placedFields = placedFields.filter(function (f) { return f !== fieldData; });
        if (selectedField === fieldData) {
            selectedField = null;
            document.getElementById('field-properties-panel').style.display = 'none';
        }
    }

    // --- Select field & show properties ---
    function selectField(fieldData) {
        placedFields.forEach(function (f) { f.el.classList.remove('selected'); });

        fieldData.el.classList.add('selected');
        selectedField = fieldData;

        var panel = document.getElementById('field-properties-panel');
        panel.style.display = 'block';

        document.getElementById('prop-field-key').value = fieldData.fieldKey;
        document.getElementById('prop-label').value = fieldData.label;
        document.getElementById('prop-x').value = fieldData.xMm.toFixed(1);
        document.getElementById('prop-y').value = fieldData.yMm.toFixed(1);
        document.getElementById('prop-width').value = fieldData.width ? fieldData.width.toFixed(1) : '';
        document.getElementById('prop-height').value = fieldData.height ? fieldData.height.toFixed(1) : '';
        document.getElementById('prop-font-size').value = fieldData.fontSize;
        document.getElementById('prop-font-style').value = fieldData.fontStyle;
        document.getElementById('prop-format').value = fieldData.format;

        var alignRadio = document.getElementById('align-' + fieldData.alignment);
        if (alignRadio) alignRadio.checked = true;
    }

    // --- Dragging ---
    function startDrag(e, fieldData) {
        dragging = fieldData;
        var rect = fieldData.el.getBoundingClientRect();
        dragOffset.x = e.clientX - rect.left;
        dragOffset.y = e.clientY - rect.top;
    }

    document.addEventListener('mousemove', function (e) {
        if (!dragging) return;
        e.preventDefault();

        var canvasRect = canvas.getBoundingClientRect();
        var newX = e.clientX - canvasRect.left - dragOffset.x;
        var newY = e.clientY - canvasRect.top - dragOffset.y;

        newX = Math.max(0, Math.min(newX, canvas.offsetWidth - dragging.el.offsetWidth));
        newY = Math.max(0, Math.min(newY, canvas.offsetHeight - dragging.el.offsetHeight));

        dragging.el.style.left = newX + 'px';
        dragging.el.style.top = newY + 'px';
        dragging.xMm = pxToMm(newX, 'x');
        dragging.yMm = pxToMm(newY, 'y');

        if (selectedField === dragging) {
            document.getElementById('prop-x').value = dragging.xMm.toFixed(1);
            document.getElementById('prop-y').value = dragging.yMm.toFixed(1);
        }
    });

    document.addEventListener('mouseup', function () { dragging = null; });

    // --- Add field from sidebar ---
    document.querySelectorAll('.add-field-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var item = btn.closest('.template-field-item');
            var fd = addFieldToCanvas({
                fieldKey: item.dataset.key,
                label: item.dataset.label,
                fieldType: item.dataset.type,
                x: pageWidthMm / 2 - 20,
                y: pageHeightMm / 2,
                width: null,
                height: null,
                fontSize: 10,
                fontStyle: '',
                alignment: 'L',
                format: item.dataset.type === 'check' ? 'X' : ''
            });
            selectField(fd);
        });
    });

    // --- Properties panel bindings ---
    function bindPropInput(inputId, fieldProp, parser) {
        var el = document.getElementById(inputId);
        if (!el) return;
        el.addEventListener('change', function () {
            if (!selectedField) return;
            var val = parser ? parser(el.value) : el.value;
            selectedField[fieldProp] = val;

            if (fieldProp === 'xMm') {
                selectedField.el.style.left = mmToPx(val, 'x') + 'px';
            } else if (fieldProp === 'yMm') {
                selectedField.el.style.top = mmToPx(val, 'y') + 'px';
            } else if (fieldProp === 'width' && val) {
                selectedField.el.style.width = mmToPx(val, 'x') + 'px';
            } else if (fieldProp === 'width' && !val) {
                selectedField.el.style.width = '';
            } else if (fieldProp === 'label') {
                selectedField.el.childNodes[0].textContent = val;
            } else if (fieldProp === 'fontSize' || fieldProp === 'fontStyle') {
                applyFieldStyle(selectedField.el, selectedField);
            }
        });
    }

    bindPropInput('prop-label', 'label');
    bindPropInput('prop-x', 'xMm', parseFloat);
    bindPropInput('prop-y', 'yMm', parseFloat);
    bindPropInput('prop-width', 'width', function (v) { return v ? parseFloat(v) : null; });
    bindPropInput('prop-height', 'height', function (v) { return v ? parseFloat(v) : null; });
    bindPropInput('prop-font-size', 'fontSize', parseInt);
    bindPropInput('prop-font-style', 'fontStyle');
    bindPropInput('prop-format', 'format');

    document.querySelectorAll('input[name="prop-alignment"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (selectedField) selectedField.alignment = radio.value;
        });
    });

    document.getElementById('btn-remove-field').addEventListener('click', function () {
        if (selectedField) removeField(selectedField);
    });

    // --- Save ---
    document.getElementById('btn-save-fields').addEventListener('click', function () {
        var data = placedFields.map(function (f) {
            return {
                field_key: f.fieldKey,
                label: f.label,
                x: parseFloat(f.xMm.toFixed(2)),
                y: parseFloat(f.yMm.toFixed(2)),
                width: f.width ? parseFloat(f.width.toFixed(2)) : null,
                height: f.height ? parseFloat(f.height.toFixed(2)) : null,
                font_size: f.fontSize,
                font_style: f.fontStyle,
                alignment: f.alignment,
                field_type: f.fieldType,
                format: f.format || null
            };
        });

        var btn = document.getElementById('btn-save-fields');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Guardando...';

        fetch(saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrfToken"]')?.content || ''
            },
            body: JSON.stringify(data)
        })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-save me-1"></i>Guardar Campos';
            if (result.success) {
                showFlash('Campos guardados (' + result.count + ' campos).', 'success');
            } else {
                showFlash('Error: ' + (result.error || 'Desconocido'), 'danger');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-save me-1"></i>Guardar Campos';
            showFlash('Error de conexión al guardar.', 'danger');
        });
    });

    function showFlash(msg, type) {
        var container = document.getElementById('sgi-flash-container');
        if (!container) return;
        var div = document.createElement('div');
        div.className = 'alert alert-' + type + ' alert-dismissible fade show';
        var text = document.createElement('span');
        text.textContent = msg;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('data-bs-dismiss', 'alert');
        div.appendChild(text);
        div.appendChild(close);
        container.appendChild(div);
        setTimeout(function () { if (div.parentNode) div.remove(); }, 5000);
    }

    // --- Deselect on canvas background click ---
    canvas.addEventListener('mousedown', function (e) {
        if (e.target === canvas || e.target === bgImage || e.target === bgPdf) {
            placedFields.forEach(function (f) { f.el.classList.remove('selected'); });
            selectedField = null;
            document.getElementById('field-properties-panel').style.display = 'none';
        }
    });

    // --- Reposition on resize ---
    function repositionFields() {
        placedFields.forEach(function (f) {
            f.el.style.left = mmToPx(f.xMm, 'x') + 'px';
            f.el.style.top = mmToPx(f.yMm, 'y') + 'px';
            if (f.width) f.el.style.width = mmToPx(f.width, 'x') + 'px';
            applyFieldStyle(f.el, f);
        });
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            initCanvas();
            repositionFields();
        }, 200);
    });

    // --- Init ---
    function startup() {
        initCanvas();
        loadExistingFields();
    }

    if (isPdf) {
        setTimeout(startup, 300);
    } else if (bgImage) {
        bgImage.addEventListener('load', startup);
        if (bgImage.complete) startup();
    } else {
        startup();
    }
})();
