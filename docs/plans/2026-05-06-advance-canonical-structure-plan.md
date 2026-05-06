# Plan D — Estructura canónica para Advances — Plan de Implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Migrar el flujo de Advances a la base canónica del audit `flow-structure-audit-2026-05-06.md` — extraer `AdvanceLegalizationDocumentService`, reemplazar el método privado `_buildLegalizationViewModel` por una clase `AdvanceLegalizationViewModel`, y crear `AdvanceAddViewModel` para la action `add()`.

**Architecture:** Patrón ViewModel `final readonly` con propiedades públicas inyectadas por constructor (mismo patrón que `RefundAddViewModel`/`RefundEditViewModel`). DocumentService como clase dedicada que reusa `DocumentUploadTrait` (sale del service principal). DI vía contenedor en `Application.php`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, `Migrations\BaseMigration`, MySQL/MariaDB. Sin tests automatizados (ver `CLAUDE.md` Testing Policy) — validación manual al final.

**Diseño base:** [`docs/plans/2026-05-06-advance-canonical-structure-design.md`](2026-05-06-advance-canonical-structure-design.md)

**Branching:** trabajar en una rama `feat/advance-canonical-structure` (commitear, no push hasta validar manual).

---

## Convenciones del plan

- **No hay tests automatizados.** Donde la skill original pediría "write the failing test", aquí ejecutamos validaciones equivalentes: `composer cs-check`, `grep` para confirmar presencia/ausencia de strings, lectura del diff. La validación funcional final se hace manualmente en navegador (Tarea 9).
- Cada tarea termina con `composer cs-check` + commit.
- Si `cs-check` falla, ejecutar `composer cs-fix` y volver a `cs-check`.
- Mensajes de commit: `refactor(advances): ...` salvo el último (`docs(audits): ...`).

---

## Tarea 0: Crear rama y baseline

**Files:** ninguno (solo git).

**Step 1: Verificar working tree limpio**

Run:
```bash
git status
```
Expected: `nothing to commit, working tree clean` y branch `main`.

**Step 2: Crear rama**

Run:
```bash
git checkout -b feat/advance-canonical-structure
```
Expected: `Switched to a new branch 'feat/advance-canonical-structure'`.

**Step 3: Confirmar baseline de tamaños**

Run:
```bash
wc -l src/Controller/AdvancesController.php src/Service/AdvanceLegalizationService.php
```
Expected (orden de magnitud, no exacto):
- `AdvancesController.php` ~822 líneas
- `AdvanceLegalizationService.php` ~755 líneas

Anotar los valores reales para comparar al final (Tarea 9).

---

## Tarea 1: Crear `AdvanceLegalizationDocumentService` (esqueleto + `attachItemReceipt`)

**Files:**
- Create: `src/Service/AdvanceLegalizationDocumentService.php`

**Step 1: Crear el archivo con esqueleto y método `attachItemReceipt`**

Contenido completo:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Trait\DocumentUploadTrait;
use Laminas\Diactoros\UploadedFile;

/**
 * Centraliza la gestión de archivos relacionada a la legalización de anticipos.
 *
 * Extraído de `AdvanceLegalizationService` para alinear con la base canónica
 * del audit `docs/audits/flow-structure-audit-2026-05-06.md` (Plan D).
 */
class AdvanceLegalizationDocumentService
{
    use DocumentUploadTrait;

    /**
     * Sube el comprobante asociado a un item de legalización.
     *
     * Devuelve el array `['file_path' => string, 'file_name' => string, 'mime_type' => string]`
     * en éxito, o un `ServiceResult::fail()` con el mensaje de error.
     *
     * @return \App\Service\ServiceResult
     */
    public function attachItemReceipt(UploadedFile $file, int $userId): ServiceResult
    {
        $info = $this->validateAndMoveUpload($file, 'advances/items', 'item_');
        if (is_string($info)) {
            return ServiceResult::fail($info);
        }

        return ServiceResult::ok($info);
    }
}
```

**Step 2: Verificar estilo**

Run:
```bash
composer cs-check
```
Expected: `[OK] No errors found`.

Si falla, ejecutar `composer cs-fix` y volver a `cs-check`.

**Step 3: Verificar que el archivo existe y declara la clase correcta**

Run:
```bash
grep -n "class AdvanceLegalizationDocumentService" src/Service/AdvanceLegalizationDocumentService.php
```
Expected: una línea coincidente.

**Step 4: Commit**

```bash
git add src/Service/AdvanceLegalizationDocumentService.php
git commit -m "$(cat <<'EOF'
refactor(advances): introduce AdvanceLegalizationDocumentService

Esqueleto del service dedicado a gestión de archivos de la
legalización. Por ahora expone attachItemReceipt; en commits
siguientes recibirá attachRelationDocument extraído del service
principal.

Plan D del audit flow-structure-audit-2026-05-06.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 2: Mover `attachRelationDocument` al DocumentService

