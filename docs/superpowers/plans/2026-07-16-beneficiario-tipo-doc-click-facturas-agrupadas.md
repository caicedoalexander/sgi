# Beneficiario + Tipo de documento + fix de click en facturas agrupadas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** En la tarjeta "Facturas Agrupadas" (facturas hijas de Caja Menor, Reintegros y Anticipos), renombrar "Proveedor" a "Beneficiario" resolviendo proveedor/empleado, agregar la columna "Tipo de documento" y arreglar el click de fila que no navega dentro de `edit.php`.

**Architecture:** El DTO `GroupedInvoiceRowView` + `InvoicePresentation::forGroupedRow()` son la fuente única de derivación de fila; se reusa el resolver existente `InvoiceBeneficiary::label()`. Los templates (element compartido `grouped_invoices_table` + tabla bespoke de Anticipos `_linked_invoices`) consumen el DTO. El bug del click se arregla con un handler de navegación scoped en `spi-grouped-invoices.js` (sin el guard `closest('form')` del handler global).

**Tech Stack:** CakePHP 5.3, PHP 8.4, PHPUnit + CakePHP fixtures/factories, JS vanilla (`webroot/js/`).

## Global Constraints

- PHP `>=8.4`, CakePHP 5.3. Sin cambios de esquema ni migración (solo `contain`/eager-load).
- Anti-drift de vista: el mapeo estado→pill/icono vive SOLO en `InvoicePresentation` (const); los templates consumen el DTO. `documentType` se pinta como texto plano (dato crudo del entity), NO como badge.
- Dirección de dependencia única VM/DTO → Presentation; Presentation nunca importa un VM.
- Slugs persistidos inmutables: no tocar valores de `document_type` ni de módulos/pipeline.
- El resolver de beneficiario canónico es `App\View\Presentation\InvoiceBeneficiary::label($invoice)` (misma namespace que `InvoicePresentation` → sin `use`).
- `composer cs-check` debe quedar limpio; suite verde con `vendor/bin/phpunit` (correr con timeout amplio, ~300s).
- **Orden de `use` (regla `SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses` del ruleset CakePHP, aplica a `tests/`):** los imports deben quedar alfabéticamente ordenados por FQCN. Al agregar `use` en los tests, insertarlos en la posición correcta (indicada en cada step), NO al final. Red de seguridad: correr `composer cs-fix` antes de cada commit que toque tests (auto-ordena).
- Cada commit deja la suite verde (el rename `providerName`→`beneficiaryName` y sus consumidores van juntos en la Task 1).

---

### Task 1: DTO + Presentation — rename a `beneficiaryName`, agregar `documentType`, consolidar consumidores

Renombra el campo del DTO, agrega `documentType`, cablea el resolver, y actualiza EN EL MISMO commit los 2 templates y los 2 tests que referencian el campo viejo (para no dejar la suite roja).

**Files:**
- Modify: `src/View/Presentation/GroupedInvoiceRowView.php`
- Modify: `src/View/Presentation/InvoicePresentation.php:147-184` (`forGroupedRow` + docblock)
- Modify: `templates/element/grouped_invoices_table.php:69` (header) y `:90` (celda)
- Modify: `templates/element/advance_legalization/_linked_invoices.php:22` (import) y `:88` (celda beneficiario)
- Test: `tests/TestCase/View/Presentation/InvoicePresentationGroupedRowTest.php`
- Test: `tests/TestCase/View/GroupedInvoicesTableElementTest.php`

**Interfaces:**
- Produces: `GroupedInvoiceRowView` con `public string $beneficiaryName` (era `providerName`) y nuevo `public string $documentType` (posición: inmediatamente después de `beneficiaryName`).
- Produces: `InvoicePresentation::forGroupedRow(Invoice $invoice, bool $canResolveDian): GroupedInvoiceRowView` — sin cambio de firma; ahora `beneficiaryName = InvoiceBeneficiary::label($invoice)` y `documentType = (string)($invoice->document_type ?? '')`.

- [ ] **Step 1: Escribir el test que falla (resolución de empleado + documentType)**

