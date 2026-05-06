# Payment Schedulings — Migración a estructura canónica (Implementation Plan)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Llevar el módulo PaymentSchedulings a la base canónica de la auditoría 2026-05-06: renombrar `Attachment` → `Document`, fusionar `PipelineService` en el `Service` principal, extraer parser Excel a `ImportService`, aplicar Pipeline State pattern, crear ViewModels Add+Edit simétricos.

**Architecture:** Patrón canónico replicando PettyCash. `PaymentSchedulingService` queda como coordinador (dominio + pipeline). `PaymentSchedulingImportService` aislado para parsing Siesa. `Pipeline/PaymentScheduling/State/*` con interfaz mínima `getName/getNext/getPrevious/validateAdvance` + Registry. ViewModels simétricos.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB, Phinx migrations (`Migrations\BaseMigration`).

**Design doc:** `docs/plans/2026-05-06-payment-schedulings-canonical-structure-design.md`
**Audit fuente:** `docs/audits/flow-structure-audit-2026-05-06.md`
**Branch sugerido:** `refactor/payment-schedulings-canonical-structure`

**Política del proyecto (CLAUDE.md):** No hay tests automatizados. Cada task termina con **validación manual** específica en lugar de tests. Si la validación falla, no commit hasta resolver.

---

## Task 1: Crear migración de rename + extender Constants

**Files:**
- Create: `config/Migrations/20260506HHMMSS_RenamePaymentSchedulingAttachmentsToDocuments.php` (HHMMSS = timestamp actual)
- Modify: `src/Constants/PaymentSchedulingConstants.php`

**Step 1: Generar migración**

Run: `php bin/cake migrations create RenamePaymentSchedulingAttachmentsToDocuments`
Expected: archivo nuevo en `config/Migrations/` con timestamp del momento.

**Step 2: Reemplazar contenido del método `change()` por:**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RenamePaymentSchedulingAttachmentsToDocuments extends BaseMigration
{
    public function change(): void
    {
        $this->table('payment_scheduling_attachments')
            ->rename('payment_scheduling_documents')
            ->update();
    }
}
```

**Step 3: Ejecutar migración**

Run: `php bin/cake migrations migrate`
Expected: output `RenamePaymentSchedulingAttachmentsToDocuments: migrating` → `migrated`.

Verificar con: `php bin/cake migrations status` — última migración debe estar marcada como up.

**Step 4: Extender `PaymentSchedulingConstants`**

Editar `src/Constants/PaymentSchedulingConstants.php`. Agregar después de `BACKWARD_TRANSITIONS`:

```php
    // Forward transitions (extracted from PaymentSchedulingPipelineService::TRANSITIONS).
    public const FORWARD_TRANSITIONS = [
        self::STATUS_BORRADOR => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_AUT_PAGO,
        self::STATUS_AUT_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA => null,
    ];

    // Target status when Contador rejects from aut_pago.
    public const REJECTION_TARGET = self::STATUS_TESORERIA;