**Files:**
- Modify: `src/Service/AdvanceLegalizationDocumentService.php` (agregar método)
- Modify: `src/Service/AdvanceLegalizationService.php:182-252` (eliminar método y usings sobrantes)

**Step 1: Agregar método `attachRelationDocument` al DocumentService**

Insertar dentro de la clase `AdvanceLegalizationDocumentService`, **antes** de `attachItemReceipt`:

```php
    /**
     * Save the relation-of-invoices document; supersedes any pending signature row.
     *
     * Mantiene la limpieza de huérfanos en `webroot/uploads/` (audit MA-004) y
     * la validación `$leg->canUploadRelationDocument()`.
     */
    public function attachRelationDocument(AdvanceLegalization $leg, UploadedFile $file, int $userId): ServiceResult
    {
        if (!$leg->canUploadRelationDocument()) {
            return ServiceResult::fail('Solo se puede subir el documento en Validación o Revisión y Firmas.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $sigTable->getConnection()->transactional(
            function () use ($leg, $file, $userId, $sigTable, $legTable, &$result): bool {
                $upload = $this->uploadAndSave(
                    $file,
                    'AdvanceLegalizationSignatures',
                    'advances/' . $leg->id,
                    'leg_',
                    [
                        'legalization_id' => $leg->id,
                        'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                    ],
                );

                if (is_string($upload)) {
                    $result = ServiceResult::fail($upload);

                    return false;
                }

                // Borrar archivos físicos de los pendientes anteriores antes del
                // deleteAll para no dejar huérfanos en webroot/uploads/ (audit MA-004).
                $stalePending = $sigTable->find()
                    ->where([
                        'legalization_id' => $leg->id,
                        'id !=' => $upload->id,
                        'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                    ])
                    ->all();
                foreach ($stalePending as $stale) {
                    if (!empty($stale->file_path)) {
                        $diskPath = WWW_ROOT . str_replace('/', DS, $stale->file_path);
                        if (file_exists($diskPath)) {
                            @unlink($diskPath);
                        }
                    }
                }

                $sigTable->deleteAll([
                    'legalization_id' => $leg->id,
                    'id !=' => $upload->id,
                    'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                ]);

                $leg->updated_by = $userId;
                if (!$legTable->save($leg)) {
                    $result = ServiceResult::fail(
                        'No se pudo actualizar la legalización: ' . $this->_firstErrorMessage($leg->getErrors()),
                    );

                    return false;
                }

                $result = ServiceResult::ok($upload);

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * @param array<string, array<string, string>> $errors Errores de CakePHP entity->getErrors().
     */
    private function _firstErrorMessage(array $errors): string
    {
        foreach ($errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $msg) {
                return (string)$msg;
            }
        }

        return 'Error desconocido.';
    }
```

**Step 2: Agregar imports en el DocumentService**

Reemplazar el bloque `use` superior por:

```php
use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
```

**Step 3: Eliminar `attachRelationDocument` y `_firstErrorMessage` (si lo usaba) de `AdvanceLegalizationService`**

En `src/Service/AdvanceLegalizationService.php`:
- Eliminar el método `attachRelationDocument` completo (líneas 179-252 según baseline; verificar antes con `grep -n "function attachRelationDocument" src/Service/AdvanceLegalizationService.php`).
- Si `_firstErrorMessage` no se usa en ningún otro método del archivo, eliminarlo también. Confirmar con:
  ```bash
  grep -n "_firstErrorMessage" src/Service/AdvanceLegalizationService.php
  ```
  Si no hay otras llamadas, eliminarlo.

**Step 4: Verificar estilo**

Run:
```bash
composer cs-check
```
Expected: `[OK] No errors found`. Si falla, `composer cs-fix` + recheck.

**Step 5: Verificar movimiento del método**

Run:
```bash
grep -n "function attachRelationDocument" src/Service/AdvanceLegalizationDocumentService.php src/Service/AdvanceLegalizationService.php
```
Expected: una sola coincidencia, en `AdvanceLegalizationDocumentService.php`.

**Step 6: Commit**

```bash
git add src/Service/AdvanceLegalizationDocumentService.php src/Service/AdvanceLegalizationService.php
git commit -m "$(cat <<'EOF'
refactor(advances): move attachRelationDocument to AdvanceLegalizationDocumentService

attachRelationDocument sale de AdvanceLegalizationService y vive en
el nuevo document service. Se conserva la limpieza de huérfanos
(audit MA-004) y la guarda canUploadRelationDocument().

El controller aún apunta al service principal — se actualiza en la
tarea siguiente.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 3: Refactor `addLegalizationItem` para usar `attachItemReceipt`

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php` (método `addLegalizationItem`)

**Step 1: Localizar el método**

Run:
```bash
grep -n "function addLegalizationItem" src/Service/AdvanceLegalizationService.php
```

