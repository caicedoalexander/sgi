# Mis Pendientes — Bandeja Unificada Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una vista "Mis Pendientes" que agrega en una sola tabla los ítems de los 8 módulos de flujo cuyo estado actual el rol del usuario puede operar, con enlace + badge en el tope del sidebar.

**Architecture:** Clona el patrón de `Approvals/*` (DTO normalizado + servicio agregador con lógica pura testeable + Presentation/RowView anti-drift + controller `#[NoAuthGate]` + template con paginación manual + ruta). `MyPendingService` replica los `WHERE` "espejo" de `SidebarCounterService` (fetch en vez de count), con estrategia two-track (COUNT para el total, over-fetch acotado para la página). Sin cambios de esquema.

**Tech Stack:** CakePHP 5.3, PHP 8.4, PHPUnit, Sistema de Diseño SPI (`.spi-*`, `.row-fact`, `.pipeline-mini`, `.pill-*-soft`).

## Global Constraints

- PHP `>=8.4`; `declare(strict_types=1)` en todo archivo PHP nuevo.
- Servicios obtienen tablas vía `TableRegistry::getTableLocator()->get('Name')`, nunca `$this->Name`.
- Métodos privados con prefijo `_`.
- Prohibido mapas estado→pill inline en templates: viven en `{Modulo}Presentation` (const). El template consume RowView.
- Slugs de UI en español sin acentos; el módulo `module` del DTO son slugs **internos** de esta feature (no claves de `permissions`).
- `InvoiceConstants::DIAN_REJECTED = 'Rechazado'` ≠ `APPROVAL_REJECTED = 'Rechazada'` (no unificar).
- CRUD `advances` ≠ pipeline `legalizations` (no tocar slugs persistidos).
- Paginación fija 15/página.
- `composer cs-check` verde antes de cada commit; `composer cs-fix` para autofixes.
- Correr tests con `vendor/bin/phpunit` (no `composer test`); baseline verde preexistente ~843 con notices.

---

## File Structure

**Nuevos:**
- `src/Service/Dto/PendingItem.php` — DTO normalizado (contrato de datos).
- `src/Service/MyPendingService.php` — agregador de las 8 fuentes (fetch + count + merge/orden/página).
- `src/View/Presentation/PendingModuleMeta.php` — registry módulo → {label, badge, steps, statusLabels, pills, ruta, mini}.
- `src/View/Presentation/PendingPresentation.php` — `forRow(PendingItem): PendingRowView`.
- `src/View/Presentation/PendingRowView.php` — DTO de fila para el template.
- `src/Controller/PendingController.php` — `index()` con `#[NoAuthGate]`.
- `templates/Pending/index.php` — tabla unificada.
- Tests: `tests/TestCase/Service/MyPendingServiceTest.php`, `tests/TestCase/View/Presentation/PendingPresentationTest.php`, `tests/TestCase/Controller/PendingControllerTest.php`.

**Modificados:**
- `src/Application.php` — registrar `MyPendingService`; agregar `PaymentSchedulingPipelineService` a `SidebarCounterService`.
- `src/Service/SidebarCounterService.php` — `paymentSchedulingsMineCount` + `myPendingTotal`.
- `src/Service/PendingNotificationsService.php` — entrada `legalizations`.
- `config/routes.php` — ruta `/pendientes`.
- `templates/layout/default.php` — enlace tope + badge.

---

## Task 1: DTOs (PendingItem + PendingRowView)

**Files:**
- Create: `src/Service/Dto/PendingItem.php`
- Create: `src/View/Presentation/PendingRowView.php`

**Interfaces:**
- Produces: `PendingItem(string $module, int $entityId, string $code, string $counterparty, string $summary, string $status, \Cake\I18n\DateTime $date)`.
- Produces: `PendingRowView(string $module, string $moduleLabel, string $moduleBadgeClass, int $entityId, string $code, string $counterparty, string $summary, string $statusLabel, string $pillClass, array $pipelineSteps, int $stageIdx, string $pipelineVariant, string $dateLabel, array $route)`.

- [ ] **Step 1: Write `PendingItem`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use Cake\I18n\DateTime;

/**
 * Representación normalizada de un pendiente de cualquier módulo de flujo para
 * la bandeja "Mis Pendientes". Inmutable.
 */
final readonly class PendingItem
{
    /**
     * @param string $module Slug interno del módulo (invoices, advances, legalizations, petty_cash, refunds, novelties, liquidations, payment_schedulings).
     * @param int $entityId Id de la entidad destino del enlace.
     * @param string $code Código/número legible.
     * @param string $counterparty Contraparte (proveedor/empleado/responsable).
     * @param string $summary Resumen (monto formateado o tipo).
     * @param string $status Slug del estado a mostrar del pipeline del módulo.
     * @param \Cake\I18n\DateTime $date Fecha de creación (clave de orden cross-módulo).
     */
    public function __construct(
        public string $module,
        public int $entityId,
        public string $code,
        public string $counterparty,
        public string $summary,
        public string $status,
        public DateTime $date,
    ) {
    }
}
```

- [ ] **Step 2: Write `PendingRowView`**

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable de una fila de Pending/index. Producido por
 * PendingPresentation::forRow(). Toda la derivación de presentación vive ahí;
 * el template no deriva nada inline. `pipelineSteps` vacío ⇒ sin pipeline-mini.
 */
final readonly class PendingRowView
{
    /**
     * @param string $module Slug interno del módulo.
     * @param string $moduleLabel Etiqueta ES del módulo.
     * @param string $moduleBadgeClass Clase pill del módulo.
     * @param int $entityId Id de la entidad destino.
     * @param string $code Código legible.
     * @param string $counterparty Contraparte.
     * @param string $summary Resumen.
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $pillClass Clase pill del estado.
     * @param array<int,string> $pipelineSteps Pasos ordenados del pipeline (vacío = pill-only).
     * @param int $stageIdx Índice del estado actual en los pasos (-1 si no aplica).
     * @param string $pipelineVariant Variante de color de la pipeline-mini.
     * @param string $dateLabel Fecha formateada d/m/Y.
     * @param array $route URL array de CakePHP al detalle del módulo.
     */
    public function __construct(
        public string $module,
        public string $moduleLabel,
        public string $moduleBadgeClass,
        public int $entityId,
        public string $code,
        public string $counterparty,
        public string $summary,
        public string $statusLabel,
        public string $pillClass,
        public array $pipelineSteps,
        public int $stageIdx,
        public string $pipelineVariant,
        public string $dateLabel,
        public array $route,
    ) {
    }
}
```

- [ ] **Step 3: Verify autoload + cs-check**

Run: `composer cs-check -- src/Service/Dto/PendingItem.php src/View/Presentation/PendingRowView.php`
Expected: sin errores de estilo.

- [ ] **Step 4: Commit**

```bash
git add src/Service/Dto/PendingItem.php src/View/Presentation/PendingRowView.php
git commit -m "feat: DTOs PendingItem y PendingRowView para bandeja Mis Pendientes"
```

---

## Task 2: MyPendingService — núcleo puro (merge/orden/búsqueda)

**Files:**
- Create: `src/Service/MyPendingService.php`
- Test: `tests/TestCase/Service/MyPendingServiceTest.php`

**Interfaces:**
- Consumes: `PendingItem` (Task 1).
- Produces: `MyPendingService::sortByDateDesc(PendingItem[]): PendingItem[]` (static, orden fecha desc), `MyPendingService::filterBySearch(PendingItem[], ?string): PendingItem[]` (static, filtra por `code`+`counterparty`). Constantes `PER_PAGE = 15`, `MODULE_SLUGS` (8 slugs en orden).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\Dto\PendingItem;
use App\Service\MyPendingService;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

class MyPendingServiceTest extends TestCase
{
    private function item(string $module, string $code, string $counterparty, string $date): PendingItem
    {
        return new PendingItem(
            module: $module,
            entityId: 1,
            code: $code,
            counterparty: $counterparty,
            summary: '',
            status: 'x',
            date: new DateTime($date),
        );
    }

    public function testSortByDateDescOrdersNewestFirst(): void
    {
        $items = [
            $this->item('invoices', 'A', 'Prov', '2026-01-01'),
            $this->item('refunds', 'B', 'Empl', '2026-03-01'),
            $this->item('petty_cash', 'C', 'Resp', '2026-02-01'),
        ];

        $sorted = MyPendingService::sortByDateDesc($items);

        $this->assertSame(['B', 'C', 'A'], array_map(fn ($i) => $i->code, $sorted));
    }

