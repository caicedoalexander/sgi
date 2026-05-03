# Recibo de Caja Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar el campo booleano `is_equivalent_document` en facturas y consolidar el flujo "Documento Equivalente" como una opción del select `document_type` llamada **Recibo de Caja**.

**Architecture:** Migración que elimina la columna y migra datos a `document_type='Recibo de Caja'`. Templates de Invoices (add/edit/view) reemplazan el checkbox + lógica condicional por un disparador basado en el valor del select `document_type`. Sin cambios en services, controllers, ni pipeline.

**Tech Stack:** CakePHP 5.3, PHP 8.2, MySQL/MariaDB, JS vanilla.

**Nota sobre testing:** Este proyecto no usa tests automatizados (ver CLAUDE.md). Cada tarea termina con validación manual y commit. La validación integral está en la última tarea.

---

## File Structure

| Acción | Archivo | Responsabilidad |
|--------|---------|-----------------|
| Modify | `src/Constants/InvoiceConstants.php` | Agregar `DOCTYPE_RECIBO_CAJA`. |
| Create | `config/Migrations/20260503091700_DropIsEquivalentDocumentFromInvoices.php` | Migrar datos y eliminar columna. |
| Modify | `src/Model/Entity/Invoice.php` | Quitar `is_equivalent_document` de `$_accessible`. |
| Modify | `src/Model/Table/InvoicesTable.php` | Quitar regla de validación de `is_equivalent_document`. |
| Modify | `templates/Invoices/add.php` | Quitar checkbox; JS dispara fila por `document_type`. |
| Modify | `templates/Invoices/edit.php` | Quitar checkbox; rehidratar fila por `document_type`; `due_date` se desactiva por valor del select. |
| Modify | `templates/Invoices/view.php` | Quitar badge "Doc. Equivalente"; condiciones del bloque "Titular" usan `document_type`. |

---

### Task 1: Agregar constante `DOCTYPE_RECIBO_CAJA`

**Files:**
- Modify: `src/Constants/InvoiceConstants.php`

- [ ] **Step 1: Editar constantes**

En `src/Constants/InvoiceConstants.php` agregar la constante y registrarla en `DOCUMENT_TYPES`. Resultado esperado del archivo en las secciones afectadas:

```php
public const DOCUMENT_TYPES = [
    self::DOCTYPE_FACTURA,
    self::DOCTYPE_NOTA_DEBITO,
    self::DOCTYPE_CAJA_MENOR,
    self::DOCTYPE_TARJETA_CREDITO,
    self::DOCTYPE_REINTEGRO,
    self::DOCTYPE_LEGALIZACION,
    self::DOCTYPE_RECIBO,
    self::DOCTYPE_RECIBO_CAJA,
    self::DOCTYPE_ANTICIPO,
];

// ...

public const DOCTYPE_FACTURA = 'Factura';
public const DOCTYPE_NOTA_DEBITO = 'Nota Debito';
public const DOCTYPE_CAJA_MENOR = 'Caja menor';
public const DOCTYPE_TARJETA_CREDITO = 'Tarjeta de Crédito';
public const DOCTYPE_REINTEGRO = 'Reintegro';
public const DOCTYPE_LEGALIZACION = 'Legalización';
public const DOCTYPE_RECIBO = 'Recibo';
public const DOCTYPE_RECIBO_CAJA = 'Recibo de Caja';
public const DOCTYPE_ANTICIPO = 'Anticipo';
```

- [ ] **Step 2: Verificar estilo**

Run: `composer cs-check src/Constants/InvoiceConstants.php`
Expected: 0 errores (o sólo errores que ya existían en main).

- [ ] **Step 3: Commit**

```bash
git add src/Constants/InvoiceConstants.php
git commit -m "feat(invoices): añadir constante DOCTYPE_RECIBO_CAJA"
```

---

### Task 2: Crear migración que migra datos y elimina columna

**Files:**
- Create: `config/Migrations/20260503091700_DropIsEquivalentDocumentFromInvoices.php`

- [ ] **Step 1: Crear el archivo de migración**