Anotar la línea de inicio. Leer el método completo para identificar el bloque que sube `receipt_file` (referencia: alrededor de línea 483 en baseline, dentro de `confirmShortageReceipt`; verificar si `addLegalizationItem` usa el mismo patrón).

**Step 2: Inyectar el document service en el constructor**

Reemplazar el constructor de `AdvanceLegalizationService` por:

```php
private AdvanceLegalizationPipelineStateRegistry $stateRegistry;

public function __construct(
    private readonly EventManagerInterface $events,
    private readonly AdvanceLegalizationHistoryService $historyService,
    private readonly AdvanceLegalizationDocumentService $documentService,
    ?AdvanceLegalizationPipelineStateRegistry $stateRegistry = null,
) {
    $this->stateRegistry = $stateRegistry ?? new AdvanceLegalizationPipelineStateRegistry();
}
```

Agregar import al top:
```php
use App\Service\AdvanceLegalizationDocumentService;
```

**Step 3: Reemplazar uso directo de `validateAndMoveUpload`/`uploadAndSave` en `addLegalizationItem`**

Localizar el bloque `if (!empty($data['receipt_file']) && $data['receipt_file'] instanceof UploadedFile) { ... }` dentro de `addLegalizationItem`. Reemplazarlo por:

```php
if (!empty($data['receipt_file']) && $data['receipt_file'] instanceof UploadedFile) {
    $uploadResult = $this->documentService->attachItemReceipt($data['receipt_file'], $userId);
    if (!$uploadResult->success) {
        return $uploadResult;
    }
    $info = $uploadResult->data;
    $item->receipt_file_path = $info['file_path'];
    // (mantener el resto de campos que el código original ponía a partir de $info)
}
```

**Importante:** verificar contra el código real cuáles campos del `$info` se asignan al item (`receipt_file_path`, `receipt_file_name`, `receipt_mime_type`, etc.). No alterar la asignación existente — solo cambiar la fuente de `$info` de llamada directa al trait → resultado del documentService.

**Step 4: Si `confirmShortageReceipt` también usa `validateAndMoveUpload`, dejarlo igual por ahora**

`confirmShortageReceipt` (línea ~483 baseline) usa `validateAndMoveUpload` directamente. **No** está en el alcance del audit mover esto. El trait sigue disponible mientras no quitemos `use DocumentUploadTrait` (Tarea 4 lo verifica).

**Decisión:** dejar `confirmShortageReceipt` con la llamada directa al trait y no quitar el trait del service principal hasta que TODOS los usos estén migrados. **Esto significa que la Tarea 4 se reduce a verificación, no a eliminación.**

Actualizar el plan de la Tarea 4 mentalmente: si tras `addLegalizationItem` el trait sigue usándose en `confirmShortageReceipt`, la deuda se anota en el audit pero el trait queda.

**Step 5: Verificar estilo**

Run:
```bash
composer cs-check
```
Expected: OK.

**Step 6: Verificar que `addLegalizationItem` ya no llama a `validateAndMoveUpload` directamente**

Run:
```bash
grep -nE "validateAndMoveUpload|uploadAndSave" src/Service/AdvanceLegalizationService.php
```
Expected: solo coincidencias dentro de `confirmShortageReceipt` (si las había). Cero dentro de `addLegalizationItem`.

**Step 7: Commit**

```bash
git add src/Service/AdvanceLegalizationService.php
git commit -m "$(cat <<'EOF'
refactor(advances): consume document service for item receipts

addLegalizationItem ahora delega la subida del receipt al
AdvanceLegalizationDocumentService::attachItemReceipt. El service
principal recibe el document service por constructor (DI).

confirmShortageReceipt sigue usando DocumentUploadTrait directo —
no está en el alcance del audit; se documenta como deuda menor.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 4: Registrar el nuevo service en el contenedor DI

**Files:**
- Modify: `src/Application.php:217-222`

**Step 1: Registrar `AdvanceLegalizationDocumentService` y agregarlo al constructor de `AdvanceLegalizationService`**

Reemplazar el bloque actual:
```php
$container->addShared(AdvanceLegalizationHistoryService::class);
$container->addShared(AdvanceLegalizationService::class)
    ->addArguments([
        EventManagerInterface::class,
        AdvanceLegalizationHistoryService::class,
    ]);
```

Por:
```php
$container->addShared(AdvanceLegalizationHistoryService::class);
$container->addShared(AdvanceLegalizationDocumentService::class);
$container->addShared(AdvanceLegalizationService::class)
    ->addArguments([
        EventManagerInterface::class,
        AdvanceLegalizationHistoryService::class,
        AdvanceLegalizationDocumentService::class,
    ]);
