# Adaptar vistas a componentes v1.1 — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cuatro arreglos puntuales y de bajo riesgo en las vistas de facturas, empleados y novedades, sin escribir JavaScript nuevo.

**Architecture:** Cambios de markup PHP sobre templates existentes, reusando manejadores y componentes que ya existen (`data-sgi-confirm`, auto-init de Select2 por `sgi-common.js`, componente `.banner` v1.1). No se toca lógica de negocio, controladores ni servicios.

**Tech Stack:** PHP 8.4 / CakePHP 5 templates, CSS (componentes v1.1 ya en `components.css`), Select2 (vendorizado).

**Spec:** `docs/superpowers/specs/2026-05-20-adaptar-vistas-componentes-design.md`

**Notas de ejecución:**
- Comandos desde la raíz del repo: `C:\Users\sistema\Documents\sgi`.
- El proyecto **no usa tests automatizados**. Verificación por `grep` + revisión manual en navegador (descrita en cada tarea y al final).
- NO se escribe JavaScript nuevo. NO se tocan `sgi-calendar.js`, controladores ni servicios.

---

## Task 1: Facturas — migrar el `confirm()` nativo a `data-sgi-confirm`

`templates/Invoices/edit.php` tiene un formulario "Reiniciar flujo" con `onsubmit="return confirm(...)"`. El manejador `data-sgi-confirm` (delegado, en `webroot/js/sgi-common.js:44`) intercepta el click del botón, muestra `SgiDialogs.confirm` y reenvía el click si se confirma. La migración mueve la confirmación del `<form onsubmit>` al `<button data-sgi-confirm>`.

**Files:**
- Modify: `templates/Invoices/edit.php`

- [ ] **Step 1: Reemplazar el formulario**

Edit en `templates/Invoices/edit.php`:

old_string:
```
                        <form method="post" class="mt-2"
                              action="<?= $this->Url->build(['action' => 'resetFlow', $invoice->id]) ?>"
                              onsubmit="return confirm('¿Reiniciar flujo? Se limpiarán aprobaciones y se permitirá reenviar enlaces.');">
                            <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
                            <button type="submit" class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reiniciar flujo
                            </button>
                        </form>
```

new_string:
```
                        <form method="post" class="mt-2"
                              action="<?= $this->Url->build(['action' => 'resetFlow', $invoice->id]) ?>">
                            <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
                            <button type="submit" class="btn btn-sm btn-outline-dark"
                                    data-sgi-confirm="¿Reiniciar flujo? Se limpiarán aprobaciones y se permitirá reenviar enlaces.">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reiniciar flujo
                            </button>
                        </form>
```

- [ ] **Step 2: Verificar**

```bash
grep -n "onsubmit=\"return confirm" templates/Invoices/edit.php || echo "(sin confirm() nativo - OK)"
grep -n "data-sgi-confirm" templates/Invoices/edit.php
```

Expected: el primer `grep` no devuelve nada; el segundo muestra **dos** líneas con `data-sgi-confirm` (la de "Enviar links" preexistente y la nueva de "Reiniciar flujo").

- [ ] **Step 3: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "$(cat <<'EOF'
feat(view): migrar confirm() nativo de reiniciar flujo a data-sgi-confirm

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Empleados — Select2 en los selects de catálogo del formulario

`templates/element/Employees/form.php` tiene 5 `<select>` respaldados por catálogos de BD sin búsqueda. Se les añade la clase `select2-enable` (auto-init por `sgi-common.js`, config `width:100%` — funciona aunque el wrapper esté oculto) y se incluye `element('cdn_select2')` para cargar Select2 (hoy Empleados no lo carga). Los enum cortos fijos (tipo documento, género, estado civil, nivel educativo, estado, tipo de contrato) se dejan como `.form-select` plano.

**Files:**
- Modify: `templates/element/Employees/form.php`

- [ ] **Step 1: Incluir `cdn_select2` en el formulario**

Edit en `templates/element/Employees/form.php`:

old_string:
```
?>
<!-- Datos Personales -->
```

new_string:
```
?>
<?= $this->element('cdn_select2') ?>
<!-- Datos Personales -->
```