En `tests/TestCase/View/Presentation/InvoicePresentationGroupedRowTest.php`, agregar el `use` de `Employee` **en orden alfabético** — va inmediatamente antes de `use App\Model\Entity\Invoice;` (entre `App\Constants\InvoiceConstants` e `App\Model\Entity\Invoice`):

```php
use App\Constants\InvoiceConstants;
use App\Model\Entity\Employee;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use PHPUnit\Framework\TestCase;
```

Y agregar este método al final de la clase:

```php
    public function testForGroupedRowResolvesEmployeeBeneficiaryAndExposesDocumentType(): void
    {
        $invoice = new Invoice([
            'id' => 11, 'invoice_number' => 'CM-11', 'amount' => 300,
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'invoice_documents' => [],
        ]);
        // Sin proveedor y con empleado → el beneficiario es el empleado.
        $invoice->set('employee', new Employee([
            'first_name' => 'Ana', 'last_name1' => 'Gomez', 'last_name2' => 'Ruiz',
        ]));

        $row = InvoicePresentation::forGroupedRow($invoice, canResolveDian: false);

        $this->assertSame('Ana Gomez Ruiz', $row->beneficiaryName);
        $this->assertSame(InvoiceConstants::DOCTYPE_CAJA_MENOR, $row->documentType);
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit --filter testForGroupedRowResolvesEmployeeBeneficiaryAndExposesDocumentType tests/TestCase/View/Presentation/InvoicePresentationGroupedRowTest.php`
Expected: FAIL — `Error: Unknown named parameter $beneficiaryName` o propiedad `beneficiaryName`/`documentType` inexistente.

- [ ] **Step 3: Renombrar el campo del DTO y agregar `documentType`**

En `src/View/Presentation/GroupedInvoiceRowView.php`, dentro del constructor, reemplazar la línea `public string $providerName,` por estas dos líneas (en ese orden):

```php
        public string $beneficiaryName,
        public string $documentType,
```

- [ ] **Step 4: Cablear el resolver y `documentType` en `forGroupedRow` + actualizar docblock**

En `src/View/Presentation/InvoicePresentation.php`:

Docblock del método (línea ~149), reemplazar:

```php
     * Requiere que el caller haya contenido Providers e InvoiceDocuments.
```

por:

```php
     * Requiere que el caller haya contenido Providers, Employees e InvoiceDocuments.
```

En el `return new GroupedInvoiceRowView(...)`, reemplazar la línea:

```php
            providerName: $invoice->hasValue('provider') ? (string)$invoice->provider->name : '—',
```

por (nota: `InvoiceBeneficiary` está en la misma namespace, no requiere `use`):

```php
            beneficiaryName: InvoiceBeneficiary::label($invoice),
            documentType: (string)($invoice->document_type ?? ''),
```

- [ ] **Step 5: Actualizar los 2 templates consumidores del campo viejo**

En `templates/element/grouped_invoices_table.php`:
- Línea 69: `<th>Proveedor</th>` → `<th>Beneficiario</th>`
- Línea 90: `<td><?= h($row->providerName) ?></td>` → `<td><?= h($row->beneficiaryName) ?></td>`

En `templates/element/advance_legalization/_linked_invoices.php`:
- Línea 88: `<?= h(InvoiceBeneficiary::label($li)) ?>` → `<?= h($rowView->beneficiaryName) ?>`
- Línea 22: eliminar la línea `use App\View\Presentation\InvoiceBeneficiary;` (queda huérfana tras el cambio anterior; `$rowView` ya se calcula en la línea 76).

- [ ] **Step 6: Actualizar el test del element (constructor del DTO + header)**

En `tests/TestCase/View/GroupedInvoicesTableElementTest.php`, dentro de `_row()` reemplazar la línea `providerName: 'ACME',` por:

```php
            beneficiaryName: 'ACME',
            documentType: 'Caja menor',
```

Y agregar este método de test:

```php
    public function testRendersBeneficiarioHeaderNotProveedor(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
        ]);

        $this->assertStringContainsString('Beneficiario', $html);
        $this->assertStringNotContainsString('>Proveedor<', $html);
    }
```

- [ ] **Step 7: Correr los tests afectados y verificar que pasan**