```

Agregar import al top de `Application.php`:
```php
use App\Service\AdvanceLegalizationDocumentService;
```

**Step 2: Verificar estilo**

Run:
```bash
composer cs-check
```
Expected: OK.

**Step 3: Smoke test del contenedor (sin levantar servidor)**

Run:
```bash
php -r "require 'vendor/autoload.php'; require 'config/bootstrap.php'; \$app = new \App\Application(dirname(__DIR__) . '/sgi/config'); echo 'OK\n';"
```

Si no quieres ejecutar PHP CLI completo, el smoke alternativo es lanzar el server y abrir cualquier URL: `php bin/cake server` y abrir `http://localhost:8765/login`. Si no truena con error de DI, el container está bien.

Expected: sin error `Cannot resolve dependency` ni `Class not found`.

**Step 4: Commit**

```bash
git add src/Application.php
git commit -m "$(cat <<'EOF'
refactor(advances): register AdvanceLegalizationDocumentService in DI container

Permite que AdvanceLegalizationService reciba el document service
por inyección estándar del contenedor.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 5: Apuntar `AdvancesController::uploadRelationDocument` al document service

**Files:**
- Modify: `src/Controller/AdvancesController.php` (constructor + `uploadRelationDocument`)

**Step 1: Agregar propiedad y resolución del nuevo service en `initialize()`**

Modificar el bloque de propiedades del controller:

```php
private AdvanceLegalizationService $legalizationService;

private AdvanceLegalizationDocumentService $documentService;

private InvoicePipelineService $pipelineService;

private AdvanceLegalizationActionPolicy $actionPolicy;
```

Modificar `initialize()`:

```php
public function initialize(): void
{
    parent::initialize();
    $this->legalizationService = $this->getContainer()->get(AdvanceLegalizationService::class);
    $this->documentService = $this->getContainer()->get(AdvanceLegalizationDocumentService::class);
    $this->pipelineService = $this->getContainer()->get(InvoicePipelineService::class);
    $this->actionPolicy = $this->getContainer()->get(AdvanceLegalizationActionPolicy::class);
    $this->fetchTable('Invoices');
}
```

Agregar import al top:
```php
use App\Service\AdvanceLegalizationDocumentService;
```

**Step 2: Reemplazar la llamada en `uploadRelationDocument` (~línea 525 baseline)**

Cambiar:
```php
$result = $this->legalizationService->attachRelationDocument($leg, $file, (int)$this->_getCurrentUser()->id);
```

Por:
```php
$result = $this->documentService->attachRelationDocument($leg, $file, (int)$this->_getCurrentUser()->id);
```

**Step 3: Verificar estilo**

Run:
```bash
composer cs-check
```
Expected: OK.

**Step 4: Verificar que no quedan llamadas a `legalizationService->attachRelationDocument`**

Run:
```bash
grep -n "legalizationService->attachRelationDocument" src/Controller/AdvancesController.php
```
Expected: cero coincidencias.

Run:
```bash
grep -n "documentService->attachRelationDocument" src/Controller/AdvancesController.php
```
Expected: una coincidencia.

**Step 5: Commit**

```bash
git add src/Controller/AdvancesController.php
git commit -m "$(cat <<'EOF'
refactor(advances): controller delegates relation document upload to document service

uploadRelationDocument ahora llama al AdvanceLegalizationDocumentService
inyectado vía contenedor DI.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 6: Crear `AdvanceLegalizationViewModel` y refactor `legalization()`

**Files:**
- Create: `src/ViewModel/AdvanceLegalizationViewModel.php`
- Modify: `src/Controller/AdvancesController.php` (acción `legalization`, eliminar `_buildLegalizationViewModel`)

**Step 1: Crear el ViewModel**

Contenido completo de `src/ViewModel/AdvanceLegalizationViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use Cake\ORM\TableRegistry;

/**
 * Datos pre-calculados que el template `templates/Advances/legalization.php` necesita.
 *
 * Reemplaza el método privado `_buildLegalizationViewModel` que vivía en
 * `AdvancesController` (audit MI-005). Centraliza linked invoices, separación
 * de signature activa vs historial, totales, diff, banking entities y surplus
 * payment para mantener la action delgada.
 */
final readonly class AdvanceLegalizationViewModel
{
    public function __construct(
        public Invoice $invoice,
        public AdvanceLegalization $leg,
        public string $roleName,
    ) {
    }

    /**
     * Construye el set completo de variables para el template.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $linkedInvoices = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'Invoices.advance_id' => $this->invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
            ->order(['Invoices.issue_date' => 'ASC'])
            ->all();

        $linkedTotal = 0.0;
        foreach ($linkedInvoices as $li) {
            $linkedTotal += (float)$li->amount;
        }
        $advanceTotal = (float)$this->invoice->amount;
        $diff = $advanceTotal - $linkedTotal;

        // Separar signature activa (pendiente o firmada más reciente) del historial.
        $relationDocument = null;
        $signatureHistory = [];
        if ($this->leg->advance_legalization_signatures) {
            $sigs = $this->leg->advance_legalization_signatures;
            usort($sigs, fn($a, $b) => $b->id <=> $a->id);
            foreach ($sigs as $sig) {
                if ($relationDocument === null && ($sig->isPending() || $sig->isSigned())) {
                    $relationDocument = $sig;
                } else {
                    $signatureHistory[] = $sig;
                }
            }
        }

        $bankingEntities = TableRegistry::getTableLocator()->get('BankingEntities')
            ->find('list')
            ->all()
            ->toArray();

        $surplusPayment = null;
        if ($this->leg->surplus_payment_id) {
            $surplusPayment = TableRegistry::getTableLocator()->get('InvoicePayments')->get(
                $this->leg->surplus_payment_id,
                contain: ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            );
        }

        return [
            'invoice' => $this->invoice,
            'leg' => $this->leg,
            'linkedInvoices' => $linkedInvoices,
            'linkedTotal' => $linkedTotal,
            'advanceTotal' => $advanceTotal,
            'diff' => $diff,
            'relationDocument' => $relationDocument,
            'signatureHistory' => $signatureHistory,
            'bankingEntities' => $bankingEntities,
            'surplusPayment' => $surplusPayment,
            'roleName' => $this->roleName,
        ];
    }
}
```