```

**Step 5: Validación manual**

- Confirmar `php bin/cake migrations status` muestra todas las migraciones up.
- Conectar a la BD y `SHOW TABLES LIKE 'payment_scheduling_%';` debe listar `payment_scheduling_documents` (no `_attachments`).

**Step 6: Commit**

```bash
git add config/Migrations/ src/Constants/PaymentSchedulingConstants.php
git commit -m "chore(payment-schedulings): rename attachments table to documents + extend constants"
```

---

## Task 2: Renombrar Entity y Table (Attachment → Document)

**Files:**
- Rename: `src/Model/Entity/PaymentSchedulingAttachment.php` → `src/Model/Entity/PaymentSchedulingDocument.php`
- Rename: `src/Model/Table/PaymentSchedulingAttachmentsTable.php` → `src/Model/Table/PaymentSchedulingDocumentsTable.php`
- Modify: `src/Model/Table/PaymentSchedulingsTable.php:37-41` (asociación)

**Step 1: git mv archivos**

```bash
git mv src/Model/Entity/PaymentSchedulingAttachment.php src/Model/Entity/PaymentSchedulingDocument.php
git mv src/Model/Table/PaymentSchedulingAttachmentsTable.php src/Model/Table/PaymentSchedulingDocumentsTable.php
```

**Step 2: Actualizar Entity**

Editar `src/Model/Entity/PaymentSchedulingDocument.php`. Reemplazar `class PaymentSchedulingAttachment extends Entity` por:

```php
class PaymentSchedulingDocument extends Entity
```

(El array `_accessible` no cambia — los campos `payment_scheduling_id`, `file_path`, `file_name`, `uploaded_by` están bien.)

**Step 3: Actualizar Table**

Editar `src/Model/Table/PaymentSchedulingDocumentsTable.php`:

- Línea de class: `class PaymentSchedulingAttachmentsTable extends Table` → `class PaymentSchedulingDocumentsTable extends Table`
- `$this->setTable('payment_scheduling_attachments')` → `$this->setTable('payment_scheduling_documents')`

(Resto del archivo — validation, rules, asociaciones — permanece igual.)

**Step 4: Actualizar asociación en `PaymentSchedulingsTable`**

Editar `src/Model/Table/PaymentSchedulingsTable.php:37-41`. Reemplazar:

```php
        $this->hasMany('PaymentSchedulingAttachments', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
```

por:

```php
        $this->hasMany('PaymentSchedulingDocuments', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
```

**Step 5: Validación manual**

- Run `php bin/cake server`. Abrir `http://localhost:8765/payment-schedulings`.
- Si el listado se renderiza sin error 500, OK.
- Si hay error de "PaymentSchedulingAttachments not found", revisar que ningún archivo del módulo siga usando ese alias (en este task, sólo se permiten refs en controller y templates — se actualizan en Task 3).

**Nota:** En este punto, el controller y templates aún referencian `PaymentSchedulingAttachments` y `payment_scheduling_attachments`. Esto generará errores al abrir `view`/`edit`. **Esto es esperado** — Task 3 lo corrige. Por eso este commit puede romper temporalmente esas pantallas.

**Step 6: Commit**

```bash
git add src/Model/
git commit -m "refactor(payment-schedulings): rename Attachment entity/table to Document"
```

---

## Task 3: Renombrar Service + actualizar refs en controller, routes y templates

**Files:**
- Rename: `src/Service/PaymentSchedulingAttachmentService.php` → `src/Service/PaymentSchedulingDocumentService.php`
- Modify: `src/Controller/PaymentSchedulingsController.php` (refs a service y asociaciones)
- Modify: `config/routes.php:469-478` (rutas custom)
- Modify: `templates/PaymentSchedulings/edit.php:331, 344, 359, 442, 446, 540`
- Modify: `templates/PaymentSchedulings/view.php:210`

**Step 1: git mv del service**

```bash
git mv src/Service/PaymentSchedulingAttachmentService.php src/Service/PaymentSchedulingDocumentService.php
```

**Step 2: Actualizar contenido del service**

Editar `src/Service/PaymentSchedulingDocumentService.php`. Reemplazos exactos:

- `class PaymentSchedulingAttachmentService` → `class PaymentSchedulingDocumentService`
- `private const TABLE = 'PaymentSchedulingAttachments';` → `private const TABLE = 'PaymentSchedulingDocuments';`
- `'ps_'` (prefijo de upload) — se mantiene (no afecta).
- Renombrar métodos:
  - `uploadAttachment` → `uploadDocument` (signature idéntica)
  - `deleteAttachment` → `deleteDocument` (signature idéntica)
- Actualizar el array de extra fields del `uploadAndSave`: `'payment_scheduling_id' => $schedulingId, 'uploaded_by' => $uploadedBy` — se mantiene igual.

**Step 3: Actualizar `PaymentSchedulingsController.php`**

Reemplazos exactos:

- `use App\Service\PaymentSchedulingAttachmentService;` → `use App\Service\PaymentSchedulingDocumentService;`
- `private PaymentSchedulingAttachmentService $attachmentService;` → `private PaymentSchedulingDocumentService $documentService;`
- `$this->attachmentService = $container->get(PaymentSchedulingAttachmentService::class);` → `$this->documentService = $container->get(PaymentSchedulingDocumentService::class);`
- En `view()` y `edit()`, dentro del array de `contain`:
  - `'PaymentSchedulingAttachments' => [...]` → `'PaymentSchedulingDocuments' => [...]`
  - `'sort' => ['PaymentSchedulingAttachments.created' => 'DESC']` → `'sort' => ['PaymentSchedulingDocuments.created' => 'DESC']`
- Renombrar métodos y refs:
  - `public function uploadAttachment` → `public function uploadDocument`
  - `public function deleteAttachment` → `public function deleteDocument` (renombrar también el segundo parámetro `$attachmentId` → `$documentId`)
  - `$this->attachmentService->uploadAttachment(...)` → `$this->documentService->uploadDocument(...)`
  - `$this->attachmentService->deleteAttachment(...)` → `$this->documentService->deleteDocument(...)`
  - `Router::url(['action' => 'deleteAttachment', $id, $result->id])` → `Router::url(['action' => 'deleteDocument', $id, $result->id])`
  - Mensajes Flash que mencionen "soporte" se mantienen (palabra de negocio en español).
- En `deleteDocument()`:
  - `$attachmentsTable = $this->fetchTable('PaymentSchedulingAttachments');` → `$documentsTable = $this->fetchTable('PaymentSchedulingDocuments');`
  - `$attachment = $attachmentsTable->find()->where(['id' => $attachmentId, ...])` → renombrar variable también
  - `$this->attachmentService->deleteAttachment((int)$attachmentId)` → `$this->documentService->deleteDocument((int)$documentId)`

**Step 4: Actualizar `config/routes.php`**

Editar líneas 469-478. Reemplazar:

```php
        $builder->connect(
            '/payment-schedulings/upload-attachment/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'uploadAttachment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/delete-attachment/{id}/{attachmentId}',
            ['controller' => 'PaymentSchedulings', 'action' => 'deleteAttachment'],
            ['id' => '\d+', 'attachmentId' => '\d+', 'pass' => ['id', 'attachmentId']],
        );
```

por:

```php
        $builder->connect(
            '/payment-schedulings/upload-document/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/delete-document/{id}/{documentId}',
            ['controller' => 'PaymentSchedulings', 'action' => 'deleteDocument'],
            ['id' => '\d+', 'documentId' => '\d+', 'pass' => ['id', 'documentId']],
        );
```

**Step 5: Actualizar `templates/PaymentSchedulings/edit.php`**

Reemplazos exactos:

- Línea 331: `$attachments = $record->payment_scheduling_attachments ?? [];` → `$documents = $record->payment_scheduling_documents ?? [];`
- Línea 344: `data-bs-target="#uploadAttachmentModal"` → `data-bs-target="#uploadDocumentModal"`
- Línea 359: `'deleteUrl' => $this->Url->build(['action' => 'deleteAttachment', $record->id, $att->id])` → `'deleteUrl' => $this->Url->build(['action' => 'deleteDocument', $record->id, $att->id])`
- Línea 442: `<div class="modal fade" id="uploadAttachmentModal" tabindex="-1">` → `<div class="modal fade" id="uploadDocumentModal" tabindex="-1">`
- Línea 446: `data-url="<?= $this->Url->build(['action' => 'uploadAttachment', $record->id]) ?>"` → `data-url="<?= $this->Url->build(['action' => 'uploadDocument', $record->id]) ?>"`
- Línea 540: `modalSelector: '#uploadAttachmentModal',` → `modalSelector: '#uploadDocumentModal',`

Si la línea 331 cambia el nombre de la variable, verificar el bloque `foreach ($attachments as $att)` cercano y renombrar también la variable iteradora si fuera necesario (probablemente sólo el array origen).

**Step 6: Actualizar `templates/PaymentSchedulings/view.php`**

- Línea 210: `$attachments = $record->payment_scheduling_attachments ?? [];` → `$documents = $record->payment_scheduling_documents ?? [];`

Verificar el resto del archivo por refs a la variable `$attachments` (renombrar a `$documents` o mantener `$attachments` localmente — preferir renombrar para coherencia).

**Step 7: Validación manual**

Run: `php bin/cake server`

1. Abrir `http://localhost:8765/payment-schedulings` — listado debe renderizar.
2. Abrir una programación existente en `view` — la sección de soportes debe mostrarse vacía o con los archivos cargados.
3. Abrir una programación en `edit` — el botón "Subir soporte" abre el modal correcto.
4. Subir un archivo de prueba — debe aparecer en la lista.
5. Eliminar el archivo subido — debe desaparecer.

Si alguno falla, revisar logs en `logs/error.log` o `logs/debug.log`.

**Step 8: Commit**

```bash
git add src/Service/ src/Controller/PaymentSchedulingsController.php config/routes.php templates/PaymentSchedulings/
git commit -m "refactor(payment-schedulings): rename AttachmentService to DocumentService"
```

---

## Task 4: Crear interfaz y Registry del Pipeline State pattern

**Files:**
- Create: `src/Service/Pipeline/PaymentScheduling/PaymentSchedulingPipelineState.php`
- Create: `src/Service/Pipeline/PaymentScheduling/PaymentSchedulingPipelineStateRegistry.php`

**Step 1: Crear directorio**

```bash
mkdir -p src/Service/Pipeline/PaymentScheduling/State
```

**Step 2: Crear interfaz**

Archivo `src/Service/Pipeline/PaymentScheduling/PaymentSchedulingPipelineState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling;

use App\Model\Entity\PaymentScheduling;

/**
 * Polymorphic representation of one PaymentScheduling pipeline state.
 *
 * Each State knows its natural transitions (next/previous) and the field
 * requirements specific to advancing. Cross-state checks (role authorization,
 * payment application) are composed by the coordinator (PaymentSchedulingService).
 */
interface PaymentSchedulingPipelineState
{
    /** Canonical name (e.g. 'borrador'). */
    public function getName(): string;

    /** Next state's name; null if terminal. */
    public function getNext(): ?string;

    /** Previous state's name; null if first or regression blocked. */
    public function getPrevious(): ?string;

    /**
     * Errors preventing advance from this state. Does not include cross-cutting
     * invariants like "must have at least one item" — those live in the coordinator.
     *
     * @return array<string>
     */
    public function validateAdvance(PaymentScheduling $scheduling): array;
}
```

**Step 3: Crear Registry (placeholder hasta tener States)**

Archivo `src/Service/Pipeline/PaymentScheduling/PaymentSchedulingPipelineStateRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling;

use App\Service\Pipeline\PaymentScheduling\State\AutPagoState;
use App\Service\Pipeline\PaymentScheduling\State\BorradorState;
use App\Service\Pipeline\PaymentScheduling\State\PagadaState;
use App\Service\Pipeline\PaymentScheduling\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resolves `payment_schedulings.pipeline_status` (string) to a concrete State.
 * Sole dependency the coordinator (PaymentSchedulingService) needs to access states.
 */
final class PaymentSchedulingPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState>
     */
    private array $states;

    public function __construct(
        ?BorradorState $borrador = null,
        ?TesoreriaState $tesoreria = null,
        ?AutPagoState $autPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $borrador ?? new BorradorState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): PaymentSchedulingPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown payment scheduling pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}
```

**Step 4: Validación**

No hay validación funcional aquí — los States todavía no existen, así que el Registry no se puede instanciar. Sólo verificar que los archivos no tengan errores de sintaxis.

Run: `composer cs-check src/Service/Pipeline/PaymentScheduling/`
Expected: Sin errores. Si reporta cualquier issue, ejecutar `composer cs-fix`.

**Step 5: Commit**

```bash
git add src/Service/Pipeline/PaymentScheduling/
git commit -m "feat(payment-schedulings): add pipeline state interface and registry"
```

---

## Task 5: Crear los 4 Estados concretos

**Files:**
- Create: `src/Service/Pipeline/PaymentScheduling/State/BorradorState.php`
- Create: `src/Service/Pipeline/PaymentScheduling/State/TesoreriaState.php`
- Create: `src/Service/Pipeline/PaymentScheduling/State/AutPagoState.php`
- Create: `src/Service/Pipeline/PaymentScheduling/State/PagadaState.php`

**Step 1: BorradorState (con validación de items)**

Archivo `src/Service/Pipeline/PaymentScheduling/State/BorradorState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;
use Cake\ORM\TableRegistry;

final class BorradorState implements PaymentSchedulingPipelineState
{
    public function getName(): string
    {
        return PaymentSchedulingConstants::STATUS_BORRADOR;
    }

    public function getNext(): ?string
    {
        return PaymentSchedulingConstants::STATUS_TESORERIA;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $count = $itemsTable->find()
            ->where(['payment_scheduling_id' => $scheduling->id])
            ->count();

        if ($count === 0) {
            return ['Debe vincular al menos una factura'];
        }

        return [];
    }
}
```

**Step 2: TesoreriaState**

Archivo `src/Service/Pipeline/PaymentScheduling/State/TesoreriaState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class TesoreriaState implements PaymentSchedulingPipelineState
{
    public function getName(): string
    {
        return PaymentSchedulingConstants::STATUS_TESORERIA;
    }

    public function getNext(): ?string
    {
        return PaymentSchedulingConstants::STATUS_AUT_PAGO;
    }

    public function getPrevious(): ?string
    {
        return PaymentSchedulingConstants::STATUS_BORRADOR;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}
```

**Step 3: AutPagoState**

Archivo `src/Service/Pipeline/PaymentScheduling/State/AutPagoState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class AutPagoState implements PaymentSchedulingPipelineState
{
    public function getName(): string
    {
        return PaymentSchedulingConstants::STATUS_AUT_PAGO;
    }

    public function getNext(): ?string
    {
        return PaymentSchedulingConstants::STATUS_PAGADA;
    }

    public function getPrevious(): ?string
    {
        return PaymentSchedulingConstants::STATUS_TESORERIA;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}
```

**Step 4: PagadaState (terminal)**

Archivo `src/Service/Pipeline/PaymentScheduling/State/PagadaState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class PagadaState implements PaymentSchedulingPipelineState
{
    public function getName(): string
    {
        return PaymentSchedulingConstants::STATUS_PAGADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}
```

**Step 5: Validación**

Run: `composer cs-check src/Service/Pipeline/PaymentScheduling/`
Expected: Sin errores. Si hay, `composer cs-fix`.

Sanity check de instanciación:

```bash
php -r "
require 'vendor/autoload.php';
require 'config/bootstrap.php';
\$r = new App\\Service\\Pipeline\\PaymentScheduling\\PaymentSchedulingPipelineStateRegistry();
echo \$r->get('borrador')->getNext() . PHP_EOL;
echo \$r->get('aut_pago')->getPrevious() . PHP_EOL;
"
```

Expected: imprime `tesoreria` y `tesoreria`. Si arroja error, revisar imports.

**Step 6: Commit**

```bash
git add src/Service/Pipeline/PaymentScheduling/State/
git commit -m "feat(payment-schedulings): add concrete pipeline states"
```

---

## Task 6: Extraer parser Excel a `PaymentSchedulingImportService`

**Files:**
- Create: `src/Service/PaymentSchedulingImportService.php`

(El `PaymentSchedulingService` viejo se mantiene intacto en este task — Task 7 lo limpia.)

**Step 1: Crear `PaymentSchedulingImportService.php`**

Archivo nuevo en `src/Service/PaymentSchedulingImportService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PaymentSchedulingImportService
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
    }

    /**
     * Transforma número de factura de formato Siesa al formato SGI.
     * Ej: "FVE-00080933-00" → "FVE80933", "-00006755-00" → "6755"
     */
    private function _normalizeSiesaInvoiceNumber(string $raw): string
    {
        $parts = explode('-', $raw);

        $letters = '';
        $number = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (ctype_alpha($part)) {
                $letters .= $part;
            } elseif (ctype_digit($part)) {
                if ($number === '') {
                    $number = ltrim($part, '0') ?: '0';
                }
            } else {
                $letterPart = preg_replace('/[^A-Za-z]/', '', $part);
                $digitPart = preg_replace('/[^0-9]/', '', $part);
                if ($letterPart !== '') {
                    $letters .= $letterPart;
                }
                if ($digitPart !== '' && $number === '') {
                    $number = ltrim($digitPart, '0') ?: '0';
                }
            }
        }

        return $letters . $number;
    }

    /**
     * Extrae el NIT puro del formato Siesa (sin sufijo de sucursal).
     * Ej: "900474383-001" → "900474383"
     */
    private function _extractNit(string $raw): string
    {
        $parts = explode('-', $raw);

        return trim($parts[0]);
    }

    /**
     * Parsea el Excel de preprogramación de pagos (5 columnas).
     * Formato multi-fila: encabezado proveedor → factura(s).
     * Columnas: Banco aprovador (A), Proveedor/NIT (B), Razón Social (C), Saldo (D), Programado (E).
     * Retorna ['valid' => [...], 'errors' => [...]]
     */
    public function parseExcel(string $filePath): array
    {
        // [Copy paste exacto del cuerpo de parseExcel del PaymentSchedulingService actual]
        // — desde "$spreadsheet = IOFactory::load($filePath);" hasta el "return ['valid' => $valid, 'errors' => $errors];"
    }
}
```

> **Nota para el ejecutor:** copiar literalmente las líneas 75-206 de `src/Service/PaymentSchedulingService.php` (cuerpo completo de `parseExcel`) dentro del nuevo método. No reformatear.

**Step 2: Validación de sintaxis**

Run: `composer cs-check src/Service/PaymentSchedulingImportService.php`
Expected: Sin errores.

Run: `php -l src/Service/PaymentSchedulingImportService.php`
Expected: `No syntax errors detected`.

**Step 3: Commit**

```bash
git add src/Service/PaymentSchedulingImportService.php
git commit -m "refactor(payment-schedulings): extract Excel parser to ImportService"
```

---

## Task 7: Fusionar `PaymentSchedulingPipelineService` en `PaymentSchedulingService` y limpiar parsing

**Files:**
- Modify: `src/Service/PaymentSchedulingService.php`
- Delete: `src/Service/PaymentSchedulingPipelineService.php`
- Modify: `src/Controller/PaymentSchedulingsController.php`

**Step 1: Reescribir `PaymentSchedulingService.php`**

Reemplazar el contenido completo de `src/Service/PaymentSchedulingService.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineStateRegistry;
use Cake\ORM\TableRegistry;

class PaymentSchedulingService
{
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::TESORERIA => [
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::CONTADOR => [
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::ADMIN => PaymentSchedulingConstants::PIPELINE_STATUSES,
    ];

    private PipelineAuthorizationService $pipelineAuth;
    private PaymentSchedulingPipelineStateRegistry $stateRegistry;

    public function __construct(
        private readonly InvoicePaymentService $paymentService,
        ?PipelineAuthorizationService $pipelineAuth = null,
        ?PaymentSchedulingPipelineStateRegistry $stateRegistry = null,
    ) {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
        $this->stateRegistry = $stateRegistry ?? new PaymentSchedulingPipelineStateRegistry();
    }

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    public function getNextStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::FORWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getNextStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function canReject(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($currentStatus !== PaymentSchedulingConstants::STATUS_AUT_PAGO) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function validateTransitionRequirements(PaymentScheduling $scheduling, string $fromStatus): array
    {
        return $this->stateRegistry->get($fromStatus)->validateAdvance($scheduling);
    }

    public function getRegressionLockMessage(PaymentScheduling $scheduling): ?string
    {
        return null;
    }

    /**
     * Cold regression — only changes pipeline_status, doesn't touch items or payments.
     */
    public function regress(
        PaymentScheduling $scheduling,
        int $roleId,
        string $roleName,
        int $userId,
        string $reason,
    ): ServiceResult {
        $reason = trim($reason);
        $currentStatus = $scheduling->pipeline_status;

        if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Esta programación ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar esta programación.';

            return ServiceResult::fail([$error]);
        }

        $lock = $this->getRegressionLockMessage($scheduling);
        if ($lock !== null) {
            return ServiceResult::fail([$lock]);
        }

        if (mb_strlen($reason) < 10) {
            return ServiceResult::fail(['El motivo es obligatorio (mínimo 10 caracteres).']);
        }
        if (mb_strlen($reason) > 500) {
            return ServiceResult::fail(['El motivo no puede superar 500 caracteres.']);
        }

        $previousStatus = $this->getPreviousStatus($currentStatus);
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');
        $observationsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingObservations');

        $ok = $schedulingsTable->getConnection()->transactional(
            function () use (
                $schedulingsTable,
                $observationsTable,
                $scheduling,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason,
            ): bool {
                $scheduling->pipeline_status = $previousStatus;
                if (!$schedulingsTable->save($scheduling)) {
                    return false;
                }

                $observation = $observationsTable->newEntity([
                    'payment_scheduling_id' => $scheduling->id,
                    'user_id' => $userId,
                    'type' => PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION,
                    'message' => $reason,
                    'metadata' => [
                        'from_status' => $currentStatus,
                        'to_status' => $previousStatus,
                    ],
                ]);

                return (bool)$observationsTable->save($observation);
            },
        );

        if (!$ok) {
            return ServiceResult::fail(['No se pudo regresar la programación. Intente de nuevo.']);
        }

        return ServiceResult::ok(['previousStatus' => $previousStatus]);
    }

    /**
     * Vincula items validados a una programación.
     */
    public function linkItems(int $schedulingId, array $validItems): bool
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        foreach ($validItems as $item) {
            $entity = $itemsTable->newEntity([
                'payment_scheduling_id' => $schedulingId,
                'invoice_id' => $item['invoice_id'],
                'banking_entity_id' => $item['banking_entity_id'],
                'amount' => $item['amount'],
            ]);

            if (!$itemsTable->save($entity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aplica los pagos de una programación autorizada.
     */
    public function applyPayments(int $schedulingId, int $authorizedBy): array
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');

        $scheduling = $schedulingsTable->get($schedulingId);
        $items = $itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->all();

        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $items,
            $paymentsTable,
            $invoicesTable,
            $scheduling,
            $schedulingId,
            $authorizedBy,
        ) {
            $appliedInvoiceIds = [];
            $errors = [];

            foreach ($items as $item) {
                $payment = $paymentsTable->newEntity([
                    'invoice_id' => $item->invoice_id,
                    'banking_entity_id' => $item->banking_entity_id,
                    'amount' => $item->amount,
                    'payment_date' => date('Y-m-d'),
                    'payment_scheduling_id' => $schedulingId,
                    'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                    'authorized' => true,
                    'authorized_by' => $authorizedBy,
                    'authorized_date' => date('Y-m-d'),
                    'created_by' => $scheduling->created_by,
                ]);

                if (!$paymentsTable->save($payment)) {
                    $errors[] = "No se pudo crear pago para factura ID {$item->invoice_id}";
                    continue;
                }

                $appliedInvoiceIds[] = $item->invoice_id;
            }

            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors, 'advanced_to_pagada' => [], 'partial_payment' => []];
            }

            $advanced = [];
            $partial = [];
            foreach (array_unique($appliedInvoiceIds) as $invoiceId) {
                $this->paymentService->recalculatePaymentStatus($invoiceId);

                $invoice = $invoicesTable->get($invoiceId);
                if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
                    $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                    $invoicesTable->save($invoice);
                    $advanced[] = $invoiceId;
                } else {
                    $partial[] = $invoiceId;
                }
            }

            return [
                'success' => true,
                'errors' => [],
                'advanced_to_pagada' => $advanced,
                'partial_payment' => $partial,
            ];
        });
    }

    /**
     * Calcula el monto total de una programación.
     */
    public function calculateTotal(int $schedulingId): float
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        return (float)$itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->all()
            ->sumOf('amount');
    }
}
```

**Step 2: Borrar `PaymentSchedulingPipelineService.php`**

```bash
git rm src/Service/PaymentSchedulingPipelineService.php
```

**Step 3: Actualizar Controller**

Editar `src/Controller/PaymentSchedulingsController.php`:

- Eliminar `use App\Service\PaymentSchedulingPipelineService;`
- Eliminar la propiedad `private PaymentSchedulingPipelineService $pipeline;`
- Eliminar `$this->pipeline = $container->get(PaymentSchedulingPipelineService::class);` en `initialize()`
- Agregar `use App\Service\PaymentSchedulingImportService;`
- Agregar propiedad `private PaymentSchedulingImportService $importService;`
- Agregar `$this->importService = $container->get(PaymentSchedulingImportService::class);` en `initialize()`
- Reemplazar TODO uso de `$this->pipeline->...` por `$this->schedulingService->...`. Métodos afectados: `index`, `edit`, `advance`, `reject`, `regressStatus`. Específicamente:
  - `$this->pipeline->getVisibleStatuses(...)` → `$this->schedulingService->getVisibleStatuses(...)`
  - `$this->pipeline->canAdvance(...)` → `$this->schedulingService->canAdvance(...)`
  - `$this->pipeline->canReject(...)` → `$this->schedulingService->canReject(...)`
  - `$this->pipeline->validateTransitionRequirements(...)` → `$this->schedulingService->validateTransitionRequirements(...)`
  - `$this->pipeline->getNextStatus(...)` → `$this->schedulingService->getNextStatus(...)`
  - `$this->pipeline->canRegress(...)` → `$this->schedulingService->canRegress(...)`
  - `$this->pipeline->getPreviousStatus(...)` → `$this->schedulingService->getPreviousStatus(...)`
  - `$this->pipeline->getRegressionLockMessage(...)` → `$this->schedulingService->getRegressionLockMessage(...)`
  - `$this->pipeline->regress(...)` → `$this->schedulingService->regress(...)`
- En `reject()`, reemplazar `PaymentSchedulingPipelineService::REJECTION_TARGET` por `PaymentSchedulingConstants::REJECTION_TARGET`.
- En `importExcel()`, reemplazar `$this->schedulingService->parseExcel($tmpPath)` por `$this->importService->parseExcel($tmpPath)`.

**Step 4: Validación manual**

Run: `php bin/cake server`

1. Abrir `/payment-schedulings` — listado con filtros por rol funciona.
2. Abrir una programación en `/payment-schedulings/edit/{id}` — botones de pipeline aparecen según rol/estado.
3. Crear una nueva programación → Borrador.
4. Importar Excel → preview → confirmar items → vincular factura.
5. Avanzar Borrador → Tesorería → Aut. Pago → Pagada.
6. En Aut. Pago, probar `reject` → vuelve a Tesorería.
7. En Aut. Pago, probar `regress` con motivo → vuelve a Tesorería con observación creada.

**Step 5: Commit**

```bash
git add src/Service/ src/Controller/PaymentSchedulingsController.php
git commit -m "refactor(payment-schedulings): merge PipelineService into main Service with state registry"
```

---

## Task 8: Crear `PaymentSchedulingAddViewModel` e integrar en controller

**Files:**
- Create: `src/ViewModel/PaymentSchedulingAddViewModel.php`
- Modify: `src/Controller/PaymentSchedulingsController.php` (método `add`)
- Modify: `templates/PaymentSchedulings/add.php`

**Step 1: Crear ViewModel**

Archivo `src/ViewModel/PaymentSchedulingAddViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PaymentScheduling;
use Cake\Collection\CollectionInterface;

final class PaymentSchedulingAddViewModel
{
    /**
     * @param iterable<int, mixed> $operationCenters
     */
    public function __construct(
        public readonly PaymentScheduling $record,
        public readonly iterable $operationCenters,
    ) {
    }
}
```

> **Nota:** `iterable` permite tanto array como `CollectionInterface` (que devuelve `find('codeList')`). Ajustar a `array` si la convención del proyecto exige array material — verificar mirando `PettyCashAddViewModel`.

**Step 2: Refactorizar el método `add` del controller**

En `src/Controller/PaymentSchedulingsController.php`, reemplazar la implementación de `add()`:

```php
public function add()
{
    $record = $this->PaymentSchedulings->newEmptyEntity();

    if ($this->request->is('post')) {
        $user = $this->_getCurrentUser();
        $data = $this->request->getData();
        $data['operation_center_id'] = $data['operation_center_id'] ?? null;
        $data['pipeline_status'] = PaymentSchedulingConstants::STATUS_BORRADOR;
        $data['created_by'] = $user->id;

        $record = $this->PaymentSchedulings->patchEntity($record, $data);
        if ($this->PaymentSchedulings->save($record)) {
            $this->Flash->success('Programación creada correctamente.');

            return $this->redirect(['action' => 'edit', $record->id]);
        }
        $this->Flash->error('No se pudo crear la programación.');
    }

    $this->set('viewModel', $this->_buildAddViewModel($record));
}

private function _buildAddViewModel(PaymentScheduling $record): PaymentSchedulingAddViewModel
{
    $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();

    return new PaymentSchedulingAddViewModel(
        record: $record,
        operationCenters: $operationCenters,
    );
}
```

Agregar el import: `use App\ViewModel\PaymentSchedulingAddViewModel;` y `use App\Model\Entity\PaymentScheduling;`.

**Step 3: Actualizar template `add.php`**

Editar `templates/PaymentSchedulings/add.php`. Reemplazar referencias:

- `$record` → `$viewModel->record`
- `$operationCenters` → `$viewModel->operationCenters`

(Buscar todas las apariciones de `$record` y `$operationCenters` y reemplazar.)

**Step 4: Validación manual**

Run: `php bin/cake server`

- Abrir `/payment-schedulings/add` — formulario carga sin errores.
- Crear una programación con name + operation_center seleccionado.
- Tras submit, debe redirigir a `/payment-schedulings/edit/{id}`.

**Step 5: Commit**

```bash
git add src/ViewModel/PaymentSchedulingAddViewModel.php src/Controller/PaymentSchedulingsController.php templates/PaymentSchedulings/add.php
git commit -m "refactor(payment-schedulings): introduce AddViewModel"
```

---

## Task 9: Crear `PaymentSchedulingEditViewModel` e integrar en controller

**Files:**
- Create: `src/ViewModel/PaymentSchedulingEditViewModel.php`
- Modify: `src/Controller/PaymentSchedulingsController.php` (método `edit`)
- Modify: `templates/PaymentSchedulings/edit.php`

**Step 1: Crear ViewModel**

Archivo `src/ViewModel/PaymentSchedulingEditViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PaymentScheduling;

final class PaymentSchedulingEditViewModel
{
    /**
     * @param array<string> $advanceErrors
     * @param array<string, string> $pipelineLabels
     * @param iterable<int|string, string> $bankingEntities
     */
    public function __construct(
        public readonly PaymentScheduling $record,
        public readonly string $roleName,
        public readonly string $currentStatus,
        public readonly bool $canAdvance,
        public readonly bool $canReject,
        public readonly bool $canRegress,
        public readonly ?string $nextStatus,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,
        public readonly array $advanceErrors,
        public readonly float $total,
        public readonly array $pipelineLabels,
        public readonly iterable $bankingEntities,
    ) {
    }
}
```

**Step 2: Refactorizar el método `edit` del controller**

En `src/Controller/PaymentSchedulingsController.php`, reemplazar la implementación de `edit()`:

```php
public function edit($id = null)
{
    $record = $this->PaymentSchedulings->get($id, contain: [
        'CreatedByUsers',
        'PaymentSchedulingItems' => [
            'Invoices' => ['Providers'],
            'BankingEntities',
        ],
        'PaymentSchedulingDocuments' => [
            'UploadedByUsers',
            'sort' => ['PaymentSchedulingDocuments.created' => 'DESC'],
        ],
        'PaymentSchedulingObservations' => [
            'Users',
            'sort' => ['PaymentSchedulingObservations.created' => 'ASC'],
        ],
    ]);

    $roleName = $this->_getRoleName();
    $roleId = (int)$this->_getCurrentUser()->role_id;

    $this->set('viewModel', $this->_buildEditViewModel($record, $roleId, $roleName));
}

private function _buildEditViewModel(
    PaymentScheduling $record,
    int $roleId,
    string $roleName,
): PaymentSchedulingEditViewModel {
    $currentStatus = $record->pipeline_status;
    $canAdvance = $this->schedulingService->canAdvance($roleId, $roleName, $currentStatus);
    $canReject = $this->schedulingService->canReject($roleId, $roleName, $currentStatus);
    $canRegress = $this->schedulingService->canRegress($roleId, $roleName, $currentStatus);

    $advanceErrors = $canAdvance
        ? $this->schedulingService->validateTransitionRequirements($record, $currentStatus)
        : [];

    return new PaymentSchedulingEditViewModel(
        record: $record,
        roleName: $roleName,
        currentStatus: $currentStatus,
        canAdvance: $canAdvance,
        canReject: $canReject,
        canRegress: $canRegress,
        nextStatus: $this->schedulingService->getNextStatus($currentStatus),
        previousStatus: $this->schedulingService->getPreviousStatus($currentStatus),
        regressLockMessage: $this->schedulingService->getRegressionLockMessage($record),
        advanceErrors: $advanceErrors,
        total: $this->schedulingService->calculateTotal($record->id),
        pipelineLabels: PaymentSchedulingConstants::STATUS_LABELS,
        bankingEntities: $this->fetchTable('BankingEntities')->find('list')->all(),
    );
}
```

Agregar imports: `use App\ViewModel\PaymentSchedulingEditViewModel;`.

**Step 3: Actualizar template `edit.php`**

Editar `templates/PaymentSchedulings/edit.php`. Reemplazar las variables que estaban en el `compact()` original por accesos via `$viewModel`. Lista exhaustiva:

- `$record` → `$viewModel->record`
- `$roleName` → `$viewModel->roleName`
- `$currentStatus` → `$viewModel->currentStatus`
- `$canAdvance` → `$viewModel->canAdvance`
- `$canReject` → `$viewModel->canReject`
- `$canRegress` → `$viewModel->canRegress`
- `$nextStatus` → `$viewModel->nextStatus`
- `$previousStatus` → `$viewModel->previousStatus`
- `$regressLockMessage` → `$viewModel->regressLockMessage`
- `$advanceErrors` → `$viewModel->advanceErrors`
- `$total` → `$viewModel->total`
- `$pipelineLabels` → `$viewModel->pipelineLabels`
- `$bankingEntities` → `$viewModel->bankingEntities`

Verificar también que la referencia a documents (después de Task 3) sigue siendo `$viewModel->record->payment_scheduling_documents`.

**Step 4: Validación manual**

Run: `php bin/cake server`

1. Abrir `/payment-schedulings/edit/{id}` para una programación en cada estado del pipeline.
2. Verificar que los botones (Avanzar/Rechazar/Regresar) aparecen/desaparecen según el rol y estado.
3. Verificar que el total se muestra correctamente.
4. Verificar que la lista de items, documentos y observaciones se renderiza.
5. Verificar que las acciones AJAX (subir/eliminar documento) siguen funcionando.

**Step 5: Commit**

```bash
git add src/ViewModel/PaymentSchedulingEditViewModel.php src/Controller/PaymentSchedulingsController.php templates/PaymentSchedulings/edit.php
git commit -m "refactor(payment-schedulings): introduce EditViewModel"
```

---

## Task 10: Validación end-to-end final

Sin commit. Sólo ejecutar el checklist completo y reportar resultados.

**Setup:**

```bash
php bin/cake server
```

Login como rol Tesorería.

**Checklist:**

- [ ] **Listado** (`/payment-schedulings`): renderiza, filtros por code/status funcionan.
- [ ] **Crear programación** (`/payment-schedulings/add`): formulario carga, submit redirige a edit.
- [ ] **Editar programación** (`/payment-schedulings/edit/{id}`): página carga sin errores 500, sección de items + documentos + observaciones se renderiza.
- [ ] **Importar Excel** (Borrador):
  - Subir Excel de Siesa → redirige a preview.
  - Confirmar import → vincula items.
- [ ] **Subir soporte/documento** (cualquier estado ≠ Pagada):
  - Modal "Subir soporte" abre.
  - Subir archivo PDF → aparece en la lista.
  - URL del POST debe ser `/payment-schedulings/upload-document/{id}`.
- [ ] **Eliminar soporte/documento**:
  - Click eliminar → archivo desaparece.
  - URL del POST debe ser `/payment-schedulings/delete-document/{id}/{docId}`.
- [ ] **Avanzar pipeline** Borrador → Tesorería (con al menos un item).
- [ ] **Avanzar Tesorería → Aut. Pago**.
- [ ] **Avanzar Aut. Pago → Pagada**: aplica pagos a las facturas vinculadas. Verificar que `invoice_payments` tiene los nuevos registros con `status = authorized`.
- [ ] **Rechazar desde Aut. Pago** (login como Contador): vuelve a Tesorería con flash warning.
- [ ] **Regresar con motivo** (cualquier estado regresable): se crea observación tipo `regression` con metadata.
- [ ] **Rollback**: `php bin/cake migrations rollback` → tabla vuelve a `payment_scheduling_attachments`. Después `php bin/cake migrations migrate` para volver al estado nuevo.
- [ ] **Code style**: `composer cs-check` → sin errores. Si hay, `composer cs-fix` y commit.

Si todos los checks pasan: el plan está completo. Si alguno falla: revisar el task correspondiente y abrir un commit fix.

**Step final (si todo pasa): merge a main**

```bash
git checkout main
git merge --no-ff refactor/payment-schedulings-canonical-structure
git push origin main
```

Después actualizar `docs/audits/flow-structure-audit-2026-05-06.md` sección "Estado de los planes":

| Plan | Flujo | Estado | Fecha cierre |
|---|---|---|---|
| Plan A | PaymentSchedulings | ✅ Cerrado | 2026-05-DD |

---

## Resumen de commits esperados

1. `chore(payment-schedulings): rename attachments table to documents + extend constants`
2. `refactor(payment-schedulings): rename Attachment entity/table to Document`
3. `refactor(payment-schedulings): rename AttachmentService to DocumentService`
4. `feat(payment-schedulings): add pipeline state interface and registry`
5. `feat(payment-schedulings): add concrete pipeline states`
6. `refactor(payment-schedulings): extract Excel parser to ImportService`
7. `refactor(payment-schedulings): merge PipelineService into main Service with state registry`
8. `refactor(payment-schedulings): introduce AddViewModel`
9. `refactor(payment-schedulings): introduce EditViewModel`

(Task 10 no tiene commit — sólo validación.)
