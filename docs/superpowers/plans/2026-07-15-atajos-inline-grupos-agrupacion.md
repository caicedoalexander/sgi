# Atajos inline (DIAN + soporte) en la etapa Agrupación de Caja Menor y Reintegros — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Llevar los atajos inline por fila (subir soporte + resolver/indicar DIAN) a la tabla de facturas de la etapa Agrupación en Caja Menor y Reintegros, en paridad con Legalizaciones.

**Architecture:** Extender el element compartido `grouped_invoices_table` con afordances editables opcionales (columna desvincular, `<tfoot>` de total, slot de header) y usarlo como card top-level en las dos `edit.php` (extrayendo la tabla artesanal). Los controllers `edit()` propagan `groupedRows`/`readiness`/`canUploadSupport`/(Reintegros)`canResolveDian` vía sus EditViewModels, replicando los gates que ya usan sus `view()`.

**Tech Stack:** CakePHP 5.3, PHP 8.4, PHPUnit + cakephp-fixture-factories, MySQL/MariaDB (`sgi_test`).

## Global Constraints

- **No tocar Legalizaciones** (`templates/Advances/legalization.php`, `templates/element/advance_legalization/_linked_invoices.php`) ni sus flujos.
- **Anti-drift de vista:** el mapeo estado→pill/DIAN/soporte vive SOLO en `InvoicePresentation` (consts) y llega vía los DTOs. CERO literales `pill-*` nuevos en el element o las plantillas.
- **`canResolveDian` en Reintegros = gate de 3 partes** (`canOperate(aprobacion) && can_edit(invoices) && 'dian_validation' ∈ InvoiceFieldAccessPolicy::getEditableFields(roleId, STATUS_APROBACION)`). NUNCA igualarlo a `canUploadSupport`. En Caja Menor `canResolveDian` se fija en `false` (hijas nunca están en `aprobacion`).
- **`<tfoot>` de Total** se renderiza cuando `totalAmount !== null`, NO cuando `editable`. No debe desaparecer en estados post-Agrupación.
- **Retrocompatibilidad del element:** los defaults nuevos (`editable=false`, `headerActionsHtml=null`, `totalAmount=null`) preservan el markup actual que consumen las `view.php`. Los tests `PettyCashViewGroupedTableTest` y `RefundsViewGroupedTableTest` deben seguir verdes sin cambios.
- **Valores exactos (no hardcodear strings):** usar `InvoiceConstants::STATUS_APROBACION`, `InvoiceConstants::STATUS_CONTABILIDAD`, `PipelineStepConstants::PIPELINE_INVOICES`, `'dian_validation'`, `'removeInvoice'`, `'petty_cash_record_id'`, `'refund_id'`.
- **Sin migraciones, sin cambios de datos persistidos, slugs ni RBAC persistido** (tablas `permissions`/`pipeline_permissions`). Es capa de vista + propagación.
- **Git:** nunca `git add -A`; excluir `config/bootstrap.php` (cambio ajeno). Commits sin trailer de atribución.
- **Tests:** correr con `vendor/bin/phpunit` (NO `composer test`), timeout 300s, sin dos suites concurrentes.

---

### Task 1: Element `grouped_invoices_table` — afordances editables opcionales

**Files:**
- Modify: `templates/element/grouped_invoices_table.php`
- Test: `tests/TestCase/View/GroupedInvoicesTableElementTest.php` (Create)

**Interfaces:**
- Consumes: DTO `App\View\Presentation\GroupedInvoiceRowView` (constructor con args nombrados: `id, number, providerName, amount, issueDate, statusLabel, statusPill, dianMode, dianValue, dianPill, supportRequired, docsCount, supportOk, childStatus`).
- Produces: el element acepta nuevos parámetros opcionales `editable` (bool, default false), `headerActionsHtml` (string|null, default null), `unlinkAction` (string, default `'removeInvoice'`), `totalAmount` (float|null, default null). Consumidos por Tasks 2 y 3.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/TestCase/View/GroupedInvoicesTableElementTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\View;

use App\View\AppView;
use App\View\Presentation\GroupedInvoiceRowView;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * Verifica las afordances editables opcionales del element grouped_invoices_table:
 * con editable=true aparece la columna de desvincular; el <tfoot> de Total se liga a
 * totalAmount (no a editable); con los defaults (modo view.php) no hay desvincular.
 */
final class GroupedInvoicesTableElementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // El element reverse-routea (Url->build / postLink) al renderizar; sin
        // rutas cargadas lanzaría MissingRouteException. Un TestCase plano NO
        // las conecta solo.
        $this->loadRoutes();
    }

    private function _row(): GroupedInvoiceRowView
    {
        return new GroupedInvoiceRowView(
            id: 7,
            number: 'F-7',
            providerName: 'ACME',
            amount: 5000.0,
            issueDate: '01/01/2026',
            statusLabel: 'Contabilidad',
            statusPill: 'pill-info-soft',
            dianMode: 'na',
            dianValue: 'Pendiente',
            dianPill: 'pill-muted',
            supportRequired: true,
            docsCount: 0,
            supportOk: false,
            childStatus: 'contabilidad',
        );
    }

    private function _view(): AppView
    {
        $request = new ServerRequest([
            'url' => '/petty-cash-records/edit/3',
            'params' => ['controller' => 'PettyCashRecords', 'action' => 'edit', 'pass' => ['3']],
        ]);

        return new AppView($request);
    }

    public function testEditableModeAddsUnlinkColumnAndTotalFooter(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
            'editable' => true,
            'unlinkAction' => 'removeInvoice',
            'totalAmount' => 5000.0,
        ]);

        $this->assertStringContainsString('bi-x-lg', $html, 'Falta el ícono de desvincular en modo editable');
        $this->assertStringContainsString('Total:', $html, 'Falta el footer de Total');
    }

    public function testReadOnlyModeOmitsUnlinkColumn(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
        ]);

        $this->assertStringNotContainsString('bi-x-lg', $html, 'No debe haber desvincular en modo view');
        $this->assertStringContainsString('grouped-invoices-petty_cash_record_id', $html);
    }

    public function testTotalFooterRendersEvenWhenNotEditable(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
            'editable' => false,
            'totalAmount' => 5000.0,
        ]);

        $this->assertStringContainsString('Total:', $html);
        $this->assertStringNotContainsString('bi-x-lg', $html);
    }

    public function testHeaderActionsHtmlSlotRenders(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
            'headerActionsHtml' => '<button id="mi-boton-vincular">Vincular</button>',
        ]);

        $this->assertStringContainsString('mi-boton-vincular', $html);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit tests/TestCase/View/GroupedInvoicesTableElementTest.php`
Expected: FAIL — `bi-x-lg` no aparece (modo editable aún no existe) y/o `headerActionsHtml` no se renderiza.

- [ ] **Step 3: Añadir los defaults de los nuevos parámetros**

En `templates/element/grouped_invoices_table.php`, en el bloque de defaults del docblock/head (después de `$uploadModalId = $uploadModalId ?? null;`), añadir:

```php
$editable = $editable ?? false;
$headerActionsHtml = $headerActionsHtml ?? null;
$unlinkAction = $unlinkAction ?? 'removeInvoice';
$totalAmount = $totalAmount ?? null;
```

Y en el docblock de `@var`, añadir las líneas:

```php
 * @var bool $editable  Pinta la columna de desvincular por fila (default false).
 * @var string|null $headerActionsHtml  Slot HTML en el header (ej. botón Vincular).
 * @var string $unlinkAction  Acción del controller actual para el postLink de desvincular.
 * @var float|null $totalAmount  Cuando != null, renderiza el <tfoot> de Total.
```

- [ ] **Step 4: Renderizar el slot de acciones en el header**

Reemplazar el header actual:

```php
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <?= h($title) ?>
            <span class="spi-folder-count"><?= count($rows) ?></span>
        </span>
    </div>
```

por:

```php
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <?= h($title) ?>
            <span class="spi-folder-count"><?= count($rows) ?></span>
        </span>
        <?php if ($headerActionsHtml !== null): ?>
        <div class="d-flex gap-2"><?= $headerActionsHtml ?></div>
        <?php endif; ?>
    </div>
```

- [ ] **Step 5: Añadir la columna de desvincular al `<thead>`**

En el `<thead>`, después de `<th>Soporte</th>`, añadir:

```php
                        <?php if ($editable): ?>
                        <th style="width:48px;" aria-label="Acciones"></th>
                        <?php endif; ?>