**Step 2: Refactor `legalization()` en el controller**

Reemplazar el bloque que va desde la creación del `viewModel` hasta el `return null`:

Buscar:
```php
$viewModel = $this->_buildLegalizationViewModel($invoice, $leg);
$this->set($viewModel);
$this->set('actionPolicy', $this->actionPolicy);

return null;
```

Reemplazar por:
```php
$roleName = $this->_getCurrentUser()->role->name ?? '';
$viewModel = new AdvanceLegalizationViewModel($invoice, $leg, $roleName);
$this->set($viewModel->build());
$this->set('actionPolicy', $this->actionPolicy);

return null;
```

Agregar import al top del controller:
```php
use App\ViewModel\AdvanceLegalizationViewModel;
```

**Step 3: Eliminar `_buildLegalizationViewModel` del controller**

Eliminar el método completo (líneas ~291-356 baseline). Antes de borrar, confirmar que no hay otras llamadas:
```bash
grep -n "_buildLegalizationViewModel" src/Controller/AdvancesController.php
```
Expected (después de Step 2): solo la definición del método. Cero llamadas.

Tras eliminar el método:
```bash
grep -n "_buildLegalizationViewModel" src/Controller/AdvancesController.php
```
Expected: cero coincidencias.

**Step 4: Verificar imports sobrantes**

Si tras eliminar `_buildLegalizationViewModel` ya no se usan ciertos imports (`AdvanceLegalization`, `Invoice` en el controller — pero verificar primero, pueden seguir siendo usados por otras actions), revisar y limpiar solo los imports realmente huérfanos. **No** quitar imports que sigan usándose en otras partes.

Run:
```bash
grep -nE "AdvanceLegalization |Invoice " src/Controller/AdvancesController.php
```
Si hay otros usos, dejar el import. Si no hay ninguno, quitar el import correspondiente.

**Step 5: Verificar estilo**

Run:
```bash
composer cs-check
```
Expected: OK.

**Step 6: Commit**

```bash
git add src/ViewModel/AdvanceLegalizationViewModel.php src/Controller/AdvancesController.php
git commit -m "$(cat <<'EOF'
refactor(advances): introduce AdvanceLegalizationViewModel

Reemplaza el método privado _buildLegalizationViewModel del
controller por una clase final readonly. La action legalization()
queda en ~6 líneas: cargar invoice + leg, instanciar VM, set vars.

Plan D del audit flow-structure-audit-2026-05-06.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 7: Crear `AdvanceAddViewModel` y refactor `add()`

**Files:**
- Create: `src/ViewModel/AdvanceAddViewModel.php`
- Modify: `src/Controller/AdvancesController.php` (acción `add`)

**Step 1: Crear el ViewModel**

Contenido completo de `src/ViewModel/AdvanceAddViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Model\Table\InvoicesTable;

/**
 * Datos pre-calculados que el template `templates/Advances/add.php` necesita.
 * Encapsula la creación de un Anticipo: defaults, validación de beneficiario
 * y lista blanca de campos accesibles (audit CR-001 — bloquea mass-assignment
 * de approver_id, area_approval, payment_status, confirmed_by, accrued, advance_id).
 */