- [ ] **Step 2: `select2-enable` en Cargo (`position_id`)**

Edit:

old_string:
```
                <?= $this->Form->control('position_id', ['class' => 'form-select', 'label' => ['text' => 'Cargo', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
```

new_string:
```
                <?= $this->Form->control('position_id', ['class' => 'form-select select2-enable', 'label' => ['text' => 'Cargo', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
```

- [ ] **Step 3: `select2-enable` en Cargo del supervisor (`supervisor_position_id`)**

Edit:

old_string:
```
                <?= $this->Form->control('supervisor_position_id', ['class' => 'form-select', 'label' => ['text' => 'Cargo Jefe Inmediato', 'class' => 'form-label'], 'empty' => '-- Seleccione --', 'options' => $positions]) ?>
```

new_string:
```
                <?= $this->Form->control('supervisor_position_id', ['class' => 'form-select select2-enable', 'label' => ['text' => 'Cargo Jefe Inmediato', 'class' => 'form-label'], 'empty' => '-- Seleccione --', 'options' => $positions]) ?>
```

- [ ] **Step 4: `select2-enable` en Centro de Operación (`operation_center_id`)**

Edit:

old_string:
```
                <?= $this->Form->control('operation_center_id', ['class' => 'form-select', 'label' => ['text' => 'Centro de Operación', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
```

new_string:
```
                <?= $this->Form->control('operation_center_id', ['class' => 'form-select select2-enable', 'label' => ['text' => 'Centro de Operación', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
```

- [ ] **Step 5: `select2-enable` en Centro de Costos (`cost_center_id`)**

Edit:

old_string:
```
                <?= $this->Form->control('cost_center_id', ['class' => 'form-select', 'label' => ['text' => 'Centro de Costos', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
```

new_string:
```
                <?= $this->Form->control('cost_center_id', ['class' => 'form-select select2-enable', 'label' => ['text' => 'Centro de Costos', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
```

- [ ] **Step 6: `select2-enable` en Organización Temporal (`temporary_organization_id`)**

Edit:

old_string:
```
                <?= $this->Form->control('temporary_organization_id', ['class' => 'form-select', 'label' => ['text' => 'Organización Temporal', 'class' => 'form-label'], 'empty' => '-- Seleccione --', 'options' => $temporaryOrganizations]) ?>
```

new_string:
```
                <?= $this->Form->control('temporary_organization_id', ['class' => 'form-select select2-enable', 'label' => ['text' => 'Organización Temporal', 'class' => 'form-label'], 'empty' => '-- Seleccione --', 'options' => $temporaryOrganizations]) ?>
```

- [ ] **Step 7: Verificar**

```bash
grep -c "select2-enable" templates/element/Employees/form.php
grep -c "element('cdn_select2')" templates/element/Employees/form.php
```

Expected: el primero da `5` (los 5 selects de catálogo); el segundo da `1`. Confirmar también que los selects de enum corto (`document_type`, `gender`, `marital_status_id`, `education_level_id`, `status`, `contract_type`) **siguen** con `'form-select'` sin `select2-enable`:

```bash
grep -nE "marital_status_id|education_level_id|'status'|contract_type|'document_type'|'gender'" templates/element/Employees/form.php | grep "select2-enable" || echo "(enum cortos sin select2 - OK)"
```

Expected: el último `grep` no devuelve nada.

- [ ] **Step 8: Commit**

```bash
git add templates/element/Employees/form.php
git commit -m "$(cat <<'EOF'
feat(view): Select2 con búsqueda en los selects de catálogo del formulario de empleados

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Novedades — limpieza segura de Select2

`templates/EmployeeNovelties/add.php` inicializa `#massive-employees` dos veces: por la clase `.select2-enable` (auto-init de `sgi-common.js`) y por un `.select2()` manual en un `<script>` inline. El manual solo añade `placeholder` y `allowClear`; esas opciones se trasladan a la convención estándar vía atributos `data-*` en el `<select>`, y se elimina el bloque manual.

**Files:**
- Modify: `templates/EmployeeNovelties/add.php`
- Modify: `docs/design/formularios.md`

