# Soportes agrupados en la aprobación de grupo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar los soportes (documentos) de cada factura del lote, agrupados por factura, en las dos pantallas de aprobación de grupo externas (reintegros y legalización de anticipos).

**Architecture:** Cambio quirúrgico en dos ejes. (1) `ExternalApprovalsController::review()` amplía el `contain` de los dos caminos de grupo para cargar `InvoiceDocuments` de las facturas (reintegro: hijas; anticipo: anticipo padre + vinculadas), con orden determinista. (2) Las plantillas `review_group.php` y `review_group_advance.php` se reestructuran en cards hermanas e insertan el element canónico `documents_section` con un grupo por factura. Sin migración, sin tocar `process()`, tokens, quórum ni RBAC.

**Tech Stack:** PHP 8.4+, CakePHP 5.3, PHPUnit (IntegrationTestTrait), element compartido `documents_section` + `document_row`.

**Spec:** `docs/superpowers/specs/2026-07-08-soportes-aprobacion-grupal-design.md`

## Global Constraints

- PHP `>=8.4`, CakePHP 5.3. **Sin migración ni cambios de esquema** (solo lectura adicional en `contain`).
- `composer cs-check` debe quedar limpio (estándar CakePHP); usar `composer cs-fix` para autocorrección.
- Suite completa `vendor/bin/phpunit` sin regresiones; **baseline 843**; credenciales de test en `config/.env`; timeout 300s.
- **Anti-drift de vista:** reusar el element canónico `documents_section`/`document_row`; prohibido literal inline de mapa estado→pill; usar solo átomos existentes (`pill-info-soft`, `pill-primary-soft`, `.spi-card`, `.field-row`, `.mono`).
- **No anidar `documents_section` dentro de otra `.spi-card`** — el element ya emite su propia `.spi-card` (`templates/element/documents_section.php:33`); ubicarlo como card hermana (patrón de `templates/Refunds/view.php:163`).
- Slugs y valores persistidos: no tocar.
- `showBadge => false` en las filas (sin badge de estado por documento).

---

### Task 1: Reintegros — soportes agrupados en `review_group`

**Files:**
- Modify: `src/Controller/ExternalApprovalsController.php:76-79` (rama de reintegro en `review()`)
- Modify: `templates/ExternalApprovals/review_group.php` (reescritura completa)
- Test: `tests/TestCase/Controller/ExternalApprovalsGroupTest.php` (añadir un método)

**Interfaces:**
- Consumes: element `documents_section` con contrato `groups` = lista de `['label'=>?string, 'pillKind'=>?string, 'rows'=>array]`, donde cada `row` = params de `document_row` (`['doc'=>Entity, 'showBadge'=>bool]`); `totalDocs`, `canUpload`, `emptyTitle`.
- Produces: (nada consumido por tareas posteriores; Task 2 es independiente.)

- [ ] **Step 1: Escribir el test que falla**

Añadir este método a `tests/TestCase/Controller/ExternalApprovalsGroupTest.php` (usa `_service()` y `_stubInvoiceApprovalService()` ya presentes en la clase; no requiere imports nuevos):

```php
    public function testAssignedApproverSeesGroupedSupports(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $invoiceWithDoc = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'invoice_number' => 'FAC-A',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'invoice_number' => 'FAC-B',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $docs = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $docs->saveOrFail($docs->newEntity([
            'invoice_id' => $invoiceWithDoc->id,
            'pipeline_status' => 'aprobacion',
            'file_name' => 'soporte-a.pdf',
            'file_path' => 'storage/test/soporte-a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
        ]));

        $approver = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);
        $approvalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $approval = $approvalsTable->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($approval);
        $approvalsTable->saveOrFail($approval);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => $approver]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');                    // sección de soportes presente
        $this->assertResponseContains('soporte-a.pdf');               // documento de FAC-A renderizado
        $this->assertResponseContains('storage/test/soporte-a.pdf');  // href de apertura del soporte
        $this->assertResponseContains('0 archivo');                   // grupo de FAC-B (sin soportes) visible
    }

    public function testEmptyLotShowsEmptyState(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'invoice_number' => 'FAC-X'])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $approver = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);
        $approvalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $approval = $approvalsTable->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($approval);
        $approvalsTable->saveOrFail($approval);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => $approver]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Sin soportes adjuntos'); // empty state cuando el lote no tiene soportes
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit --filter testAssignedApproverSeesGroupedSupports tests/TestCase/Controller/ExternalApprovalsGroupTest.php`
Expected: FAIL — la plantilla vieja no tiene sección de soportes, así que rompe ya en `assertResponseContains('Soportes')` (y tampoco contendría `soporte-a.pdf`).