Run: `vendor/bin/phpunit tests/TestCase/View/Presentation/InvoicePresentationGroupedRowTest.php tests/TestCase/View/GroupedInvoicesTableElementTest.php`
Expected: PASS (todos).

- [ ] **Step 8: cs-check y commit**

Run: `composer cs-check`
Expected: sin errores en los archivos tocados.

```bash
git add src/View/Presentation/GroupedInvoiceRowView.php src/View/Presentation/InvoicePresentation.php templates/element/grouped_invoices_table.php templates/element/advance_legalization/_linked_invoices.php tests/TestCase/View/Presentation/InvoicePresentationGroupedRowTest.php tests/TestCase/View/GroupedInvoicesTableElementTest.php
git commit -m "feat: beneficiario (proveedor/empleado) en la tabla de facturas agrupadas"
```

---

### Task 2: Columna "Tipo de documento" en el element compartido

Agrega la columna Tipo después de `# Factura` en `grouped_invoices_table` y recalcula el `colspan` del `<tfoot>`.

**Files:**
- Modify: `templates/element/grouped_invoices_table.php` (thead, tbody, tfoot)
- Test: `tests/TestCase/View/GroupedInvoicesTableElementTest.php`

**Interfaces:**
- Consumes: `GroupedInvoiceRowView::$documentType` (Task 1).

- [ ] **Step 1: Escribir el test que falla (header Tipo + valor)**

En `tests/TestCase/View/GroupedInvoicesTableElementTest.php`, agregar:

```php
    public function testRendersDocumentTypeColumn(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
        ]);

        $this->assertStringContainsString('<th>Tipo</th>', $html);
        $this->assertStringContainsString('Caja menor', $html);
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit --filter testRendersDocumentTypeColumn tests/TestCase/View/GroupedInvoicesTableElementTest.php`
Expected: FAIL — no existe `<th>Tipo</th>`.

- [ ] **Step 3: Agregar la columna Tipo en el thead**

En `templates/element/grouped_invoices_table.php`, en el `<thead>`, insertar `<th>Tipo</th>` justo después de `<th># Factura</th>`:

```php
                        <th># Factura</th>
                        <th>Tipo</th>
                        <th>Beneficiario</th>
```

- [ ] **Step 4: Agregar la celda Tipo en el tbody**

En el mismo archivo, dentro del `<tr class="clickable-row" ...>`, justo después del `</td>` de la celda `# Factura` (la que cierra tras `<?= h($row->number) ?>`) y antes de la celda de beneficiario (`<td><?= h($row->beneficiaryName) ?></td>`), insertar:

```php
                        <td><?= h($row->documentType ?: '—') ?></td>
```

- [ ] **Step 5: Recalcular el `colspan` del `<tfoot>` de Total**