final readonly class AdvanceAddViewModel
{
    /**
     * Lista blanca de campos aceptados desde el formulario de creación.
     */
    private const ALLOWED_FIELDS = [
        'provider_id', 'employee_id', 'operation_center_id',
        'expense_type_id', 'cost_center_id', 'amount', 'detail',
        'issue_date', 'due_date', 'document_type', 'registered_by',
        'pipeline_status', 'registration_date',
    ];

    /**
     * Campos explícitamente bloqueados (mass-assignment guard).
     */
    private const BLOCKED_FIELDS = [
        'approver_id', 'area_approval', 'payment_status',
        'confirmed_by', 'accrued', 'advance_id',
    ];

    /**
     * @param \App\Model\Entity\Invoice $invoice Entidad nueva o parcheada.
     * @param array<string, mixed> $dropdowns Listas para los <select> del form.
     * @param array<int, string> $errors Errores de validación a nivel del VM.
     */
    public function __construct(
        public Invoice $invoice,
        public array $dropdowns,
        public array $errors = [],
    ) {
    }

    /**
     * Construir VM para GET (form vacío).
     *
     * @param array<string, mixed> $dropdowns
     */
    public static function forForm(InvoicesTable $invoicesTable, array $dropdowns): self
    {
        return new self($invoicesTable->newEmptyEntity(), $dropdowns);
    }

    /**
     * Construir VM para POST: aplicar defaults, validar beneficiario,
     * patch con accessibleFields restringido.
     *
     * @param array<string, mixed> $data Payload crudo del request.
     * @param array<string, mixed> $dropdowns
     */
    public static function fromRequest(
        InvoicesTable $invoicesTable,
        array $data,
        int $userId,
        array $dropdowns,
    ): self {
        $data['document_type'] = InvoiceConstants::DOCTYPE_ANTICIPO;
        $data['registered_by'] = $userId;
        $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
        $data['registration_date'] = date('Y-m-d');
        // Anticipos no tienen fecha de vencimiento; usamos la de emisión.
        if (empty($data['due_date']) && !empty($data['issue_date'])) {
            $data['due_date'] = $data['issue_date'];
        }

        $errors = [];
        if (empty($data['provider_id']) && empty($data['employee_id'])) {
            $errors[] = 'Debe seleccionar un proveedor o un empleado como beneficiario.';

            return new self($invoicesTable->newEmptyEntity(), $dropdowns, $errors);
        }

        $accessibleFields = array_fill_keys(self::ALLOWED_FIELDS, true)
            + array_fill_keys(self::BLOCKED_FIELDS, false);

        $invoice = $invoicesTable->patchEntity(
            $invoicesTable->newEmptyEntity(),
            $data,
            ['accessibleFields' => $accessibleFields],
        );

        return new self($invoice, $dropdowns, $errors);
    }
}
```

**Step 2: Refactor `add()` en el controller**

Reemplazar la action completa por:

```php
public function add(): ?Response
{
    /** @var \App\Model\Table\InvoicesTable $invoicesTable */
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $dropdowns = $this->_dropdowns();

    if ($this->request->is('post')) {
        $vm = AdvanceAddViewModel::fromRequest(
            $invoicesTable,
            $this->request->getData(),
            $this->_getCurrentUser()->id,
            $dropdowns,
        );

        if (!empty($vm->errors)) {
            $this->Flash->error($vm->errors[0]);
            $this->set('invoice', $vm->invoice);
            $this->set($vm->dropdowns);

            return null;
        }

        if ($invoicesTable->save($vm->invoice)) {
            $this->Flash->success('Anticipo creado.');

            return $this->redirect(['action' => 'view', $vm->invoice->id]);
        }

        $this->Flash->error('No se pudo guardar el anticipo.');
        $this->set('invoice', $vm->invoice);
        $this->set($vm->dropdowns);

        return null;
    }

    $vm = AdvanceAddViewModel::forForm($invoicesTable, $dropdowns);
    $this->set('invoice', $vm->invoice);
    $this->set($vm->dropdowns);

    return null;
}
```

Agregar import al top del controller:
```php
use App\ViewModel\AdvanceAddViewModel;
```

**Step 3: Verificar estilo**

Run:
```bash
composer cs-check
```
Expected: OK.

**Step 4: Verificar que la action ya no tiene la lista blanca inline**

Run:
```bash
grep -n "accessibleFields" src/Controller/AdvancesController.php
```
Expected: cero coincidencias (toda la lógica vive ahora en el VM).

```bash
grep -n "approver_id" src/Controller/AdvancesController.php
```
Expected: cero coincidencias.

**Step 5: Commit**

```bash
git add src/ViewModel/AdvanceAddViewModel.php src/Controller/AdvancesController.php
git commit -m "$(cat <<'EOF'
refactor(advances): introduce AdvanceAddViewModel

add() pasa de ~55 líneas a ~30. Lista blanca de campos accesibles
(audit CR-001 — mass-assignment guard) y defaults del Anticipo
viven ahora en el VM.

Plan D del audit flow-structure-audit-2026-05-06.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 8: Actualizar el documento del audit

**Files:**
- Modify: `docs/audits/flow-structure-audit-2026-05-06.md` (secciones 6, 8, 9)

**Step 1: Marcar Plan D en sección 6**