- [ ] **Step 3: Ampliar el `contain` de la rama de reintegro en el controlador**

En `src/Controller/ExternalApprovalsController.php`, reemplazar el bloque actual (`:76-79`):

```php
            $refund = TableRegistry::getTableLocator()->get('Refunds')->get(
                $groupApproval->refund_id,
                contain: ['Invoices' => ['Providers'], 'BeneficiaryEmployees', 'BeneficiaryProviders'],
            );
```

por:

```php
            $refund = TableRegistry::getTableLocator()->get('Refunds')->get(
                $groupApproval->refund_id,
                contain: [
                    'Invoices' => [
                        'sort' => ['Invoices.id' => 'ASC'],
                        'Providers',
                        'InvoiceDocuments',
                    ],
                    'BeneficiaryEmployees',
                    'BeneficiaryProviders',
                ],
            );
```

- [ ] **Step 4: Reescribir la plantilla `review_group.php`**

Reemplazar TODO el contenido de `templates/ExternalApprovals/review_group.php` por:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var \App\Model\Entity\Refund $refund
 * @var object $currentUser
 */
$this->assign('title', 'Revisión de Aprobación de Grupo');

// Soportes agrupados: un grupo por cada factura del reintegro (incluso sin soportes).
$groups = [];
$totalDocs = 0;
foreach ($refund->invoices as $inv) {
    $rows = [];
    foreach ($inv->invoice_documents ?? [] as $doc) {
        $rows[] = ['doc' => $doc, 'showBadge' => false];
    }
    $totalDocs += count($rows);
    $groups[] = [
        'label' => $inv->invoice_number ?? '#' . $inv->id,
        'pillKind' => 'pill-info-soft',
        'rows' => $rows,
    ];
}
?>

<div class="mb-3">
    <span class="pill pill-info-soft">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        Aprobando como: <strong><?= h($currentUser->full_name) ?></strong>
    </span>
</div>

<div class="spi-card mb-4 d-flex flex-column gap-3">
    <div>
        <div class="spi-title-card"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Solicitud de Aprobación de Grupo</div>
        <div class="spi-body-faint">Reintegro agrupado — revise las facturas incluidas antes de decidir.</div>
    </div>

    <div>
        <div class="spi-label">Reintegro</div>
        <div class="field-row">
            <span class="k">Código</span>
            <span class="v mono"><?= h($refund->code ?? '#' . $refund->id) ?></span>
        </div>
        <div class="field-row">
            <span class="k">Beneficiario</span>
            <span class="v"><?= h($refund->getBeneficiaryName() ?? '—') ?></span>
        </div>
        <div class="field-row">
            <span class="k">Total</span>
            <span class="v mono spi-fg-primary">
                $ <?= number_format((float)$refund->total_amount, 0, ',', '.') ?>
            </span>
        </div>
    </div>

    <div>
        <div class="spi-label">Facturas del Reintegro</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th># Factura</th>
                        <th>Proveedor</th>
                        <th class="text-end">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refund->invoices as $inv): ?>
                    <tr>
                        <td class="mono" style="font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                        <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                        <td class="text-end mono">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mb-4">
    <?= $this->element('documents_section', [
        'groups' => $groups,
        'totalDocs' => $totalDocs,
        'canUpload' => false,
        'emptyTitle' => 'Sin soportes adjuntos',
    ]) ?>
</div>