    public function testFilterBySearchMatchesCodeOrCounterpartyCaseInsensitive(): void
    {
        $items = [
            $this->item('invoices', 'FV-1023', 'Proveedor X', '2026-01-01'),
            $this->item('refunds', 'RC-88', 'María G', '2026-01-01'),
        ];

        $byCode = MyPendingService::filterBySearch($items, 'fv-10');
        $byName = MyPendingService::filterBySearch($items, 'maría');

        $this->assertCount(1, $byCode);
        $this->assertSame('FV-1023', $byCode[0]->code);
        $this->assertCount(1, $byName);
        $this->assertSame('RC-88', $byName[0]->code);
    }

    public function testFilterBySearchEmptyReturnsAll(): void
    {
        $items = [$this->item('invoices', 'A', 'P', '2026-01-01')];
        $this->assertCount(1, MyPendingService::filterBySearch($items, ''));
        $this->assertCount(1, MyPendingService::filterBySearch($items, null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/MyPendingServiceTest.php`
Expected: FAIL con "Class MyPendingService not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Dto\PendingItem;

/**
 * Bandeja "Mis Pendientes": agrega los ítems de los 8 módulos de flujo cuyo
 * estado actual el rol puede operar (espejo de SidebarCounterService), los
 * ordena por fecha desc y pagina. Estrategia two-track: COUNT para el total,
 * over-fetch acotado para la ventana de página (la agregación es por-rol y puede
 * ser grande). La lógica de orden/búsqueda es pura (sin DB) para ser testeable.
 */
class MyPendingService
{
    public const PER_PAGE = 15;

    /** Módulos en orden de dispatch/visualización. */
    public const MODULE_SLUGS = [
        'invoices', 'advances', 'legalizations', 'petty_cash',
        'refunds', 'novelties', 'liquidations', 'payment_schedulings',
    ];

    /**
     * Ordena por fecha descendente (más reciente primero).
     *
     * @param array<\App\Service\Dto\PendingItem> $items
     * @return array<\App\Service\Dto\PendingItem>
     */
    public static function sortByDateDesc(array $items): array
    {
        usort($items, static fn (PendingItem $a, PendingItem $b): int => $b->date <=> $a->date);

        return $items;
    }

    /**
     * Filtra por coincidencia en código + contraparte (case-insensitive).
     *
     * @param array<\App\Service\Dto\PendingItem> $items
     * @return array<\App\Service\Dto\PendingItem>
     */
    public static function filterBySearch(array $items, ?string $search): array
    {
        if ($search === null || trim($search) === '') {
            return array_values($items);
        }
        $needle = mb_strtolower(trim($search));

        return array_values(array_filter($items, static function (PendingItem $i) use ($needle): bool {
            return str_contains(mb_strtolower($i->code . ' ' . $i->counterparty), $needle);
        }));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/MyPendingServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/MyPendingService.php tests/TestCase/Service/MyPendingServiceTest.php
git commit -m "feat: MyPendingService núcleo puro (orden + búsqueda)"
```

---

## Task 3: MyPendingService — fetchers de la familia factura + getPending + DI

**Files:**
- Modify: `src/Service/MyPendingService.php`
- Modify: `src/Application.php:496` (registrar tras `ApprovalInboxService`)
- Test: `tests/TestCase/Service/MyPendingServiceTest.php`

**Interfaces:**
- Consumes: `InvoicePipelineService::getVisibleStatuses(int): array`, `AdvanceLegalizationActionPolicy::getVisibleStatuses(int): array`, `InvoiceBeneficiary::label(object): string`, `InvoiceConstants`, `AdvanceConstants`.
- Produces: `MyPendingService::getPending(int $roleId, ?string $module, ?string $search, int $page): array{items: PendingItem[], total: int, page: int, perPage: int}`. Fetchers privados `_fetchInvoices/_fetchAdvances/_fetchLegalizations` y counts `_countInvoices/...`. Dispatch `_fetch(string,int,int)` / `_count(string,int)`.

- [ ] **Step 1: Write the failing test (normalización de facturas)**

Añadir al final de `MyPendingServiceTest`. Requiere fixtures — declararlas en la clase:

```php
    protected array $fixtures = [
        'app.Roles', 'app.Users', 'app.Permissions', 'app.PipelinePermissions',
        'app.Providers', 'app.Employees', 'app.Invoices', 'app.AdvanceLegalizations',
        'app.PettyCashRecords', 'app.Refunds', 'app.EmployeeNovelties',
        'app.NoveltyTypes', 'app.NoveltyLiquidationDocs', 'app.PaymentSchedulings',
    ];

    private function service(): MyPendingService
    {
        return $this->getContainer()->get(MyPendingService::class);
    }
```

```php
    public function testFetchInvoicesNormalizesToPendingItem(): void
    {
        // Sembrar: un rol con pipeline_permissions que opere 'contabilidad' en
        // facturas, y una factura no-anticipo en ese estado (ver ejemplo en
        // ApprovalInboxServiceTest para el patrón de fixtures/seed).
        [$roleId, $invoiceId] = $this->seedInvoiceInOperableStatus();

        $result = $this->service()->getPending($roleId, 'invoices', null, 1);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $item = $result['items'][0];
        $this->assertSame('invoices', $item->module);
        $this->assertSame($invoiceId, $item->entityId);
        $this->assertNotSame('', $item->code);
    }
```

> **Nota de seed:** `seedInvoiceInOperableStatus()` es un helper del test que
> inserta un Role, una fila `pipeline_permissions` (pipeline `invoices`, step
> `contabilidad`, `can_operate = true`) y una `Invoice` (`document_type` != Anticipo,
> `pipeline_status = 'contabilidad'`). Copiar el estilo de seed de
> `tests/TestCase/Service/SidebarCounterServiceTest.php` si existe, o de
> `ApprovalInboxServiceTest`. Devuelve `[roleId, invoiceId]`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/MyPendingServiceTest.php --filter testFetchInvoices`
Expected: FAIL con "Call to undefined method ...getPending()".

- [ ] **Step 3: Write implementation (fetchers factura + getPending + dispatch)**

Añadir imports al inicio de `MyPendingService.php`:

```php
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\View\Presentation\InvoiceBeneficiary;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use NumberFormatter;
```

Añadir constructor + constante de scan de búsqueda:

```php
    // Búsqueda: escanea hasta N ítems recientes por módulo. El total two-track
    // exacto solo aplica SIN búsqueda; con búsqueda el total puede truncarse a
    // este tope (tradeoff documentado; la búsqueda es de conveniencia).
    private const SEARCH_SCAN_LIMIT = 300;

    /**
     * @param \App\Service\InvoicePipelineService $invoicePipeline
     * @param \App\Service\NoveltyPipelineService $noveltyPipeline
     * @param \App\Service\PettyCashPipelineService $pettyCashService
     * @param \App\Service\RefundPipelineService $refundService
     * @param \App\Service\PaymentSchedulingPipelineService $paymentSchedulingService
     * @param \App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy $legalizationPolicy
     */
    public function __construct(
        private readonly InvoicePipelineService $invoicePipeline,
        private readonly NoveltyPipelineService $noveltyPipeline,
        private readonly PettyCashPipelineService $pettyCashService,
        private readonly RefundPipelineService $refundService,
        private readonly PaymentSchedulingPipelineService $paymentSchedulingService,
        private readonly AdvanceLegalizationActionPolicy $legalizationPolicy,
    ) {
    }
```

`getPending` + dispatch:

```php
    /**
     * Bandeja completa del rol: agrega, filtra, ordena y pagina.
     *
     * @return array{items: array<\App\Service\Dto\PendingItem>, total: int, page: int, perPage: int}
     */
    public function getPending(int $roleId, ?string $module, ?string $search, int $page): array
    {
        $page = max(1, $page);
        $modules = ($module !== null && in_array($module, self::MODULE_SLUGS, true))
            ? [$module]
            : self::MODULE_SLUGS;
        $hasSearch = $search !== null && trim($search) !== '';

        if ($hasSearch) {
            $items = [];
            foreach ($modules as $m) {
                $items = array_merge($items, $this->_fetch($m, $roleId, self::SEARCH_SCAN_LIMIT));
            }
            $items = self::sortByDateDesc(self::filterBySearch($items, $search));
            $total = count($items);
        } else {
            $total = 0;
            foreach ($modules as $m) {
                $total += $this->_count($m, $roleId);
            }
            $fetchLimit = $page * self::PER_PAGE;
            $items = [];
            foreach ($modules as $m) {
                $items = array_merge($items, $this->_fetch($m, $roleId, $fetchLimit));
            }
            $items = self::sortByDateDesc($items);
        }

        $window = array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return ['items' => $window, 'total' => $total, 'page' => $page, 'perPage' => self::PER_PAGE];
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetch(string $module, int $roleId, int $limit): array
    {
        return match ($module) {
            'invoices' => $this->_fetchInvoices($roleId, $limit),
            'advances' => $this->_fetchAdvances($roleId, $limit),
            'legalizations' => $this->_fetchLegalizations($roleId, $limit),
            default => [],
        };
    }

    private function _count(string $module, int $roleId): int
    {
        return match ($module) {
            'invoices' => $this->_countInvoices($roleId),
            'advances' => $this->_countAdvances($roleId),
            'legalizations' => $this->_countLegalizations($roleId),
            default => 0,
        };
    }
```

Fetchers factura (WHERE espejo de `SidebarCounterService`). **Patrón M1:** `_queryX` lleva **solo el WHERE espejo del badge** (sin `contain` de hidratación); `_countX` = `_queryX->count()` (así el count es espejo EXACTO del badge, sin joins de hidratación); `_fetchX` agrega los `contain` antes de iterar. En Legalizaciones el `innerJoinWith` sí vive en `_queryX` porque es un filtro (y el badge también lo tiene):

```php
    private function _queryInvoices(int $roleId): ?SelectQuery
    {
        $statuses = array_values(array_filter(
            $this->invoicePipeline->getVisibleStatuses($roleId),
            static fn (string $s): bool => $s !== InvoiceConstants::STATUS_LEGALIZADA,
        ));
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find('withoutParent')
            ->where([
                'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status IN' => $statuses,
            ]);
    }

    private function _countInvoices(int $roleId): int
    {
        $q = $this->_queryInvoices($roleId);

        return $q === null ? 0 : $q->count();
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetchInvoices(int $roleId, int $limit): array
    {
        $q = $this->_queryInvoices($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['Providers', 'Employees'])->orderBy(['Invoices.created' => 'DESC'])->limit($limit)->all() as $inv) {
            $items[] = new PendingItem(
                module: 'invoices',
                entityId: (int)$inv->id,
                code: (string)($inv->invoice_number ?: '#' . $inv->id),
                counterparty: InvoiceBeneficiary::label($inv),
                summary: self::_formatCurrency((float)$inv->amount),
                status: (string)$inv->pipeline_status,
                date: $inv->created ?? new DateTime(),
            );
        }

        return $items;
    }

    private function _queryAdvances(int $roleId): ?SelectQuery
    {
        $statuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status IN' => $statuses,
            ]);
    }

    private function _countAdvances(int $roleId): int
    {
        $q = $this->_queryAdvances($roleId);

        return $q === null ? 0 : $q->count();
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetchAdvances(int $roleId, int $limit): array
    {
        $q = $this->_queryAdvances($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['Providers', 'Employees'])->orderBy(['Invoices.created' => 'DESC'])->limit($limit)->all() as $inv) {
            $items[] = new PendingItem(
                module: 'advances',
                entityId: (int)$inv->id,
                code: (string)($inv->invoice_number ?: '#' . $inv->id),
                counterparty: InvoiceBeneficiary::label($inv),
                summary: self::_formatCurrency((float)$inv->amount),
                status: (string)$inv->pipeline_status,
                date: $inv->created ?? new DateTime(),
            );
        }

        return $items;
    }

    private function _queryLegalizations(int $roleId): ?SelectQuery
    {
        $steps = $this->legalizationPolicy->getVisibleStatuses($roleId);
        if ($steps === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->innerJoinWith('AdvanceLegalization', static function ($q) use ($steps) {
                return $q->where([
                    'AdvanceLegalization.status IN' => $steps,
                    'AdvanceLegalization.status !=' => AdvanceConstants::STATUS_LEGALIZADA,
                ]);
            });
    }

    private function _countLegalizations(int $roleId): int
    {
        $q = $this->_queryLegalizations($roleId);

        return $q === null ? 0 : $q->count();
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetchLegalizations(int $roleId, int $limit): array
    {
        $q = $this->_queryLegalizations($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['Providers', 'Employees', 'AdvanceLegalization'])->orderBy(['Invoices.created' => 'DESC'])->limit($limit)->all() as $inv) {
            $items[] = new PendingItem(
                module: 'legalizations',
                entityId: (int)$inv->id,
                code: (string)($inv->invoice_number ?: '#' . $inv->id),
                counterparty: InvoiceBeneficiary::label($inv),
                summary: self::_formatCurrency((float)$inv->amount),
                status: (string)($inv->advance_legalization->status ?? ''),
                date: $inv->created ?? new DateTime(),
            );
        }

        return $items;
    }

    private static function _formatCurrency(float $amount): string
    {
        $f = new NumberFormatter('es_CO', NumberFormatter::CURRENCY);
        $f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);

        return $f->formatCurrency($amount, 'COP') ?: '$ ' . number_format($amount, 0, ',', '.');
    }
```

Registrar en el container (`src/Application.php`, tras la línea `$container->addShared(ApprovalInboxService::class);`):

```php
        $container->addShared(MyPendingService::class)
            ->addArguments([
                InvoicePipelineService::class,
                NoveltyPipelineService::class,
                PettyCashPipelineService::class,
                RefundPipelineService::class,
                PaymentSchedulingPipelineService::class,
                AdvanceLegalizationActionPolicy::class,
            ]);
```

Añadir el import en `src/Application.php` si falta: `use App\Service\MyPendingService;` (junto a los demás `use App\Service\...`).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/MyPendingServiceTest.php --filter testFetchInvoices`
Expected: PASS.

- [ ] **Step 5: cs-check + commit**

```bash
composer cs-fix -- src/Service/MyPendingService.php src/Application.php
git add src/Service/MyPendingService.php src/Application.php tests/TestCase/Service/MyPendingServiceTest.php
git commit -m "feat: MyPendingService fetchers factura/anticipo/legalización + getPending + DI"
```

---

## Task 4: MyPendingService — fetchers de los 5 módulos restantes + test de espejo

**Files:**
- Modify: `src/Service/MyPendingService.php`
- Test: `tests/TestCase/Service/MyPendingServiceTest.php`

**Interfaces:**
- Consumes: `PettyCashPipelineService/RefundPipelineService/PaymentSchedulingPipelineService::getVisibleStatuses(int): array`, `NoveltyPipelineService::getVisibleStatuses/getVisibleLiquidationStatuses(int): array`, `NoveltyConstants`.
- Produces: extiende `_fetch`/`_count` con los 5 módulos.

- [ ] **Step 1: Write the failing test (espejo badge==lista)**

Añadir a `MyPendingServiceTest`:

```php
    public function testEspejoConteoIgualBadgePorModulo(): void
    {
        // Sembrar un rol que opere varios pasos en varios módulos, con registros
        // en estados operables (ver helper de seed / SidebarCounterServiceTest).
        $roleId = $this->seedPendingsAcrossModules();

        $counters = $this->getContainer()->get(\App\Service\SidebarCounterService::class)
            ->getCounters($roleId);
        $service = $this->service();

        // Mapa slug de módulo → conteo del badge equivalente. Cubre los 8 módulos:
        // esta es la garantía R2 (badge == lista) del criterio de aceptación #3.
        $expected = [
            'invoices' => (int)array_sum($counters['sidebarCounters'] ?? []),
            'advances' => (int)$counters['advancesMineCount'],
            'legalizations' => (int)$counters['advancesPendingLegalizationCount'],
            'petty_cash' => (int)$counters['pettyCashMineCount'],
            'refunds' => (int)$counters['refundsMineCount'],
            'novelties' => (int)$counters['noveltiesCount'],
            'liquidations' => (int)$counters['liquidationMineCount'],
        ];
        // 'paymentSchedulingsMineCount' se agrega al counter en Task 8; el guard
        // isset() mantiene este test verde antes de Task 8 (cubre 7) y suma el 8º
        // automáticamente una vez que Task 8 lo expone.
        if (isset($counters['paymentSchedulingsMineCount'])) {
            $expected['payment_schedulings'] = (int)$counters['paymentSchedulingsMineCount'];
        }

        foreach ($expected as $slug => $badge) {
            $listTotal = $service->getPending($roleId, $slug, null, 1)['total'];
            $this->assertSame(
                $badge,
                $listTotal,
                "Espejo roto en módulo {$slug}: lista {$listTotal} != badge {$badge}",
            );
        }
    }
```

> **Nota:** `SidebarCounterService::getCounters` cachea (`Cache::remember`, grupo
> `sidebar`). En el test, limpiar con `\Cake\Cache\Cache::clear('sidebar')` en
> `setUp()` para evitar contaminación entre casos.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/MyPendingServiceTest.php --filter testEspejo`
Expected: FAIL (total = 0 porque los módulos aún caen en `default => []`).

- [ ] **Step 3: Write implementation (5 fetchers + extender dispatch)**

Añadir import: `use App\Constants\NoveltyConstants;`

Extender `_fetch` y `_count` con los nuevos casos (reemplazar los `match`):

```php
    private function _fetch(string $module, int $roleId, int $limit): array
    {
        return match ($module) {
            'invoices' => $this->_fetchInvoices($roleId, $limit),
            'advances' => $this->_fetchAdvances($roleId, $limit),
            'legalizations' => $this->_fetchLegalizations($roleId, $limit),
            'petty_cash' => $this->_fetchPettyCash($roleId, $limit),
            'refunds' => $this->_fetchRefunds($roleId, $limit),
            'novelties' => $this->_fetchNovelties($roleId, $limit),
            'liquidations' => $this->_fetchLiquidations($roleId, $limit),
            'payment_schedulings' => $this->_fetchPaymentSchedulings($roleId, $limit),
            default => [],
        };
    }

    private function _count(string $module, int $roleId): int
    {
        return match ($module) {
            'invoices' => $this->_countInvoices($roleId),
            'advances' => $this->_countAdvances($roleId),
            'legalizations' => $this->_countLegalizations($roleId),
            'petty_cash' => $this->_countPettyCash($roleId),
            'refunds' => $this->_countRefunds($roleId),
            'novelties' => $this->_countNovelties($roleId),
            'liquidations' => $this->_countLiquidations($roleId),
            'payment_schedulings' => $this->_countPaymentSchedulings($roleId),
            default => 0,
        };
    }
```

Fetchers restantes (mismo **patrón M1**: `_queryX` solo el WHERE espejo sin `contain`; `_countX = _queryX->count()`; `_fetchX` agrega el `contain` de hidratación antes de iterar):

```php
    private function _queryPettyCash(int $roleId): ?SelectQuery
    {
        $statuses = $this->pettyCashService->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('PettyCashRecords')->find()
            ->where(['PettyCashRecords.status IN' => $statuses]);
    }

    private function _countPettyCash(int $roleId): int
    {
        $q = $this->_queryPettyCash($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchPettyCash(int $roleId, int $limit): array
    {
        $q = $this->_queryPettyCash($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['CreatedByUsers'])->orderBy(['PettyCashRecords.created' => 'DESC'])->limit($limit)->all() as $r) {
            $items[] = new PendingItem(
                module: 'petty_cash',
                entityId: (int)$r->id,
                code: (string)($r->code ?: '#' . $r->id),
                counterparty: (string)($r->created_by_user->full_name ?? '—'),
                summary: self::_formatCurrency((float)$r->total_amount),
                status: (string)$r->status,
                date: $r->created ?? new DateTime(),
            );
        }

        return $items;
    }

    private function _queryRefunds(int $roleId): ?SelectQuery
    {
        $statuses = $this->refundService->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Refunds')->find()
            ->where(['Refunds.status IN' => $statuses]);
    }

    private function _countRefunds(int $roleId): int
    {
        $q = $this->_queryRefunds($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchRefunds(int $roleId, int $limit): array
    {
        $q = $this->_queryRefunds($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['CreatedByUsers'])->orderBy(['Refunds.created' => 'DESC'])->limit($limit)->all() as $r) {
            $items[] = new PendingItem(
                module: 'refunds',
                entityId: (int)$r->id,
                code: (string)($r->code ?: '#' . $r->id),
                counterparty: (string)($r->created_by_user->full_name ?? '—'),
                summary: self::_formatCurrency((float)$r->total_amount),
                status: (string)$r->status,
                date: $r->created ?? new DateTime(),
            );
        }

        return $items;
    }

    private function _queryNovelties(int $roleId): ?SelectQuery
    {
        $statuses = $this->noveltyPipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('EmployeeNovelties')->find()
            ->where([
                'EmployeeNovelties.pipeline_status IN' => $statuses,
                'EmployeeNovelties.pipeline_status !=' => NoveltyConstants::STATUS_RECHAZADA,
            ])
            ->where(static function ($exp) {
                return $exp->or([
                    'EmployeeNovelties.pipeline_status !=' => NoveltyConstants::STATUS_CONTABILIDAD,
                    'EmployeeNovelties.liquidation_doc_id IS' => null,
                ]);
            });
    }

    private function _countNovelties(int $roleId): int
    {
        $q = $this->_queryNovelties($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchNovelties(int $roleId, int $limit): array
    {
        $q = $this->_queryNovelties($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['Employees', 'NoveltyTypes'])->orderBy(['EmployeeNovelties.created' => 'DESC'])->limit($limit)->all() as $n) {
            $items[] = new PendingItem(
                module: 'novelties',
                entityId: (int)$n->id,
                code: 'NV-' . str_pad((string)$n->id, 4, '0', STR_PAD_LEFT),
                counterparty: (string)($n->custom_name ?: ($n->employee->full_name ?? '—')),
                summary: (string)($n->novelty_type->name ?? '—'),
                status: (string)$n->pipeline_status,
                date: $n->created ?? new DateTime(),
            );
        }

        return $items;
    }

    private function _queryLiquidations(int $roleId): ?SelectQuery
    {
        $statuses = $this->noveltyPipeline->getVisibleLiquidationStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs')->find()
            ->where(['NoveltyLiquidationDocs.pipeline_status IN' => $statuses]);
    }

    private function _countLiquidations(int $roleId): int
    {
        $q = $this->_queryLiquidations($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchLiquidations(int $roleId, int $limit): array
    {
        $q = $this->_queryLiquidations($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['PerformedByUsers'])->orderBy(['NoveltyLiquidationDocs.created' => 'DESC'])->limit($limit)->all() as $d) {
            $items[] = new PendingItem(
                module: 'liquidations',
                entityId: (int)$d->id,
                code: 'LQ-' . str_pad((string)$d->id, 4, '0', STR_PAD_LEFT),
                counterparty: (string)($d->performed_by_user->full_name ?? '—'),
                summary: '—',
                status: (string)$d->pipeline_status,
                date: $d->created ?? new DateTime(),
            );
        }

        return $items;
    }

    private function _queryPaymentSchedulings(int $roleId): ?SelectQuery
    {
        $statuses = $this->paymentSchedulingService->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('PaymentSchedulings')->find()
            ->where(['PaymentSchedulings.pipeline_status IN' => $statuses]);
    }

    private function _countPaymentSchedulings(int $roleId): int
    {
        $q = $this->_queryPaymentSchedulings($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchPaymentSchedulings(int $roleId, int $limit): array
    {
        $q = $this->_queryPaymentSchedulings($roleId);
        if ($q === null) {
            return [];
        }
        $items = [];
        foreach ($q->contain(['CreatedByUsers'])->orderBy(['PaymentSchedulings.created' => 'DESC'])->limit($limit)->all() as $p) {
            $items[] = new PendingItem(
                module: 'payment_schedulings',
                entityId: (int)$p->id,
                code: (string)($p->code ?: '#' . $p->id),
                counterparty: (string)($p->created_by_user->full_name ?? '—'),
                summary: '—',
                status: (string)$p->pipeline_status,
                date: $p->created ?? new DateTime(),
            );
        }

        return $items;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/MyPendingServiceTest.php`
Expected: PASS (todos).

- [ ] **Step 5: cs-check + commit**

```bash
composer cs-fix -- src/Service/MyPendingService.php
git add src/Service/MyPendingService.php tests/TestCase/Service/MyPendingServiceTest.php
git commit -m "feat: MyPendingService fetchers caja/reintegros/novedades/liquidaciones/programaciones + test espejo"
```

---

## Task 5: PendingModuleMeta + PendingPresentation::forRow

**Files:**
- Create: `src/View/Presentation/PendingModuleMeta.php`
- Create: `src/View/Presentation/PendingPresentation.php`
- Test: `tests/TestCase/View/Presentation/PendingPresentationTest.php`

**Interfaces:**
- Consumes: `PendingItem` (Task 1), `PendingRowView` (Task 1), `InvoiceConstants`, `AdvanceConstants`, `PettyCashConstants`, `RefundConstants`, `PaymentSchedulingConstants`, `NoveltyConstants` y las `*Presentation::STATUS_BADGES`, `PipelineColorMap::variant`.
- Produces: `PendingModuleMeta::MODULES` (const), `PendingPresentation::forRow(PendingItem): PendingRowView`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Service\Dto\PendingItem;
use App\View\Presentation\PendingPresentation;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

class PendingPresentationTest extends TestCase
{
    private function item(string $module, string $status): PendingItem
    {
        return new PendingItem(
            module: $module,
            entityId: 7,
            code: 'X-1',
            counterparty: 'Contraparte',
            summary: '$ 100',
            status: $status,
            date: new DateTime('2026-07-20'),
        );
    }

    public function testInvoiceRowHasPipelineMiniWithCorrectStage(): void
    {
        $row = PendingPresentation::forRow($this->item('invoices', InvoiceConstants::STATUS_TESORERIA));

        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $row->pipelineSteps);
        $this->assertSame(
            array_search(InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::PIPELINE_STATUSES, true),
            $row->stageIdx,
        );
        $this->assertSame('Factura', $row->moduleLabel);
        $this->assertSame('2026-07-20', (new DateTime('2026-07-20'))->format('Y-m-d'));
        $this->assertSame(['controller' => 'Invoices', 'action' => 'edit', 7], $row->route);
    }

    public function testAdvanceUsesInvoiceStepSetNotAdvance(): void
    {
        $row = PendingPresentation::forRow($this->item('advances', InvoiceConstants::STATUS_CONTABILIDAD));

        // Anticipo = Invoice: sus pasos son los de facturas, NO los de legalización.
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $row->pipelineSteps);
        $this->assertSame('Anticipo', $row->moduleLabel);
    }

    public function testNoveltyIsPillOnly(): void
    {
        $row = PendingPresentation::forRow($this->item('novelties', NoveltyConstants::STATUS_CONTABILIDAD));

        $this->assertSame([], $row->pipelineSteps);
        $this->assertSame(-1, $row->stageIdx);
        $this->assertSame('', $row->pipelineVariant);
        $this->assertSame('Novedad', $row->moduleLabel);
    }

    public function testUnknownStatusFallsBackToMutedPill(): void
    {
        $row = PendingPresentation::forRow($this->item('invoices', 'estado_inexistente'));

        $this->assertSame('pill-muted', $row->pillClass);
        $this->assertSame(-1, $row->stageIdx);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/View/Presentation/PendingPresentationTest.php`
Expected: FAIL con "Class PendingPresentation not found".

- [ ] **Step 3: Write `PendingModuleMeta`**

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;

/**
 * Registry único módulo → metadatos de presentación de la bandeja Mis Pendientes.
 * Fuente anti-drift: reusa los step-sets ordenados y los mapas estado→pill de
 * cada módulo, no los redeclara.
 *
 * ⚠️ Anticipos usa el step-set/pill de FACTURAS (InvoiceConstants/InvoicePresentation),
 * NO AdvancePresentation (que está keyed por estados de legalización).
 */
final class PendingModuleMeta
{
    /**
     * @var array<string, array{label:string, moduleBadge:string, controller:string, action:string, mini:bool, steps:array<int,string>, statusLabels:array<string,string>, pills:array<string,string>}>
     */
    public const MODULES = [
        'invoices' => [
            'label' => 'Factura', 'moduleBadge' => 'pill-info-soft',
            'controller' => 'Invoices', 'action' => 'edit', 'mini' => true,
            'steps' => InvoiceConstants::PIPELINE_STATUSES,
            'statusLabels' => InvoiceConstants::STATUS_LABELS,
            'pills' => InvoicePresentation::STATUS_BADGES,
        ],
        'advances' => [
            'label' => 'Anticipo', 'moduleBadge' => 'pill-accent-soft',
            'controller' => 'Advances', 'action' => 'edit', 'mini' => true,
            'steps' => InvoiceConstants::PIPELINE_STATUSES,
            'statusLabels' => InvoiceConstants::STATUS_LABELS,
            'pills' => InvoicePresentation::STATUS_BADGES,
        ],
        'legalizations' => [
            'label' => 'Legalización', 'moduleBadge' => 'pill-primary-soft',
            'controller' => 'Advances', 'action' => 'legalization', 'mini' => true,
            'steps' => AdvanceConstants::PIPELINE_STATUSES,
            'statusLabels' => AdvanceConstants::STATUS_LABELS,
            'pills' => AdvancePresentation::STATUS_BADGES,
        ],
        'petty_cash' => [
            'label' => 'Caja Menor', 'moduleBadge' => 'pill-orange-soft',
            'controller' => 'PettyCashRecords', 'action' => 'edit', 'mini' => true,
            'steps' => PettyCashConstants::STATUSES,
            'statusLabels' => PettyCashConstants::STATUS_LABELS,
            'pills' => PettyCashPresentation::STATUS_BADGES,
        ],
        'refunds' => [
            'label' => 'Reintegro', 'moduleBadge' => 'pill-warning-soft',
            'controller' => 'Refunds', 'action' => 'edit', 'mini' => true,
            'steps' => RefundConstants::STATUSES,
            'statusLabels' => RefundConstants::STATUS_LABELS,
            'pills' => RefundPresentation::STATUS_BADGES,
        ],
        'novelties' => [
            'label' => 'Novedad', 'moduleBadge' => 'pill-muted',
            'controller' => 'EmployeeNovelties', 'action' => 'edit', 'mini' => false,
            'steps' => [],
            'statusLabels' => NoveltyConstants::STATUS_LABELS,
            'pills' => NoveltyPresentation::STATUS_BADGES,
        ],
        'liquidations' => [
            'label' => 'Liquidación', 'moduleBadge' => 'pill-muted',
            'controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', 'mini' => false,
            'steps' => [],
            'statusLabels' => NoveltyConstants::STATUS_LABELS,
            'pills' => NoveltyPresentation::STATUS_BADGES,
        ],
        'payment_schedulings' => [
            'label' => 'Prog. Pago', 'moduleBadge' => 'pill-dark',
            'controller' => 'PaymentSchedulings', 'action' => 'edit', 'mini' => true,
            'steps' => PaymentSchedulingConstants::PIPELINE_STATUSES,
            'statusLabels' => PaymentSchedulingConstants::STATUS_LABELS,
            'pills' => PaymentSchedulingPresentation::STATUS_BADGES,
        ],
    ];
}
```

> **Nota de verificación:** confirmar que existen `RefundConstants::STATUS_LABELS`,
> `PaymentSchedulingConstants::STATUS_LABELS`, `RefundPresentation::STATUS_BADGES`,
> `PaymentSchedulingPresentation::STATUS_BADGES`. Están confirmados
> `InvoicePresentation::STATUS_BADGES`, `AdvancePresentation::STATUS_BADGES`,
> `PettyCashPresentation::STATUS_BADGES`, `NoveltyPresentation::STATUS_BADGES`,
> `InvoiceConstants::PIPELINE_STATUSES/STATUS_LABELS`, `AdvanceConstants::PIPELINE_STATUSES/STATUS_LABELS`,
> `PettyCashConstants::STATUSES/STATUS_LABELS`, `RefundConstants::STATUSES`,
> `PaymentSchedulingConstants::PIPELINE_STATUSES`, `NoveltyConstants::STATUS_LABELS`.

- [ ] **Step 4: Write `PendingPresentation`**

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Service\Dto\PendingItem;

/**
 * Diccionario de presentación de la bandeja Mis Pendientes. Único punto de
 * derivación de fila: pill+label de módulo, pipeline-mini (solo módulos `mini`),
 * pill+label de estado, ruta. Anti-drift: consume PendingModuleMeta, no redeclara
 * mapas.
 */
final class PendingPresentation
{
    /**
     * Construye el DTO de fila para Pending/index.
     */
    public static function forRow(PendingItem $item): PendingRowView
    {
        $meta = PendingModuleMeta::MODULES[$item->module] ?? [];
        $mini = (bool)($meta['mini'] ?? false);
        $steps = $mini ? ($meta['steps'] ?? []) : [];

        $stageIdx = -1;
        if ($steps !== []) {
            $found = array_search($item->status, $steps, true);
            $stageIdx = $found === false ? -1 : (int)$found;
        }

        $statusLabels = $meta['statusLabels'] ?? [];
        $pills = $meta['pills'] ?? [];

        return new PendingRowView(
            module: $item->module,
            moduleLabel: (string)($meta['label'] ?? $item->module),
            moduleBadgeClass: (string)($meta['moduleBadge'] ?? 'pill-muted'),
            entityId: $item->entityId,
            code: $item->code,
            counterparty: $item->counterparty,
            summary: $item->summary,
            statusLabel: (string)($statusLabels[$item->status] ?? $item->status),
            pillClass: (string)($pills[$item->status] ?? 'pill-muted'),
            pipelineSteps: $steps,
            stageIdx: $stageIdx,
            pipelineVariant: $mini ? PipelineColorMap::variant($item->status) : '',
            dateLabel: $item->date->format('d/m/Y'),
            route: [
                'controller' => (string)($meta['controller'] ?? 'Dashboard'),
                'action' => (string)($meta['action'] ?? 'index'),
                $item->entityId,
            ],
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/View/Presentation/PendingPresentationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: cs-check + commit**

```bash
composer cs-fix -- src/View/Presentation/PendingModuleMeta.php src/View/Presentation/PendingPresentation.php
git add src/View/Presentation/PendingModuleMeta.php src/View/Presentation/PendingPresentation.php tests/TestCase/View/Presentation/PendingPresentationTest.php
git commit -m "feat: PendingModuleMeta + PendingPresentation (pipeline-mini 6 módulos, pill-only novedades/liquidaciones)"
```

---

## Task 6: PendingController + ruta

**Files:**
- Create: `src/Controller/PendingController.php`
- Modify: `config/routes.php:662` (antes de `$builder->fallbacks();`)
- Test: `tests/TestCase/Controller/PendingControllerTest.php`

**Interfaces:**
- Consumes: `MyPendingService::getPending(...)` (Tasks 3-4), `PendingPresentation::forRow` (Task 5), `MyPendingService::MODULE_SLUGS`.
- Produces: acción `GET /pendientes` → `Pending::index`, set de `rows`, `total`, `page`, `perPage`, `activeModule`, `search`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class PendingControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Roles', 'app.Users', 'app.Permissions', 'app.PipelinePermissions',
        'app.Providers', 'app.Employees', 'app.Invoices',
    ];

    public function testIndexRendersForAnyAuthenticatedUser(): void
    {
        // Loguear un usuario con un rol SIN permiso del módulo 'pending' (no existe):
        // debe entrar igual (NoAuthGate). Copiar el helper de login de
        // ApprovalsControllerTest / otros ControllerTest.
        $this->loginAsRole('Tesorería');

        $this->get('/pendientes');

        $this->assertResponseOk();
        $this->assertResponseContains('Mis Pendientes');
    }

    public function testIndexAcceptsModuleFilter(): void
    {
        $this->loginAsRole('Tesorería');

        $this->get('/pendientes?module=invoices');

        $this->assertResponseOk();
    }
}
```

> **Nota:** `loginAsRole()` es el helper de sesión ya usado en los ControllerTest
> del repo (setea la identidad autenticada). Reusar el existente.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PendingControllerTest.php`
Expected: FAIL (404 o "controller not found").

- [ ] **Step 3: Write the controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Service\MyPendingService;
use App\View\Presentation\PendingPresentation;

/**
 * Bandeja personal "Mis Pendientes": agrega los ítems de los 8 módulos de flujo
 * cuyo estado actual el rol del usuario puede operar. Vista derivada de permisos
 * ya existentes (cada fila viene filtrada por lo que el rol opera), de ahí
 * #[NoAuthGate] y la ausencia de entrada en $controllerModuleMap.
 */
class PendingController extends AppController
{
    private MyPendingService $pendingService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pendingService = $this->getContainer()->get(MyPendingService::class);
    }

    #[NoAuthGate(reason: 'Vista personal derivada de permisos ya existentes; cada fila ya está filtrada por lo que el rol opera')]
    public function index(): void
    {
        $roleId = (int)$this->Authentication->getIdentity()->getOriginalData()->role_id;

        $moduleQuery = $this->request->getQuery('module');
        $module = in_array($moduleQuery, MyPendingService::MODULE_SLUGS, true) ? $moduleQuery : null;
        $search = $this->request->getQuery('q');
        $page = max(1, (int)$this->request->getQuery('page'));

        $result = $this->pendingService->getPending($roleId, $module, $search, $page);

        $this->set([
            'rows' => array_map(
                [PendingPresentation::class, 'forRow'],
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'activeModule' => $module,
            'search' => $search,
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

En `config/routes.php`, justo antes de `$builder->fallbacks();` (línea ~662):

```php
        // Bandeja personal "Mis Pendientes" (acceso autenticado; filtrado por rol).
        $builder->connect(
            '/pendientes',
            ['controller' => 'Pending', 'action' => 'index'],
        );
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PendingControllerTest.php`
Expected: PASS (2 tests). Requiere Task 7 (template) para que `assertResponseContains('Mis Pendientes')` pase — si Task 7 aún no está, crear un template mínimo o ejecutar este test tras Task 7.

> **Orden:** si se ejecuta subagente-por-tarea, mover `assertResponseContains`
> a después de Task 7, o crear el template en esta tarea. Recomendado: crear
> `templates/Pending/index.php` completo en Task 7 y correr este test al cierre
> de Task 7.

- [ ] **Step 6: cs-check + commit**

```bash
composer cs-fix -- src/Controller/PendingController.php config/routes.php
git add src/Controller/PendingController.php config/routes.php tests/TestCase/Controller/PendingControllerTest.php
git commit -m "feat: PendingController #[NoAuthGate] + ruta /pendientes"
```

---

## Task 7: templates/Pending/index.php

**Files:**
- Create: `templates/Pending/index.php`

**Interfaces:**
- Consumes: `PendingRowView[] $rows`, `int $total`, `int $page`, `int $perPage`, `?string $activeModule`, `?string $search`; `MyPendingService::MODULE_SLUGS` para las chips.

- [ ] **Step 1: Write the template**

```php
<?php
/**
 * Bandeja "Mis Pendientes" — tabla unificada cross-módulo.
 *
 * @var \App\View\AppView $this
 * @var \App\View\Presentation\PendingRowView[] $rows
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string|null $activeModule
 * @var string|null $search
 */
use App\View\Presentation\PendingModuleMeta;

$this->assign('title', 'Mis Pendientes');

$activeModule = $activeModule ?? '';
$search       = $search ?? '';

/* Chips de módulo: [slug => label] desde el registry (fuente única). */
$moduleChips = ['' => 'Todos'];
foreach (PendingModuleMeta::MODULES as $slug => $meta) {
    $moduleChips[$slug] = $meta['label'];
}

$gridStyle = 'display:grid;grid-template-columns:1fr 2.2fr 1.4fr 1.9fr 1fr 28px;gap:14px;align-items:center;';
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            Mis Pendientes
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            <?= (int)$total ?> <?= $total === 1 ? 'pendiente' : 'pendientes' ?>
        </div>
    </div>
</div>

<?php /* ════════════════════════ BUSCADOR ════════════════════════ */ ?>
<?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'index']]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q"
               value="<?= h($search) ?>"
               placeholder="Buscar por código o contraparte…"
               aria-label="Buscar pendientes">
        <?php if ($search !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => 'index', '?' => array_filter(['module' => $activeModule ?: null])],
                ['escape' => false,
                 'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                 'title'  => 'Limpiar búsqueda']
            ) ?>
        <?php endif; ?>
    </label>
    <button type="submit" class="btn btn-default">
        <i class="bi bi-search" aria-hidden="true"></i><span>Buscar</span>
    </button>
</div>
<?php if ($activeModule !== ''): ?>
    <input type="hidden" name="module" value="<?= h($activeModule) ?>">
<?php endif; ?>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR MÓDULO ════════════════════════ */ ?>
<div class="d-flex flex-wrap" style="gap:4px;margin-bottom:14px;" role="tablist" aria-label="Filtrar por módulo">
    <?php foreach ($moduleChips as $slug => $label):
        $isActive = $activeModule === $slug;
    ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:var(--primary-color);"></span>' : '') . h($label),
            ['action' => 'index', '?' => array_filter([
                'module' => $slug ?: null,
                'q'      => $search ?: null,
            ])],
            [
                'class'         => 'chip' . ($isActive ? ' is-active' : ''),
                'escape'        => false,
                'role'          => 'tab',
                'aria-selected' => $isActive ? 'true' : 'false',
                'style'         => $isActive ? 'color:var(--primary-color);' : '',
            ]
        ) ?>
    <?php endforeach; ?>
</div>

<?php /* ════════════════════════ TABLA ════════════════════════ */ ?>
<div class="spi-card" style="padding:0;">
    <?php if (!empty($rows)): ?>
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Módulo</span>
        <span>Código · Contraparte</span>
        <span>Resumen</span>
        <span>Estado · Pipeline</span>
        <span>Fecha</span>
        <span aria-hidden="true"></span>
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $row):
        $href = $this->Url->build($row->route);
    ?>
        <a href="<?= h($href) ?>" role="row" class="row-fact" style="<?= $gridStyle ?>padding:14px 18px;">
            <?php /* 1. Módulo */ ?>
            <div>
                <span class="pill <?= h($row->moduleBadgeClass) ?> pill-sm">
                    <?= h(strtoupper($row->moduleLabel)) ?>
                </span>
            </div>

            <?php /* 2. Código + contraparte */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($row->code) ?>
                </div>
                <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($row->counterparty) ?>
                </div>
            </div>

            <?php /* 3. Resumen */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($row->summary ?: '—') ?>
            </div>

            <?php /* 4. Estado · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($row->pipelineSteps !== [] && $row->stageIdx >= 0): ?>
                    <div class="pipeline-mini <?= h($row->pipelineVariant) ?>" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0, $n = count($row->pipelineSteps); $s < $n; $s++): ?>
                            <div class="<?= $s <= $row->stageIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
                <span class="pill <?= h($row->pillClass) ?> pill-sm">
                    <?= h(strtoupper($row->statusLabel)) ?>
                </span>
            </div>

            <?php /* 5. Fecha */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-faint);">
                <?= h($row->dateLabel) ?>
            </div>

            <?php /* 6. Chevron */ ?>
            <div style="display:flex;justify-content:flex-end;align-items:center;color:var(--text-faint);">
                <i class="bi bi-chevron-right" style="font-size:14px;" aria-hidden="true"></i>
            </div>
        </a>
    <?php endforeach; ?>

    <?php if (empty($rows)): ?>
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
            <div class="es-title">No tienes pendientes</div>
            <div class="es-msg">Aquí aparecerán los ítems de todos los módulos que requieren tu acción.</div>
        </div>
    <?php endif; ?>

    <?php /* ════════════════════════ PAGINACIÓN INLINE ════════════════════════ */
    if (!empty($rows) && $total > $perPage):
        $totalPages = (int)ceil($total / $perPage);
        $queryBase  = array_filter(['module' => $activeModule ?: null, 'q' => $search ?: null]);
        $pageUrl = function (int $p) use ($queryBase): string {
            return $this->Url->build(['action' => 'index', '?' => $queryBase + ['page' => $p]]);
        };
        $prevDisabled = $page <= 1;
        $nextDisabled = $page >= $totalPages;
    ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small style="font-size:11px;color:var(--text-faint);">
            Mostrando <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $total) ?> de <?= $total ?>
        </small>
        <div class="pgn">
            <?php if ($prevDisabled): ?>
                <span class="pgn-btn disabled"><i class="bi bi-chevron-left"></i></span>
            <?php else: ?>
                <a class="pgn-btn" href="<?= h($pageUrl($page - 1)) ?>"><i class="bi bi-chevron-left"></i></a>
            <?php endif; ?>
            <?php
            for ($p = 1; $p <= $totalPages; $p++):
                if ($p === $page): ?>
                    <span class="pgn-btn active"><?= $p ?></span>
                <?php else: ?>
                    <a class="pgn-btn" href="<?= h($pageUrl($p)) ?>"><?= $p ?></a>
                <?php endif;
            endfor; ?>
            <?php if ($nextDisabled): ?>
                <span class="pgn-btn disabled"><i class="bi bi-chevron-right"></i></span>
            <?php else: ?>
                <a class="pgn-btn" href="<?= h($pageUrl($page + 1)) ?>"><i class="bi bi-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
```

- [ ] **Step 2: Run controller test (ahora con template)**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PendingControllerTest.php`
Expected: PASS (`assertResponseContains('Mis Pendientes')` OK).

- [ ] **Step 3: Smoke manual**

Run: `php bin/cake server` y navegar a `/pendientes` con un usuario de rol operativo (p.ej. Tesorería). Verificar: filas con pipeline-mini en facturas/caja/reintegros, pill-only en novedades/liquidaciones, chips de módulo, paginación.

- [ ] **Step 4: Commit**

```bash
git add templates/Pending/index.php
git commit -m "feat: template Pending/index (tabla unificada Mis Pendientes)"
```

---

## Task 8: SidebarCounterService — paymentSchedulingsMineCount + myPendingTotal

**Files:**
- Modify: `src/Service/SidebarCounterService.php`
- Modify: `src/Application.php:481-488` (agregar arg a `SidebarCounterService`)
- Test: `tests/TestCase/Service/SidebarCounterServiceTest.php` (crear si no existe)

**Interfaces:**
- Consumes: `PaymentSchedulingPipelineService::getVisibleStatuses(int): array`.
- Produces: claves nuevas en `getCounters()`: `paymentSchedulingsMineCount` (int), `myPendingTotal` (int).

- [ ] **Step 1: Write the failing test**

```php
    public function testMyPendingTotalSumsTheEightMineCounts(): void
    {
        // Rol sin permisos: todos los "mine" = 0 → total 0.
        $counters = $this->getContainer()->get(\App\Service\SidebarCounterService::class)
            ->getCounters($this->roleWithNoPermissions());

        $this->assertArrayHasKey('myPendingTotal', $counters);
        $this->assertArrayHasKey('paymentSchedulingsMineCount', $counters);
        $this->assertSame(0, $counters['myPendingTotal']);
    }
```

> Si no existe `SidebarCounterServiceTest`, crearlo con `use IntegrationTestTrait`
> no es necesario (es un service test); declarar fixtures de las tablas de flujo +
> Roles/Permissions/PipelinePermissions y limpiar `Cache::clear('sidebar')` en setUp.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/SidebarCounterServiceTest.php --filter testMyPendingTotal`
Expected: FAIL con "Failed asserting that array has the key 'myPendingTotal'".

- [ ] **Step 3: Modify `SidebarCounterService`**

Añadir el import y el 6º parámetro de constructor:

```php
use App\Service\PaymentSchedulingPipelineService;
```

Constructor (agregar el parámetro al final):

```php
    public function __construct(
        private readonly InvoicePipelineService $invoicePipeline,
        private readonly NoveltyPipelineService $noveltyPipeline,
        private readonly PettyCashPipelineService $pettyCashService,
        private readonly RefundPipelineService $refundService,
        private readonly AdvanceLegalizationActionPolicy $legalizationPolicy,
        private readonly PaymentSchedulingPipelineService $paymentSchedulingService,
    ) {
        $this->logger = new StructuredLogger('Sidebar');
    }
```

Reescribir `_buildCounters` para capturar locales y calcular el total (reemplaza el método existente):

```php
    private function _buildCounters(int $roleId): array
    {
        $sidebarCounters = $this->getInvoiceStatusCounters($roleId);
        $advancesMine = $this->getAdvancesMineCount($roleId);
        $advancesPendingLegalization = $this->getAdvancesPendingLegalizationCount($roleId);
        $pettyCashMine = $this->getPettyCashMineCount($roleId);
        $refundsMine = $this->getRefundsMineCount($roleId);
        $noveltiesMine = $this->getNoveltiesCount($roleId);
        $liquidationMine = $this->getLiquidationMineCount($roleId);
        $paymentSchedulingsMine = $this->getPaymentSchedulingsMineCount($roleId);

        $myPendingTotal = (int)array_sum($sidebarCounters)
            + $advancesMine + $advancesPendingLegalization + $pettyCashMine
            + $refundsMine + $noveltiesMine + $liquidationMine + $paymentSchedulingsMine;

        return [
            'sidebarCounters' => $sidebarCounters,
            'totalInvoicesCount' => $this->getCount(
                'Invoices',
                ['document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO],
            ),
            'rejectedInvoicesCount' => $this->getRejectedInvoicesCount($roleId),
            'overdueInvoicesCount' => $this->getOverdueInvoicesCount($roleId),
            'pettyCashCount' => $this->getCount(
                'PettyCashRecords',
                ['status !=' => PettyCashConstants::STATUS_PAGADA],
            ),
            'pettyCashMineCount' => $pettyCashMine,
            'refundsCount' => $this->getCount(
                'Refunds',
                ['status !=' => RefundConstants::STATUS_PAGADA],
            ),
            'refundsMineCount' => $refundsMine,
            'advancesMineCount' => $advancesMine,
            'noveltiesCount' => $noveltiesMine,
            'rejectedNoveltiesCount' => $this->getCount(
                'EmployeeNovelties',
                ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
            ),
            'activeNoveltiesCount' => $this->getActiveNoveltiesCount(),
            'liquidationMineCount' => $liquidationMine,
            'liquidationRejectedCount' => $this->getCount(
                'NoveltyLiquidationDocs',
                ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
            ),
            'advancesPendingLegalizationCount' => $advancesPendingLegalization,
            'paymentSchedulingsMineCount' => $paymentSchedulingsMine,
            'myPendingTotal' => $myPendingTotal,
            'openAlertsCount' => TableRegistry::getTableLocator()->get('AssetAlerts')
                ->find()->where(['status' => AssetAlertConstants::STATUS_ABIERTA])->count(),
        ];
    }
```

Añadir el método de conteo (espejo de `PendingNotificationsService::_getPaymentSchedulingsCount`):

```php
    /**
     * Cuenta las programaciones de pago cuyo estado el rol puede operar.
     */
    private function getPaymentSchedulingsMineCount(int $roleId): int
    {
        $visibleStatuses = $this->paymentSchedulingService->getVisibleStatuses($roleId);
        if ($visibleStatuses === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('PaymentSchedulings')->find()
            ->where(['pipeline_status IN' => $visibleStatuses])
            ->count();
    }
```

Añadir las dos claves a `_emptyCounters()`:

```php
            'advancesPendingLegalizationCount' => 0,
            'paymentSchedulingsMineCount' => 0,
            'myPendingTotal' => 0,
            'openAlertsCount' => 0,
```

Actualizar el registro DI (`src/Application.php`, arg 6 en `SidebarCounterService`):

```php
        $container->addShared(SidebarCounterService::class)
            ->addArguments([
                InvoicePipelineService::class,
                NoveltyPipelineService::class,
                PettyCashPipelineService::class,
                RefundPipelineService::class,
                AdvanceLegalizationActionPolicy::class,
                PaymentSchedulingPipelineService::class,
            ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/SidebarCounterServiceTest.php --filter testMyPendingTotal`
Expected: PASS.

- [ ] **Step 5: cs-check + commit**

```bash
composer cs-fix -- src/Service/SidebarCounterService.php src/Application.php
git add src/Service/SidebarCounterService.php src/Application.php tests/TestCase/Service/SidebarCounterServiceTest.php
git commit -m "feat: SidebarCounterService expone paymentSchedulingsMineCount + myPendingTotal"
```

---

## Task 9: PendingNotificationsService — reconciliación (+legalizations)

**Files:**
- Modify: `src/Service/PendingNotificationsService.php:98-121` (dentro de `_buildModules`, tras la entrada `advances`)
- Test: `tests/TestCase/Service/PendingNotificationsServiceTest.php` (extender si existe)

**Interfaces:**
- Consumes: `SidebarCounterService::getCounters()['advancesPendingLegalizationCount']` (Task 8 lo garantiza presente).
- Produces: `_buildModules` incluye un módulo `legalizations` cuando su conteo > 0.

- [ ] **Step 1: Write the failing test**

```php
    public function testBuildModulesIncludesLegalizationsWhenPending(): void
    {
        // Sembrar un rol con ≥1 anticipo pendiente de legalización operable.
        // getPendingByUser() debe incluir un módulo key='legalizations'.
        $rows = $this->getContainer()->get(\App\Service\PendingNotificationsService::class)
            ->getPendingByUser();

        $keys = [];
        foreach ($rows as $r) {
            foreach ($r['modules'] as $m) {
                $keys[] = $m['key'];
            }
        }
        $this->assertContains('legalizations', $keys);
    }
```

> Requiere seed de un usuario/rol con anticipo pagado + legalización en estado
> operable. Reusar el estilo de seed de `SidebarCounterServiceTest`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/PendingNotificationsServiceTest.php --filter testBuildModulesIncludesLegalizations`
Expected: FAIL (`legalizations` ausente).

- [ ] **Step 3: Modify `_buildModules`**

En `src/Service/PendingNotificationsService.php`, dentro del array `$raw` de `_buildModules`, insertar la entrada `legalizations` justo después de la de `advances`:

```php
            [
                'key' => 'advances',
                'label' => 'Anticipos',
                'count' => (int)($counters['advancesMineCount'] ?? 0),
                'route' => ['controller' => 'Advances', 'action' => 'index'],
            ],
            [
                'key' => 'legalizations',
                'label' => 'Legalización de Anticipos',
                'count' => (int)($counters['advancesPendingLegalizationCount'] ?? 0),
                'route' => ['controller' => 'Advances', 'action' => 'pendingLegalization'],
            ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/PendingNotificationsServiceTest.php --filter testBuildModulesIncludesLegalizations`
Expected: PASS.

- [ ] **Step 5: cs-check + commit**

```bash
composer cs-fix -- src/Service/PendingNotificationsService.php
git add src/Service/PendingNotificationsService.php tests/TestCase/Service/PendingNotificationsServiceTest.php
git commit -m "feat: reconciliar PendingNotifications con legalizaciones (8 módulos = badge/lista)"
```

---

## Task 10: Sidebar — enlace tope + badge

**Files:**
- Modify: `templates/layout/default.php:127-133` (tras el `<li>` de "Inicio")

**Interfaces:**
- Consumes: `$myPendingTotal` (view var seteada por `_setSidebarCounters` desde Task 8), `$navLink` (closure ya definido en el layout).

- [ ] **Step 1: Add the nav link**

En `templates/layout/default.php`, inmediatamente después del `<li>` de "Inicio" (el que enlaza a `Dashboard`), añadir:

```php
                <li>
                    <?= $this->Html->link(
                        '<span class="ic"><i class="bi bi-check2-square" aria-hidden="true"></i></span>'
                        . '<span class="grow">Mis Pendientes</span>'
                        . (($myPendingTotal ?? 0) > 0
                            ? '<span class="sb-badge is-primary">' . (int)$myPendingTotal . '</span>'
                            : ''),
                        ['controller' => 'Pending', 'action' => 'index'],
                        ['class' => $navLink('Pending'), 'escape' => false],
                    ) ?>
                </li>
```

- [ ] **Step 2: Smoke manual**

Run: `php bin/cake server`. Con un usuario de rol operativo, verificar que "Mis Pendientes" aparece en el tope del sidebar (tras Inicio) con badge numérico cuando hay pendientes, y sin badge cuando el total es 0. El enlace se marca activo (`is-active`) al estar en `/pendientes`.

- [ ] **Step 3: Commit**

```bash
git add templates/layout/default.php
git commit -m "feat: enlace 'Mis Pendientes' + badge en el tope del sidebar"
```

---

## Task 11: Verificación final

- [ ] **Step 1: Suite completa**

Run: `vendor/bin/phpunit`
Expected: verde (baseline preexistente + los nuevos tests). Si hay fallos cascade entre suites consecutivas, re-correr limpio antes de concluir (contaminación DB back-to-back conocida).

- [ ] **Step 2: cs-check global de lo tocado**

Run: `composer cs-check`
Expected: sin nuevos errores.

- [ ] **Step 3: Auditoría de permisos (invariante operar-implica-ver)**

Run: `php bin/cake permissions_audit`
Expected: exit 0. (Mis Pendientes no agrega módulo de permisos — es `#[NoAuthGate]` — así que no debe alterar la auditoría.)

- [ ] **Step 4: Smoke E2E manual por rol**

Loguear con 2-3 roles distintos (p.ej. Tesorería, Contabilidad, Auxiliar de Personal) y verificar que `/pendientes` muestra exactamente los ítems que cada rol ve en las bandejas de módulo, y que el badge del sidebar coincide con el conteo de la lista.

---

## Self-Review (cobertura spec ↔ plan)

- **8 módulos / espejo del sidebar** → Tasks 3-4 (fetchers con WHERE espejo + test de espejo).
- **Tabla única + pipeline-mini (6) / pill-only (2)** → Task 5 (registry `mini`) + Task 7 (template condicional).
- **Trampa Anticipos/Legalizaciones** → Task 5 (`advances` usa `InvoiceConstants::PIPELINE_STATUSES`; test `testAdvanceUsesInvoiceStepSetNotAdvance`).
- **status de Legalizaciones = AdvanceLegalization.status** → Task 3 (`_fetchLegalizations`).
- **Perf two-track (COUNT total + over-fetch acotado)** → Task 3-4 (`getPending`).
- **`#[NoAuthGate]` sin `$controllerModuleMap`** → Task 6.
- **Ruta `/pendientes`** → Task 6.
- **Sidebar enlace tope + badge (total 8, oculto si 0)** → Tasks 8 + 10.
- **Reconciliación n8n a 8** → Task 9.
- **Sin cambios de esquema** → ninguna migración en el plan.
- **Criterios de aceptación 1-10** → cubiertos por Tasks 3-10 + Task 11 (verificación).

Sin placeholders de implementación: todo step con código incluye el código. Firmas consistentes entre tareas (`getPending`, `forRow`, `MODULE_SLUGS`, `myPendingTotal`, `PendingModuleMeta::MODULES`).