Localizar la fila de **Advances** en la tabla de la sección 6. Reemplazar:

```
| 🟡 Media | **Advances** | Crear `AdvanceLegalizationDocumentService` propio (despegar de `InvoiceDocumentService`). Convertir `_buildLegalizationViewModel` privado en clase. Crear Add ViewModel. | Backlog (no entra en opción 2) |
```

Por:

```
| 🟡 Media | **Advances** | Crear `AdvanceLegalizationDocumentService` propio. Convertir `_buildLegalizationViewModel` privado en `AdvanceLegalizationViewModel`. Crear `AdvanceAddViewModel`. | **Completado — Plan D** |
```

**Step 2: Agregar fila en sección 8**

Localizar la tabla de "Estado de los planes". Agregar una fila al final:

```
| Plan D | Advances | 🟢 Completado | 2026-05-06 |
```

**Step 3: Registrar cambios en sección 9**

Agregar al final de la lista de cambios (después de la última entrada del 2026-05-06):

```
- **2026-05-06** — Activación Plan D (Advances). Se promueve desde Backlog. Justificación: continuación natural tras Plan C; cierra el cuarto flujo del audit. Refunds y Plan D comparten el principio "DocumentService propio + ViewModels simétricos".
- **2026-05-06** — Desviación Plan D: ViewModels nombrados `AdvanceAddViewModel` + `AdvanceLegalizationViewModel` (no `AdvanceEditViewModel`). Razón: un Anticipo es internamente una `Invoice` con `document_type=ANTICIPO`; `AdvancesController::edit()` solo redirige a `InvoicesController::edit()`. La simetría real del flujo es Add (crear anticipo) + Legalization (proceso post-pago), no Add/Edit clásico. La sección 5 del canónico se interpreta caso por caso cuando el dominio comparte entidad con otro flujo.
- **2026-05-06** — Hallazgo Plan D: el item original del audit "Advances reusa `InvoiceDocumentService`" estaba **desactualizado** — la realidad era que `AdvanceLegalizationService` usaba `DocumentUploadTrait` directamente (no había service de documentos en absoluto). El nuevo `AdvanceLegalizationDocumentService` cierra la deuda real. El cruce funcional con la tabla `InvoiceDocuments` (legalizaciones que comparten documentos con la factura padre) se conserva — es por diseño del dominio, no acoplamiento accidental.
- **2026-05-06** — Deuda menor Plan D: `confirmShortageReceipt` en `AdvanceLegalizationService` sigue usando `validateAndMoveUpload` directo del trait. No estaba en el alcance del audit (no hay un item explícito) y migrarlo requiere decidir si crear `attachShortageReceipt` en el document service o generalizar el flujo. Se anota para próxima sesión si se vuelve a tocar Advances.
```

**Step 4: Verificar estilo (markdown no toca cs-check, pero verificar coherencia)**

Run:
```bash
grep -n "Plan D" docs/audits/flow-structure-audit-2026-05-06.md
```
Expected: al menos 3 coincidencias (sección 6, sección 8, sección 9).

**Step 5: Commit**

```bash
git add docs/audits/flow-structure-audit-2026-05-06.md
git commit -m "$(cat <<'EOF'
docs(audits): mark Plan D (Advances) as completed

Cierra el Plan D del audit de estructura de flujos. Documenta:
- desviación de naming ViewModels (Add/Legalization vs Add/Edit)
- corrección al hallazgo original (no reusaba InvoiceDocumentService;
  usaba DocumentUploadTrait directo)
- deuda menor pendiente: confirmShortageReceipt sigue con trait directo

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tarea 9: Validación manual

**Files:** ninguno (solo testing en navegador).

**Step 1: Comparar tamaños vs baseline**

Run:
```bash
wc -l src/Controller/AdvancesController.php src/Service/AdvanceLegalizationService.php src/Service/AdvanceLegalizationDocumentService.php src/ViewModel/AdvanceAddViewModel.php src/ViewModel/AdvanceLegalizationViewModel.php
```
Expected approximate (no exacto):
- `AdvancesController.php`: baseline 822 → ahora ~700 (delta -120)
- `AdvanceLegalizationService.php`: baseline 755 → ahora ~620 (delta -130)
- `AdvanceLegalizationDocumentService.php`: ~150
- `AdvanceAddViewModel.php`: ~110
- `AdvanceLegalizationViewModel.php`: ~95

Si las diferencias son drásticamente distintas (ej. controller bajó solo 20 líneas), revisar qué falta antes de seguir.

**Step 2: Levantar el servidor**

Run:
```bash
php bin/cake server
```
Servidor en `http://localhost:8765`.

Login: `admin` / `Admin2024*`.

**Step 3: Validación funcional — checklist en navegador**

Marcar cada item conforme se valida. Si alguno falla, abrir issue/branch dedicada — **no** parchear sin revisar plan.