En el mismo archivo, en el `<tfoot>`, la etiqueta "Total:" pasa a abarcar 3 columnas (# Factura, Tipo, Beneficiario). Reemplazar:

```php
                        <td colspan="2" style="text-align:right;font-weight:700;">Total:</td>
```

por:

```php
                        <td colspan="3" style="text-align:right;font-weight:700;">Total:</td>
```

(El `<td colspan="<?= $editable ? 5 : 4 ?>"></td>` del trailing NO cambia: total de columnas queda 8 en modo view / 9 con desvincular.)

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/View/GroupedInvoicesTableElementTest.php`
Expected: PASS (todos, incluidos los 4 previos que verifican tfoot/desvincular).

- [ ] **Step 7: cs-check y commit**

Run: `composer cs-check`

```bash
git add templates/element/grouped_invoices_table.php tests/TestCase/View/GroupedInvoicesTableElementTest.php
git commit -m "feat: columna Tipo de documento en la tabla compartida de facturas agrupadas"
```

---

### Task 3: Columna "Tipo de documento" en la tabla de Anticipos (`_linked_invoices`)

Replica la columna Tipo en la tabla bespoke (CSS grid) de la legalización de anticipos. Cuidado con el conteo de tracks del grid (off-by-one).

**Files:**
- Modify: `templates/element/advance_legalization/_linked_invoices.php` (`$liGrid`, header, fila, tfoot)
- Test: `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`

**Interfaces:**
- Consumes: `GroupedInvoiceRowView::$documentType` vía `$rowView` (ya calculado en la línea 76 con `forGroupedRow`).

- [ ] **Step 1: Escribir el test de integración que falla**

En `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`, agregar este método (usa los mismos factories que los tests vecinos):

```php
    public function testLinkedInvoicesRenderDocumentTypeColumn(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        // Hija Recibo de Caja: su document_type aparece en la nueva columna Tipo.
        InvoiceFactory::new(['provider_id' => $provider->id, 'advance_id' => $anticipo->id])
            ->reciboDeCaja()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('<span>Tipo</span>');
        $this->assertResponseContains(InvoiceConstants::DOCTYPE_RECIBO_CAJA);
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit --filter testLinkedInvoicesRenderDocumentTypeColumn tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`
Expected: FAIL — no existe `<span>Tipo</span>`.

- [ ] **Step 3: Agregar un track al grid**

En `templates/element/advance_legalization/_linked_invoices.php`, línea 33, reemplazar:

```php
$liGrid = 'display:grid;grid-template-columns:1.1fr 1.8fr 0.9fr 1fr 1.2fr 1fr 0.9fr 32px;gap:12px;align-items:center;';
```

por (inserta `1fr` para Tipo, tras el primer track):

```php
$liGrid = 'display:grid;grid-template-columns:1.1fr 1fr 1.8fr 0.9fr 1fr 1.2fr 1fr 0.9fr 32px;gap:12px;align-items:center;';
```

- [ ] **Step 4: Agregar el header span "Tipo"**

En la fila de header (el `<div style="<?= $liGrid ?>...` con `role="row"`), insertar `<span>Tipo</span>` justo después de `<span># Factura</span>`:

```php
            <span># Factura</span>
            <span>Tipo</span>
            <span>Beneficiario</span>
```

- [ ] **Step 5: Agregar el span de Tipo en la fila de datos**

Dentro del `<div class="clickable-row" ...>`, justo después del `</span>` que cierra la celda `# Factura` (la que contiene `<?= h($li->invoice_number ?: '#' . $li->id) ?>`) y antes del span de beneficiario, insertar:

```php
            <span class="mono" style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($rowView->documentType ?: '—') ?>
            </span>
```

- [ ] **Step 6: Corregir el grid de la fila de total (evitar off-by-one)**

En la fila de total (el último `<div style="<?= $liGrid ?>...`), ensanchar SOLO el label de `1 / 4` a `1 / 5` y **mantener los 4 spans vacíos** (NO agregar un quinto). Reemplazar:

```php
            <span style="grid-column:1 / 4;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">
                Total vinculado
            </span>
```

por:

```php
            <span style="grid-column:1 / 5;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">
                Total vinculado
            </span>
```

Y actualizar el comentario de los spans vacíos de `Cols 5-8` a `Cols 6-9` (los 4 spans vacíos existentes se conservan tal cual):

```php
            <?php // Cols 6-9 (Estado, DIAN, Soporte, desvincular) — vacías en el total. ?>
```

Reparto resultante: label(1/5 = 4 tracks) + Monto(auto, 1) + 4 vacíos = 9 tracks. ✅

- [ ] **Step 7: Correr el test y verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`
Expected: PASS (el nuevo test y los ~13 existentes de render de la legalización).

- [ ] **Step 8: cs-check y commit**

Run: `composer cs-check`

```bash
git add templates/element/advance_legalization/_linked_invoices.php tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
git commit -m "feat: columna Tipo de documento en la tabla de facturas de Anticipos"
```

---

### Task 4: `contain('Employees')` en los controllers + integración del beneficiario-empleado

Sin el eager-load de `Employees`, `InvoiceBeneficiary` cae a `'—'` para hijas con beneficiario-empleado. Agrega el contain en los 4 puntos y bloquea la regresión con tests de integración.

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php:183` (view) y `:266` (edit)
- Modify: `src/Controller/RefundsController.php:228` (view) y `:336` (edit)
- Test: `tests/TestCase/Controller/PettyCashViewGroupedTableTest.php`
- Test: `tests/TestCase/Controller/RefundsViewGroupedTableTest.php`

**Interfaces:**
- Consumes: `InvoiceBeneficiary::label()` (rama empleado) — requiere que `$invoice->employee` esté contenido.

- [ ] **Step 1: Escribir los tests de integración que fallan**

En `tests/TestCase/Controller/PettyCashViewGroupedTableTest.php`, agregar los 2 `use` que faltan **en orden alfabético**: `App\Constants\InvoiceConstants` va primero de todo, y `App\Test\Factory\EmployeeFactory` va antes de `InvoiceFactory`. El bloque de imports queda así:

```php
use App\Constants\InvoiceConstants;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
```

Y agregar el método:

```php
    public function testViewShowsEmployeeBeneficiaryAndDocumentType(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'petty_cash',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $employee = EmployeeFactory::new([
            'first_name' => 'Ana', 'last_name1' => 'Gomez', 'last_name2' => 'Ruiz',
        ])->save();
        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new([
            'petty_cash_record_id' => $record->id,
            'employee_id' => $employee->id,
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
        ])->save();

        $this->session(['Auth' => $user]);
        $this->get('/petty-cash-records/view/' . $record->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Ana Gomez Ruiz');
        $this->assertResponseContains('<th>Tipo</th>');
    }
```

En `tests/TestCase/Controller/RefundsViewGroupedTableTest.php`, agregar los 2 `use` que faltan **en orden alfabético**: `App\Constants\InvoiceConstants` va primero de todo, y `App\Test\Factory\EmployeeFactory` va antes de `InvoiceFactory`. El bloque de imports queda así:

```php
use App\Constants\InvoiceConstants;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
```

Y agregar el método:

```php
    public function testViewShowsEmployeeBeneficiaryAndDocumentType(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'refunds',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $employee = EmployeeFactory::new([
            'first_name' => 'Ana', 'last_name1' => 'Gomez', 'last_name2' => 'Ruiz',
        ])->save();
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'employee_id' => $employee->id,
            'document_type' => InvoiceConstants::DOCTYPE_REINTEGRO,
        ])->save();

        $this->session(['Auth' => $user]);
        $this->get('/refunds/view/' . $refund->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Ana Gomez Ruiz');
        $this->assertResponseContains('<th>Tipo</th>');
    }
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `vendor/bin/phpunit --filter testViewShowsEmployeeBeneficiaryAndDocumentType tests/TestCase/Controller/PettyCashViewGroupedTableTest.php tests/TestCase/Controller/RefundsViewGroupedTableTest.php`
Expected: FAIL — la respuesta muestra `—` en vez de `Ana Gomez Ruiz` (el empleado no está contenido).

- [ ] **Step 3: Agregar `Employees` al contain en PettyCash**

En `src/Controller/PettyCashRecordsController.php`:
- Línea ~183 (acción `view`): `'Invoices' => ['Providers', 'InvoiceDocuments'],` → `'Invoices' => ['Providers', 'Employees', 'InvoiceDocuments'],`
- Línea ~266 (acción `edit`): `'Invoices' => ['Providers', 'OperationCenters', 'InvoiceDocuments'],` → `'Invoices' => ['Providers', 'Employees', 'OperationCenters', 'InvoiceDocuments'],`

- [ ] **Step 4: Agregar `Employees` al contain en Refunds**

En `src/Controller/RefundsController.php`:
- Línea ~228 (acción `view`): `'Invoices' => ['Providers', 'InvoiceDocuments'],` → `'Invoices' => ['Providers', 'Employees', 'InvoiceDocuments'],`
- Línea ~336 (acción `edit`): `'Invoices' => ['Providers', 'OperationCenters', 'InvoiceDocuments'],` → `'Invoices' => ['Providers', 'Employees', 'OperationCenters', 'InvoiceDocuments'],`

- [ ] **Step 5: Correr los tests y verificar que pasan**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PettyCashViewGroupedTableTest.php tests/TestCase/Controller/RefundsViewGroupedTableTest.php`
Expected: PASS.

- [ ] **Step 6: cs-check y commit**

Run: `composer cs-check`

```bash
git add src/Controller/PettyCashRecordsController.php src/Controller/RefundsController.php tests/TestCase/Controller/PettyCashViewGroupedTableTest.php tests/TestCase/Controller/RefundsViewGroupedTableTest.php
git commit -m "fix: contener Employees para resolver beneficiario-empleado en facturas agrupadas"
```

---

### Task 5: Fix del click de fila dentro de `edit.php`

Agrega navegación scoped en `spi-grouped-invoices.js`. El handler global de `.clickable-row` (`spi-common.js`) aborta con `closest('form')` cuando la fila vive dentro del `<form>` de `edit.php`; este handler scoped no tiene ese guard. Las celdas DIAN/Soporte/desvincular ya hacen `event.stopPropagation()`, así que quedan excluidas.

**Files:**
- Modify: `webroot/js/spi-grouped-invoices.js` (dentro de `init()`)

**Interfaces:**
- Consumes: cada fila (`.clickable-row` / `[role="row"]`) expone `data-href` con la URL de la factura (ya presente en ambos elements).

- [ ] **Step 1: Agregar el handler de navegación scoped**

En `webroot/js/spi-grouped-invoices.js`, dentro de `function init(opts)`, después del bloque `root.addEventListener('click', ...)` del botón de subida (el que termina antes de `var form = opts.uploadFormSelector ...`), insertar:

```javascript
        // Navegación de fila scoped: el handler global de .clickable-row
        // (spi-common.js) aborta con closest('form') cuando la tabla vive dentro
        // del <form> de edit.php. Aquí navegamos sin ese guard. Las celdas
        // interactivas (DIAN/Soporte/desvincular) hacen stopPropagation en su
        // <td>/<span>, por lo que no llegan hasta este listener del root.
        root.addEventListener('click', function (e) {
            if (e.target.closest('a, button, select, input, textarea, label')) return;
            var row = e.target.closest('[data-href]');
            if (row && root.contains(row) && row.dataset.href) {
                global.location.href = row.dataset.href;
            }
        });
```

- [ ] **Step 2: Smoke-test manual (no hay harness JS automatizado)**

Levantar el server (`php bin/cake server`) y, autenticado con un rol que opere Caja Menor:
1. Abrir `/petty-cash-records/edit/{id}` de un registro con facturas hijas.
2. Click en una celda de texto de una fila (# Factura, Tipo, Beneficiario, Monto) → **navega** a `/invoices/view/{id}`.
3. Click en el botón de subir soporte (`.grouped-upload-btn`) → **abre el modal**, NO navega.
4. Cambiar el `<select>` DIAN inline (si la hija está en `aprobacion`) → dispara el fetch, **no navega** la fila.
5. Repetir el paso 2 en `/refunds/edit/{id}` y en `/advances/legalization/{id}`.

Expected: navegación OK en filas; controles interactivos intactos.

- [ ] **Step 3: Commit**

```bash
git add webroot/js/spi-grouped-invoices.js
git commit -m "fix: navegacion de fila en la tabla de facturas agrupadas dentro de edit.php"
```

---

### Cierre: suite completa + cs-check

- [ ] **Step 1: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: verde (mismo baseline que antes; sin nuevas fallas). Si aparecen errores de contaminación entre suites consecutivas, re-correr limpio antes de concluir.

- [ ] **Step 2: cs-check global de lo tocado**

Run: `composer cs-check`
Expected: sin errores.

---

## Self-Review (cobertura del spec)

- **Beneficiario (proveedor/empleado):** Task 1 (resolver + rename) + Task 4 (contain que lo alimenta). ✅
- **Columna Tipo:** Task 2 (element compartido) + Task 3 (Anticipos). ✅
- **Fix del click:** Task 5. ✅
- **Sin migración:** ninguna task toca esquema. ✅
- **RBAC:** el destino `/invoices/view/{id}` depende de `invoices.can_view` preexistente (declarado en el spec como aceptable; sin cambios de código). ✅
- **Anti-drift:** `documentType` es texto plano crudo; el mapeo estado→pill sigue viviendo en `InvoicePresentation`. ✅
- **Consistencia de tipos:** `beneficiaryName`/`documentType` (string) usados igual en DTO (Task 1), templates (Tasks 1-3) y tests. ✅
- **Off-by-one del grid de Anticipos:** Task 3 Step 6 ensancha el label a `1/5` y conserva 4 vacíos (9 tracks). ✅