```

- [ ] **Step 6: Añadir la celda de desvincular a cada fila del `<tbody>`**

En el `<tr>` de cada fila, después de la llamada a `$this->element('grouped_cells/_support', [...])`, añadir:

```php
                        <?php if ($editable): ?>
                        <td onclick="event.stopPropagation();" style="text-align:center;">
                            <?= $this->Form->postLink(
                                '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                                ['action' => $unlinkAction, (int)$parentId, $row->id],
                                [
                                    'class' => 'btn-icon',
                                    'escape' => false,
                                    'confirm' => '¿Remover esta factura del registro?',
                                    'title' => 'Quitar',
                                    'block' => true,
                                ]
                            ) ?>
                        </td>
                        <?php endif; ?>
```

- [ ] **Step 7: Añadir el `<tfoot>` de Total**

Después de `</tbody>` y antes de `</table>`, añadir:

```php
                <?php if ($totalAmount !== null): ?>
                <tfoot>
                    <tr>
                        <td colspan="2" style="text-align:right;font-weight:700;">Total:</td>
                        <td class="mono" style="text-align:right;font-weight:700;color:var(--primary-color);">
                            $ <?= number_format((float)$totalAmount, 0, ',', '.') ?>
                        </td>
                        <td colspan="<?= $editable ? 5 : 4 ?>"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
```

- [ ] **Step 8: Correr el test y verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/View/GroupedInvoicesTableElementTest.php`
Expected: PASS (4 tests).

- [ ] **Step 9: Verificar retrocompatibilidad de las `view.php`**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PettyCashViewGroupedTableTest.php tests/TestCase/Controller/RefundsViewGroupedTableTest.php`
Expected: PASS (los tests de vista siguen verdes; los defaults no cambian su markup).

- [ ] **Step 10: Commit**

```bash
git add templates/element/grouped_invoices_table.php tests/TestCase/View/GroupedInvoicesTableElementTest.php
git commit -m "feat: modo editable opcional en grouped_invoices_table (desvincular + total + slot header)"
```

---

### Task 2: Caja Menor — EditViewModel + controller `edit()` + `edit.php`

**Files:**
- Modify: `src/ViewModel/PettyCashEditViewModel.php`
- Modify: `src/Controller/PettyCashRecordsController.php`
- Modify: `templates/PettyCashRecords/edit.php`
- Test: `tests/TestCase/Controller/PettyCashEditGroupedTableTest.php` (Create)

**Interfaces:**
- Consumes: element `grouped_invoices_table` con `editable`/`headerActionsHtml`/`unlinkAction`/`totalAmount` (Task 1); `InvoicePresentation::forGroupedRow(Invoice $inv, bool $canResolveDian): GroupedInvoiceRowView`; `App\Service\Dto\GroupReadinessReport`; Guard `App\Service\Pipeline\PettyCash\Guard\PettyCashGuard::childRequirements(int): GroupReadinessReport`.
- Produces: `PettyCashEditViewModel` gana `public array $groupedRows`, `public ?GroupReadinessReport $readiness`, `public bool $canUploadSupport`. El template publica variables sueltas (`set(get_object_vars($vm))`), así que en `edit.php` estarán `$groupedRows`, `$readiness`, `$canUploadSupport`.

- [ ] **Step 1: Escribir el test de integración que falla**

Crear `tests/TestCase/Controller/PettyCashEditGroupedTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * La etapa Agrupación de Caja Menor (edit) renderiza la tabla rica de facturas
 * (element grouped_invoices_table) con la columna de desvincular, en vez de la
 * tabla artesanal de 3 columnas.
 */
final class PettyCashEditGroupedTableTest extends TestCase
{
    use IntegrationTestTrait;