- [ ] **Step 1: Añadir `data-placeholder` y `data-allow-clear` al select `#massive-employees`**

Edit en `templates/EmployeeNovelties/add.php`:

old_string:
```
                <select name="massive_employee_ids[]" id="massive-employees" class="form-select select2-enable" multiple>
```

new_string:
```
                <select name="massive_employee_ids[]" id="massive-employees" class="form-select select2-enable" data-placeholder="Seleccione empleados..." data-allow-clear="true" multiple>
```

(Select2 lee `data-placeholder` y `data-allow-clear` del elemento en su init estándar — equivale a las opciones `placeholder`/`allowClear` del bloque manual.)

- [ ] **Step 2: Eliminar el bloque `.select2()` manual redundante**

Edit en `templates/EmployeeNovelties/add.php` — elimina la línea en blanco previa, el comentario y el bloque `if`:

old_string:
```

                // Re-init Select2 for massive if shown
                if (flags.is_massive && typeof jQuery !== 'undefined') {
                    jQuery('#massive-employees').select2({
                        placeholder: 'Seleccione empleados...',
                        allowClear: true,
                        width: '100%'
                    });
                }
```

new_string: *(cadena vacía — eliminar por completo el bloque)*

- [ ] **Step 3: Documentar la convención de clases de Select2 en `formularios.md`**

`docs/design/formularios.md` termina con la sección `## Segmented` añadida previamente. Edit para añadir una sección nueva al final:

old_string:
```
## Segmented

Para alternar entre 2–4 opciones cortas mutuamente excluyentes, usar el componente `.segmented` / `.seg` ya documentado en este archivo (sección **08 · Tabs y filtros**). El "Segmented" de la propuesta v1.1 es funcionalmente idéntico — no se introduce una clase nueva.
```

new_string:
```
## Segmented

Para alternar entre 2–4 opciones cortas mutuamente excluyentes, usar el componente `.segmented` / `.seg` ya documentado en este archivo (sección **08 · Tabs y filtros**). El "Segmented" de la propuesta v1.1 es funcionalmente idéntico — no se introduce una clase nueva.

---

## Select2 — convención de clases de init

El proyecto inicializa Select2 con dos clases distintas, según quién dispara el init:

- **`.select2-enable`** — auto-inicializada globalmente por `webroot/js/sgi-common.js` (función `sgiInit`, en `DOMContentLoaded` y tras inyecciones AJAX). Es la convención por defecto para cualquier `<select>` que deba tener búsqueda. Config: `width:100%`, locale `es`, `minimumResultsForSearch:7`.
- **`.select2`** (sin `-enable`) — usada por los filtros del calendario de Novedades; la inicializa `webroot/js/sgi-calendar.js` con su propia configuración.

Para un select nuevo, usar **`.select2-enable`**. Opciones por select vía atributos del `<select>`: `data-placeholder`, `data-allow-clear`. No re-inicializar Select2 manualmente con `.select2()` — la convivencia de inits causa doble inicialización.
```

- [ ] **Step 4: Verificar**

```bash
grep -n "jQuery('#massive-employees').select2" templates/EmployeeNovelties/add.php || echo "(sin .select2() manual - OK)"
grep -n "data-placeholder" templates/EmployeeNovelties/add.php
grep -n "convención de clases de init" docs/design/formularios.md
```

Expected: el primer `grep` no devuelve nada; el segundo muestra el `data-placeholder` en `#massive-employees`; el tercero muestra el encabezado de la sección nueva.

- [ ] **Step 5: Commit**