| # | Caso | Acción | Esperado |
|---|------|--------|----------|
| 1 | Crear anticipo con provider | `/advances/add` con `provider_id`, sin employee, monto, fechas | Redirect a `/advances/view/{id}`, registro en BD con `document_type=ANTICIPO`, `pipeline_status=aprobacion` |
| 2 | Crear anticipo con employee | `/advances/add` con `employee_id`, sin provider | Redirect a view, registro creado |
| 3 | Beneficiario faltante | POST sin `provider_id` ni `employee_id` | Flash error "Debe seleccionar un proveedor o un empleado…", no se crea registro |
| 4 | Mass-assignment guard | POST con `approver_id`, `area_approval`, `payment_status`, `confirmed_by`, `accrued`, `advance_id` en el body (usar curl o devtools). Verificar BD con `SELECT approver_id, area_approval, payment_status, confirmed_by, accrued, advance_id FROM invoices WHERE id={nuevo_id}` | Todos los campos NULL/default. NINGUNO recibe el valor enviado en el body. |
| 5 | Default `due_date` | POST sin `due_date` y con `issue_date='2026-05-06'` | BD muestra `due_date='2026-05-06'` |
| 6 | Vista legalización OK | Anticipo en `pagada` con `advance_legalization` iniciada → `/advances/legalization/{id}` | Render correcto: linked invoices, totales, diff, signature actual separada del historial, surplus payment cargado cuando aplica, dropdown de banking entities. **Comparar visualmente** con un screenshot/recuerdo del baseline. |
| 7 | Vista legalización defensiva | `/advances/legalization/{id}` cuando aún no existe `advance_legalization` (anticipo no pagado todavía) | Redirect a `view` con flash info: "La legalización aún no ha iniciado. Espere a que el anticipo esté en estado Pagada." |
| 8 | Adjuntar relation document | En la vista de legalización (estado validacion o revision_firmas) subir un PDF | Flash success "Documento adjuntado". Verificar archivo físico en `webroot/uploads/AdvanceLegalizationSignatures/advances/{leg_id}/`. Verificar fila en `advance_legalization_signatures` con `signature_status=pending`. Si había pendientes anteriores, verificar que el archivo físico fue eliminado del disco (audit MA-004). |
| 9 | Adjuntar receipt en item | En el formulario de agregar item a la legalización subir `receipt_file` | Item creado con `receipt_file_path` apuntando al archivo subido en `webroot/uploads/advances/items/`. |

**Step 4: Si todo verde, anotar en el audit**

(Opcional, si quieres dejar evidencia de la validación.) En el commit del Step 5 de la Tarea 8 se podría agregar una nota, pero ya está cerrado. Si quisieras agregar la fecha exacta de validación, hacer un commit extra `docs(audits): record Plan D manual validation date`.

**Step 5: Squash check (opcional) y cerrar la rama**

Decisión del usuario: ¿se mergea la rama `feat/advance-canonical-structure` a `main` directamente, vía PR, o squash? Tras los Plans A/B/C el patrón ha sido commits directos a `main`. Recomendado mismo flujo:

Run:
```bash
git checkout main
git merge --no-ff feat/advance-canonical-structure -m "merge: Plan D — Advance canonical structure"
git branch -d feat/advance-canonical-structure
```

(Push solo si el usuario lo confirma — no auto-push.)

---

## Resumen de archivos creados/modificados

| Tipo | Archivo | Tarea |
|------|---------|-------|
| Create | `src/Service/AdvanceLegalizationDocumentService.php` | 1, 2 |
| Modify | `src/Service/AdvanceLegalizationService.php` | 2, 3 |
| Modify | `src/Application.php` | 4 |
| Modify | `src/Controller/AdvancesController.php` | 5, 6, 7 |
| Create | `src/ViewModel/AdvanceLegalizationViewModel.php` | 6 |
| Create | `src/ViewModel/AdvanceAddViewModel.php` | 7 |
| Modify | `docs/audits/flow-structure-audit-2026-05-06.md` | 8 |

**Total commits estimados:** 8 (uno por tarea, salvo Tarea 0 sin commit y Tarea 9 sin commit).

## Reglas durante la ejecución

- **Nunca** combinar tareas en un solo commit, salvo que el cambio de una tarea sea ≤5 líneas y dependa físicamente de la siguiente.
- **Nunca** correr `composer cs-fix` sin antes ver el diff que va a aplicar (`git diff` después).
- Si una validación de `grep` no devuelve lo esperado, **detenerse** y leer el código manualmente. No avanzar a la siguiente tarea con un grep verde "fingido".
- Si `bin/cake server` truena al iniciar tras la Tarea 4, revisar `src/Application.php` — probablemente falta el import o falla el `addArguments`.
- Las validaciones manuales de la Tarea 9 son **bloqueantes**. Si una falla, NO mergeear a `main`.