<div class="spi-card mb-4 d-flex flex-column gap-3">
    <?= $this->Form->create(null, ['url' => ['action' => 'process', $token]]) ?>
    <div class="mb-3">
        <label class="input-label">Observaciones (opcional)</label>
        <textarea name="observations" class="form-control" rows="3"></textarea>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" name="action" value="approve" class="btn btn-primary">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar grupo
        </button>
        <button type="submit" name="action" value="reject" class="btn btn-danger">
            <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar grupo
        </button>
    </div>
    <?= $this->Form->end() ?>
</div>
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `vendor/bin/phpunit --filter testAssignedApproverSeesGroupedSupports tests/TestCase/Controller/ExternalApprovalsGroupTest.php`
Expected: PASS.

- [ ] **Step 6: Correr toda la clase de test (no romper los casos existentes)**

Run: `vendor/bin/phpunit tests/TestCase/Controller/ExternalApprovalsGroupTest.php`
Expected: PASS (los 4 casos previos + el nuevo).

- [ ] **Step 7: cs-check de los archivos tocados**

Run: `composer cs-check`
Expected: sin errores. Si hay, correr `composer cs-fix` y volver a verificar. Nota: `phpcs.xml` escanea `src/` y `tests/`, **no** `templates/`; el lint cubre el controlador y el test, no las plantillas (mantener el estilo del archivo existente al reescribirlas).

- [ ] **Step 8: Commit**

```bash
git add src/Controller/ExternalApprovalsController.php templates/ExternalApprovals/review_group.php tests/TestCase/Controller/ExternalApprovalsGroupTest.php
git commit -m "feat: soportes agrupados por factura en la aprobación de grupo de reintegros"
```

---

### Task 2: Legalización de anticipos — soportes agrupados en `review_group_advance`

**Files:**
- Modify: `src/Controller/ExternalApprovalsController.php` (rama de anticipo en `review()` — el path `advApproval`). ⚠️ **Anclar por contenido, NO por número de línea:** Task 1 ya creció la rama de reintegro del mismo archivo, así que las líneas se desplazaron. Localizar los bloques por su texto exacto (mostrados en el Step 3).
- Modify: `templates/ExternalApprovals/review_group_advance.php` (reescritura completa)
- Test: `tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php` (añadir un método)

**Interfaces:**
- Consumes: mismo contrato de `documents_section` que Task 1.
- Produces: (nada.)

- [ ] **Step 1: Escribir el test que falla**

Añadir este método a `tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php` (usa `_stubInvoiceApprovalService()` ya presente; no requiere imports nuevos):

```php
    public function testAssignedApproverSeesGroupedSupports(): void
    {
        $anticipo = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'invoice_number' => 'ANT-1',
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $linked = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => $anticipo->id,
            'invoice_number' => 'FAC-L',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $docs = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $docs->saveOrFail($docs->newEntity([
            'invoice_id' => $anticipo->id,
            'pipeline_status' => 'aprobacion',
            'file_name' => 'anticipo-doc.pdf',
            'file_path' => 'storage/test/anticipo-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2222,
        ]));
        $docs->saveOrFail($docs->newEntity([
            'invoice_id' => $linked->id,
            'pipeline_status' => 'aprobacion',
            'file_name' => 'legal-doc.pdf',
            'file_path' => 'storage/test/legal-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3333,
        ]));

        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        $approver = UserFactory::new()->save();
        $svc = new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
        $svc->assignApprovers($leg, [$approver->id], 'https://x', (int)$approver->id);
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);

        $this->_stubInvoiceApprovalService();
        $this->session(['Auth' => TableRegistry::getTableLocator()->get('Users')->get($approver->id)]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseContains('anticipo-doc.pdf'); // grupo del anticipo padre
        $this->assertResponseContains('legal-doc.pdf');     // grupo de la factura vinculada
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit --filter testAssignedApproverSeesGroupedSupports tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php`
Expected: FAIL — la plantilla vieja no tiene sección de soportes, así que rompe ya en `assertResponseContains('Soportes')` (y tampoco contendría `anticipo-doc.pdf` / `legal-doc.pdf`).

- [ ] **Step 3: Ampliar el `contain` de la rama de anticipo en el controlador**