Contenido completo:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropIsEquivalentDocumentFromInvoices extends BaseMigration
{
    public function up(): void
    {
        // Migrar datos: las filas marcadas como documento equivalente pasan a 'Recibo de Caja'.
        $this->execute(
            "UPDATE invoices SET document_type = 'Recibo de Caja' WHERE is_equivalent_document = 1"
        );

        $table = $this->table('invoices');
        if ($table->hasColumn('is_equivalent_document')) {
            $table->removeColumn('is_equivalent_document')->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoices');
        if (!$table->hasColumn('is_equivalent_document')) {
            $table->addColumn('is_equivalent_document', 'boolean', [
                'default' => false,
                'null'    => false,
                'after'   => 'document_type',
            ])->update();
        }

        // Reversibilidad mínima: marcar las filas con document_type='Recibo de Caja'.
        // No se restaura el document_type original (no hay forma de recuperarlo).
        $this->execute(
            "UPDATE invoices SET is_equivalent_document = 1 WHERE document_type = 'Recibo de Caja'"
        );
    }
}
```

- [ ] **Step 2: Correr la migración**

Run: `php bin/cake migrations migrate`
Expected: salida con `== DropIsEquivalentDocumentFromInvoices: migrated`.

- [ ] **Step 3: Verificar el esquema**

Run: `php bin/cake migrations status | tail -5`
Expected: la migración aparece como `up`.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/20260503091700_DropIsEquivalentDocumentFromInvoices.php
git commit -m "feat(invoices): migrar is_equivalent_document a doc type Recibo de Caja"
```

---

### Task 3: Quitar `is_equivalent_document` del entity

**Files:**
- Modify: `src/Model/Entity/Invoice.php` (línea 19)

- [ ] **Step 1: Editar `$_accessible`**

Borrar la línea 19 (`'is_equivalent_document' => true,`). El array debe quedar sin esa entrada; las demás entradas no cambian.

- [ ] **Step 2: Verificar estilo**

Run: `composer cs-check src/Model/Entity/Invoice.php`
Expected: 0 errores nuevos.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Entity/Invoice.php
git commit -m "refactor(invoices): quitar is_equivalent_document de entity accessible"
```

---

### Task 4: Quitar regla de validación de `is_equivalent_document`

**Files:**
- Modify: `src/Model/Table/InvoicesTable.php` (líneas 156-158)

- [ ] **Step 1: Editar `validationDefault`**

Borrar el bloque:

```php
$validator
    ->boolean('is_equivalent_document')
    ->allowEmptyString('is_equivalent_document');
```

Las reglas de `equivalent_holder_type`, `employee_id`, `manual_document_number` (líneas 160-172) **no se tocan**: ya son `allowEmptyString`, lo que sigue siendo correcto cuando `document_type !== 'Recibo de Caja'`.

- [ ] **Step 2: Verificar estilo**

Run: `composer cs-check src/Model/Table/InvoicesTable.php`
Expected: 0 errores nuevos.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Table/InvoicesTable.php
git commit -m "refactor(invoices): quitar validador de is_equivalent_document"
```

---

### Task 5: Refactor `templates/Invoices/add.php`

**Files:**
- Modify: `templates/Invoices/add.php` (líneas 82-119, 219-251)

- [ ] **Step 1: Reemplazar el bloque "Documento Equivalente" (líneas 82-119)**

El nuevo bloque elimina el checkbox. La fila contiene sólo los tres sub-campos condicionales (todos `d-none` por defecto, los activa el JS):

```php
            <!-- Sub-formulario disparado por document_type='Recibo de Caja' -->
            <div class="row g-3 mt-1 d-none" id="equivalent-doc-row">
                <div class="col-md-3" id="holder-type-wrapper">
                    <?= $this->Form->control('equivalent_holder_type', [
                        'class'   => 'form-select',
                        'label'   => ['text' => 'Titular del Documento', 'class' => 'form-label'],
                        'options' => ['provider' => 'Proveedor', 'employee' => 'Empleado', 'manual' => 'Cédula Manual'],
                        'empty'   => '-- Seleccione --',
                        'id'      => 'equivalent-holder-type',
                    ]) ?>
                </div>
                <div class="col-md-3 d-none" id="employee-wrapper">
                    <?= $this->Form->control('employee_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Empleado', 'class' => 'form-label'],
                        'options' => $employees ?? [],
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3 d-none" id="manual-doc-wrapper">
                    <?= $this->Form->control('manual_document_number', [
                        'class'       => 'form-control',
                        'label'       => ['text' => 'Cédula', 'class' => 'form-label'],
                        'placeholder' => 'Número de cédula',
                    ]) ?>
                </div>
            </div>
```

- [ ] **Step 2: Reemplazar el `<script>` (líneas 219-252)**

El nuevo script:
- Al cambiar `document_type`: si vale `Recibo de Caja`, muestra `equivalent-doc-row`; si vale `Legalización`, oculta `purchase-order-wrapper` y `due-date-wrapper`. Cualquier otro valor todo visible.
- Al cambiar `equivalent_holder_type`: muestra el wrapper correspondiente (`employee` o `manual`).
- Cuando se oculta un wrapper se limpian los inputs internos para no enviar valores fantasma.

```php
<?php $this->append('script') ?>
<script>
(function () {
    var docTypeSelect = document.querySelector('select[name="document_type"]');
    if (!docTypeSelect) return;

    var purchaseOrder = document.getElementById('purchase-order-wrapper');
    var dueDate       = document.getElementById('due-date-wrapper');
    var equivalentRow = document.getElementById('equivalent-doc-row');
    var holderSelect  = document.getElementById('equivalent-holder-type');
    var employeeWrap  = document.getElementById('employee-wrapper');
    var manualWrap    = document.getElementById('manual-doc-wrapper');

    function setVisible(wrapper, visible) {
        if (!wrapper) return;
        wrapper.classList.toggle('d-none', !visible);
        wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = !visible;
            if (!visible) { el.value = ''; el.checked = false; }
        });
    }

    function applyHolderRules() {
        var holder = holderSelect ? holderSelect.value : '';
        setVisible(employeeWrap, holder === 'employee');
        setVisible(manualWrap,   holder === 'manual');
    }

    function applyDocTypeRules() {
        var value           = docTypeSelect.value;
        var isLegalization  = value === 'Legalización';
        var isReciboDeCaja  = value === 'Recibo de Caja';

        setVisible(purchaseOrder, !isLegalization);
        setVisible(dueDate,       !isLegalization && !isReciboDeCaja);
        setVisible(equivalentRow, isReciboDeCaja);

        if (!isReciboDeCaja) {
            // Reset holder + sub-campos cuando salimos de Recibo de Caja.
            if (holderSelect) holderSelect.value = '';
            setVisible(employeeWrap, false);
            setVisible(manualWrap,   false);
        } else {
            applyHolderRules();
        }
    }

    docTypeSelect.addEventListener('change', applyDocTypeRules);
    if (holderSelect) holderSelect.addEventListener('change', applyHolderRules);
    applyDocTypeRules();
})();
</script>
<?php $this->end() ?>
```

Nota: el script ahora oculta `due_date` en `Recibo de Caja` (paridad con el comportamiento previo del checkbox que deshabilitaba la fecha de vencimiento).

- [ ] **Step 3: Verificar estilo**

Run: `composer cs-check templates/Invoices/add.php`
Expected: 0 errores nuevos.

- [ ] **Step 4: Validación manual**

1. Levantar `php bin/cake server`, abrir `/invoices/add`.
2. Confirmar que el checkbox "Es Documento Equivalente" no aparece.
3. Cambiar el select a `Recibo de Caja`: aparece holder_type. Elegir `Empleado` → aparece selector de empleado. Elegir `Cédula Manual` → aparece input manual.
4. Cambiar a `Legalización`: oculta `purchase_order` y `due_date`, oculta fila equivalent.
5. Cambiar a `Factura`: todo visible salvo la fila equivalent.

(Validación manual a cargo del usuario; el implementador deja la observación en el commit.)

- [ ] **Step 5: Commit**

```bash
git add templates/Invoices/add.php
git commit -m "refactor(invoices): add.php usa document_type para disparar Recibo de Caja"
```

---

### Task 6: Refactor `templates/Invoices/edit.php`

**Files:**
- Modify: `templates/Invoices/edit.php` (líneas 447-488 y 528)

- [ ] **Step 1: Reemplazar el bloque "Documento Equivalente" (líneas 447-488)**

Quitar el checkbox y rehidratar visibilidad inicial por `document_type`. La columna del checkbox se elimina (los tres sub-campos ocupan la fila):

```php
            <!-- Sub-formulario disparado por document_type='Recibo de Caja' -->
            <?php $isReciboDeCaja = ($invoice->document_type ?? '') === 'Recibo de Caja'; ?>
            <div class="row g-3 mt-1 <?= $isReciboDeCaja ? '' : 'd-none' ?>" id="equivalent-doc-row">
                <div class="col-md-3" id="holder-type-wrapper">
                    <label class="form-label">Titular del Documento</label>
                    <?= $this->Form->control('equivalent_holder_type', array_merge(
                        ['label' => false, 'options' => ['provider' => 'Proveedor', 'employee' => 'Empleado', 'manual' => 'Cédula Manual'], 'empty' => '-- Seleccione --', 'id' => 'equivalent-holder-type'],
                        $canEdit('document_type')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3 <?= ($invoice->equivalent_holder_type ?? '') !== 'employee' ? 'd-none' : '' ?>" id="employee-wrapper">
                    <label class="form-label">Empleado</label>
                    <?= $this->Form->control('employee_id', array_merge(
                        ['label' => false, 'options' => $employees ?? [], 'empty' => '-- Seleccione --'],
                        $canEdit('document_type')
                            ? ['class' => 'form-select select2-enable']
                            : ['class' => 'form-select select2-enable', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3 <?= ($invoice->equivalent_holder_type ?? '') !== 'manual' ? 'd-none' : '' ?>" id="manual-doc-wrapper">
                    <label class="form-label">Cédula</label>
                    <?= $this->Form->control('manual_document_number', array_merge(
                        ['label' => false, 'placeholder' => 'Número de cédula'],
                        $canEdit('document_type')
                            ? ['class' => 'form-control']
                            : ['class' => 'form-control', 'disabled' => true]
                    )) ?>
                </div>
            </div>
```

- [ ] **Step 2: Cambiar la condición del `disabled` en `due_date` (línea 528)**

Reemplazar:

```php
<?= !empty($invoice->is_equivalent_document) ? 'disabled' : '' ?>>
```

Por:

```php
<?= ($invoice->document_type ?? '') === 'Recibo de Caja' ? 'disabled' : '' ?>>
```

- [ ] **Step 3: Inyectar el JS de sincronización**

`edit.php` no tiene un bloque `$this->append('script')` propio (el formulario se renderiza dentro del layout). Verificá si ya existe algún `<script>` al final del template; si lo hay, agregar el bloque debajo. Si no existe, agregar este bloque al final del archivo, después del `<?= $this->Form->end() ?>` correspondiente.

```php
<?php $this->append('script') ?>
<script>
(function () {
    var docTypeSelect = document.querySelector('select[name="document_type"]');
    if (!docTypeSelect) return;

    var equivalentRow = document.getElementById('equivalent-doc-row');
    var holderSelect  = document.getElementById('equivalent-holder-type');
    var employeeWrap  = document.getElementById('employee-wrapper');
    var manualWrap    = document.getElementById('manual-doc-wrapper');
    var dueDateInput  = document.querySelector('input[name="due_date"]');

    function setVisible(wrapper, visible) {
        if (!wrapper) return;
        wrapper.classList.toggle('d-none', !visible);
        wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = !visible;
            if (!visible) { el.value = ''; el.checked = false; }
        });
    }

    function applyHolderRules() {
        var holder = holderSelect ? holderSelect.value : '';
        setVisible(employeeWrap, holder === 'employee');
        setVisible(manualWrap,   holder === 'manual');
    }

    function applyDocTypeRules() {
        var isReciboDeCaja = docTypeSelect.value === 'Recibo de Caja';

        setVisible(equivalentRow, isReciboDeCaja);

        if (dueDateInput) {
            dueDateInput.disabled = isReciboDeCaja;
            if (isReciboDeCaja) dueDateInput.value = '';
        }

        if (!isReciboDeCaja) {
            if (holderSelect) holderSelect.value = '';
            setVisible(employeeWrap, false);
            setVisible(manualWrap,   false);
        } else {
            applyHolderRules();
        }
    }

    docTypeSelect.addEventListener('change', applyDocTypeRules);
    if (holderSelect) holderSelect.addEventListener('change', applyHolderRules);
    // No invocar applyDocTypeRules() en load: el server ya rehidrató la fila correctamente.
})();
</script>
<?php $this->end() ?>
```

Nota importante: este script **no** ejecuta `applyDocTypeRules()` en load — la rehidratación inicial la hace PHP del lado server (clases `d-none` en línea según `$invoice->document_type`). Esto evita pisar valores cargados en edit.

- [ ] **Step 4: Verificar estilo**

Run: `composer cs-check templates/Invoices/edit.php`
Expected: 0 errores nuevos.

- [ ] **Step 5: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "refactor(invoices): edit.php dispara Recibo de Caja por document_type"
```

---

### Task 7: Refactor `templates/Invoices/view.php`

**Files:**
- Modify: `templates/Invoices/view.php` (líneas 123-125 y 185-193)

- [ ] **Step 1: Quitar el badge "Doc. Equivalente"**

Borrar el bloque (líneas 123-125):

```php
                    <?php if (!empty($invoice->is_equivalent_document)): ?>
                        <span class="badge bg-dark">Doc. Equivalente</span>
                    <?php endif; ?>
```

El badge `<?= h($invoice->document_type) ?>` (línea 115) ya muestra `Recibo de Caja` cuando aplica.

- [ ] **Step 2: Cambiar las condiciones del bloque "Titular" (líneas 185-193)**

Reemplazar:

```php
                    <?php if (!empty($invoice->is_equivalent_document) && ($invoice->equivalent_holder_type ?? '') === 'employee'): ?>
                        <?= $invoice->hasValue('employee') ? h($invoice->employee->full_name) : '—' ?>
                        <span class="text-muted small">(Empleado)</span>
                    <?php elseif (!empty($invoice->is_equivalent_document) && ($invoice->equivalent_holder_type ?? '') === 'manual'): ?>
                        <?= h($invoice->manual_document_number ?? '—') ?>
                        <span class="text-muted small">(Cédula Manual)</span>
                    <?php else: ?>
                        <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '—' ?>
                    <?php endif; ?>
```

Por:

```php
                    <?php $isReciboDeCaja = ($invoice->document_type ?? '') === 'Recibo de Caja'; ?>
                    <?php if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'employee'): ?>
                        <?= $invoice->hasValue('employee') ? h($invoice->employee->full_name) : '—' ?>
                        <span class="text-muted small">(Empleado)</span>
                    <?php elseif ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'manual'): ?>
                        <?= h($invoice->manual_document_number ?? '—') ?>
                        <span class="text-muted small">(Cédula Manual)</span>
                    <?php else: ?>
                        <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '—' ?>
                    <?php endif; ?>