```bash
git add templates/EmployeeNovelties/add.php docs/design/formularios.md
git commit -m "$(cat <<'EOF'
feat(view): eliminar init manual redundante de Select2 en novedades

El .select2() manual de #massive-employees duplicaba el auto-init de
sgi-common.js. placeholder/allowClear se trasladan a atributos data-*.
Se documenta la convención de clases en docs/design/formularios.md.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Banner de factura rechazada en `Invoices/edit.php`

Se añade el componente `.banner danger` (v1.1, CSS puro — ya en `components.css`) al inicio del contenido de `edit.php`, renderizado condicionalmente con `$viewModel->isRejected`. `InvoiceEditViewModel` no expone un campo de motivo de rechazo, así que el `.banner-msg` da una orientación fija (revisar observaciones / reiniciar flujo) en vez de un motivo concreto.

**Files:**
- Modify: `templates/Invoices/edit.php`

- [ ] **Step 1: Insertar el banner tras los includes de assets, antes del header de página**

Edit en `templates/Invoices/edit.php`:

old_string:
```
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<?php /* ═══════════════════ HEADER DE PÁGINA ═══════════════════ */ ?>
```

new_string:
```
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<?php if ($viewModel->isRejected): ?>
<div class="banner danger view-anim" style="margin-bottom:14px;">
    <div class="banner-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
    <div class="banner-body">
        <div class="banner-title">Esta factura fue rechazada en la aprobación de área</div>
        <div class="banner-msg">Revisa las observaciones de los aprobadores. Registro/Revisión puede reiniciar el flujo para reenviar los enlaces de aprobación.</div>
    </div>
</div>
<?php endif; ?>

<?php /* ═══════════════════ HEADER DE PÁGINA ═══════════════════ */ ?>
```

- [ ] **Step 2: Verificar**

```bash
grep -n "banner danger" templates/Invoices/edit.php
grep -nE '^\.banner\b|\.banner\.danger' webroot/css/components.css | head -3
```

Expected: el primer `grep` muestra el `<div class="banner danger ...">` nuevo; el segundo confirma que `.banner` y `.banner.danger` existen en `components.css` (añadidos en la fase v1.1).

- [ ] **Step 3: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "$(cat <<'EOF'
feat(view): banner de aviso cuando la factura está rechazada

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Validación manual final

Este proyecto no usa tests automatizados. Tras las 4 tareas, levantar `php bin/cake server` y verificar:

1. **Facturas — confirmación:** en una factura rechazada en estado `aprobacion`, el botón "Reiniciar flujo" abre el diálogo `SgiDialogs.confirm` (estilizado, no el `confirm()` del navegador); confirmar reinicia el flujo, cancelar no hace nada.
2. **Empleados — Select2:** abrir `Employees/add` y `Employees/edit`; Cargo, Cargo Jefe Inmediato, Centro de Operación, Centro de Costos y Organización Temporal muestran el widget Select2 con búsqueda (la Organización Temporal funciona también al mostrarse su wrapper). Estado civil, nivel educativo, estado, tipo de documento, género y tipo de contrato siguen como select normal. Guardar un empleado funciona.
3. **Novedades — Select2:** abrir `EmployeeNovelties/add`; seleccionar un tipo de novedad masiva muestra `#massive-employees` como multi-select con búsqueda y placeholder "Seleccione empleados…", una sola instancia (sin doble init). Los filtros del calendario en `index`/`active` siguen operativos.
4. **Banner:** abrir en `edit` una factura con `area_approval='Rechazada'`; aparece el `.banner danger` arriba. Abrir una factura no rechazada; el banner no aparece.
5. **Estilo de código:** `composer cs-check` no introduce errores nuevos en los archivos tocados; si los marca por algo trivial, `composer cs-fix` solo sobre esos archivos.

## Self-Review (autor del plan)

- **Cobertura del spec:** Ítem 1 → Task 1; Ítem 2 → Task 2 (5 selects + cdn_select2); Ítem 3 → Task 3 (eliminar init manual + data-* + doc); Ítem 4 → Task 4 (banner). Fuera de alcance respetado: no se toca `sgi-calendar.js`, ni la clase `.select2`, ni modales, ni se escribe JS.
- **Sin placeholders:** cada step tiene `old_string`/`new_string` exactos extraídos del código actual.
- **Consistencia:** la clase `select2-enable` y el patrón `data-sgi-confirm` coinciden con los manejadores reales de `sgi-common.js`; el `.banner danger` coincide con las clases de `components.css`. El motivo de rechazo se confirmó ausente en `InvoiceEditViewModel` — el banner usa un mensaje fijo, no un campo inexistente.