En `src/Controller/ExternalApprovalsController.php`, dentro de la rama `if ($advApproval)` de `review()` (localizar por contenido, no por línea), reemplazar la carga del anticipo:

```php
            $anticipo = $invoices->get($leg->advance_invoice_id, contain: ['Providers', 'Employees']);
```

por:

```php
            $anticipo = $invoices->get($leg->advance_invoice_id, contain: ['Providers', 'Employees', 'InvoiceDocuments']);
```

y reemplazar el bloque de `$linkedInvoices` (mismo bloque, localizado por contenido):

```php
            $linkedInvoices = $invoices->find()
                ->where([
                    'Invoices.advance_id' => $leg->advance_invoice_id,
                    'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                ])
                ->contain(['Providers', 'Employees'])
                ->all();
```

por:

```php
            $linkedInvoices = $invoices->find()
                ->where([
                    'Invoices.advance_id' => $leg->advance_invoice_id,
                    'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                ])
                ->contain(['Providers', 'Employees', 'InvoiceDocuments'])
                ->orderBy(['Invoices.id' => 'ASC'])
                ->all();
```

- [ ] **Step 4: Reescribir la plantilla `review_group_advance.php`**

Reemplazar TODO el contenido de `templates/ExternalApprovals/review_group_advance.php` por:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \App\Model\Entity\Invoice $anticipo
 * @var iterable<\App\Model\Entity\Invoice> $linkedInvoices
 * @var object $currentUser
 */

use App\View\Presentation\InvoiceBeneficiary;

$this->assign('title', 'Revisión de Aprobación de Grupo');

$linkedInvoicesList = is_array($linkedInvoices) ? $linkedInvoices : iterator_to_array($linkedInvoices);
$linkedTotal = array_sum(array_map(fn($inv) => (float)$inv->amount, $linkedInvoicesList));

// Soportes agrupados: primero el anticipo padre, luego un grupo por factura vinculada (incluso sin soportes).
$buildRows = static function ($invoice): array {
    $rows = [];
    foreach ($invoice->invoice_documents ?? [] as $doc) {
        $rows[] = ['doc' => $doc, 'showBadge' => false];
    }

    return $rows;
};
$groups = [];
$totalDocs = 0;
$anticipoRows = $buildRows($anticipo);
$totalDocs += count($anticipoRows);
$groups[] = [
    'label' => $anticipo->invoice_number ?? '#' . $anticipo->id,
    'pillKind' => 'pill-primary-soft',
    'rows' => $anticipoRows,
];
foreach ($linkedInvoicesList as $inv) {
    $rows = $buildRows($inv);
    $totalDocs += count($rows);
    $groups[] = [
        'label' => $inv->invoice_number ?? '#' . $inv->id,
        'pillKind' => 'pill-info-soft',
        'rows' => $rows,
    ];
}
?>

<div class="mb-3">
    <span class="pill pill-info-soft">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        Aprobando como: <strong><?= h($currentUser->full_name) ?></strong>
    </span>
</div>

<div class="spi-card mb-4 d-flex flex-column gap-3">
    <div>
        <div class="spi-title-card"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Solicitud de Aprobación de Grupo</div>
        <div class="spi-body-faint">Legalización de Anticipo — revise las facturas incluidas antes de decidir.</div>
    </div>

    <div>
        <div class="spi-label">Legalización de Anticipo</div>
        <div class="field-row">
            <span class="k">Código</span>
            <span class="v mono"><?= h($anticipo->invoice_number ?? '#' . $anticipo->id) ?></span>
        </div>
        <div class="field-row">
            <span class="k">Beneficiario</span>
            <span class="v"><?= h(InvoiceBeneficiary::label($anticipo)) ?></span>
        </div>
        <div class="field-row">
            <span class="k">Monto del anticipo</span>
            <span class="v mono spi-fg-primary">
                $ <?= number_format((float)$anticipo->amount, 0, ',', '.') ?>
            </span>
        </div>
    </div>

    <div>
        <div class="spi-label">Facturas de la Legalización</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th># Factura</th>
                        <th>Beneficiario</th>
                        <th class="text-end">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($linkedInvoicesList)) : ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted">Sin facturas vinculadas</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($linkedInvoicesList as $inv) : ?>
                    <tr>
                        <td class="mono" style="font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                        <td><?= h(InvoiceBeneficiary::label($inv)) ?></td>
                        <td class="text-end mono">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (!empty($linkedInvoicesList)) : ?>
                <tfoot>
                    <tr>
                        <th colspan="2" class="text-end">Total vinculado</th>
                        <th class="text-end mono">$ <?= number_format($linkedTotal, 0, ',', '.') ?></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<div class="mb-4">
    <?= $this->element('documents_section', [
        'groups' => $groups,
        'totalDocs' => $totalDocs,
        'canUpload' => false,
        'emptyTitle' => 'Sin soportes adjuntos',
    ]) ?>