```

- [ ] **Step 3: Verificar estilo**

Run: `composer cs-check templates/Invoices/view.php`
Expected: 0 errores nuevos.

- [ ] **Step 4: Commit**

```bash
git add templates/Invoices/view.php
git commit -m "refactor(invoices): view.php usa document_type para titular Recibo de Caja"
```

---

### Task 8: Validación manual integral

**Files:** ninguno (validación end-to-end)

- [ ] **Step 1: cs-check global**

Run: `composer cs-check`
Expected: sin errores nuevos respecto a `main`.

- [ ] **Step 2: Validación manual (a cargo del usuario)**

Pasos:

1. **Esquema**: confirmar que `is_equivalent_document` no existe en la tabla `invoices` (e.g. `DESCRIBE invoices` no la lista) y que filas legacy quedaron con `document_type='Recibo de Caja'`.
2. **Add**: `/invoices/add`. El select Tipo de Documento contiene `Recibo` y `Recibo de Caja` como opciones distintas. El checkbox "Es Documento Equivalente" no existe.
3. **Add — Recibo de Caja + provider**: seleccionar `Recibo de Caja` + holder `Proveedor` + proveedor + resto. Guardar. Verificar persistencia.
4. **Add — Recibo de Caja + employee**: idem con holder `Empleado` + empleado.
5. **Add — Recibo de Caja + manual**: idem con holder `Cédula Manual` + cédula.
6. **Add — switch**: arrancar con `Recibo de Caja` + holder=employee, cambiar el select a `Factura` antes de guardar. Tras guardar, verificar que `equivalent_holder_type`, `employee_id`, `manual_document_number` quedan vacíos en la BD.
7. **Edit**: abrir una factura `Recibo de Caja` previamente creada. La fila equivalent aparece con valores cargados. Cambiar holder y guardar — los cambios se persisten.
8. **View**: factura `Recibo de Caja` muestra empleado/cédula/proveedor según corresponda. No aparece el badge "Doc. Equivalente". Una factura `Factura` muestra al proveedor en "Titular" y no expone los campos equivalent.
9. **Legalización**: en `/invoices/add` seleccionar `Legalización` → quedan ocultos `purchase_order`, `due_date` y la fila equivalent.

(El usuario ejecuta los pasos 2-9 en navegador; el implementador no levanta el server.)

- [ ] **Step 3: Cierre — code quality review**

Tras validación manual del usuario, ejecutar review de calidad sobre el branch entero (consistencia, dead code, regresiones). Esta es la única revisión de calidad del plan; no se hace por tarea.

---

## Self-Review Checklist (interno, ya aplicado)

- ✅ Cobertura del spec: bloques 1-4 mapeados a Tareas 1-7 + validación 8.
- ✅ Sin placeholders: cada paso tiene código o comando concreto.
- ✅ Consistencia: la cadena `'Recibo de Caja'` se usa idéntica en constante, migración, JS y vistas. La constante PHP es `DOCTYPE_RECIBO_CAJA`.
- ✅ Decisión sobre `due_date`: hoy se desactiva por checkbox; en el plan se desactiva cuando `document_type='Recibo de Caja'` para mantener paridad.
- ✅ Decisión sobre rehidratación en edit: la hace PHP del lado server (clases `d-none` inline) para no pisar valores; el JS sólo reacciona a cambios del usuario.