    public function testEditAgrupacionRendersRichGroupedTableWithUnlink(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'petty_cash',
            'can_view' => true,
            'can_edit' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $record = PettyCashRecordFactory::new()->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        InvoiceFactory::new([
            'petty_cash_record_id' => $record->id,
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
        ])->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/petty-cash-records/edit/' . $record->id);

        $this->assertResponseOk();
        // Root del element rico (no la tabla artesanal).
        $this->assertResponseContains('grouped-invoices-petty_cash_record_id');
        // Columna DIAN/Soporte del element rico (ausente en la tabla artesanal de 3 columnas).
        $this->assertResponseContains('>Soporte<');
        // Acción de desvincular por fila. OJO: DashedRoute + ruta explícita
        // `/petty-cash-records/remove-invoice/...` → la URL sale dasherizada.
        $this->assertResponseContains('remove-invoice');
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PettyCashEditGroupedTableTest.php`
Expected: FAIL — `grouped-invoices-petty_cash_record_id` no aparece (la edit aún usa la tabla artesanal sin columna Soporte ni el root del element).

- [ ] **Step 3: Extender `PettyCashEditViewModel` con las nuevas propiedades**

En `src/ViewModel/PettyCashEditViewModel.php`:

Añadir imports (después de `use App\Model\Entity\PettyCashRecord;`):

```php
use App\Service\Dto\GroupReadinessReport;
use App\View\Presentation\InvoicePresentation;
```

Declarar la propiedad derivada (junto a las otras `public` del bloque de propiedades):

```php
    /** @var list<\App\View\Presentation\GroupedInvoiceRowView> */
    public array $groupedRows;
```

Añadir dos parámetros al final del constructor (después de `public array $groupFilters,`):

```php
        public ?GroupReadinessReport $readiness = null,
        public bool $canUploadSupport = false,
```

Al final del cuerpo del constructor (después de la asignación de `$this->submitButtonHtml`), construir las filas:

```php
        $groupedRows = [];
        foreach ($record->invoices ?? [] as $inv) {
            // Caja Menor: las hijas viven en `contabilidad`; canResolveDian es
            // irrelevante (forGroupedRow solo activa el <select> en `aprobacion`).
            $groupedRows[] = InvoicePresentation::forGroupedRow($inv, canResolveDian: false);
        }
        $this->groupedRows = $groupedRows;
```

- [ ] **Step 4: Añadir `InvoiceDocuments` al contain de `edit()` y extraer el gate de soporte**

En `src/Controller/PettyCashRecordsController.php`:

(a) En `edit()`, cambiar el contain de las hijas:

```php
            'Invoices' => ['Providers', 'OperationCenters'],
```
por:
```php
            'Invoices' => ['Providers', 'OperationCenters', 'InvoiceDocuments'],
```

(b) Extraer el gate de soporte a un helper privado compartido por `view()` y `edit()`. Añadir el método (junto a `_buildEditViewModel`):

```php
    /**
     * Gate para pintar el atajo de subir soporte en las hijas. Las hijas de un
     * registro de Caja Menor viven en `contabilidad` (saltan `aprobacion` por
     * diseño — sin DIAN), así que el gate se resuelve contra ese step. Es un
     * PROXY visual; `Invoices::uploadDocument` revalida server-side.
     */
    private function _canUploadChildSupport(): bool
    {
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $context = new UserContext($roleId);

        return $this->authFacade->canOperate(
            $context,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_CONTABILIDAD,
        ) && $this->_checkPermission('invoices', 'edit');
    }
```

(c) En `view()`, reemplazar el bloque inline del gate — **incluyendo el comentario que lo describe** (`// Gate para la acción inline de soporte sobre las hijas. Las facturas / hijas ... / contabilidad ...`), que ahora vive dentro de `_canUploadChildSupport()`. Reemplazar desde ese comentario hasta la construcción de `PettyCashViewViewModel`:

```php
        // Gate para la acción inline de soporte sobre las hijas. Las facturas
        // hijas de un registro de caja menor viven en `contabilidad` (saltan
        // `aprobacion` por diseño — sin DIAN), así que el gate se resuelve
        // contra ese step del pipeline de facturas.
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $context = new UserContext($roleId);
        $canUploadSupport = $this->authFacade->canOperate(
            $context,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_CONTABILIDAD,
        ) && $this->_checkPermission('invoices', 'edit');

        $this->set('viewModel', new PettyCashViewViewModel(
            $record,
            (new PettyCashGuard())->childRequirements((int)$record->id),
            canUploadSupport: $canUploadSupport,
        ));
```
por:
```php
        $this->set('viewModel', new PettyCashViewViewModel(
            $record,
            (new PettyCashGuard())->childRequirements((int)$record->id),
            canUploadSupport: $this->_canUploadChildSupport(),
        ));
```

- [ ] **Step 5: Pasar `readiness` y `canUploadSupport` al EditViewModel**

En `_buildEditViewModel()`, añadir al final de la construcción de `PettyCashEditViewModel` (después de `groupFilters: ...,`):

```php
            readiness: (new PettyCashGuard())->childRequirements((int)$record->id),
            canUploadSupport: $this->_canUploadChildSupport(),
```

- [ ] **Step 6: Reemplazar la tabla artesanal por el element en `edit.php`**

En `templates/PettyCashRecords/edit.php`:

(a) En el array `$sections`, eliminar la línea:
```php
        $sections[] = ['key' => 'invoices', 'editable' => $record->isAgrupacion()];
```

(b) Eliminar el bloque de render de la sección de facturas (el `<?php if ($section['key'] === 'invoices'): ?> ... <?php endif; ?>`, con su tabla artesanal, botón Vincular y tfoot).

(c) Insertar la card top-level del element justo después del banner de "requisitos para avanzar" (después del `<?php endif; ?>` de ese banner) y antes del bloque `<?php // Section reordering ... ?>`:

```php
        <?php /* ── Facturas agrupadas: card top-level (paridad con view.php + Legalizaciones) ── */ ?>
        <?php
        $linkBtnHtml = null;
        if ($record->isAgrupacion()) {
            $linkBtnHtml = '<button type="button" class="btn btn-secondary btn-sm" '
                . 'data-bs-toggle="modal" data-bs-target="#linkPettyCashInvoicesModal">'
                . '<i class="bi bi-link-45deg" aria-hidden="true"></i>Vincular facturas</button>';
        }
        ?>
        <?= $this->element('grouped_invoices_table', [
            'rows' => $groupedRows,
            'readiness' => $readiness,
            'parentField' => 'petty_cash_record_id',
            'parentId' => (int)$record->id,
            'canUploadSupport' => $canUploadSupport,
            'uploadModalId' => $canUploadSupport ? 'groupedUploadModal' : null,
            'editable' => $record->isAgrupacion(),
            'headerActionsHtml' => $linkBtnHtml,
            'unlinkAction' => 'removeInvoice',
            'totalAmount' => (float)$record->total_amount,
        ]) ?>
```

- [ ] **Step 7: Incluir el modal de subida grupal en `edit.php`**

En `templates/PettyCashRecords/edit.php`, junto a los demás modales (después del bloque `<?php if (!$record->isPagada()): ?> ... upload_doc_modal 'uploadPcDocModal' ... <?php endif; ?>`), añadir:

```php
<?php // Modal compartido para subir soporte a una hija; el JS (SpiGroupedInvoices) fija su URL por fila. ?>
<?php if ($canUploadSupport): ?>
<?= $this->element('upload_doc_modal', [
    'modalId' => 'groupedUploadModal',
    'uploadUrl' => '',
    'formId' => 'grouped-upload-form',
    'showDocumentType' => true,
]) ?>
<?php endif; ?>
```

- [ ] **Step 8: Correr el test de integración y verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PettyCashEditGroupedTableTest.php`
Expected: PASS.

- [ ] **Step 9: Correr la regresión de Caja Menor (view + edit + servicio)**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PettyCashViewGroupedTableTest.php tests/TestCase/Controller/PettyCashViewGroupedTableTest.php tests/TestCase/Controller/PettyCashRecordsControllerTest.php`
Expected: PASS (la `view()` sigue igual tras extraer el gate; la `edit()` sigue guardando/avanzando).

Nota: si `PettyCashRecordsControllerTest` no existe, correr solo los dos tests de tabla agrupada de Caja Menor.

- [ ] **Step 10: Commit**

```bash
git add src/ViewModel/PettyCashEditViewModel.php src/Controller/PettyCashRecordsController.php templates/PettyCashRecords/edit.php tests/TestCase/Controller/PettyCashEditGroupedTableTest.php
git commit -m "feat: atajos inline (soporte) en la etapa Agrupacion de Caja Menor"
```

---

### Task 3: Reintegros — EditViewModel + controller `edit()` + `edit.php`

**Files:**
- Modify: `src/ViewModel/RefundEditViewModel.php`
- Modify: `src/Controller/RefundsController.php`
- Modify: `templates/Refunds/edit.php`
- Test: `tests/TestCase/Controller/RefundEditGroupedTableTest.php` (Create)

**Interfaces:**
- Consumes: element `grouped_invoices_table` (Task 1); `InvoicePresentation::forGroupedRow`; `App\Service\Dto\GroupReadinessReport`; `App\Service\RefundApprovalGuard::childRequirements(int): GroupReadinessReport`; `App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy::getEditableFields(int $roleId, string $step): array`.
- Produces: `RefundEditViewModel` gana `public array $groupedRows`, `public ?GroupReadinessReport $readiness`, `public bool $canUploadSupport`, `public bool $canResolveDian`. `RefundsController::edit()` publica con `set('viewModel', $vm)`, así que en `edit.php` se accede vía `$viewModel->groupedRows`, etc.

- [ ] **Step 1: Escribir el test de integración que falla**

Crear `tests/TestCase/Controller/RefundEditGroupedTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * La etapa Agrupación de Reintegros (edit) renderiza la tabla rica de facturas
 * (element grouped_invoices_table) con la columna de desvincular.
 */
final class RefundEditGroupedTableTest extends TestCase
{
    use IntegrationTestTrait;

    public function testEditAgrupacionRendersRichGroupedTableWithUnlink(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'refunds',
            'can_view' => true,
            'can_edit' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_AGRUPACION)->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'document_type' => InvoiceConstants::DOCTYPE_REINTEGRO,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/refunds/edit/' . $refund->id);

        $this->assertResponseOk();
        $this->assertResponseContains('grouped-invoices-refund_id');
        $this->assertResponseContains('>Soporte<');
        // DashedRoute + ruta explícita `/refunds/remove-invoice/...` → URL dasherizada.
        $this->assertResponseContains('remove-invoice');
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit tests/TestCase/Controller/RefundEditGroupedTableTest.php`
Expected: FAIL — `grouped-invoices-refund_id` no aparece (edit usa la tabla artesanal).

- [ ] **Step 3: Extender `RefundEditViewModel` con las nuevas propiedades**

En `src/ViewModel/RefundEditViewModel.php`:

Añadir imports (después de `use App\Model\Entity\Refund;`):

```php
use App\Service\Dto\GroupReadinessReport;
use App\View\Presentation\InvoicePresentation;
```

Declarar la propiedad derivada (junto a las otras `public` del bloque):

```php
    /** @var list<\App\View\Presentation\GroupedInvoiceRowView> */
    public array $groupedRows;
```

Añadir tres parámetros al final del constructor (después de `public bool $hasPendingApprovals = false,`):

```php
        public ?GroupReadinessReport $readiness = null,
        public bool $canUploadSupport = false,
        public bool $canResolveDian = false,
```

Y añadir sus líneas al docblock `@param` del constructor (junto a los `@param` existentes, para no dejar la doc desincronizada con la firma):

```php
     * @param \App\Service\Dto\GroupReadinessReport|null $readiness Requisitos DIAN/soporte pendientes de las hijas.
     * @param bool $canUploadSupport Pinta el atajo de subir soporte en las hijas.
     * @param bool $canResolveDian Pinta el <select> DIAN inline en las hijas en `aprobacion` (gate de 3 partes).
```

Al final del cuerpo del constructor (después de la asignación de `$this->submitButtonHtml`), construir las filas:

```php
        $groupedRows = [];
        foreach ($record->invoices ?? [] as $inv) {
            $groupedRows[] = InvoicePresentation::forGroupedRow($inv, $canResolveDian);
        }
        $this->groupedRows = $groupedRows;
```

- [ ] **Step 4: Añadir `InvoiceDocuments` al contain de `edit()` y extraer los gates**

En `src/Controller/RefundsController.php`:

(a) En `edit()`, cambiar el contain de las hijas:

```php
            'Invoices' => ['Providers', 'OperationCenters'],
```
por:
```php
            'Invoices' => ['Providers', 'OperationCenters', 'InvoiceDocuments'],
```

(b) Extraer los gates a un helper privado compartido por `view()` y `edit()`. Añadir el método (junto a `_buildEditViewModel`):

```php
    /**
     * Gates para las acciones inline sobre las hijas de un reintegro (viven en
     * `aprobacion`). `canResolveDian` es de 3 partes: además de operar el step y
     * tener can_edit(invoices), el `InvoiceFieldAccessPolicy` del rol debe incluir
     * `dian_validation` (invariante FieldAccessPolicy rol-aware). NO igualar a
     * canUploadSupport. `updateDianInline` revalida server-side.
     *
     * @return array{canUploadSupport: bool, canResolveDian: bool}
     */
    private function _childActionGates(): array
    {
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $context = new UserContext($roleId);
        $canOperateAprobacion = $this->authFacade->canOperate(
            $context,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        );
        $canEditInvoices = $this->_checkPermission('invoices', 'edit');
        $fieldPolicy = new InvoiceFieldAccessPolicy($this->authFacade);
        $canResolveDian = $canOperateAprobacion && $canEditInvoices
            && in_array(
                'dian_validation',
                $fieldPolicy->getEditableFields($roleId, InvoiceConstants::STATUS_APROBACION),
                true,
            );

        return [
            'canUploadSupport' => $canOperateAprobacion && $canEditInvoices,
            'canResolveDian' => $canResolveDian,
        ];
    }
```

(c) En `view()`, reemplazar el bloque inline del gate — **incluyendo los comentarios que lo describen** (`// Gate para las acciones inline sobre las hijas ...` y el bloque `// canUploadSupport usa ... PROXY ...`), que ahora viven junto al helper `_childActionGates()`. Reemplazar desde el primer comentario del gate hasta el cierre de la construcción de `RefundViewViewModel` por:

```php
        $gates = $this->_childActionGates();
        $this->set('viewModel', new RefundViewViewModel(
            $record,
            (new RefundApprovalGuard())->childRequirements((int)$record->id),
            $gates['canResolveDian'],
            canUploadSupport: $gates['canUploadSupport'],
        ));
```

- [ ] **Step 5: Pasar `readiness` y los gates al EditViewModel**

En `_buildEditViewModel()`, capturar los gates una sola vez al inicio del método (junto a `$nextStatus = ...`):

```php
        $gates = $this->_childActionGates();
```

y añadir al final de la construcción de `RefundEditViewModel` (después del último parámetro actual):

```php
            readiness: (new RefundApprovalGuard())->childRequirements((int)$record->id),
            canUploadSupport: $gates['canUploadSupport'],
            canResolveDian: $gates['canResolveDian'],
```

- [ ] **Step 6: Reemplazar la tabla artesanal por el element en `edit.php`**

En `templates/Refunds/edit.php`:

(a) Al inicio (junto a los otros `$x = $viewModel->x;`), añadir:

```php
$groupedRows      = $viewModel->groupedRows;
$readiness        = $viewModel->readiness;
$canUploadSupport = $viewModel->canUploadSupport;
```

(b) En el array `$sections`, eliminar la línea:
```php
        $sections[] = ['key' => 'invoices',    'editable' => $record->isAgrupacion()];
```

(c) Eliminar el bloque de render de la sección de facturas (`<?php if ($section['key'] === 'invoices'): ?> ... <?php endif; ?>`, con su tabla artesanal, tfoot y botón Vincular).

Nota (verificado): la card "Información del reintegro" (`<div class="card" style="padding:20px;">`, sin guard `if(!empty($sections))`) NO queda como shell vacío al quitar `invoices`. En todos los estados conserva contenido: agrupacion→`beneficiary` (editable), aprobacion→`approval`, contabilidad+→`accounting`/`treasury` (porque `PipelineEditFlags::showAccounting = statusIndex >= contabilidadIdx`). No añadir guard.

(d) Insertar la card top-level del element como primer contenido del `<main>`, después del `usort(...)` y su `?>`, antes de `<div class="card" style="padding:20px;">`:

```php
        <?php /* ── Facturas agrupadas: card top-level (paridad con view.php + Legalizaciones) ── */ ?>
        <?php
        $linkBtnHtml = null;
        if ($record->isAgrupacion()) {
            $linkBtnHtml = '<button type="button" class="btn btn-sm btn-primary" '
                . 'data-bs-toggle="modal" data-bs-target="#linkRefundInvoicesModal">'
                . '<i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Vincular facturas</button>';
        }
        ?>
        <?= $this->element('grouped_invoices_table', [
            'rows' => $groupedRows,
            'readiness' => $readiness,
            'parentField' => 'refund_id',
            'parentId' => (int)$record->id,
            'canUploadSupport' => $canUploadSupport,
            'uploadModalId' => $canUploadSupport ? 'groupedUploadModal' : null,
            'editable' => $record->isAgrupacion(),
            'headerActionsHtml' => $linkBtnHtml,
            'unlinkAction' => 'removeInvoice',
            'totalAmount' => (float)$record->total_amount,
        ]) ?>
```

- [ ] **Step 7: Incluir el modal de subida grupal en `edit.php`**

En `templates/Refunds/edit.php`, junto a los demás modales (después del `upload_doc_modal 'uploadRefundDocModal'`), añadir:

```php
<?php // Modal compartido para subir soporte a una hija; el JS (SpiGroupedInvoices) fija su URL por fila. ?>
<?php if ($canUploadSupport): ?>
<?= $this->element('upload_doc_modal', [
    'modalId' => 'groupedUploadModal',
    'uploadUrl' => '',
    'formId' => 'grouped-upload-form',
    'showDocumentType' => true,
]) ?>
<?php endif; ?>
```

- [ ] **Step 8: Correr el test de integración y verificar que pasa**

Run: `vendor/bin/phpunit tests/TestCase/Controller/RefundEditGroupedTableTest.php`
Expected: PASS.

- [ ] **Step 9: Correr la regresión de Reintegros**

Run: `vendor/bin/phpunit tests/TestCase/Controller/RefundsViewGroupedTableTest.php tests/TestCase/Controller/RefundsControllerTest.php`
Expected: PASS (la `view()` sigue igual tras extraer los gates).

Nota: si `RefundsControllerTest` no existe, correr solo `RefundsViewGroupedTableTest`.

- [ ] **Step 10: Commit**

```bash
git add src/ViewModel/RefundEditViewModel.php src/Controller/RefundsController.php templates/Refunds/edit.php tests/TestCase/Controller/RefundEditGroupedTableTest.php
git commit -m "feat: atajos inline (DIAN + soporte) en la etapa Agrupacion de Reintegros"
```

---

### Task 4: Documentar el modo editable en CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: nada (doc).
- Produces: nada (doc).

- [ ] **Step 1: Actualizar la nota del element compartido**

En `CLAUDE.md`, en la subsección de la capa de vista donde se describe `templates/element/grouped_invoices_table.php` (la fila/párrafo que menciona la "Tabla de hijas de un padre (Refund/PettyCash/Advance)"), añadir una frase indicando el modo editable:

```
El element `grouped_invoices_table` tiene un modo editable opcional (`editable`, `headerActionsHtml`, `unlinkAction`, `totalAmount`) que las `edit.php` de Caja Menor y Reintegros usan en la etapa Agrupación (columna desvincular + footer de total + botón Vincular en el header), reusando las mismas celdas inline de DIAN/soporte que la `view.php`. El `<tfoot>` de total se liga a `totalAmount !== null`, no a `editable`.
```

- [ ] **Step 2: Verificar el cambio**

Run: `git diff CLAUDE.md`
Expected: la nota aparece bajo la descripción del element; sin otros cambios.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: documentar modo editable de grouped_invoices_table en la etapa Agrupacion"
```

---

## Self-Review

**1. Cobertura del spec:**
- Element editable (params + unlink + tfoot + header slot) → Task 1. ✓
- Comportamiento por dominio (DIAN select solo en aprobacion) → verificado por construcción; Caja Menor hardcodea `false` (Task 2 Step 3), Reintegros pasa el gate de 3 partes (Task 3 Steps 3-4). ✓
- Decisión estructural (card top-level, extraer de `$sections`) → Task 2 Step 6, Task 3 Step 6. ✓
- RBAC (gates transcritos, `canResolveDian` ≠ `canUploadSupport`) → Task 3 Step 4 (helper `_childActionGates`). ✓
- `InvoiceDocuments` contain → Task 2 Step 4, Task 3 Step 4. ✓
- Modal de subida grupal en edit → Task 2 Step 7, Task 3 Step 7. ✓
- Criterios de aceptación 1-6 → tests de Tasks 1-3. ✓ (El criterio 5 —rol sin `dian_validation` no ve el select— queda cubierto por construcción vía el gate; el test de integración de Task 3 valida el render con rol operante, y la paridad con `view()` la garantiza el helper compartido.)
- Qué NO cambia (Legalizaciones, pipeline, view.php) → ningún task los toca; regresión en Steps 9. ✓

**2. Placeholder scan:** sin TBD/TODO; todo el código está transcrito. ✓

**3. Consistencia de tipos:** `forGroupedRow(Invoice, bool): GroupedInvoiceRowView`, `childRequirements(int): GroupReadinessReport`, `getEditableFields(int, string): array`, params del element (`editable` bool, `totalAmount` float|null, `headerActionsHtml` string|null, `unlinkAction` string) usados consistentemente en Tasks 1-3. ✓

**Nota de ejecución:** Task 1 es prerequisito de Tasks 2 y 3. Tasks 2 y 3 son independientes entre sí. Task 4 (doc) al final.