</div>

<div class="spi-card mb-4 d-flex flex-column gap-3">
    <?= $this->Form->create(null, ['url' => ['action' => 'process', $token]]) ?>
    <div class="mb-3">
        <label class="input-label">Observaciones (opcional)</label>
        <textarea name="observations" class="form-control" rows="3"></textarea>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" name="action" value="approve" class="btn btn-primary">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar grupo
        </button>
        <button type="submit" name="action" value="reject" class="btn btn-danger">
            <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar grupo
        </button>
    </div>
    <?= $this->Form->end() ?>
</div>
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `vendor/bin/phpunit --filter testAssignedApproverSeesGroupedSupports tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php`
Expected: PASS.

- [ ] **Step 6: Correr toda la clase de test**

Run: `vendor/bin/phpunit tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php`
Expected: PASS (los 3 casos previos + el nuevo).

- [ ] **Step 7: cs-check**

Run: `composer cs-check`
Expected: sin errores (usar `composer cs-fix` si hace falta).

- [ ] **Step 8: Commit**

```bash
git add src/Controller/ExternalApprovalsController.php templates/ExternalApprovals/review_group_advance.php tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php
git commit -m "feat: soportes agrupados por factura en la aprobación de grupo de anticipos"
```

---

### Task 3: Verificación integral

**Files:** (ninguno nuevo; solo verificación)

- [ ] **Step 1: Suite completa sin regresiones**

Run: `vendor/bin/phpunit` (timeout 300s; credenciales en `config/.env`)
Expected: sin fallos nuevos respecto al baseline (843). Si aparecen errores en cascada por contaminación entre suites, re-correr limpio antes de concluir regresión.

- [ ] **Step 2: cs-check global limpio**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 3: (Opcional) Smoke manual en el navegador**

Con un token de grupo real (reintegro y legalización en estado `aprobacion` con aprobador asignado), abrir `/approve/{token}` autenticado como el aprobador y confirmar visualmente: la sección "Soportes" aparece como card hermana entre la tabla de facturas y los botones; hay un encabezado por factura (anticipo padre primero en anticipos); las facturas sin soportes muestran "0 archivos"; el enlace de cada documento abre el archivo en pestaña nueva.

- [ ] **Step 4: (Si aplica) Actualizar el índice de finalización**

Sin cambios de esquema ni de RBAC → no requiere `permissions_audit` ni migración. Confirmar que `git status` no dejó archivos sin commitear del feature.

---

## Notas de implementación

- El element `documents_section` cuenta un grupo como "con documentos" solo si tiene `rows`, pero **igual renderiza el encabezado** de un grupo con `rows` vacío ("0 archivos"). Por eso "mostrar todos los grupos" funciona sin tocar el element.
- Cuando el lote entero no tiene soportes (`totalDocs === 0`), el element muestra además su empty state "Sin soportes adjuntos" por encima de los encabezados con "0 archivos"; es intencional (no bloqueante) y no se corrige para no modificar el element compartido.
- El enlace de apertura lo renderiza `document_row` como ícono `bi-eye` con `href="/<file_path>"` y `title="Abrir"` (no un botón con texto "Abrir"); los asserts verifican el `file_path`, no el literal "Abrir".
- No se toca `process()`, ni los tokens, ni el gate `#[NoAuthGate]` + match de identidad.
