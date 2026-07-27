# Módulo TIC — Inventario de Activos (Administración Web SPI) — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el módulo de inventario de activos de TI dentro de SPI (datos, servicios de dominio, UI web de administración y alertas) como fuente de verdad, **sin** la API REST ni el push a n8n (esos quedan para el plan "ITAM" posterior).

**Architecture:** Patrón **catálogo + servicios + log** (NO pipeline). Los movimientos de activos y de stock son un **log inmutable**; registrar un movimiento es una transacción atómica que (1) inserta la fila inmutable, (2) actualiza el agregado (activo o consumible) y (3) marca acta pendiente si aplica. Sigue las convenciones canónicas de SPI: enums de dominio como fuente única, Constants que delegan, Entities/Tables ORM, servicios que retornan `ServiceResult`, RBAC por atributos `#[Permission]`, y la capa de vista Presentation/ViewModel.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, MySQL/MariaDB, Migrations (`BaseMigration`), PHPUnit + cakephp-fixture-factories, PhpSpreadsheet (no usado aquí), Bootstrap + sistema de diseño `.spi-*`.

## Global Constraints

Toda tarea hereda implícitamente estas reglas (copiadas del spec y de `CLAUDE.md`):

- **PHP `>=8.4`**, CakePHP 5.3. Migraciones extienden `Migrations\BaseMigration` (NO `AbstractMigration`).
- **Enums = fuente única**: `src/Constants/Domain/{Asset|Consumable}/{Enum}.php` con `label()` + helpers; `src/Constants/{Modulo}Constants.php` **delega** (`const X = Enum::CASE->value`). Nunca hardcodear strings de estado.
- **Slugs de estado en español sin acentos** (`disponible`, `asignado`, `en_reparacion`, `dado_de_baja`, `entrega`, `devolucion`, `prestamo`, `baja`, `ingreso`, `ajuste`, `traslado`, `pendiente`, `cargada`, `validada`, `rechazada`, `stock_bajo`, `acta_pendiente`, `abierta`, `resuelta`, `vencida`, `alta`, `media`, `baja`). `source` slugs `web`/`agent` en inglés (técnico interno).
- **Servicios** retornan `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`; verificar `->success` antes de `->data`. Acceden a tablas vía `TableRegistry::getTableLocator()->get('X')`, nunca `$this->X`. Inyección de dependencias con params nullable y `?? new ServiceClass()`.
- **Transacciones atómicas**: `$connection->transactional(function () use (..., &$serviceResult): bool { ... return true|false; })`. El `ServiceResult` se captura por referencia y se retorna fuera; `return false` fuerza rollback. Usar `->epilog('FOR UPDATE')` para lecturas con lock.
- **Métodos privados** con guion bajo: `_buildQuery()`. **Paginación fija 15** por página.
- **Almacenamiento de documentos PRIVADO** (actas/soportes): `ROOT/storage/assets/{assetId}` (fuera de webroot), replicando `EmployeeDocumentService` (validación de MIME real con finfo + canonicalización de extensión). NO usar `DocumentUploadTrait` (guarda en `WWW_ROOT/uploads`, público).
- **RBAC**: cada acción de controller lleva `#[Permission(action: 'view'|'add'|'edit'|'delete')]`. Mapear controller→módulo en `$controllerModuleMap` (AppController) y módulo→display en `AuthorizationService::MODULES` + `MODULE_GROUPS`. El rol Administrador **NO** bypassa estos módulos (solo `users`/`roles`).
- **CSS prefix `.spi-`**. Templates siguen los esqueletos canónicos (INDEX sin shell con `<a class="row-fact">`, VIEW `.spi-invoice-view-grid`, EDIT `.spi-edit-shell`). Mapeo estado→pill vive SOLO en `src/View/Presentation/{Modulo}Presentation` (const). Prohibido literales inline de pills.
- **Comandos de consola**: `extends Cake\Command\Command`, auto-discovery en `src/Command/`. `FooBarCommand.php` → `bin/cake foo_bar`.
- **Estilo**: `composer cs-check` debe pasar (auto-fix con `composer cs-fix`). Tests con `vendor/bin/phpunit`.

## Modelo de datos (referencia para todas las tareas)

7 tablas nuevas. Todas con `created`/`modified` **salvo los logs** (`asset_movements`, `consumable_movements`) que solo tienen `created` (inmutables). Todas las FK a `int signed` (las PK de SPI son signed).

| Tabla | Columnas |
|---|---|
| `asset_categories` | `id`, `code` str(30) uniq, `name` str(100), `description` text null, `active` bool def true, `created`, `modified` |
| `assets` | `id`, `code` str(30) uniq, `serial_number` str(100) null, `asset_category_id` FK→asset_categories, `brand` str(100) null, `model` str(100) null, `description` text null, `status` str(20) def `disponible`, `responsible_employee_id` FK→employees null, `operation_center_id` FK→operation_centers, `cost_center_id` FK→cost_centers null, `acquisition_date` date null, `observations` text null, `created`, `modified` |
| `asset_movements` (log) | `id`, `asset_id` FK→assets, `movement_type` str(20), `from_employee_id` FK→employees null, `to_employee_id` FK→employees null, `from_operation_center_id` FK→operation_centers null, `to_operation_center_id` FK→operation_centers null, `reason` text null, `movement_date` datetime, `acta_status` str(20) null, `performed_by_user_id` FK→users, `requested_by_phone` str(30) null, `requested_by_employee_id` FK→employees null, `source` str(10) def `web`, `created` |
| `asset_documents` | `id`, `asset_id` FK→assets (CASCADE), `asset_movement_id` FK→asset_movements null (SET_NULL), `document_type` str(30), `name` str(255), `file_path` str(255), `file_size` int null, `mime_type` str(100) null, `uploaded_by` FK→users, `created`, `modified` |
| `consumables` | `id`, `reference` str(50) uniq, `description` str(255), `current_stock` int def 0, `minimum_stock` int def 0, `maximum_stock` int null, `operation_center_id` FK→operation_centers null, `unit` str(20) null, `created`, `modified` |
| `consumable_movements` (log) | `id`, `consumable_id` FK→consumables, `movement_type` str(20), `quantity` int, `balance_after` int, `reason` text null, `related_asset_id` FK→assets null (SET_NULL), `movement_date` datetime, `performed_by_user_id` FK→users, `requested_by_phone` str(30) null, `source` str(10) def `web`, `created` |
| `asset_alerts` | `id`, `alert_type` str(30), `priority` str(10) def `media`, `asset_id` FK→assets null (CASCADE), `consumable_id` FK→consumables null (CASCADE), `asset_movement_id` FK→asset_movements null (CASCADE), `message` str(255), `status` str(10) def `abierta`, `notified_at` datetime null, `resolved_at` datetime null, `created`, `modified` |

### Reglas de transición (movimiento → efecto en el activo)

| `movement_type` | `assets.status` | Responsable | Acta (`acta_status`) |
|---|---|---|---|
| `ingreso` | `disponible` | — | null (No) |
| `entrega` | `asignado` | set `to_employee` | `pendiente` |
| `prestamo` | `prestado` | set `to_employee` | `pendiente` |
| `devolucion` | `disponible` | limpia responsable | `pendiente` |
| `traslado` | sin cambio | sin cambio | null (cambia `operation_center`) |
| `baja` | `dado_de_baja` (terminal) | limpia responsable | `pendiente` |
| `ajuste` | corrección manual | según corrección | null |

## File Structure

```
config/Migrations/
  20260619000001_CreateItamTables.php              ← 7 tablas
  20260619000002_SeedItamAdminPermissions.php      ← permisos Administrador en módulos ITAM

src/Constants/
  AssetConstants.php                               ← delega a enums Asset
  ConsumableConstants.php                          ← delega a enum Consumable
  AssetAlertConstants.php                          ← delega a enums de alertas
  Domain/Asset/AssetStatus.php
  Domain/Asset/MovementType.php
  Domain/Asset/ActaStatus.php
  Domain/Asset/DocumentType.php
  Domain/Asset/MovementSource.php
  Domain/Asset/AlertType.php
  Domain/Asset/AlertStatus.php
  Domain/Asset/AlertPriority.php
  Domain/Consumable/MovementType.php

src/Model/Entity/
  Asset.php, AssetCategory.php, AssetMovement.php, AssetDocument.php,
  Consumable.php, ConsumableMovement.php, AssetAlert.php
src/Model/Table/
  AssetsTable.php, AssetCategoriesTable.php, AssetMovementsTable.php, AssetDocumentsTable.php,
  ConsumablesTable.php, ConsumableMovementsTable.php, AssetAlertsTable.php

src/Service/
  AssetInventoryService.php                        ← movimientos de activos (transacción atómica)
  ConsumableStockService.php                       ← movimientos de stock
  AssetDocumentService.php                         ← storage privado ROOT/storage/assets/{id}
  AssetAlertService.php                            ← cálculo + persistencia de alertas
  CodeGeneratorService.php                         ← (MODIFICAR) + generateAssetCode()

src/Command/
  ItamGenerateAlertsCommand.php                    ← bin/cake itam_generate_alerts

src/Controller/
  AssetsController.php, ConsumablesController.php,
  AssetCategoriesController.php, AssetAlertsController.php
  AppController.php                                ← (MODIFICAR) $controllerModuleMap
  Trait/CatalogCrudTrait.php                       ← (REUSAR) catálogo

src/View/Presentation/
  AssetPresentation.php, AssetRowView.php
  ConsumablePresentation.php, ConsumableRowView.php
src/ViewModel/
  AssetViewViewModel.php                          ← solo VIEW (índice/edit/add usan Presentation/form directo)

templates/Assets/{index,view,edit,add}.php
templates/Consumables/{index,view,edit,add}.php
templates/AssetCategories/{index,view,add,edit}.php
templates/AssetAlerts/index.php
templates/element/sidebar/itam.php                ← nueva sección "Inventario TI"
templates/layout/default.php                      ← (MODIFICAR) incluir el element itam

src/Service/AuthorizationService.php              ← (MODIFICAR) MODULES + MODULE_GROUPS
src/Service/SidebarCounterService.php             ← (MODIFICAR) contador de alertas abiertas
config/routes.php                                 ← NO requiere cambios (DashedRoute fallback cubre /assets/assign/5, /assets/upload-document/5, etc.)
```

---

# FASE 1 — Datos y modelo ORM

Resultado verificable: migrar la BD crea las 7 tablas; los enums/constants/tables existen con validación y finders, y `composer cs-check` + los tests unitarios pasan.

---

### Task 1: Enums de dominio (Asset + Consumable)

**Files:**
- Create: `src/Constants/Domain/Asset/AssetStatus.php`
- Create: `src/Constants/Domain/Asset/MovementType.php`
- Create: `src/Constants/Domain/Asset/ActaStatus.php`
- Create: `src/Constants/Domain/Asset/DocumentType.php`
- Create: `src/Constants/Domain/Asset/MovementSource.php`
- Create: `src/Constants/Domain/Asset/AlertType.php`
- Create: `src/Constants/Domain/Asset/AlertStatus.php`
- Create: `src/Constants/Domain/Asset/AlertPriority.php`
- Create: `src/Constants/Domain/Consumable/MovementType.php`
- Test: `tests/TestCase/Constants/Domain/Asset/AssetStatusTest.php`

**Interfaces:**
- Produces: enums string-backed `App\Constants\Domain\Asset\AssetStatus` (cases DISPONIBLE, ASIGNADO, PRESTADO, EN_REPARACION, DADO_DE_BAJA; métodos `label(): string`, `isTerminal(): bool`, `values(): array`), `App\Constants\Domain\Asset\MovementType` (cases INGRESO, ENTREGA, DEVOLUCION, TRASLADO, PRESTAMO, BAJA, AJUSTE; `label()`, `requiresActa(): bool`, `values()`), `ActaStatus` (PENDIENTE, CARGADA, VALIDADA, RECHAZADA; `label()`, `values()`), `DocumentType` (ACTA, FACTURA_COMPRA, FOTO, SOPORTE_MANTENIMIENTO, OTRO; `label()`, `values()`), `MovementSource` (WEB, AGENT; `label()`), `AlertType` (STOCK_BAJO, ACTA_PENDIENTE, ACTIVO_SIN_RESPONSABLE, REGISTRO_INCOMPLETO, MOVIMIENTO_SIN_CERRAR; `label()`, `values()`), `AlertStatus` (ABIERTA, RESUELTA, VENCIDA; `label()`, `values()`), `AlertPriority` (ALTA, MEDIA, BAJA; `label()`, `values()`), `App\Constants\Domain\Consumable\MovementType` (INGRESO, SALIDA, AJUSTE; `label()`, `values()`).

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Constants/Domain/Asset/AssetStatusTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\Asset;

use App\Constants\Domain\Asset\AssetStatus;
use App\Constants\Domain\Asset\MovementType;
use PHPUnit\Framework\TestCase;

final class AssetStatusTest extends TestCase
{
    public function testLabelReturnsHumanReadableSpanish(): void
    {
        $this->assertSame('Disponible', AssetStatus::DISPONIBLE->label());
        $this->assertSame('Dado de baja', AssetStatus::DADO_DE_BAJA->label());
    }

    public function testIsTerminalOnlyForDadoDeBaja(): void
    {
        $this->assertTrue(AssetStatus::DADO_DE_BAJA->isTerminal());
        $this->assertFalse(AssetStatus::DISPONIBLE->isTerminal());
    }

    public function testValuesReturnsAllSlugs(): void
    {
        $this->assertSame(
            ['disponible', 'asignado', 'prestado', 'en_reparacion', 'dado_de_baja'],
            AssetStatus::values(),
        );
    }

    public function testMovementTypeRequiresActa(): void
    {
        $this->assertTrue(MovementType::ENTREGA->requiresActa());
        $this->assertTrue(MovementType::BAJA->requiresActa());
        $this->assertFalse(MovementType::INGRESO->requiresActa());
        $this->assertFalse(MovementType::TRASLADO->requiresActa());
        $this->assertFalse(MovementType::AJUSTE->requiresActa());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Constants/Domain/Asset/AssetStatusTest.php`
Expected: FAIL — `Class "App\Constants\Domain\Asset\AssetStatus" not found`.

- [ ] **Step 3: Write the enums**

`src/Constants/Domain/Asset/AssetStatus.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AssetStatus: string
{
    case DISPONIBLE = 'disponible';
    case ASIGNADO = 'asignado';
    case PRESTADO = 'prestado';
    case EN_REPARACION = 'en_reparacion';
    case DADO_DE_BAJA = 'dado_de_baja';

    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::ASIGNADO => 'Asignado',
            self::PRESTADO => 'Prestado',
            self::EN_REPARACION => 'En reparación',
            self::DADO_DE_BAJA => 'Dado de baja',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::DADO_DE_BAJA;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Asset/MovementType.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum MovementType: string
{
    case INGRESO = 'ingreso';
    case ENTREGA = 'entrega';
    case DEVOLUCION = 'devolucion';
    case TRASLADO = 'traslado';
    case PRESTAMO = 'prestamo';
    case BAJA = 'baja';
    case AJUSTE = 'ajuste';

    public function label(): string
    {
        return match ($this) {
            self::INGRESO => 'Ingreso',
            self::ENTREGA => 'Entrega',
            self::DEVOLUCION => 'Devolución',
            self::TRASLADO => 'Traslado',
            self::PRESTAMO => 'Préstamo',
            self::BAJA => 'Baja',
            self::AJUSTE => 'Ajuste',
        };
    }

    /**
     * Entrega, préstamo, devolución y baja requieren acta firmada (RN-05).
     */
    public function requiresActa(): bool
    {
        return match ($this) {
            self::ENTREGA, self::PRESTAMO, self::DEVOLUCION, self::BAJA => true,
            self::INGRESO, self::TRASLADO, self::AJUSTE => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Asset/ActaStatus.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum ActaStatus: string
{
    case PENDIENTE = 'pendiente';
    case CARGADA = 'cargada';
    case VALIDADA = 'validada';
    case RECHAZADA = 'rechazada';

    public function label(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::CARGADA => 'Cargada',
            self::VALIDADA => 'Validada',
            self::RECHAZADA => 'Rechazada',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Asset/DocumentType.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum DocumentType: string
{
    case ACTA = 'acta';
    case FACTURA_COMPRA = 'factura_compra';
    case FOTO = 'foto';
    case SOPORTE_MANTENIMIENTO = 'soporte_mantenimiento';
    case OTRO = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::ACTA => 'Acta',
            self::FACTURA_COMPRA => 'Factura de compra',
            self::FOTO => 'Foto',
            self::SOPORTE_MANTENIMIENTO => 'Soporte de mantenimiento',
            self::OTRO => 'Otro',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Asset/MovementSource.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum MovementSource: string
{
    case WEB = 'web';
    case AGENT = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::WEB => 'Web',
            self::AGENT => 'Agente',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Asset/AlertType.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AlertType: string
{
    case STOCK_BAJO = 'stock_bajo';
    case ACTA_PENDIENTE = 'acta_pendiente';
    case ACTIVO_SIN_RESPONSABLE = 'activo_sin_responsable';
    case REGISTRO_INCOMPLETO = 'registro_incompleto';
    case MOVIMIENTO_SIN_CERRAR = 'movimiento_sin_cerrar';

    public function label(): string
    {
        return match ($this) {
            self::STOCK_BAJO => 'Stock bajo',
            self::ACTA_PENDIENTE => 'Acta pendiente',
            self::ACTIVO_SIN_RESPONSABLE => 'Activo sin responsable',
            self::REGISTRO_INCOMPLETO => 'Registro incompleto',
            self::MOVIMIENTO_SIN_CERRAR => 'Movimiento sin cerrar',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Asset/AlertStatus.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AlertStatus: string
{
    case ABIERTA = 'abierta';
    case RESUELTA = 'resuelta';
    case VENCIDA = 'vencida';

    public function label(): string
    {
        return match ($this) {
            self::ABIERTA => 'Abierta',
            self::RESUELTA => 'Resuelta',
            self::VENCIDA => 'Vencida',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Asset/AlertPriority.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Asset;

enum AlertPriority: string
{
    case ALTA = 'alta';
    case MEDIA = 'media';
    case BAJA = 'baja';

    public function label(): string
    {
        return match ($this) {
            self::ALTA => 'Alta',
            self::MEDIA => 'Media',
            self::BAJA => 'Baja',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

`src/Constants/Domain/Consumable/MovementType.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Consumable;

enum MovementType: string
{
    case INGRESO = 'ingreso';
    case SALIDA = 'salida';
    case AJUSTE = 'ajuste';

    public function label(): string
    {
        return match ($this) {
            self::INGRESO => 'Ingreso',
            self::SALIDA => 'Salida',
            self::AJUSTE => 'Ajuste',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Constants/Domain/Asset/AssetStatusTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Constants/Domain/Asset src/Constants/Domain/Consumable tests/TestCase/Constants/Domain/Asset
git commit -m "feat(itam): enums de dominio de activos y consumibles"
```

---

### Task 2: Constants que delegan a los enums

**Files:**
- Create: `src/Constants/AssetConstants.php`
- Create: `src/Constants/ConsumableConstants.php`
- Create: `src/Constants/AssetAlertConstants.php`
- Test: `tests/TestCase/Constants/AssetConstantsTest.php`

**Interfaces:**
- Consumes: enums de Task 1.
- Produces: `App\Constants\AssetConstants` con consts `STATUS_DISPONIBLE`/`STATUS_ASIGNADO`/`STATUS_PRESTADO`/`STATUS_EN_REPARACION`/`STATUS_DADO_DE_BAJA`, `MOVEMENT_INGRESO`/`_ENTREGA`/`_DEVOLUCION`/`_TRASLADO`/`_PRESTAMO`/`_BAJA`/`_AJUSTE`, `ACTA_PENDIENTE`/`_CARGADA`/`_VALIDADA`/`_RECHAZADA`, `DOCTYPE_ACTA`/`_FACTURA_COMPRA`/`_FOTO`/`_SOPORTE_MANTENIMIENTO`/`_OTRO`, `SOURCE_WEB`/`SOURCE_AGENT`, arrays `STATUSES`, `MOVEMENT_TYPES`, `ACTA_STATUSES`, `DOCUMENT_TYPES`, mapas `STATUS_LABELS`/`MOVEMENT_LABELS`, const `CODE_PREFIX = 'ACT'`. `App\Constants\ConsumableConstants` con `MOVEMENT_INGRESO`/`_SALIDA`/`_AJUSTE`, `MOVEMENT_TYPES`, `MOVEMENT_LABELS`. `App\Constants\AssetAlertConstants` con `TYPE_*`, `STATUS_*`, `PRIORITY_*`, arrays `TYPES`, `STATUSES`, `PRIORITIES` y mapas de labels.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Constants/AssetConstantsTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants;

use App\Constants\AssetAlertConstants;
use App\Constants\AssetConstants;
use App\Constants\ConsumableConstants;
use App\Constants\Domain\Asset\AssetStatus;
use PHPUnit\Framework\TestCase;

final class AssetConstantsTest extends TestCase
{
    public function testStatusConstantsDelegateToEnum(): void
    {
        $this->assertSame(AssetStatus::DISPONIBLE->value, AssetConstants::STATUS_DISPONIBLE);
        $this->assertSame('dado_de_baja', AssetConstants::STATUS_DADO_DE_BAJA);
    }

    public function testStatusesArrayMatchesEnumValues(): void
    {
        $this->assertSame(AssetStatus::values(), AssetConstants::STATUSES);
    }

    public function testStatusLabelsCoverEveryStatus(): void
    {
        foreach (AssetConstants::STATUSES as $status) {
            $this->assertArrayHasKey($status, AssetConstants::STATUS_LABELS);
        }
    }

    public function testCodePrefix(): void
    {
        $this->assertSame('ACT', AssetConstants::CODE_PREFIX);
    }

    public function testConsumableAndAlertConstants(): void
    {
        $this->assertSame('salida', ConsumableConstants::MOVEMENT_SALIDA);
        $this->assertSame('stock_bajo', AssetAlertConstants::TYPE_STOCK_BAJO);
        $this->assertSame('abierta', AssetAlertConstants::STATUS_ABIERTA);
        $this->assertContains('media', AssetAlertConstants::PRIORITIES);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Constants/AssetConstantsTest.php`
Expected: FAIL — `Class "App\Constants\AssetConstants" not found`.

- [ ] **Step 3: Write the constants**

`src/Constants/AssetConstants.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Asset\ActaStatus;
use App\Constants\Domain\Asset\AssetStatus;
use App\Constants\Domain\Asset\DocumentType;
use App\Constants\Domain\Asset\MovementSource;
use App\Constants\Domain\Asset\MovementType;

/**
 * Constantes de activos. Fuente única real en los enums de Domain\Asset; estas
 * consts delegan por retrocompatibilidad y ergonomía en validación/templates.
 */
final class AssetConstants
{
    public const CODE_PREFIX = 'ACT';

    // Estados del activo
    public const STATUS_DISPONIBLE = AssetStatus::DISPONIBLE->value;
    public const STATUS_ASIGNADO = AssetStatus::ASIGNADO->value;
    public const STATUS_PRESTADO = AssetStatus::PRESTADO->value;
    public const STATUS_EN_REPARACION = AssetStatus::EN_REPARACION->value;
    public const STATUS_DADO_DE_BAJA = AssetStatus::DADO_DE_BAJA->value;

    // Tipos de movimiento
    public const MOVEMENT_INGRESO = MovementType::INGRESO->value;
    public const MOVEMENT_ENTREGA = MovementType::ENTREGA->value;
    public const MOVEMENT_DEVOLUCION = MovementType::DEVOLUCION->value;
    public const MOVEMENT_TRASLADO = MovementType::TRASLADO->value;
    public const MOVEMENT_PRESTAMO = MovementType::PRESTAMO->value;
    public const MOVEMENT_BAJA = MovementType::BAJA->value;
    public const MOVEMENT_AJUSTE = MovementType::AJUSTE->value;

    // Estados de acta
    public const ACTA_PENDIENTE = ActaStatus::PENDIENTE->value;
    public const ACTA_CARGADA = ActaStatus::CARGADA->value;
    public const ACTA_VALIDADA = ActaStatus::VALIDADA->value;
    public const ACTA_RECHAZADA = ActaStatus::RECHAZADA->value;

    // Tipos de documento
    public const DOCTYPE_ACTA = DocumentType::ACTA->value;
    public const DOCTYPE_FACTURA_COMPRA = DocumentType::FACTURA_COMPRA->value;
    public const DOCTYPE_FOTO = DocumentType::FOTO->value;
    public const DOCTYPE_SOPORTE_MANTENIMIENTO = DocumentType::SOPORTE_MANTENIMIENTO->value;
    public const DOCTYPE_OTRO = DocumentType::OTRO->value;

    // Origen del movimiento
    public const SOURCE_WEB = MovementSource::WEB->value;
    public const SOURCE_AGENT = MovementSource::AGENT->value;

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DISPONIBLE,
        self::STATUS_ASIGNADO,
        self::STATUS_PRESTADO,
        self::STATUS_EN_REPARACION,
        self::STATUS_DADO_DE_BAJA,
    ];

    /** @var array<int, string> */
    public const MOVEMENT_TYPES = [
        self::MOVEMENT_INGRESO,
        self::MOVEMENT_ENTREGA,
        self::MOVEMENT_DEVOLUCION,
        self::MOVEMENT_TRASLADO,
        self::MOVEMENT_PRESTAMO,
        self::MOVEMENT_BAJA,
        self::MOVEMENT_AJUSTE,
    ];

    /** @var array<int, string> */
    public const ACTA_STATUSES = [
        self::ACTA_PENDIENTE,
        self::ACTA_CARGADA,
        self::ACTA_VALIDADA,
        self::ACTA_RECHAZADA,
    ];

    /** @var array<int, string> */
    public const DOCUMENT_TYPES = [
        self::DOCTYPE_ACTA,
        self::DOCTYPE_FACTURA_COMPRA,
        self::DOCTYPE_FOTO,
        self::DOCTYPE_SOPORTE_MANTENIMIENTO,
        self::DOCTYPE_OTRO,
    ];

    /** @var array<int, string> */
    public const SOURCES = [self::SOURCE_WEB, self::SOURCE_AGENT];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DISPONIBLE => 'Disponible',
        self::STATUS_ASIGNADO => 'Asignado',
        self::STATUS_PRESTADO => 'Prestado',
        self::STATUS_EN_REPARACION => 'En reparación',
        self::STATUS_DADO_DE_BAJA => 'Dado de baja',
    ];

    /** @var array<string, string> */
    public const MOVEMENT_LABELS = [
        self::MOVEMENT_INGRESO => 'Ingreso',
        self::MOVEMENT_ENTREGA => 'Entrega',
        self::MOVEMENT_DEVOLUCION => 'Devolución',
        self::MOVEMENT_TRASLADO => 'Traslado',
        self::MOVEMENT_PRESTAMO => 'Préstamo',
        self::MOVEMENT_BAJA => 'Baja',
        self::MOVEMENT_AJUSTE => 'Ajuste',
    ];
}
```

`src/Constants/ConsumableConstants.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Consumable\MovementType;

final class ConsumableConstants
{
    public const MOVEMENT_INGRESO = MovementType::INGRESO->value;
    public const MOVEMENT_SALIDA = MovementType::SALIDA->value;
    public const MOVEMENT_AJUSTE = MovementType::AJUSTE->value;

    /** @var array<int, string> */
    public const MOVEMENT_TYPES = [
        self::MOVEMENT_INGRESO,
        self::MOVEMENT_SALIDA,
        self::MOVEMENT_AJUSTE,
    ];

    /** @var array<string, string> */
    public const MOVEMENT_LABELS = [
        self::MOVEMENT_INGRESO => 'Ingreso',
        self::MOVEMENT_SALIDA => 'Salida',
        self::MOVEMENT_AJUSTE => 'Ajuste',
    ];
}
```

`src/Constants/AssetAlertConstants.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Asset\AlertPriority;
use App\Constants\Domain\Asset\AlertStatus;
use App\Constants\Domain\Asset\AlertType;

final class AssetAlertConstants
{
    public const TYPE_STOCK_BAJO = AlertType::STOCK_BAJO->value;
    public const TYPE_ACTA_PENDIENTE = AlertType::ACTA_PENDIENTE->value;
    public const TYPE_ACTIVO_SIN_RESPONSABLE = AlertType::ACTIVO_SIN_RESPONSABLE->value;
    public const TYPE_REGISTRO_INCOMPLETO = AlertType::REGISTRO_INCOMPLETO->value;
    public const TYPE_MOVIMIENTO_SIN_CERRAR = AlertType::MOVIMIENTO_SIN_CERRAR->value;

    public const STATUS_ABIERTA = AlertStatus::ABIERTA->value;
    public const STATUS_RESUELTA = AlertStatus::RESUELTA->value;
    public const STATUS_VENCIDA = AlertStatus::VENCIDA->value;

    public const PRIORITY_ALTA = AlertPriority::ALTA->value;
    public const PRIORITY_MEDIA = AlertPriority::MEDIA->value;
    public const PRIORITY_BAJA = AlertPriority::BAJA->value;

    /** Días tras los cuales un acta pendiente genera alerta (RN-06). */
    public const ACTA_PENDING_DAYS = 3;

    /** @var array<int, string> */
    public const TYPES = [
        self::TYPE_STOCK_BAJO,
        self::TYPE_ACTA_PENDIENTE,
        self::TYPE_ACTIVO_SIN_RESPONSABLE,
        self::TYPE_REGISTRO_INCOMPLETO,
        self::TYPE_MOVIMIENTO_SIN_CERRAR,
    ];

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_ABIERTA, self::STATUS_RESUELTA, self::STATUS_VENCIDA];

    /** @var array<int, string> */
    public const PRIORITIES = [self::PRIORITY_ALTA, self::PRIORITY_MEDIA, self::PRIORITY_BAJA];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_STOCK_BAJO => 'Stock bajo',
        self::TYPE_ACTA_PENDIENTE => 'Acta pendiente',
        self::TYPE_ACTIVO_SIN_RESPONSABLE => 'Activo sin responsable',
        self::TYPE_REGISTRO_INCOMPLETO => 'Registro incompleto',
        self::TYPE_MOVIMIENTO_SIN_CERRAR => 'Movimiento sin cerrar',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_ABIERTA => 'Abierta',
        self::STATUS_RESUELTA => 'Resuelta',
        self::STATUS_VENCIDA => 'Vencida',
    ];

    /** @var array<string, string> */
    public const PRIORITY_LABELS = [
        self::PRIORITY_ALTA => 'Alta',
        self::PRIORITY_MEDIA => 'Media',
        self::PRIORITY_BAJA => 'Baja',
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Constants/AssetConstantsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Constants/AssetConstants.php src/Constants/ConsumableConstants.php src/Constants/AssetAlertConstants.php tests/TestCase/Constants/AssetConstantsTest.php
git commit -m "feat(itam): constantes de activos, consumibles y alertas que delegan a enums"
```

---

### Task 3: Migración de las 7 tablas

**Files:**
- Create: `config/Migrations/20260619000001_CreateItamTables.php`

**Interfaces:**
- Produces: tablas `asset_categories`, `assets`, `asset_movements`, `asset_documents`, `consumables`, `consumable_movements`, `asset_alerts` con el esquema de la sección "Modelo de datos".

> **Nota:** No hay test unitario para la migración; la verificación es ejecutar `migrate` y `rollback` limpios (Steps 2-4). Crear el archivo con el timestamp indicado para mantener el orden.

- [ ] **Step 1: Write the migration**

`config/Migrations/20260619000001_CreateItamTables.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateItamTables extends BaseMigration
{
    public function up(): void
    {
        // asset_categories
        if (!$this->hasTable('asset_categories')) {
            $t = $this->table('asset_categories');
            $t->addColumn('code', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('name', 'string', ['limit' => 100, 'null' => false]);
            $t->addColumn('description', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('active', 'boolean', ['null' => false, 'default' => true]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['code'], ['unique' => true]);
            $t->create();
        }

        // assets
        if (!$this->hasTable('assets')) {
            $t = $this->table('assets');
            $t->addColumn('code', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('serial_number', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('asset_category_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('brand', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('model', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('description', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'disponible']);
            $t->addColumn('responsible_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('operation_center_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('cost_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('acquisition_date', 'date', ['null' => true, 'default' => null]);
            $t->addColumn('observations', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['code'], ['unique' => true]);
            $t->addIndex(['status']);
            $t->addIndex(['asset_category_id']);
            $t->addIndex(['responsible_employee_id']);
            $t->addIndex(['operation_center_id']);
            $t->addForeignKey('asset_category_id', 'asset_categories', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('responsible_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('cost_center_id', 'cost_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // asset_movements (log inmutable — solo created)
        if (!$this->hasTable('asset_movements')) {
            $t = $this->table('asset_movements');
            $t->addColumn('asset_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('movement_type', 'string', ['limit' => 20, 'null' => false]);
            $t->addColumn('from_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('to_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('from_operation_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('to_operation_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('reason', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('movement_date', 'datetime', ['null' => false]);
            $t->addColumn('acta_status', 'string', ['limit' => 20, 'null' => true, 'default' => null]);
            $t->addColumn('performed_by_user_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('requested_by_phone', 'string', ['limit' => 30, 'null' => true, 'default' => null]);
            $t->addColumn('requested_by_employee_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('source', 'string', ['limit' => 10, 'null' => false, 'default' => 'web']);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['asset_id']);
            $t->addIndex(['movement_type']);
            $t->addIndex(['acta_status']);
            $t->addIndex(['movement_date']);
            $t->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('from_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('to_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('from_operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('to_operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('performed_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('requested_by_employee_id', 'employees', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // asset_documents
        if (!$this->hasTable('asset_documents')) {
            $t = $this->table('asset_documents');
            $t->addColumn('asset_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('asset_movement_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('document_type', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('name', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('file_path', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('file_size', 'integer', ['null' => true, 'default' => null]);
            $t->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true, 'default' => null]);
            $t->addColumn('uploaded_by', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['asset_id']);
            $t->addIndex(['asset_movement_id']);
            $t->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->addForeignKey('asset_movement_id', 'asset_movements', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE']);
            $t->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // consumables
        if (!$this->hasTable('consumables')) {
            $t = $this->table('consumables');
            $t->addColumn('reference', 'string', ['limit' => 50, 'null' => false]);
            $t->addColumn('description', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('current_stock', 'integer', ['null' => false, 'default' => 0]);
            $t->addColumn('minimum_stock', 'integer', ['null' => false, 'default' => 0]);
            $t->addColumn('maximum_stock', 'integer', ['null' => true, 'default' => null]);
            $t->addColumn('operation_center_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('unit', 'string', ['limit' => 20, 'null' => true, 'default' => null]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['reference'], ['unique' => true]);
            $t->addIndex(['operation_center_id']);
            $t->addForeignKey('operation_center_id', 'operation_centers', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // consumable_movements (log inmutable — solo created)
        if (!$this->hasTable('consumable_movements')) {
            $t = $this->table('consumable_movements');
            $t->addColumn('consumable_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('movement_type', 'string', ['limit' => 20, 'null' => false]);
            $t->addColumn('quantity', 'integer', ['null' => false]);
            $t->addColumn('balance_after', 'integer', ['null' => false]);
            $t->addColumn('reason', 'text', ['null' => true, 'default' => null]);
            $t->addColumn('related_asset_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('movement_date', 'datetime', ['null' => false]);
            $t->addColumn('performed_by_user_id', 'integer', ['null' => false, 'signed' => true]);
            $t->addColumn('requested_by_phone', 'string', ['limit' => 30, 'null' => true, 'default' => null]);
            $t->addColumn('source', 'string', ['limit' => 10, 'null' => false, 'default' => 'web']);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['consumable_id']);
            $t->addIndex(['movement_type']);
            $t->addIndex(['movement_date']);
            $t->addForeignKey('consumable_id', 'consumables', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->addForeignKey('related_asset_id', 'assets', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE']);
            $t->addForeignKey('performed_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE']);
            $t->create();
        }

        // asset_alerts
        if (!$this->hasTable('asset_alerts')) {
            $t = $this->table('asset_alerts');
            $t->addColumn('alert_type', 'string', ['limit' => 30, 'null' => false]);
            $t->addColumn('priority', 'string', ['limit' => 10, 'null' => false, 'default' => 'media']);
            $t->addColumn('asset_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('consumable_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('asset_movement_id', 'integer', ['null' => true, 'default' => null, 'signed' => true]);
            $t->addColumn('message', 'string', ['limit' => 255, 'null' => false]);
            $t->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'abierta']);
            $t->addColumn('notified_at', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('resolved_at', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('created', 'datetime', ['null' => true, 'default' => null]);
            $t->addColumn('modified', 'datetime', ['null' => true, 'default' => null]);
            $t->addIndex(['alert_type']);
            $t->addIndex(['status']);
            $t->addIndex(['priority']);
            $t->addIndex(['asset_id']);
            $t->addIndex(['consumable_id']);
            $t->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->addForeignKey('consumable_id', 'consumables', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->addForeignKey('asset_movement_id', 'asset_movements', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE']);
            $t->create();
        }
    }

    public function down(): void
    {
        foreach ([
            'asset_alerts',
            'consumable_movements',
            'consumables',
            'asset_documents',
            'asset_movements',
            'assets',
            'asset_categories',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }
}
```

- [ ] **Step 2: Run the migration**

Run: `php bin/cake migrations migrate`
Expected: 7 tablas creadas sin error.

- [ ] **Step 3: Verify rollback works (down() is correct)**

Run: `php bin/cake migrations rollback` then `php bin/cake migrations migrate`
Expected: rollback elimina las 7 tablas en orden inverso (respeta FKs) y vuelve a migrar limpio.

- [ ] **Step 4: Regenerate schema lock**

Run: `php bin/cake schema_cache clear` (si aplica en el proyecto) y confirma que `config/Migrations/schema-dump-default.lock` se actualizó tras migrar.

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/20260619000001_CreateItamTables.php config/Migrations/schema-dump-default.lock
git commit -m "feat(itam): migración de 7 tablas de inventario de activos"
```

---

### Task 4: AssetCategory — entity, table y factory

**Files:**
- Create: `src/Model/Entity/AssetCategory.php`
- Create: `src/Model/Table/AssetCategoriesTable.php`
- Create: `tests/Factory/AssetCategoryFactory.php`
- Test: `tests/TestCase/Model/Table/AssetCategoriesTableTest.php`

**Interfaces:**
- Produces: `App\Model\Table\AssetCategoriesTable` (displayField `name`, finder `findActive`, validación de `code`/`name` requeridos y `code` único). `App\Test\Factory\AssetCategoryFactory` con `definition()` que genera `code`/`name` y helper `inactive()`.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Model/Table/AssetCategoriesTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\AssetCategoryFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetCategoriesTableTest extends TestCase
{
    public function testRequiresCodeAndName(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetCategories');
        $entity = $table->newEntity([]);
        $this->assertArrayHasKey('code', $entity->getErrors());
        $this->assertArrayHasKey('name', $entity->getErrors());
    }

    public function testFindActiveExcludesInactive(): void
    {
        AssetCategoryFactory::new()->save();
        AssetCategoryFactory::new()->inactive()->save();

        $table = TableRegistry::getTableLocator()->get('AssetCategories');
        $active = $table->find('active')->all()->toArray();

        $this->assertCount(1, $active);
        $this->assertTrue($active[0]->active);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetCategoriesTableTest.php`
Expected: FAIL — table/factory no existen.

- [ ] **Step 3: Write entity, table and factory**

`src/Model/Entity/AssetCategory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AssetCategory extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'name' => true,
        'description' => true,
        'active' => true,
        'created' => false,
        'modified' => false,
    ];
}
```

`src/Model/Table/AssetCategoriesTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetCategoriesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_categories');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Assets', [
            'foreignKey' => 'asset_category_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->requirePresence('code', 'create')
            ->notEmptyString('code', 'El código es requerido.');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name', 'El nombre es requerido.');

        $validator
            ->scalar('description')
            ->allowEmptyString('description');

        $validator
            ->boolean('active')
            ->notEmptyString('active');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code'], 'El código ya existe.'), ['errorField' => 'code']);

        return $rules;
    }

    /**
     * Solo categorías activas, ordenadas por nombre.
     */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['AssetCategories.active' => true])->orderBy(['AssetCategories.name' => 'ASC']);
    }
}
```

`tests/Factory/AssetCategoryFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class AssetCategoryFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'AssetCategories';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'code' => 'CAT-' . Seq::next(),
            'name' => $generator->word(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->setField('active', false);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetCategoriesTableTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/AssetCategory.php src/Model/Table/AssetCategoriesTable.php tests/Factory/AssetCategoryFactory.php tests/TestCase/Model/Table/AssetCategoriesTableTest.php
git commit -m "feat(itam): AssetCategory entity/table/factory + finder findActive"
```

---

### Task 5: Asset — entity, table, factory y `generateAssetCode`

**Files:**
- Create: `src/Model/Entity/Asset.php`
- Create: `src/Model/Table/AssetsTable.php`
- Create: `tests/Factory/AssetFactory.php`
- Modify: `src/Service/CodeGeneratorService.php` (añadir `generateAssetCode`)
- Test: `tests/TestCase/Model/Table/AssetsTableTest.php`
- Test: `tests/TestCase/Service/CodeGeneratorServiceTest.php` (añadir caso; el archivo ya existe)

**Interfaces:**
- Consumes: `AssetCategoriesTable` (Task 4), `OperationCenterFactory` (existente), `AssetConstants::CODE_PREFIX` (Task 2).
- Produces:
  - `App\Service\CodeGeneratorService::generateAssetCode(int $operationCenterId): string` → `ACT-{yy}-{CCC}-{NNNN}`.
  - `App\Model\Entity\Asset` con predicados `isDisponible()`, `isAsignado()`, `isDadoDeBaja()`.
  - `App\Model\Table\AssetsTable` con asociaciones (`AssetCategories`, `ResponsibleEmployees`→Employees, `OperationCenters`, `CostCenters`, `hasMany AssetMovements`, `hasMany AssetDocuments`), `beforeSave` que autogenera `code`, validación, rules, y finder `findFiltered(array $options)` (filtra por `status`, `category_id`, `responsible_employee_id`, `operation_center_id`, `q` sobre `code`/`serial_number`/`brand`/`model`).
  - `App\Test\Factory\AssetFactory` con `definition()` (status `disponible`, `code` `ACT-…`), helpers `withStatus(string)`, `withCategory(int)`, `withOperationCenter(int)`, `withResponsible(int)`.

- [ ] **Step 1: Write the failing tests**

`tests/TestCase/Model/Table/AssetsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetConstants;
use App\Test\Factory\AssetCategoryFactory;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\OperationCenterFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetsTableTest extends TestCase
{
    public function testRequiresCategoryAndOperationCenter(): void
    {
        $table = TableRegistry::getTableLocator()->get('Assets');
        $entity = $table->newEntity([]);
        $this->assertArrayHasKey('asset_category_id', $entity->getErrors());
        $this->assertArrayHasKey('operation_center_id', $entity->getErrors());
    }

    public function testBeforeSaveAutogeneratesCode(): void
    {
        $category = AssetCategoryFactory::new()->save();
        $center = OperationCenterFactory::new(['code' => '7'])->save();
        $table = TableRegistry::getTableLocator()->get('Assets');

        $asset = $table->newEntity([
            'asset_category_id' => $category->id,
            'operation_center_id' => $center->id,
            'status' => AssetConstants::STATUS_DISPONIBLE,
        ]);
        $table->saveOrFail($asset);

        $this->assertMatchesRegularExpression('/^ACT-\d{2}-007-0001$/', $asset->code);
    }

    public function testFindFilteredByStatusAndText(): void
    {
        AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)
            ->setField('serial_number', 'SN-ALPHA')->save();
        AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->setField('serial_number', 'SN-BETA')->save();

        $table = TableRegistry::getTableLocator()->get('Assets');

        $byStatus = $table->find('filtered', options: ['status' => AssetConstants::STATUS_ASIGNADO])->all();
        $this->assertCount(1, $byStatus);

        $byText = $table->find('filtered', options: ['q' => 'ALPHA'])->all();
        $this->assertCount(1, $byText);
    }
}
```

Add to `tests/TestCase/Service/CodeGeneratorServiceTest.php` a new method:

```php
    public function testGenerateAssetCode(): void
    {
        $center = \App\Test\Factory\OperationCenterFactory::new(['code' => '3'])->save();
        $code = (new \App\Service\CodeGeneratorService())->generateAssetCode($center->id);
        $this->assertMatchesRegularExpression('/^ACT-\d{2}-003-0001$/', $code);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetsTableTest.php tests/TestCase/Service/CodeGeneratorServiceTest.php`
Expected: FAIL — `Assets` table/factory y `generateAssetCode` no existen.

- [ ] **Step 3: Add `generateAssetCode` to CodeGeneratorService**

In `src/Service/CodeGeneratorService.php`, add the `use` import and a method (after `generateAdvanceInvoiceNumber`, before the private `generate`):

```php
use App\Constants\AssetConstants;
```

```php
    public function generateAssetCode(int $operationCenterId): string
    {
        return $this->generate(
            AssetConstants::CODE_PREFIX,
            'Assets',
            'code',
            $operationCenterId,
        );
    }
```

- [ ] **Step 4: Write the Asset entity, table and factory**

`src/Model/Entity/Asset.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\AssetConstants;
use Cake\ORM\Entity;

class Asset extends Entity
{
    protected array $_accessible = [
        'code' => false,
        'serial_number' => true,
        'asset_category_id' => true,
        'brand' => true,
        'model' => true,
        'description' => true,
        'status' => false,
        'responsible_employee_id' => false,
        'operation_center_id' => true,
        'cost_center_id' => true,
        'acquisition_date' => true,
        'observations' => true,
        'created' => false,
        'modified' => false,
    ];

    public function isDisponible(): bool
    {
        return ($this->status ?? '') === AssetConstants::STATUS_DISPONIBLE;
    }

    public function isAsignado(): bool
    {
        return ($this->status ?? '') === AssetConstants::STATUS_ASIGNADO;
    }

    public function isDadoDeBaja(): bool
    {
        return ($this->status ?? '') === AssetConstants::STATUS_DADO_DE_BAJA;
    }
}
```

`src/Model/Table/AssetsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use App\Service\CodeGeneratorService;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('assets');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AssetCategories', [
            'foreignKey' => 'asset_category_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ResponsibleEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'responsible_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CostCenters', [
            'foreignKey' => 'cost_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('AssetMovements', [
            'foreignKey' => 'asset_id',
        ]);
        $this->hasMany('AssetDocuments', [
            'foreignKey' => 'asset_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!$entity->isNew() || !empty($entity->code)) {
            return;
        }
        if (empty($entity->operation_center_id)) {
            return;
        }

        $generator = new CodeGeneratorService();
        $entity->code = $generator->generateAssetCode((int)$entity->operation_center_id);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->allowEmptyString('code');

        $validator
            ->scalar('serial_number')
            ->maxLength('serial_number', 100)
            ->allowEmptyString('serial_number');

        $validator
            ->integer('asset_category_id')
            ->requirePresence('asset_category_id', 'create')
            ->notEmptyString('asset_category_id', 'Selecciona una categoría.');

        $validator
            ->integer('operation_center_id')
            ->requirePresence('operation_center_id', 'create')
            ->notEmptyString('operation_center_id', 'Selecciona un centro de operación.');

        $validator->scalar('brand')->maxLength('brand', 100)->allowEmptyString('brand');
        $validator->scalar('model')->maxLength('model', 100)->allowEmptyString('model');
        $validator->scalar('description')->allowEmptyString('description');
        $validator->scalar('observations')->allowEmptyString('observations');
        $validator->integer('cost_center_id')->allowEmptyString('cost_center_id');
        $validator->integer('responsible_employee_id')->allowEmptyString('responsible_employee_id');
        $validator->date('acquisition_date')->allowEmptyDate('acquisition_date');

        $validator
            ->scalar('status')
            ->inList('status', AssetConstants::STATUSES);

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code'], 'El código ya existe.'), ['errorField' => 'code', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('asset_category_id', 'AssetCategories'), ['errorField' => 'asset_category_id']);
        $rules->add($rules->existsIn('operation_center_id', 'OperationCenters'), ['errorField' => 'operation_center_id']);
        $rules->add(
            $rules->existsIn('responsible_employee_id', 'ResponsibleEmployees'),
            ['errorField' => 'responsible_employee_id', 'allowNullableNulls' => true],
        );
        $rules->add(
            $rules->existsIn('cost_center_id', 'CostCenters'),
            ['errorField' => 'cost_center_id', 'allowNullableNulls' => true],
        );

        return $rules;
    }

    /**
     * Filtra el listado de activos. `$options` admite: status, category_id,
     * responsible_employee_id, operation_center_id, q (texto parcial sobre
     * code/serial_number/brand/model).
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query base.
     * @param array<string, mixed> $options Filtros.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findFiltered(SelectQuery $query, array $options = []): SelectQuery
    {
        if (!empty($options['status'])) {
            $query->where(['Assets.status' => $options['status']]);
        }
        if (!empty($options['category_id'])) {
            $query->where(['Assets.asset_category_id' => (int)$options['category_id']]);
        }
        if (!empty($options['responsible_employee_id'])) {
            $query->where(['Assets.responsible_employee_id' => (int)$options['responsible_employee_id']]);
        }
        if (!empty($options['operation_center_id'])) {
            $query->where(['Assets.operation_center_id' => (int)$options['operation_center_id']]);
        }
        if (!empty($options['q'])) {
            $term = '%' . trim((string)$options['q']) . '%';
            $query->where([
                'OR' => [
                    'Assets.code LIKE' => $term,
                    'Assets.serial_number LIKE' => $term,
                    'Assets.brand LIKE' => $term,
                    'Assets.model LIKE' => $term,
                ],
            ]);
        }

        return $query;
    }
}
```

`tests/Factory/AssetFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\AssetConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de Asset. Auto-crea los parents NOT NULL (asset_category_id,
 * operation_center_id) vía withRequiredParents.
 */
class AssetFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Assets';
    }

    public static function new(mixed $makeParameter = [], int $times = 1): static
    {
        return parent::new($makeParameter, $times)->withRequiredParents();
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'code' => 'ACT-' . Seq::next(),
            'status' => AssetConstants::STATUS_DISPONIBLE,
            'brand' => $generator->word(),
            'model' => $generator->word(),
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('status', $status);
    }

    public function withCategory(int $categoryId): static
    {
        return $this->setField('asset_category_id', $categoryId);
    }

    public function withOperationCenter(int $operationCenterId): static
    {
        return $this->setField('operation_center_id', $operationCenterId);
    }

    public function withResponsible(int $employeeId): static
    {
        return $this->setField('responsible_employee_id', $employeeId);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetsTableTest.php tests/TestCase/Service/CodeGeneratorServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Model/Entity/Asset.php src/Model/Table/AssetsTable.php tests/Factory/AssetFactory.php src/Service/CodeGeneratorService.php tests/TestCase/Model/Table/AssetsTableTest.php tests/TestCase/Service/CodeGeneratorServiceTest.php
git commit -m "feat(itam): Asset entity/table/factory + generateAssetCode + finder findFiltered"
```

---

### Task 6: AssetMovement — entity y table (log inmutable)

**Files:**
- Create: `src/Model/Entity/AssetMovement.php`
- Create: `src/Model/Table/AssetMovementsTable.php`
- Test: `tests/TestCase/Model/Table/AssetMovementsTableTest.php`

**Interfaces:**
- Produces: `App\Model\Table\AssetMovementsTable` con Timestamp solo-`created`, asociaciones (`Assets`, `FromEmployees`/`ToEmployees`→Employees, `FromOperationCenters`/`ToOperationCenters`→OperationCenters, `PerformedByUsers`→Users, `RequestedByEmployees`→Employees), validación (`movement_type` inList, `source` inList, `acta_status` inList nullable, `movement_date` requerido), y finder `findForAsset(int $assetId)` (orden `movement_date DESC`). `App\Model\Entity\AssetMovement` con `$_accessible` completo (las columnas se setean por el servicio).

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Model/Table/AssetMovementsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetMovementsTableTest extends TestCase
{
    public function testRejectsInvalidMovementType(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetMovements');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'movement_type' => 'no_existe',
            'movement_date' => '2026-06-19 10:00:00',
            'performed_by_user_id' => 1,
            'source' => AssetConstants::SOURCE_WEB,
        ]);
        $this->assertArrayHasKey('movement_type', $entity->getErrors());
    }

    public function testAcceptsValidMovement(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetMovements');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'movement_type' => AssetConstants::MOVEMENT_ENTREGA,
            'movement_date' => '2026-06-19 10:00:00',
            'performed_by_user_id' => 1,
            'acta_status' => AssetConstants::ACTA_PENDIENTE,
            'source' => AssetConstants::SOURCE_WEB,
        ]);
        $this->assertSame([], $entity->getErrors());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetMovementsTableTest.php`
Expected: FAIL — table no existe.

- [ ] **Step 3: Write entity and table**

`src/Model/Entity/AssetMovement.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AssetMovement extends Entity
{
    protected array $_accessible = [
        'asset_id' => true,
        'movement_type' => true,
        'from_employee_id' => true,
        'to_employee_id' => true,
        'from_operation_center_id' => true,
        'to_operation_center_id' => true,
        'reason' => true,
        'movement_date' => true,
        'acta_status' => true,
        'performed_by_user_id' => true,
        'requested_by_phone' => true,
        'requested_by_employee_id' => true,
        'source' => true,
        'created' => false,
    ];
}
```

`src/Model/Table/AssetMovementsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetMovementsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_movements');
        $this->setDisplayField('movement_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Assets', [
            'foreignKey' => 'asset_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('FromEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'from_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('ToEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'to_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('FromOperationCenters', [
            'className' => 'OperationCenters',
            'foreignKey' => 'from_operation_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('ToOperationCenters', [
            'className' => 'OperationCenters',
            'foreignKey' => 'to_operation_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PerformedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'performed_by_user_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('RequestedByEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'requested_by_employee_id',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('asset_id')
            ->requirePresence('asset_id', 'create')
            ->notEmptyString('asset_id');

        $validator
            ->scalar('movement_type')
            ->requirePresence('movement_type', 'create')
            ->inList('movement_type', AssetConstants::MOVEMENT_TYPES, 'Tipo de movimiento inválido.');

        $validator
            ->dateTime('movement_date')
            ->requirePresence('movement_date', 'create')
            ->notEmptyDateTime('movement_date');

        $validator
            ->integer('performed_by_user_id')
            ->requirePresence('performed_by_user_id', 'create')
            ->notEmptyString('performed_by_user_id');

        $validator
            ->scalar('acta_status')
            ->inList('acta_status', AssetConstants::ACTA_STATUSES, 'Estado de acta inválido.')
            ->allowEmptyString('acta_status');

        $validator
            ->scalar('source')
            ->inList('source', AssetConstants::SOURCES)
            ->notEmptyString('source');

        $validator->scalar('reason')->allowEmptyString('reason');
        $validator->scalar('requested_by_phone')->maxLength('requested_by_phone', 30)->allowEmptyString('requested_by_phone');
        $validator->integer('from_employee_id')->allowEmptyString('from_employee_id');
        $validator->integer('to_employee_id')->allowEmptyString('to_employee_id');
        $validator->integer('from_operation_center_id')->allowEmptyString('from_operation_center_id');
        $validator->integer('to_operation_center_id')->allowEmptyString('to_operation_center_id');
        $validator->integer('requested_by_employee_id')->allowEmptyString('requested_by_employee_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('asset_id', 'Assets'), ['errorField' => 'asset_id']);
        $rules->add($rules->existsIn('performed_by_user_id', 'PerformedByUsers'), ['errorField' => 'performed_by_user_id']);

        return $rules;
    }

    /**
     * Movimientos de un activo, más reciente primero.
     */
    public function findForAsset(SelectQuery $query, int $assetId): SelectQuery
    {
        return $query
            ->where(['AssetMovements.asset_id' => $assetId])
            ->orderBy(['AssetMovements.movement_date' => 'DESC', 'AssetMovements.id' => 'DESC']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetMovementsTableTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/AssetMovement.php src/Model/Table/AssetMovementsTable.php tests/TestCase/Model/Table/AssetMovementsTableTest.php
git commit -m "feat(itam): AssetMovement entity/table (log inmutable) + finder findForAsset"
```

---

### Task 7: AssetDocument — entity y table

**Files:**
- Create: `src/Model/Entity/AssetDocument.php`
- Create: `src/Model/Table/AssetDocumentsTable.php`
- Test: `tests/TestCase/Model/Table/AssetDocumentsTableTest.php`

**Interfaces:**
- Produces: `App\Model\Table\AssetDocumentsTable` con Timestamp, asociaciones (`Assets`, `AssetMovements`, `UploadedByUsers`→Users), validación (`document_type` inList, `name`/`file_path` requeridos), finder `findForAsset(int $assetId)`. `App\Model\Entity\AssetDocument` con `$_accessible`.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Model/Table/AssetDocumentsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetDocumentsTableTest extends TestCase
{
    public function testRejectsInvalidDocumentType(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetDocuments');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'document_type' => 'no_existe',
            'name' => 'acta.pdf',
            'file_path' => 'assets/1/acta.pdf',
            'uploaded_by' => 1,
        ]);
        $this->assertArrayHasKey('document_type', $entity->getErrors());
    }

    public function testAcceptsValidActa(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetDocuments');
        $entity = $table->newEntity([
            'asset_id' => 1,
            'document_type' => AssetConstants::DOCTYPE_ACTA,
            'name' => 'acta.pdf',
            'file_path' => 'assets/1/acta.pdf',
            'uploaded_by' => 1,
        ]);
        $this->assertSame([], $entity->getErrors());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetDocumentsTableTest.php`
Expected: FAIL — table no existe.

- [ ] **Step 3: Write entity and table**

`src/Model/Entity/AssetDocument.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AssetDocument extends Entity
{
    protected array $_accessible = [
        'asset_id' => true,
        'asset_movement_id' => true,
        'document_type' => true,
        'name' => true,
        'file_path' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
        'created' => false,
        'modified' => false,
    ];
}
```

`src/Model/Table/AssetDocumentsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetDocumentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_documents');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Assets', [
            'foreignKey' => 'asset_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('AssetMovements', [
            'foreignKey' => 'asset_movement_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('asset_id')
            ->requirePresence('asset_id', 'create')
            ->notEmptyString('asset_id');

        $validator
            ->scalar('document_type')
            ->requirePresence('document_type', 'create')
            ->inList('document_type', AssetConstants::DOCUMENT_TYPES, 'Tipo de documento inválido.');

        $validator
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 255)
            ->requirePresence('file_path', 'create')
            ->notEmptyString('file_path');

        $validator
            ->integer('uploaded_by')
            ->requirePresence('uploaded_by', 'create')
            ->notEmptyString('uploaded_by');

        $validator->integer('file_size')->allowEmptyString('file_size');
        $validator->scalar('mime_type')->maxLength('mime_type', 100)->allowEmptyString('mime_type');
        $validator->integer('asset_movement_id')->allowEmptyString('asset_movement_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('asset_id', 'Assets'), ['errorField' => 'asset_id']);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), ['errorField' => 'uploaded_by']);
        $rules->add(
            $rules->existsIn('asset_movement_id', 'AssetMovements'),
            ['errorField' => 'asset_movement_id', 'allowNullableNulls' => true],
        );

        return $rules;
    }

    /**
     * Documentos de un activo, más reciente primero.
     */
    public function findForAsset(SelectQuery $query, int $assetId): SelectQuery
    {
        return $query
            ->where(['AssetDocuments.asset_id' => $assetId])
            ->orderBy(['AssetDocuments.created' => 'DESC']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetDocumentsTableTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/AssetDocument.php src/Model/Table/AssetDocumentsTable.php tests/TestCase/Model/Table/AssetDocumentsTableTest.php
git commit -m "feat(itam): AssetDocument entity/table + finder findForAsset"
```

---

### Task 8: Consumable + ConsumableMovement — entities, tables y factory

**Files:**
- Create: `src/Model/Entity/Consumable.php`
- Create: `src/Model/Entity/ConsumableMovement.php`
- Create: `src/Model/Table/ConsumablesTable.php`
- Create: `src/Model/Table/ConsumableMovementsTable.php`
- Create: `tests/Factory/ConsumableFactory.php`
- Test: `tests/TestCase/Model/Table/ConsumablesTableTest.php`

**Interfaces:**
- Produces:
  - `App\Model\Table\ConsumablesTable` (displayField `description`, `hasMany ConsumableMovements`, `belongsTo OperationCenters`, validación `reference`/`description` requeridos + `reference` única + stocks enteros ≥0, finder `findLowStock` = `current_stock <= minimum_stock`).
  - `App\Model\Table\ConsumableMovementsTable` (Timestamp solo-`created`, `belongsTo Consumables`/`PerformedByUsers`/`RelatedAssets`→Assets, validación `movement_type` inList + `quantity`/`balance_after` enteros, finder `findForConsumable(int)`).
  - `App\Test\Factory\ConsumableFactory` con `definition()` y helpers `withStock(int $current, int $minimum)`.
  - Entities con `$_accessible` (en `Consumable`: `current_stock` NO accesible — lo gestiona el servicio).

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Model/Table/ConsumablesTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\ConsumableFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class ConsumablesTableTest extends TestCase
{
    public function testRequiresReferenceAndDescription(): void
    {
        $table = TableRegistry::getTableLocator()->get('Consumables');
        $entity = $table->newEntity([]);
        $this->assertArrayHasKey('reference', $entity->getErrors());
        $this->assertArrayHasKey('description', $entity->getErrors());
    }

    public function testFindLowStockReturnsAtOrBelowMinimum(): void
    {
        ConsumableFactory::new()->withStock(2, 5)->save();   // bajo
        ConsumableFactory::new()->withStock(10, 5)->save();  // ok
        ConsumableFactory::new()->withStock(5, 5)->save();   // en el mínimo => bajo

        $table = TableRegistry::getTableLocator()->get('Consumables');
        $low = $table->find('lowStock')->all();

        $this->assertCount(2, $low);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/ConsumablesTableTest.php`
Expected: FAIL — tables/factory no existen.

- [ ] **Step 3: Write entities, tables and factory**

`src/Model/Entity/Consumable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Consumable extends Entity
{
    protected array $_accessible = [
        'reference' => true,
        'description' => true,
        'current_stock' => false,
        'minimum_stock' => true,
        'maximum_stock' => true,
        'operation_center_id' => true,
        'unit' => true,
        'created' => false,
        'modified' => false,
    ];

    public function isLowStock(): bool
    {
        return (int)($this->current_stock ?? 0) <= (int)($this->minimum_stock ?? 0);
    }
}
```

`src/Model/Entity/ConsumableMovement.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class ConsumableMovement extends Entity
{
    protected array $_accessible = [
        'consumable_id' => true,
        'movement_type' => true,
        'quantity' => true,
        'balance_after' => true,
        'reason' => true,
        'related_asset_id' => true,
        'movement_date' => true,
        'performed_by_user_id' => true,
        'requested_by_phone' => true,
        'source' => true,
        'created' => false,
    ];
}
```

`src/Model/Table/ConsumablesTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ConsumablesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('consumables');
        $this->setDisplayField('description');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('ConsumableMovements', [
            'foreignKey' => 'consumable_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('reference')
            ->maxLength('reference', 50)
            ->requirePresence('reference', 'create')
            ->notEmptyString('reference', 'La referencia es requerida.');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->requirePresence('description', 'create')
            ->notEmptyString('description', 'La descripción es requerida.');

        $validator
            ->integer('minimum_stock')
            ->greaterThanOrEqual('minimum_stock', 0)
            ->notEmptyString('minimum_stock');

        $validator
            ->integer('maximum_stock')
            ->greaterThanOrEqual('maximum_stock', 0)
            ->allowEmptyString('maximum_stock');

        $validator->scalar('unit')->maxLength('unit', 20)->allowEmptyString('unit');
        $validator->integer('operation_center_id')->allowEmptyString('operation_center_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['reference'], 'La referencia ya existe.'), ['errorField' => 'reference']);
        $rules->add(
            $rules->existsIn('operation_center_id', 'OperationCenters'),
            ['errorField' => 'operation_center_id', 'allowNullableNulls' => true],
        );

        return $rules;
    }

    /**
     * Consumibles con stock en o por debajo del mínimo (RN-07).
     */
    public function findLowStock(SelectQuery $query): SelectQuery
    {
        return $query->where(['Consumables.current_stock <= Consumables.minimum_stock']);
    }
}
```

`src/Model/Table/ConsumableMovementsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use App\Constants\ConsumableConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ConsumableMovementsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('consumable_movements');
        $this->setDisplayField('movement_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Consumables', [
            'foreignKey' => 'consumable_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('PerformedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'performed_by_user_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('RelatedAssets', [
            'className' => 'Assets',
            'foreignKey' => 'related_asset_id',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('consumable_id')
            ->requirePresence('consumable_id', 'create')
            ->notEmptyString('consumable_id');

        $validator
            ->scalar('movement_type')
            ->requirePresence('movement_type', 'create')
            ->inList('movement_type', ConsumableConstants::MOVEMENT_TYPES, 'Tipo de movimiento inválido.');

        $validator
            ->integer('quantity')
            ->requirePresence('quantity', 'create')
            ->notEmptyString('quantity');

        $validator
            ->integer('balance_after')
            ->requirePresence('balance_after', 'create')
            ->notEmptyString('balance_after');

        $validator
            ->dateTime('movement_date')
            ->requirePresence('movement_date', 'create')
            ->notEmptyDateTime('movement_date');

        $validator
            ->integer('performed_by_user_id')
            ->requirePresence('performed_by_user_id', 'create')
            ->notEmptyString('performed_by_user_id');

        $validator
            ->scalar('source')
            ->inList('source', AssetConstants::SOURCES)
            ->notEmptyString('source');

        $validator->scalar('reason')->allowEmptyString('reason');
        $validator->integer('related_asset_id')->allowEmptyString('related_asset_id');
        $validator->scalar('requested_by_phone')->maxLength('requested_by_phone', 30)->allowEmptyString('requested_by_phone');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('consumable_id', 'Consumables'), ['errorField' => 'consumable_id']);
        $rules->add($rules->existsIn('performed_by_user_id', 'PerformedByUsers'), ['errorField' => 'performed_by_user_id']);

        return $rules;
    }

    /**
     * Movimientos de stock de un consumible, más reciente primero.
     */
    public function findForConsumable(SelectQuery $query, int $consumableId): SelectQuery
    {
        return $query
            ->where(['ConsumableMovements.consumable_id' => $consumableId])
            ->orderBy(['ConsumableMovements.movement_date' => 'DESC', 'ConsumableMovements.id' => 'DESC']);
    }
}
```

`tests/Factory/ConsumableFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class ConsumableFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Consumables';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'reference' => 'REF-' . Seq::next(),
            'description' => $generator->word(),
            'current_stock' => 10,
            'minimum_stock' => 2,
        ];
    }

    public function withStock(int $current, int $minimum): static
    {
        return $this->setField('current_stock', $current)->setField('minimum_stock', $minimum);
    }
}
```

> **Nota:** `current_stock` no es accesible en la entity, pero las Factories de cakephp-fixture-factories escriben directo a la tabla (bypassean `$_accessible`), por eso `withStock` puede setearlo.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/ConsumablesTableTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/Consumable.php src/Model/Entity/ConsumableMovement.php src/Model/Table/ConsumablesTable.php src/Model/Table/ConsumableMovementsTable.php tests/Factory/ConsumableFactory.php tests/TestCase/Model/Table/ConsumablesTableTest.php
git commit -m "feat(itam): Consumable + ConsumableMovement entities/tables/factory + finders"
```

---

### Task 9: AssetAlert — entity y table

**Files:**
- Create: `src/Model/Entity/AssetAlert.php`
- Create: `src/Model/Table/AssetAlertsTable.php`
- Test: `tests/TestCase/Model/Table/AssetAlertsTableTest.php`

**Interfaces:**
- Produces: `App\Model\Table\AssetAlertsTable` (Timestamp, `belongsTo Assets`/`Consumables`/`AssetMovements`, validación `alert_type`/`status`/`priority` inList + `message` requerido, finders `findOpen` = `status = abierta` y `findByStatus(string)`). `App\Model\Entity\AssetAlert` con `$_accessible` (`status`/`notified_at`/`resolved_at` los gestiona el servicio).

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Model/Table/AssetAlertsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetAlertConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetAlertsTableTest extends TestCase
{
    public function testRejectsInvalidType(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetAlerts');
        $entity = $table->newEntity([
            'alert_type' => 'no_existe',
            'message' => 'x',
            'priority' => AssetAlertConstants::PRIORITY_MEDIA,
            'status' => AssetAlertConstants::STATUS_ABIERTA,
        ]);
        $this->assertArrayHasKey('alert_type', $entity->getErrors());
    }

    public function testFindOpenReturnsOnlyOpen(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetAlerts');
        $table->saveOrFail($table->newEntity([
            'alert_type' => AssetAlertConstants::TYPE_STOCK_BAJO,
            'message' => 'Stock bajo en tóner',
            'priority' => AssetAlertConstants::PRIORITY_ALTA,
            'status' => AssetAlertConstants::STATUS_ABIERTA,
        ]));
        $table->saveOrFail($table->newEntity([
            'alert_type' => AssetAlertConstants::TYPE_STOCK_BAJO,
            'message' => 'Resuelta',
            'priority' => AssetAlertConstants::PRIORITY_BAJA,
            'status' => AssetAlertConstants::STATUS_RESUELTA,
        ]));

        $this->assertCount(1, $table->find('open')->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetAlertsTableTest.php`
Expected: FAIL — table no existe.

- [ ] **Step 3: Write entity and table**

`src/Model/Entity/AssetAlert.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AssetAlert extends Entity
{
    protected array $_accessible = [
        'alert_type' => true,
        'priority' => true,
        'asset_id' => true,
        'consumable_id' => true,
        'asset_movement_id' => true,
        'message' => true,
        'status' => false,
        'notified_at' => false,
        'resolved_at' => false,
        'created' => false,
        'modified' => false,
    ];
}
```

`src/Model/Table/AssetAlertsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetAlertConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetAlertsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_alerts');
        $this->setDisplayField('message');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Assets', [
            'foreignKey' => 'asset_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Consumables', [
            'foreignKey' => 'consumable_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('AssetMovements', [
            'foreignKey' => 'asset_movement_id',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('alert_type')
            ->requirePresence('alert_type', 'create')
            ->inList('alert_type', AssetAlertConstants::TYPES, 'Tipo de alerta inválido.');

        $validator
            ->scalar('priority')
            ->inList('priority', AssetAlertConstants::PRIORITIES, 'Prioridad inválida.')
            ->notEmptyString('priority');

        $validator
            ->scalar('status')
            ->inList('status', AssetAlertConstants::STATUSES, 'Estado inválido.')
            ->notEmptyString('status');

        $validator
            ->scalar('message')
            ->maxLength('message', 255)
            ->requirePresence('message', 'create')
            ->notEmptyString('message');

        $validator->integer('asset_id')->allowEmptyString('asset_id');
        $validator->integer('consumable_id')->allowEmptyString('consumable_id');
        $validator->integer('asset_movement_id')->allowEmptyString('asset_movement_id');
        $validator->dateTime('notified_at')->allowEmptyDateTime('notified_at');
        $validator->dateTime('resolved_at')->allowEmptyDateTime('resolved_at');

        return $validator;
    }

    /**
     * Alertas abiertas, prioridad alta primero, luego más recientes.
     */
    public function findOpen(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['AssetAlerts.status' => AssetAlertConstants::STATUS_ABIERTA])
            ->orderBy(['AssetAlerts.priority' => 'ASC', 'AssetAlerts.created' => 'DESC']);
    }

    /**
     * Alertas por estado.
     */
    public function findByStatus(SelectQuery $query, string $status): SelectQuery
    {
        return $query
            ->where(['AssetAlerts.status' => $status])
            ->orderBy(['AssetAlerts.created' => 'DESC']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Model/Table/AssetAlertsTableTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the whole Fase 1 suite + cs-check**

Run: `vendor/bin/phpunit tests/TestCase/Model tests/TestCase/Constants tests/TestCase/Service/CodeGeneratorServiceTest.php` then `composer cs-check`
Expected: todo verde.

- [ ] **Step 6: Commit**

```bash
git add src/Model/Entity/AssetAlert.php src/Model/Table/AssetAlertsTable.php tests/TestCase/Model/Table/AssetAlertsTableTest.php
git commit -m "feat(itam): AssetAlert entity/table + finders findOpen/findByStatus"
```

---

# FASE 2 — Servicios de dominio

Resultado verificable: los servicios registran movimientos atómicamente, actualizan el agregado y marcan actas; los documentos se guardan en storage privado. Tests de integración con Factories pasan.

---

### Task 10: AssetInventoryService — `registerIngress` + `assign` (+ EmployeeFactory)

**Files:**
- Create: `src/Service/AssetInventoryService.php`
- Create: `tests/Factory/EmployeeFactory.php`
- Test: `tests/TestCase/Service/Integration/AssetInventoryServiceTest.php`

**Interfaces:**
- Consumes: `AssetsTable`, `AssetMovementsTable`, `AssetConstants`, `App\Constants\Domain\Asset\MovementType`, `ServiceResult`. Factories: `AssetFactory`, `EmployeeFactory`, `UserFactory`.
- Produces:
  - `App\Service\AssetInventoryService` con (esta tarea) `registerIngress(int $assetId, array $data, int $userId): ServiceResult` y `assign(int $assetId, int $toEmployeeId, array $data, int $userId): ServiceResult`, más los helpers privados `_run`, `_baseMovementData`, `_commit`, `_saveError` que las demás operaciones (Task 11) reutilizan. En `->data` retorna `['asset' => Asset, 'movement' => AssetMovement, 'message' => string]`.
  - `App\Test\Factory\EmployeeFactory` con `definition()` (document_number único, first_name, last_name, active true).
  - **Contrato de `$data`** (compartido por todas las operaciones): claves opcionales `reason` (string|null), `movement_date` (string `Y-m-d H:i:s`, default ahora), `source` (default `web`), `requested_by_phone` (null), `requested_by_employee_id` (null).
  - **Contrato de actas**: las operaciones cuyo `movement_type` cumple `MovementType::requiresActa()` (entrega, prestamo, devolucion, baja) graban `acta_status = pendiente` en el movimiento; las demás `null`.

- [ ] **Step 1: Write EmployeeFactory and the failing test**

`tests/Factory/EmployeeFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory mínima de Employee. Todas las FK de employees son nullable, así que
 * no requiere parents. document_number es único.
 */
class EmployeeFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Employees';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'document_type' => 'CC',
            'document_number' => 'DOC-' . Seq::next(),
            'first_name' => $generator->firstName(),
            'last_name' => $generator->lastName(),
            'active' => true,
        ];
    }
}
```

`tests/TestCase/Service/Integration/AssetInventoryServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetConstants;
use App\Service\AssetInventoryService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

final class AssetInventoryServiceTest extends TestCase
{
    private function service(): AssetInventoryService
    {
        return new AssetInventoryService();
    }

    public function testRegisterIngressLogsMovementAndKeepsAvailable(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerIngress($asset->id, ['reason' => 'Compra inicial'], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_DISPONIBLE, $persisted->status);

        $movements = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->all()->toArray();
        $this->assertCount(1, $movements);
        $this->assertSame(AssetConstants::MOVEMENT_INGRESO, $movements[0]->movement_type);
        $this->assertNull($movements[0]->acta_status);
    }

    public function testAssignSetsResponsibleStatusAndPendingActa(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $employee = EmployeeFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->assign($asset->id, $employee->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_ASIGNADO, $persisted->status);
        $this->assertSame($employee->id, $persisted->responsible_employee_id);

        $movement = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->firstOrFail();
        $this->assertSame(AssetConstants::MOVEMENT_ENTREGA, $movement->movement_type);
        $this->assertSame($employee->id, $movement->to_employee_id);
        $this->assertSame(AssetConstants::ACTA_PENDIENTE, $movement->acta_status);
    }

    public function testAssignFailsWhenAssetNotAvailable(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)->save();
        $employee = EmployeeFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->assign($asset->id, $employee->id, [], $user->id);

        $this->assertFalse($result->success);
        $this->assertCount(0, $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->all()->toArray());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetInventoryServiceTest.php`
Expected: FAIL — `AssetInventoryService` no existe.

- [ ] **Step 3: Write the service (skeleton + helpers + registerIngress + assign)**

`src/Service/AssetInventoryService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetConstants;
use App\Constants\Domain\Asset\MovementType;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * Operaciones del inventario de activos. Cada operación es una transacción
 * atómica que (1) inserta el movimiento inmutable, (2) actualiza el activo y
 * (3) marca acta pendiente si el tipo de movimiento lo requiere.
 *
 * No es un pipeline: los movimientos son un log, no un flujo de aprobación.
 */
class AssetInventoryService
{
    /**
     * Registra un movimiento de ingreso. Deja el activo en disponible.
     *
     * @param int $assetId Activo.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function registerIngress(int $assetId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use ($assetId, $data, $userId): ServiceResult {
            if ($asset->status === AssetConstants::STATUS_DADO_DE_BAJA) {
                return ServiceResult::fail('No se puede ingresar un activo dado de baja.');
            }

            $asset->status = AssetConstants::STATUS_DISPONIBLE;

            $movement = $movements->newEntity(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_INGRESO, $userId, $data),
            );

            return $this->_commit($assets, $movements, $asset, $movement, 'Ingreso registrado.');
        });
    }

    /**
     * Entrega un activo disponible a un empleado. Marca acta pendiente.
     *
     * @param int $assetId Activo.
     * @param int $toEmployeeId Empleado responsable.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function assign(int $assetId, int $toEmployeeId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use ($assetId, $toEmployeeId, $data, $userId): ServiceResult {
            if ($asset->status !== AssetConstants::STATUS_DISPONIBLE) {
                return ServiceResult::fail('Solo se puede asignar un activo disponible.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = $toEmployeeId;
            $asset->status = AssetConstants::STATUS_ASIGNADO;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_ENTREGA, $userId, $data),
                ['from_employee_id' => $fromEmployee, 'to_employee_id' => $toEmployeeId],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo asignado correctamente.');
        });
    }

    /**
     * Abre la transacción, lee el activo con lock y delega la operación.
     * `$operation` recibe (Asset, AssetsTable, AssetMovementsTable) y retorna
     * ServiceResult; success=true commitea, false hace rollback.
     *
     * @param int $assetId Activo.
     * @param callable(\Cake\Datasource\EntityInterface, \Cake\ORM\Table, \Cake\ORM\Table): \App\Service\ServiceResult $operation
     */
    protected function _run(int $assetId, callable $operation): ServiceResult
    {
        $assets = TableRegistry::getTableLocator()->get('Assets');
        $movements = TableRegistry::getTableLocator()->get('AssetMovements');
        $connection = $assets->getConnection();

        $result = null;

        $connection->transactional(function () use ($assets, $movements, $assetId, $operation, &$result): bool {
            $asset = $assets->find()->where(['Assets.id' => $assetId])->epilog('FOR UPDATE')->first();
            if ($asset === null) {
                $result = ServiceResult::fail('Activo no encontrado.');

                return false;
            }

            $result = $operation($asset, $assets, $movements);

            return $result->success;
        });

        return $result ?? ServiceResult::fail('No se pudo completar la operación.');
    }

    /**
     * Campos comunes de un movimiento, con acta pendiente si el tipo lo exige.
     *
     * @param array<string, mixed> $data Metadatos.
     * @return array<string, mixed>
     */
    protected function _baseMovementData(int $assetId, string $movementType, int $userId, array $data): array
    {
        $requiresActa = MovementType::from($movementType)->requiresActa();

        return [
            'asset_id' => $assetId,
            'movement_type' => $movementType,
            'performed_by_user_id' => $userId,
            'movement_date' => $data['movement_date'] ?? date('Y-m-d H:i:s'),
            'reason' => $data['reason'] ?? null,
            'source' => $data['source'] ?? AssetConstants::SOURCE_WEB,
            'requested_by_phone' => $data['requested_by_phone'] ?? null,
            'requested_by_employee_id' => $data['requested_by_employee_id'] ?? null,
            'acta_status' => $requiresActa ? AssetConstants::ACTA_PENDIENTE : null,
        ];
    }

    /**
     * Persiste activo + movimiento dentro de la transacción abierta por _run.
     */
    protected function _commit(
        Table $assets,
        Table $movements,
        EntityInterface $asset,
        EntityInterface $movement,
        string $successMessage,
    ): ServiceResult {
        if (!$assets->save($asset)) {
            return ServiceResult::fail($this->_saveError('No se pudo actualizar el activo.', $asset->getErrors()));
        }
        if (!$movements->save($movement)) {
            return ServiceResult::fail($this->_saveError('No se pudo registrar el movimiento.', $movement->getErrors()));
        }

        return ServiceResult::ok([
            'asset' => $asset,
            'movement' => $movement,
            'message' => $successMessage,
        ]);
    }

    /**
     * Compone un mensaje de error legible incluyendo detalles de validación.
     *
     * @param array<string, mixed> $entityErrors Errores de Entity::getErrors().
     */
    protected function _saveError(string $base, array $entityErrors): string
    {
        $details = [];
        foreach ($entityErrors as $field => $fieldErrors) {
            foreach ((array)$fieldErrors as $msg) {
                if (is_string($msg) && $msg !== '') {
                    $details[] = sprintf('%s: %s', $field, $msg);
                }
            }
        }

        return $details === [] ? $base : ($base . ' ' . implode(', ', $details));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetInventoryServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/AssetInventoryService.php tests/Factory/EmployeeFactory.php tests/TestCase/Service/Integration/AssetInventoryServiceTest.php
git commit -m "feat(itam): AssetInventoryService registerIngress/assign + EmployeeFactory"
```

---

### Task 11: AssetInventoryService — `lend`, `returnAsset`, `transfer`, `dispose`

**Files:**
- Modify: `src/Service/AssetInventoryService.php` (añadir 4 métodos públicos)
- Test: `tests/TestCase/Service/Integration/AssetInventoryServiceTransitionsTest.php`

**Interfaces:**
- Consumes: helpers `_run`/`_baseMovementData`/`_commit` (Task 10).
- Produces (añade a `AssetInventoryService`):
  - `lend(int $assetId, int $toEmployeeId, array $data, int $userId): ServiceResult` — requiere disponible; status → `prestado`, set responsible, acta pendiente.
  - `returnAsset(int $assetId, array $data, int $userId): ServiceResult` — requiere asignado o prestado; status → `disponible`, limpia responsible, `from_employee` = responsable previo, acta pendiente.
  - `transfer(int $assetId, int $toOperationCenterId, array $data, int $userId): ServiceResult` — requiere no terminal; cambia `operation_center_id` (sin cambio de status), graba `from_operation_center_id`/`to_operation_center_id`, sin acta.
  - `dispose(int $assetId, array $data, int $userId): ServiceResult` — requiere no terminal; status → `dado_de_baja`, limpia responsible, acta pendiente.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Service/Integration/AssetInventoryServiceTransitionsTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetConstants;
use App\Service\AssetInventoryService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\OperationCenterFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

final class AssetInventoryServiceTransitionsTest extends TestCase
{
    private function service(): AssetInventoryService
    {
        return new AssetInventoryService();
    }

    public function testLendSetsPrestado(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $employee = EmployeeFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->lend($asset->id, $employee->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_PRESTADO, $persisted->status);
        $this->assertSame($employee->id, $persisted->responsible_employee_id);
    }

    public function testReturnClearsResponsibleAndSetsAvailable(): void
    {
        $employee = EmployeeFactory::new()->save();
        $asset = AssetFactory::new()
            ->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->withResponsible($employee->id)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->returnAsset($asset->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_DISPONIBLE, $persisted->status);
        $this->assertNull($persisted->responsible_employee_id);

        $movement = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->firstOrFail();
        $this->assertSame($employee->id, $movement->from_employee_id);
        $this->assertSame(AssetConstants::ACTA_PENDIENTE, $movement->acta_status);
    }

    public function testReturnFailsWhenAvailable(): void
    {
        $asset = AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->returnAsset($asset->id, [], $user->id);
        $this->assertFalse($result->success);
    }

    public function testTransferChangesOperationCenterWithoutStatusChange(): void
    {
        $origin = OperationCenterFactory::new()->save();
        $destination = OperationCenterFactory::new()->save();
        $asset = AssetFactory::new()
            ->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->withOperationCenter($origin->id)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->transfer($asset->id, $destination->id, [], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame($destination->id, $persisted->operation_center_id);
        $this->assertSame(AssetConstants::STATUS_ASIGNADO, $persisted->status);

        $movement = $this->fetchTable('AssetMovements')->find()->where(['asset_id' => $asset->id])->firstOrFail();
        $this->assertSame($origin->id, $movement->from_operation_center_id);
        $this->assertSame($destination->id, $movement->to_operation_center_id);
        $this->assertNull($movement->acta_status);
    }

    public function testDisposeMakesTerminalAndClearsResponsible(): void
    {
        $employee = EmployeeFactory::new()->save();
        $asset = AssetFactory::new()
            ->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->withResponsible($employee->id)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->dispose($asset->id, ['reason' => 'Obsoleto'], $user->id);

        $this->assertTrue($result->success);
        $persisted = $this->fetchTable('Assets')->get($asset->id);
        $this->assertSame(AssetConstants::STATUS_DADO_DE_BAJA, $persisted->status);
        $this->assertNull($persisted->responsible_employee_id);

        // Terminal: no se puede volver a operar.
        $again = $this->service()->dispose($asset->id, [], $user->id);
        $this->assertFalse($again->success);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetInventoryServiceTransitionsTest.php`
Expected: FAIL — métodos no existen.

- [ ] **Step 3: Add the 4 methods to AssetInventoryService**

Insert after `assign()` (before `_run`):

```php
    /**
     * Presta un activo disponible a un empleado. Marca acta pendiente.
     */
    public function lend(int $assetId, int $toEmployeeId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use ($assetId, $toEmployeeId, $data, $userId): ServiceResult {
            if ($asset->status !== AssetConstants::STATUS_DISPONIBLE) {
                return ServiceResult::fail('Solo se puede prestar un activo disponible.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = $toEmployeeId;
            $asset->status = AssetConstants::STATUS_PRESTADO;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_PRESTAMO, $userId, $data),
                ['from_employee_id' => $fromEmployee, 'to_employee_id' => $toEmployeeId],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo prestado correctamente.');
        });
    }

    /**
     * Devuelve un activo asignado o prestado. Limpia el responsable y lo deja
     * disponible. Marca acta pendiente.
     */
    public function returnAsset(int $assetId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use ($assetId, $data, $userId): ServiceResult {
            if (!in_array($asset->status, [AssetConstants::STATUS_ASIGNADO, AssetConstants::STATUS_PRESTADO], true)) {
                return ServiceResult::fail('Solo se puede devolver un activo asignado o prestado.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = null;
            $asset->status = AssetConstants::STATUS_DISPONIBLE;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_DEVOLUCION, $userId, $data),
                ['from_employee_id' => $fromEmployee, 'to_employee_id' => null],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo devuelto correctamente.');
        });
    }

    /**
     * Traslada un activo a otro centro de operación. No cambia el estado.
     */
    public function transfer(int $assetId, int $toOperationCenterId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use ($assetId, $toOperationCenterId, $data, $userId): ServiceResult {
            if ($asset->status === AssetConstants::STATUS_DADO_DE_BAJA) {
                return ServiceResult::fail('No se puede trasladar un activo dado de baja.');
            }

            $fromCenter = $asset->operation_center_id;
            $asset->operation_center_id = $toOperationCenterId;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_TRASLADO, $userId, $data),
                ['from_operation_center_id' => $fromCenter, 'to_operation_center_id' => $toOperationCenterId],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo trasladado correctamente.');
        });
    }

    /**
     * Da de baja un activo (estado terminal). Limpia el responsable. Marca acta
     * pendiente.
     */
    public function dispose(int $assetId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use ($assetId, $data, $userId): ServiceResult {
            if ($asset->status === AssetConstants::STATUS_DADO_DE_BAJA) {
                return ServiceResult::fail('El activo ya está dado de baja.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = null;
            $asset->status = AssetConstants::STATUS_DADO_DE_BAJA;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_BAJA, $userId, $data),
                ['from_employee_id' => $fromEmployee],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo dado de baja.');
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetInventoryServiceTransitionsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/AssetInventoryService.php tests/TestCase/Service/Integration/AssetInventoryServiceTransitionsTest.php
git commit -m "feat(itam): AssetInventoryService lend/returnAsset/transfer/dispose"
```

---

### Task 12: ConsumableStockService

**Files:**
- Create: `src/Service/ConsumableStockService.php`
- Test: `tests/TestCase/Service/Integration/ConsumableStockServiceTest.php`

**Interfaces:**
- Consumes: `ConsumablesTable`, `ConsumableMovementsTable`, `ConsumableConstants`, `AssetConstants` (SOURCE_*), `ServiceResult`. Factory `ConsumableFactory`, `UserFactory`.
- Produces: `App\Service\ConsumableStockService` con:
  - `registerIngress(int $consumableId, int $quantity, array $data, int $userId): ServiceResult` — `current_stock += quantity`; movimiento `ingreso`.
  - `registerOutput(int $consumableId, int $quantity, array $data, int $userId): ServiceResult` — valida `quantity > 0` y stock suficiente; `current_stock -= quantity`; movimiento `salida`.
  - `adjust(int $consumableId, int $newStock, array $data, int $userId): ServiceResult` — fija `current_stock = newStock` (≥0); movimiento `ajuste` con `quantity = newStock - anterior`.
  - Cada movimiento graba `balance_after = current_stock` resultante. `$data`: `reason`, `movement_date`, `source`, `requested_by_phone`, `related_asset_id`.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Service/Integration/ConsumableStockServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\ConsumableConstants;
use App\Service\ConsumableStockService;
use App\Test\Factory\ConsumableFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

final class ConsumableStockServiceTest extends TestCase
{
    private function service(): ConsumableStockService
    {
        return new ConsumableStockService();
    }

    public function testIngressIncreasesStockAndRecordsBalance(): void
    {
        $consumable = ConsumableFactory::new()->withStock(10, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerIngress($consumable->id, 5, [], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(15, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);

        $movement = $this->fetchTable('ConsumableMovements')->find()->where(['consumable_id' => $consumable->id])->firstOrFail();
        $this->assertSame(ConsumableConstants::MOVEMENT_INGRESO, $movement->movement_type);
        $this->assertSame(15, $movement->balance_after);
    }

    public function testOutputDecreasesStock(): void
    {
        $consumable = ConsumableFactory::new()->withStock(10, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerOutput($consumable->id, 4, [], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(6, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);
    }

    public function testOutputFailsWhenInsufficientStock(): void
    {
        $consumable = ConsumableFactory::new()->withStock(3, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->registerOutput($consumable->id, 5, [], $user->id);

        $this->assertFalse($result->success);
        $this->assertSame(3, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);
        $this->assertCount(0, $this->fetchTable('ConsumableMovements')->find()->where(['consumable_id' => $consumable->id])->all()->toArray());
    }

    public function testAdjustSetsAbsoluteStock(): void
    {
        $consumable = ConsumableFactory::new()->withStock(10, 2)->save();
        $user = UserFactory::new()->save();

        $result = $this->service()->adjust($consumable->id, 7, ['reason' => 'Conteo físico'], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(7, $this->fetchTable('Consumables')->get($consumable->id)->current_stock);

        $movement = $this->fetchTable('ConsumableMovements')->find()->where(['consumable_id' => $consumable->id])->firstOrFail();
        $this->assertSame(ConsumableConstants::MOVEMENT_AJUSTE, $movement->movement_type);
        $this->assertSame(-3, $movement->quantity);
        $this->assertSame(7, $movement->balance_after);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/ConsumableStockServiceTest.php`
Expected: FAIL — service no existe.

- [ ] **Step 3: Write the service**

`src/Service/ConsumableStockService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetConstants;
use App\Constants\ConsumableConstants;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * Control de stock de consumibles. Cada operación es atómica: lee el consumible
 * con lock, recalcula el stock, lo persiste y registra el movimiento inmutable
 * con el balance resultante.
 */
class ConsumableStockService
{
    public function registerIngress(int $consumableId, int $quantity, array $data, int $userId): ServiceResult
    {
        if ($quantity <= 0) {
            return ServiceResult::fail('La cantidad debe ser mayor a cero.');
        }

        return $this->_run($consumableId, function (
            EntityInterface $consumable,
            Table $consumables,
            Table $movements,
        ) use ($consumableId, $quantity, $data, $userId): ServiceResult {
            $newStock = (int)$consumable->current_stock + $quantity;
            $consumable->current_stock = $newStock;

            $movement = $movements->newEntity($this->_movementData(
                $consumableId,
                ConsumableConstants::MOVEMENT_INGRESO,
                $quantity,
                $newStock,
                $userId,
                $data,
            ));

            return $this->_commit($consumables, $movements, $consumable, $movement, 'Ingreso de stock registrado.');
        });
    }

    public function registerOutput(int $consumableId, int $quantity, array $data, int $userId): ServiceResult
    {
        if ($quantity <= 0) {
            return ServiceResult::fail('La cantidad debe ser mayor a cero.');
        }

        return $this->_run($consumableId, function (
            EntityInterface $consumable,
            Table $consumables,
            Table $movements,
        ) use ($consumableId, $quantity, $data, $userId): ServiceResult {
            $newStock = (int)$consumable->current_stock - $quantity;
            if ($newStock < 0) {
                return ServiceResult::fail('Stock insuficiente para la salida solicitada.');
            }
            $consumable->current_stock = $newStock;

            $movement = $movements->newEntity($this->_movementData(
                $consumableId,
                ConsumableConstants::MOVEMENT_SALIDA,
                $quantity,
                $newStock,
                $userId,
                $data,
            ));

            return $this->_commit($consumables, $movements, $consumable, $movement, 'Salida de stock registrada.');
        });
    }

    public function adjust(int $consumableId, int $newStock, array $data, int $userId): ServiceResult
    {
        if ($newStock < 0) {
            return ServiceResult::fail('El stock ajustado no puede ser negativo.');
        }

        return $this->_run($consumableId, function (
            EntityInterface $consumable,
            Table $consumables,
            Table $movements,
        ) use ($consumableId, $newStock, $data, $userId): ServiceResult {
            $delta = $newStock - (int)$consumable->current_stock;
            $consumable->current_stock = $newStock;

            $movement = $movements->newEntity($this->_movementData(
                $consumableId,
                ConsumableConstants::MOVEMENT_AJUSTE,
                $delta,
                $newStock,
                $userId,
                $data,
            ));

            return $this->_commit($consumables, $movements, $consumable, $movement, 'Stock ajustado.');
        });
    }

    /**
     * @param callable(\Cake\Datasource\EntityInterface, \Cake\ORM\Table, \Cake\ORM\Table): \App\Service\ServiceResult $operation
     */
    protected function _run(int $consumableId, callable $operation): ServiceResult
    {
        $consumables = TableRegistry::getTableLocator()->get('Consumables');
        $movements = TableRegistry::getTableLocator()->get('ConsumableMovements');
        $connection = $consumables->getConnection();

        $result = null;

        $connection->transactional(function () use ($consumables, $movements, $consumableId, $operation, &$result): bool {
            $consumable = $consumables->find()->where(['Consumables.id' => $consumableId])->epilog('FOR UPDATE')->first();
            if ($consumable === null) {
                $result = ServiceResult::fail('Consumible no encontrado.');

                return false;
            }

            $result = $operation($consumable, $consumables, $movements);

            return $result->success;
        });

        return $result ?? ServiceResult::fail('No se pudo completar la operación.');
    }

    /**
     * @param array<string, mixed> $data Metadatos.
     * @return array<string, mixed>
     */
    protected function _movementData(
        int $consumableId,
        string $movementType,
        int $quantity,
        int $balanceAfter,
        int $userId,
        array $data,
    ): array {
        return [
            'consumable_id' => $consumableId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'balance_after' => $balanceAfter,
            'reason' => $data['reason'] ?? null,
            'related_asset_id' => $data['related_asset_id'] ?? null,
            'movement_date' => $data['movement_date'] ?? date('Y-m-d H:i:s'),
            'performed_by_user_id' => $userId,
            'requested_by_phone' => $data['requested_by_phone'] ?? null,
            'source' => $data['source'] ?? AssetConstants::SOURCE_WEB,
        ];
    }

    protected function _commit(
        Table $consumables,
        Table $movements,
        EntityInterface $consumable,
        EntityInterface $movement,
        string $successMessage,
    ): ServiceResult {
        if (!$consumables->save($consumable)) {
            return ServiceResult::fail('No se pudo actualizar el stock del consumible.');
        }
        if (!$movements->save($movement)) {
            return ServiceResult::fail('No se pudo registrar el movimiento de stock.');
        }

        return ServiceResult::ok([
            'consumable' => $consumable,
            'movement' => $movement,
            'message' => $successMessage,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/ConsumableStockServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/ConsumableStockService.php tests/TestCase/Service/Integration/ConsumableStockServiceTest.php
git commit -m "feat(itam): ConsumableStockService ingress/output/adjust con balance_after"
```

---

### Task 13: AssetDocumentService (almacenamiento PRIVADO)

**Files:**
- Create: `src/Service/AssetDocumentService.php`
- Test: `tests/TestCase/Service/Integration/AssetDocumentServiceTest.php`

**Interfaces:**
- Consumes: `AssetDocumentsTable`, `AssetConstants` (DOCUMENT_TYPES), `UploadConstants` (MAX_BYTES), `ServiceResult`, `Laminas\Diactoros\UploadedFile`. Factory `AssetFactory`, `UserFactory`.
- Produces: `App\Service\AssetDocumentService` con:
  - `static storageRoot(): string` → `ROOT . DS . 'storage' . DS . 'assets'`.
  - `resolveStoragePath(string $relativePath): string` → ruta absoluta en disco.
  - `uploadDocument(int $assetId, UploadedFile $file, string $documentType, ?int $movementId, int $uploadedBy): ServiceResult` — valida tipo de documento, tamaño, extensión whitelist y **MIME real con finfo**; canonicaliza la extensión; persiste la fila en `asset_documents` con `file_path` relativo al storage root. Retorna `ServiceResult::ok($document)`.
  - `deleteDocument(int $assetId, int $documentId): ServiceResult` — valida ownership (doc pertenece al activo), borra la fila y el archivo físico.

> **Patrón:** réplica directa de `EmployeeDocumentService` (storage fuera de webroot, validación MIME real, canonicalización). NO usa `DocumentUploadTrait` porque ese trait guarda en `WWW_ROOT/uploads` (público).

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Service/Integration/AssetDocumentServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetConstants;
use App\Service\AssetDocumentService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class AssetDocumentServiceTest extends TestCase
{
    /** @var array<int, string> Archivos a limpiar tras cada test. */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function makePdfUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'itamdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal acta\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'acta.pdf', 'application/pdf');
    }

    public function testUploadStoresFileOutsideWebrootAndPersistsRow(): void
    {
        $asset = AssetFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = new AssetDocumentService();

        $result = $service->uploadDocument(
            $asset->id,
            $this->makePdfUpload(),
            AssetConstants::DOCTYPE_ACTA,
            null,
            $user->id,
        );

        $this->assertTrue($result->success);
        $document = $result->data;
        $this->createdPaths[] = $service->resolveStoragePath($document->file_path);

        $this->assertStringContainsString('storage' . DIRECTORY_SEPARATOR . 'assets', $service->resolveStoragePath($document->file_path));
        $this->assertFileExists($service->resolveStoragePath($document->file_path));
        $this->assertSame('application/pdf', $document->mime_type);

        $persisted = $this->fetchTable('AssetDocuments')->get($document->id);
        $this->assertSame($asset->id, $persisted->asset_id);
        $this->assertSame(AssetConstants::DOCTYPE_ACTA, $persisted->document_type);
    }

    public function testUploadRejectsInvalidDocumentType(): void
    {
        $asset = AssetFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = new AssetDocumentService();

        $result = $service->uploadDocument($asset->id, $this->makePdfUpload(), 'no_existe', null, $user->id);

        $this->assertFalse($result->success);
    }

    public function testDeleteRemovesRowAndFile(): void
    {
        $asset = AssetFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = new AssetDocumentService();

        $document = $service->uploadDocument($asset->id, $this->makePdfUpload(), AssetConstants::DOCTYPE_ACTA, null, $user->id)->data;
        $absolute = $service->resolveStoragePath($document->file_path);

        $result = $service->deleteDocument($asset->id, $document->id);

        $this->assertTrue($result->success);
        $this->assertFileDoesNotExist($absolute);
        $this->assertFalse($this->fetchTable('AssetDocuments')->exists(['id' => $document->id]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetDocumentServiceTest.php`
Expected: FAIL — service no existe.

- [ ] **Step 3: Write the service**

`src/Service/AssetDocumentService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetConstants;
use App\Constants\UploadConstants;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Gestión de actas y soportes de activos. Almacenamiento PRIVADO en
 * ROOT/storage/assets/{assetId} (fuera de webroot, sin acceso directo por URL).
 * Réplica del patrón de EmployeeDocumentService (validación de MIME real +
 * canonicalización de extensión).
 */
class AssetDocumentService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];

    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const MIME_TO_EXT = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public static function storageRoot(): string
    {
        return ROOT . DS . 'storage' . DS . 'assets';
    }

    public function resolveStoragePath(string $relativePath): string
    {
        return self::storageRoot() . DS . str_replace('/', DS, $relativePath);
    }

    public function uploadDocument(
        int $assetId,
        UploadedFile $file,
        string $documentType,
        ?int $movementId,
        int $uploadedBy,
    ): ServiceResult {
        if (!in_array($documentType, AssetConstants::DOCUMENT_TYPES, true)) {
            return ServiceResult::fail('Tipo de documento inválido.');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ServiceResult::fail('No se recibió ningún archivo válido.');
        }

        if ($file->getSize() > UploadConstants::MAX_BYTES) {
            return ServiceResult::fail('El archivo excede el tamaño máximo de ' . UploadConstants::MAX_BYTES_LABEL . '.');
        }

        $originalName = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ServiceResult::fail('Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.');
        }

        $uploadDir = self::storageRoot() . DS . $assetId;
        $this->_ensureDir($uploadDir);

        $uniqueName = uniqid('asset_') . '.' . $extension;
        $absolutePath = $uploadDir . DS . $uniqueName;

        try {
            $file->moveTo($absolutePath);
        } catch (Throwable) {
            return ServiceResult::fail('No se pudo guardar el archivo en disco.');
        }

        $realMime = $this->_detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido del archivo no coincide con su extensión.');
        }

        [$absolutePath, $relativePath] = $this->_canonicalize($absolutePath, $assetId . '/' . $uniqueName, $realMime);

        $documentsTable = TableRegistry::getTableLocator()->get('AssetDocuments');
        $document = $documentsTable->newEntity([
            'asset_id' => $assetId,
            'asset_movement_id' => $movementId,
            'document_type' => $documentType,
            'name' => $originalName,
            'file_path' => $relativePath,
            'file_size' => $file->getSize(),
            'mime_type' => $realMime,
            'uploaded_by' => $uploadedBy,
        ]);

        if (!$documentsTable->save($document)) {
            @unlink($absolutePath);

            return ServiceResult::fail('No se pudo guardar el documento.');
        }

        return ServiceResult::ok($document);
    }

    public function deleteDocument(int $assetId, int $documentId): ServiceResult
    {
        $documentsTable = TableRegistry::getTableLocator()->get('AssetDocuments');

        try {
            $document = $documentsTable->find()
                ->where(['AssetDocuments.id' => $documentId, 'AssetDocuments.asset_id' => $assetId])
                ->firstOrFail();
        } catch (RecordNotFoundException) {
            return ServiceResult::fail('El documento no existe o no pertenece al activo.');
        }

        $absolutePath = $this->resolveStoragePath($document->file_path);

        if (!$documentsTable->delete($document)) {
            return ServiceResult::fail('No se pudo eliminar el documento.');
        }

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ServiceResult::ok();
    }

    private function _detectRealMime(string $absolutePath): string
    {
        if (!is_file($absolutePath)) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file($finfo, $absolutePath);

        return $mime !== false ? $mime : '';
    }

    private function _ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('No se pudo crear el directorio %s.', $path));
        }
    }

    /**
     * Renombra el archivo para que su extensión coincida con la canónica del
     * MIME real. Defense-in-depth; si el rename falla, conserva el original.
     *
     * @return array{0:string, 1:string} [absolutePath, relativePath]
     */
    private function _canonicalize(string $absolutePath, string $relativePath, string $realMime): array
    {
        $canonicalExt = self::MIME_TO_EXT[$realMime] ?? null;
        if ($canonicalExt === null) {
            return [$absolutePath, $relativePath];
        }

        $currentExt = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($currentExt === $canonicalExt) {
            return [$absolutePath, $relativePath];
        }

        $newAbsolute = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $absolutePath) ?? $absolutePath;
        $newRelative = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $relativePath) ?? $relativePath;

        if (!@rename($absolutePath, $newAbsolute)) {
            return [$absolutePath, $relativePath];
        }

        return [$newAbsolute, $newRelative];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetDocumentServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the whole Fase 2 suite + cs-check**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetInventoryServiceTest.php tests/TestCase/Service/Integration/AssetInventoryServiceTransitionsTest.php tests/TestCase/Service/Integration/ConsumableStockServiceTest.php tests/TestCase/Service/Integration/AssetDocumentServiceTest.php` then `composer cs-check`
Expected: todo verde.

- [ ] **Step 6: Commit**

```bash
git add src/Service/AssetDocumentService.php tests/TestCase/Service/Integration/AssetDocumentServiceTest.php
git commit -m "feat(itam): AssetDocumentService con almacenamiento privado y validación MIME"
```

---

# FASE 3 — UI web de administración + RBAC

Resultado verificable: con permisos sembrados, un operador puede gestionar categorías, activos (CRUD + movimientos + actas) y consumibles (CRUD + stock) desde la UI; el sidebar muestra la sección "Inventario TI". Tests de controller (gate de auth) y de Presentation pasan.

> **Nota sobre tests de controller:** el proyecto no tiene harness de sesión autenticada en tests de integración de controller (los existentes verifican el redirect a `/login`). Por eso los tests de controller aquí verifican el **gate de autenticación** (sin sesión → redirect). La lógica de negocio ya está cubierta por los tests de servicio de la Fase 2.

---

### Task 14: Registro RBAC + siembra de permisos

**Files:**
- Modify: `src/Controller/AppController.php` (añadir entradas a `$controllerModuleMap`)
- Modify: `src/Service/AuthorizationService.php` (añadir a `MODULES` y `MODULE_GROUPS`)
- Create: `config/Migrations/20260619000002_SeedItamAdminPermissions.php`
- Test: `tests/TestCase/Service/AuthorizationServiceModulesTest.php`

**Interfaces:**
- Produces: módulos RBAC `assets` ('Activos'), `consumables` ('Consumibles'), `asset_categories` ('Categorías de Activos'), `asset_alerts` ('Alertas de Inventario'), agrupados en `MODULE_GROUPS['Inventario TI']`. Controllers `Assets`/`Consumables`/`AssetCategories`/`AssetAlerts` mapeados al módulo correspondiente. Permisos completos sembrados para el rol `Administrador`.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Service/AuthorizationServiceModulesTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\AuthorizationService;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceModulesTest extends TestCase
{
    public function testItamModulesRegistered(): void
    {
        foreach (['assets', 'consumables', 'asset_categories', 'asset_alerts'] as $module) {
            $this->assertArrayHasKey($module, AuthorizationService::MODULES);
        }
        $this->assertSame('Activos', AuthorizationService::MODULES['assets']);
    }

    public function testEveryModuleBelongsToAGroup(): void
    {
        $grouped = array_merge(...array_values(AuthorizationService::MODULE_GROUPS));
        foreach (array_keys(AuthorizationService::MODULES) as $module) {
            $this->assertContains($module, $grouped, "El módulo '$module' no está en ningún MODULE_GROUP.");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/AuthorizationServiceModulesTest.php`
Expected: FAIL — los módulos ITAM no están en `MODULES`.

- [ ] **Step 3: Register modules in AuthorizationService**

In `src/Service/AuthorizationService.php`, add to the `MODULES` const (after `'email_logs' => 'Logs de correo',`):

```php
        'assets' => 'Activos',
        'consumables' => 'Consumibles',
        'asset_categories' => 'Categorías de Activos',
        'asset_alerts' => 'Alertas de Inventario',
```

And add a new group to `MODULE_GROUPS` (after the `'Sistema' => [...]` entry):

```php
        'Inventario TI' => [
            'assets', 'consumables', 'asset_categories', 'asset_alerts',
        ],
```

- [ ] **Step 4: Register controllers in AppController**

In `src/Controller/AppController.php`, add to `$controllerModuleMap` (after `'EmailLogs' => 'email_logs',`):

```php
        'Assets' => 'assets',
        'Consumables' => 'consumables',
        'AssetCategories' => 'asset_categories',
        'AssetAlerts' => 'asset_alerts',
```

- [ ] **Step 5: Run the modules test (should pass now)**

Run: `vendor/bin/phpunit tests/TestCase/Service/AuthorizationServiceModulesTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Write the permissions seed migration**

`config/Migrations/20260619000002_SeedItamAdminPermissions.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Siembra permisos completos (view/create/edit/delete) para el rol
 * Administrador en los módulos de inventario TI. El Administrador NO bypassa
 * estos módulos (solo users/roles), así que sin esta siembra el módulo sería
 * invisible tras desplegar. Otros roles se asignan desde la UI de Roles.
 * Idempotente.
 */
class SeedItamAdminPermissions extends BaseMigration
{
    private const MODULES = ['assets', 'consumables', 'asset_categories', 'asset_alerts'];

    public function up(): void
    {
        $role = $this->fetchRow("SELECT id FROM roles WHERE name = 'Administrador'");
        if (!$role) {
            return;
        }
        $roleId = (int)($role['id'] ?? $role[0]);

        foreach (self::MODULES as $module) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM permissions WHERE role_id = %d AND module = '%s'",
                $roleId,
                $module,
            ));
            if ($existing) {
                continue;
            }

            $this->table('permissions')->insert([[
                'role_id' => $roleId,
                'module' => $module,
                'can_view' => 1,
                'can_create' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ]])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM permissions WHERE module IN ('assets', 'consumables', 'asset_categories', 'asset_alerts')",
        );
    }
}
```

- [ ] **Step 7: Run the migration**

Run: `php bin/cake migrations migrate`
Expected: la siembra corre sin error (si existe el rol Administrador).

- [ ] **Step 8: Commit**

```bash
git add src/Controller/AppController.php src/Service/AuthorizationService.php config/Migrations/20260619000002_SeedItamAdminPermissions.php config/Migrations/schema-dump-default.lock tests/TestCase/Service/AuthorizationServiceModulesTest.php
git commit -m "feat(itam): registro RBAC de módulos de inventario + siembra de permisos admin"
```

---

### Task 15: AssetCategories — catálogo (controller + templates)

**Files:**
- Create: `src/Controller/AssetCategoriesController.php`
- Create: `templates/element/forms/asset_categories.php`
- Create: `templates/AssetCategories/index.php`
- Create: `templates/AssetCategories/add.php`
- Create: `templates/AssetCategories/edit.php`
- Create: `templates/AssetCategories/view.php`
- Test: `tests/TestCase/Controller/AssetCategoriesControllerTest.php`

**Interfaces:**
- Consumes: `CatalogCrudTrait` (`_catalogSave`), `AssetCategoriesTable`, `#[Permission]`.
- Produces: `App\Controller\AssetCategoriesController` con `index/view/add/edit/delete` (patrón catálogo, paginación 15, modal AJAX via `catalog_modal`).

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Controller/AssetCategoriesControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetCategoriesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/asset-categories');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testAddRequiresAuthentication(): void
    {
        $this->get('/asset-categories/add');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetCategoriesControllerTest.php`
Expected: FAIL — controller no existe (error de routing/clase).

- [ ] **Step 3: Write the controller**

`src/Controller/AssetCategoriesController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;

class AssetCategoriesController extends AppController
{
    use CatalogCrudTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $assetCategories = $this->paginate($this->AssetCategories->find()->orderBy(['AssetCategories.name' => 'ASC']));

        $this->set(compact('assetCategories'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $assetCategory = $this->AssetCategories->get($id);

        $this->set(compact('assetCategory'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $assetCategory = $this->AssetCategories->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->AssetCategories,
            $assetCategory,
            __('La categoría ha sido guardada.'),
            __('No se pudo guardar la categoría. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('assetCategory'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $assetCategory = $this->AssetCategories->get($id);
        $result = $this->_catalogSave(
            $this->AssetCategories,
            $assetCategory,
            __('La categoría ha sido actualizada.'),
            __('No se pudo actualizar la categoría. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('assetCategory'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $assetCategory = $this->AssetCategories->get($id);
        if ($this->AssetCategories->delete($assetCategory)) {
            $this->Flash->success(__('La categoría ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la categoría. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
```

- [ ] **Step 4: Write the form fields element and templates**

`templates/element/forms/asset_categories.php`:

```php
<?php
/**
 * Campos del form de Categoría de Activo. Compartido por la página standalone
 * (add/edit) y el modal AJAX. Asume estar dentro de un Form->create($entity).
 *
 * @var \App\View\AppView $this
 */
?>
<div class="row g-3">
    <div class="col-md-4">
        <?= $this->Form->control('code', [
            'class' => 'form-control',
            'label' => ['text' => 'Código', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-8">
        <?= $this->Form->control('name', [
            'class' => 'form-control',
            'label' => ['text' => 'Nombre', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-12">
        <?= $this->Form->control('description', [
            'type' => 'textarea',
            'rows' => 2,
            'class' => 'form-control',
            'label' => ['text' => 'Descripción', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-12">
        <?= $this->Form->control('active', [
            'type' => 'checkbox',
            'label' => ['text' => 'Activa', 'class' => 'input-label'],
        ]) ?>
    </div>
</div>
```

`templates/AssetCategories/index.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AssetCategory> $assetCategories
 */
$this->assign('title', 'Categorías de Activos');

$canEdit = !empty($userPermissions['asset_categories']['can_edit']);
$canDelete = !empty($userPermissions['asset_categories']['can_delete']);
$gridCols = '80px 140px 1fr 90px 96px';
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Categorías de Activos</span>
    <?php if (!empty($userPermissions['asset_categories']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Categoría',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false, 'data-catalog-modal' => 'true']
    ) ?>
    <?php endif; ?>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('code', 'Código') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('active', 'Estado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($assetCategories as $category): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $category->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($category->id) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= h($category->code) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($category->name) ?></span>
        <span>
            <?php if ($category->active): ?>
                <span class="pill pill-accent-soft">Activa</span>
            <?php else: ?>
                <span class="pill pill-secondary-soft">Inactiva</span>
            <?php endif; ?>
        </span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $category->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $category->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'data-catalog-modal' => 'true']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $category->id],
                ['confirm' => '¿Está seguro de eliminar?', 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-tags" aria-hidden="true"></i></div>
        <div class="es-title">Sin categorías</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>
<?= $this->element('catalog_modal') ?>
```

`templates/AssetCategories/add.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AssetCategory $assetCategory
 */
$this->assign('title', 'Nueva Categoría');

if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $assetCategory,
        'fieldsElement' => 'forms/asset_categories',
        'title' => 'Nueva Categoría',
        'submitLabel' => 'Guardar',
    ]);

    return;
}
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nueva Categoría</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($assetCategory) ?>
    <?= $this->element('forms/asset_categories') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
    <?= $this->Form->end() ?>
</div>
```

`templates/AssetCategories/edit.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AssetCategory $assetCategory
 */
$this->assign('title', 'Editar Categoría');

if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $assetCategory,
        'fieldsElement' => 'forms/asset_categories',
        'title' => 'Editar Categoría',
        'submitLabel' => 'Actualizar',
    ]);

    return;
}
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Editar Categoría</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($assetCategory) ?>
    <?= $this->element('forms/asset_categories') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    <?= $this->Form->end() ?>
</div>
```

`templates/AssetCategories/view.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AssetCategory $assetCategory
 */
$this->assign('title', 'Categoría: ' . $assetCategory->name);
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Detalle de la Categoría</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['asset_categories']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $assetCategory->id],
            ['class' => 'btn btn-primary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="spi-card">
    <div class="field-row">
        <span class="k">Código</span>
        <span class="v mono"><?= h($assetCategory->code) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Nombre</span>
        <span class="v"><?= h($assetCategory->name) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Descripción</span>
        <span class="v"><?= h($assetCategory->description) ?: '—' ?></span>
    </div>
    <div class="field-row is-last">
        <span class="k">Estado</span>
        <span class="v"><?= $assetCategory->active ? 'Activa' : 'Inactiva' ?></span>
    </div>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetCategoriesControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Controller/AssetCategoriesController.php templates/AssetCategories templates/element/forms/asset_categories.php tests/TestCase/Controller/AssetCategoriesControllerTest.php
git commit -m "feat(itam): catálogo AssetCategories (controller + templates)"
```

---

### Task 16: AssetPresentation + AssetRowView + AssetViewViewModel

**Files:**
- Create: `src/View/Presentation/AssetPresentation.php`
- Create: `src/View/Presentation/AssetRowView.php`
- Create: `src/ViewModel/AssetViewViewModel.php`
- Test: `tests/TestCase/View/Presentation/AssetPresentationTest.php`

**Interfaces:**
- Consumes: `Asset` entity, `AssetConstants`.
- Produces:
  - `App\View\Presentation\AssetPresentation` (final) con consts `STATUS_BADGES` (status→pill), `STATUS_ICONS` (status→bi icon), `ACTA_BADGES` (acta_status→pill) y `static forRow(Asset $asset): AssetRowView`.
  - `App\View\Presentation\AssetRowView` (final readonly) con `statusLabel`, `statusBadgeClass`, `statusIcon`, `categoryName`, `responsibleName`, `operationCenterName`.
  - `App\ViewModel\AssetViewViewModel` (final readonly) con `Asset $asset`, `array $movements`, `array $documents`, flags `canEdit`/`canDelete`/`canCreateMovement`, dropdowns `mixed $employees`/`mixed $operationCenters`, y propiedad derivada `array $currentStatusBadge` = `[label, pillClass]`.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/View/Presentation/AssetPresentationTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\AssetConstants;
use App\Model\Entity\Asset;
use App\Model\Entity\AssetCategory;
use App\View\Presentation\AssetPresentation;
use PHPUnit\Framework\TestCase;

final class AssetPresentationTest extends TestCase
{
    public function testStatusBadgesCoverEveryStatus(): void
    {
        foreach (AssetConstants::STATUSES as $status) {
            $this->assertArrayHasKey($status, AssetPresentation::STATUS_BADGES);
            $this->assertArrayHasKey($status, AssetPresentation::STATUS_ICONS);
        }
    }

    public function testForRowDerivesLabelsAndAssociations(): void
    {
        $asset = new Asset([
            'code' => 'ACT-26-001-0001',
            'status' => AssetConstants::STATUS_ASIGNADO,
            'asset_category' => new AssetCategory(['name' => 'Portátil']),
        ]);

        $row = AssetPresentation::forRow($asset);

        $this->assertSame('Asignado', $row->statusLabel);
        $this->assertSame('pill-info-soft', $row->statusBadgeClass);
        $this->assertSame('Portátil', $row->categoryName);
        $this->assertSame('—', $row->responsibleName);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/View/Presentation/AssetPresentationTest.php`
Expected: FAIL — clases no existen.

- [ ] **Step 3: Write RowView, Presentation and ViewModel**

`src/View/Presentation/AssetRowView.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

final readonly class AssetRowView
{
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $statusIcon,
        public string $categoryName,
        public string $responsibleName,
        public string $operationCenterName,
    ) {
    }
}
```

`src/View/Presentation/AssetPresentation.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AssetConstants;
use App\Model\Entity\Asset;

/**
 * Diccionario UI estático de activos. Fuente única del mapeo estado→pill/icono.
 * Los templates lo consumen vía forRow() o las consts; nunca redeclaran mapas
 * inline (anti-drift).
 */
final class AssetPresentation
{
    /** @var array<string, string> */
    public const STATUS_BADGES = [
        AssetConstants::STATUS_DISPONIBLE => 'pill-accent-soft',
        AssetConstants::STATUS_ASIGNADO => 'pill-info-soft',
        AssetConstants::STATUS_PRESTADO => 'pill-orange-soft',
        AssetConstants::STATUS_EN_REPARACION => 'pill-warning-soft',
        AssetConstants::STATUS_DADO_DE_BAJA => 'pill-secondary-soft',
    ];

    /** @var array<string, string> */
    public const STATUS_ICONS = [
        AssetConstants::STATUS_DISPONIBLE => 'bi-box-seam',
        AssetConstants::STATUS_ASIGNADO => 'bi-person-check',
        AssetConstants::STATUS_PRESTADO => 'bi-arrow-left-right',
        AssetConstants::STATUS_EN_REPARACION => 'bi-tools',
        AssetConstants::STATUS_DADO_DE_BAJA => 'bi-x-octagon',
    ];

    /** @var array<string, string> */
    public const ACTA_BADGES = [
        AssetConstants::ACTA_PENDIENTE => 'pill-warning-soft',
        AssetConstants::ACTA_CARGADA => 'pill-info-soft',
        AssetConstants::ACTA_VALIDADA => 'pill-accent-soft',
        AssetConstants::ACTA_RECHAZADA => 'pill-danger-soft',
    ];

    public static function forRow(Asset $asset): AssetRowView
    {
        $status = $asset->status ?? '';

        return new AssetRowView(
            statusLabel: AssetConstants::STATUS_LABELS[$status] ?? $status,
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-secondary-soft',
            statusIcon: self::STATUS_ICONS[$status] ?? 'bi-box',
            categoryName: $asset->asset_category->name ?? '—',
            responsibleName: trim(
                ($asset->responsible_employee->first_name ?? '') . ' ' . ($asset->responsible_employee->last_name ?? ''),
            ) ?: '—',
            operationCenterName: $asset->operation_center->name ?? '—',
        );
    }
}
```

`src/ViewModel/AssetViewViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\AssetConstants;
use App\Model\Entity\Asset;
use App\View\Presentation\AssetPresentation;

/**
 * Agregado per-request para la vista de un activo. Deriva el badge de estado de
 * AssetPresentation (dirección VM → Presentation).
 */
final readonly class AssetViewViewModel
{
    /** @var array{0:string, 1:string} [label, pillClass] */
    public array $currentStatusBadge;

    /**
     * @param array<int, \App\Model\Entity\AssetMovement> $movements
     * @param array<int, \App\Model\Entity\AssetDocument> $documents
     * @param iterable<\App\Model\Entity\Employee> $employees
     * @param iterable<\App\Model\Entity\OperationCenter> $operationCenters
     */
    public function __construct(
        public Asset $asset,
        public array $movements,
        public array $documents,
        public bool $canEdit,
        public bool $canDelete,
        public bool $canCreateMovement,
        public iterable $employees,
        public iterable $operationCenters,
    ) {
        $status = $asset->status ?? '';
        $this->currentStatusBadge = [
            AssetConstants::STATUS_LABELS[$status] ?? $status,
            AssetPresentation::STATUS_BADGES[$status] ?? 'pill-secondary-soft',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/View/Presentation/AssetPresentationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/View/Presentation/AssetPresentation.php src/View/Presentation/AssetRowView.php src/ViewModel/AssetViewViewModel.php tests/TestCase/View/Presentation/AssetPresentationTest.php
git commit -m "feat(itam): AssetPresentation + AssetRowView + AssetViewViewModel"
```

---

### Task 17: AssetsController CRUD + templates (index, view, add, edit)

**Files:**
- Create: `src/Controller/AssetsController.php`
- Create: `templates/element/forms/assets.php`
- Create: `templates/Assets/index.php`
- Create: `templates/Assets/view.php`
- Create: `templates/Assets/add.php`
- Create: `templates/Assets/edit.php`
- Test: `tests/TestCase/Controller/AssetsControllerTest.php`

**Interfaces:**
- Consumes: `AssetsTable` (finder `findFiltered`), `AssetMovementsTable`/`AssetDocumentsTable` (finders `findForAsset`), `AssetCategoriesTable` (`findActive`), `AssetPresentation`, `AssetViewViewModel`, `#[Permission]`.
- Produces: `App\Controller\AssetsController` con `index` (filtros + paginación), `view` (set `viewModel`), `add`, `edit`, `delete`. Las acciones de movimiento y documentos se añaden en Tasks 18-19.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Controller/AssetsControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/assets');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testViewRequiresAuthentication(): void
    {
        $this->get('/assets/view/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetsControllerTest.php`
Expected: FAIL — controller no existe.

- [ ] **Step 3: Write the controller**

`src/Controller/AssetsController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\AssetConstants;
use App\ViewModel\AssetViewViewModel;

class AssetsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $filters = [
            'status' => $this->request->getQuery('status'),
            'category_id' => $this->request->getQuery('category_id'),
            'operation_center_id' => $this->request->getQuery('operation_center_id'),
            'q' => $this->request->getQuery('q'),
        ];

        $query = $this->Assets->find('filtered', options: $filters)
            ->contain(['AssetCategories', 'ResponsibleEmployees', 'OperationCenters'])
            ->orderBy(['Assets.created' => 'DESC']);

        $assets = $this->paginate($query);
        $categories = $this->Assets->AssetCategories->find('active')->all()->combine('id', 'name')->toArray();
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();
        $statusLabels = AssetConstants::STATUS_LABELS;

        $this->set(compact('assets', 'categories', 'operationCenters', 'statusLabels', 'filters'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $asset = $this->Assets->get($id, contain: [
            'AssetCategories', 'ResponsibleEmployees', 'OperationCenters', 'CostCenters',
        ]);

        $movements = $this->fetchTable('AssetMovements')->find('forAsset', assetId: (int)$asset->id)
            ->contain(['FromEmployees', 'ToEmployees', 'FromOperationCenters', 'ToOperationCenters', 'PerformedByUsers'])
            ->all()->toArray();
        $documents = $this->fetchTable('AssetDocuments')->find('forAsset', assetId: (int)$asset->id)->all()->toArray();

        $employees = $this->fetchTable('Employees')->find()
            ->where(['Employees.active' => true])
            ->orderBy(['Employees.first_name' => 'ASC'])
            ->all()
            ->combine('id', fn($e) => trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')))
            ->toArray();
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();

        $viewModel = new AssetViewViewModel(
            asset: $asset,
            movements: $movements,
            documents: $documents,
            canEdit: $this->_checkPermission('assets', 'edit'),
            canDelete: $this->_checkPermission('assets', 'delete'),
            canCreateMovement: $this->_checkPermission('assets', 'edit'),
            employees: $employees,
            operationCenters: $operationCenters,
        );

        $this->set(compact('viewModel'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $asset = $this->Assets->newEmptyEntity();
        if ($this->request->is('post')) {
            $asset = $this->Assets->patchEntity($asset, $this->request->getData());
            $asset->status = AssetConstants::STATUS_DISPONIBLE;
            if ($this->Assets->save($asset)) {
                $this->Flash->success(__('El activo ha sido creado.'));

                return $this->redirect(['action' => 'view', $asset->id]);
            }
            $this->Flash->error(__('No se pudo crear el activo. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('asset'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $asset = $this->Assets->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $asset = $this->Assets->patchEntity($asset, $this->request->getData());
            if ($this->Assets->save($asset)) {
                $this->Flash->success(__('El activo ha sido actualizado.'));

                return $this->redirect(['action' => 'view', $asset->id]);
            }
            $this->Flash->error(__('No se pudo actualizar el activo. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('asset'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $asset = $this->Assets->get($id);
        if ($this->Assets->delete($asset)) {
            $this->Flash->success(__('El activo ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el activo. Si tiene movimientos registrados, dé de baja en su lugar.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Dropdowns compartidos por add/edit.
     */
    protected function _setFormDropdowns(): void
    {
        $categories = $this->Assets->AssetCategories->find('active')->all()->combine('id', 'name')->toArray();
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();
        $costCenters = $this->fetchTable('CostCenters')->find('list')->all()->toArray();

        $this->set(compact('categories', 'operationCenters', 'costCenters'));
    }
}
```

- [ ] **Step 4: Write the form fields element**

`templates/element/forms/assets.php`:

```php
<?php
/**
 * Campos del form de Activo (add/edit). Asume estar dentro de Form->create($asset).
 * El estado y el responsable NO se editan aquí: se gestionan vía movimientos.
 *
 * @var \App\View\AppView $this
 * @var array<int, string> $categories
 * @var array<int, string> $operationCenters
 * @var array<int, string> $costCenters
 */
?>
<div class="row g-3">
    <div class="col-md-6">
        <?= $this->Form->control('asset_category_id', [
            'options' => $categories,
            'empty' => 'Seleccione…',
            'class' => 'form-select select2-enable',
            'label' => ['text' => 'Categoría', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('serial_number', [
            'class' => 'form-control',
            'label' => ['text' => 'Número de serie', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('brand', [
            'class' => 'form-control',
            'label' => ['text' => 'Marca', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('model', [
            'class' => 'form-control',
            'label' => ['text' => 'Modelo', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('operation_center_id', [
            'options' => $operationCenters,
            'empty' => 'Seleccione…',
            'class' => 'form-select select2-enable',
            'label' => ['text' => 'Centro de operación', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('cost_center_id', [
            'options' => $costCenters,
            'empty' => 'Sin centro de costo',
            'class' => 'form-select select2-enable',
            'label' => ['text' => 'Centro de costo', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('acquisition_date', [
            'type' => 'text',
            'class' => 'form-control flatpickr-date',
            'label' => ['text' => 'Fecha de adquisición', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-12">
        <?= $this->Form->control('description', [
            'type' => 'textarea', 'rows' => 2,
            'class' => 'form-control',
            'label' => ['text' => 'Descripción', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-12">
        <?= $this->Form->control('observations', [
            'type' => 'textarea', 'rows' => 2,
            'class' => 'form-control',
            'label' => ['text' => 'Observaciones', 'class' => 'input-label'],
        ]) ?>
    </div>
</div>
```

- [ ] **Step 5: Write the index template**

`templates/Assets/index.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Asset> $assets
 * @var array<int, string> $categories
 * @var array<int, string> $operationCenters
 * @var array<string, string> $statusLabels
 * @var array<string, mixed> $filters
 */
use App\View\Presentation\AssetPresentation;

$this->assign('title', 'Activos');

$canCreate = !empty($userPermissions['assets']['can_create']);
$gridCols = '120px 1fr 130px 1fr 1fr 90px';
$q = (string)($filters['q'] ?? '');
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Activos</span>
    <?php if ($canCreate): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Activo',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
<div class="d-flex flex-wrap align-items-end" style="gap:8px;margin-bottom:14px;">
    <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px;"
           placeholder="Código, serie, marca…" value="<?= h($q) ?>">
    <?= $this->Form->control('status', [
        'options' => $statusLabels, 'empty' => 'Todos los estados',
        'class' => 'form-select form-select-sm', 'label' => false,
        'value' => $filters['status'] ?? null, 'style' => 'max-width:180px;',
    ]) ?>
    <?= $this->Form->control('category_id', [
        'options' => $categories, 'empty' => 'Todas las categorías',
        'class' => 'form-select form-select-sm', 'label' => false,
        'value' => $filters['category_id'] ?? null, 'style' => 'max-width:200px;',
    ]) ?>
    <?= $this->Form->control('operation_center_id', [
        'options' => $operationCenters, 'empty' => 'Todas las sedes',
        'class' => 'form-select form-select-sm', 'label' => false,
        'value' => $filters['operation_center_id'] ?? null, 'style' => 'max-width:200px;',
    ]) ?>
    <button type="submit" class="btn btn-default btn-sm">Filtrar</button>
</div>
<?= $this->Form->end() ?>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Código</span>
        <span>Descripción</span>
        <span>Categoría</span>
        <span>Responsable</span>
        <span>Sede</span>
        <span>Estado</span>
    </div>

    <?php $rowCount = 0; foreach ($assets as $asset): $rowCount++; ?>
        <?php $row = AssetPresentation::forRow($asset); ?>
        <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
             data-href="<?= $this->Url->build(['action' => 'view', $asset->id]) ?>" role="row">
            <span class="mono" style="color:var(--text-muted);"><?= h($asset->code) ?></span>
            <span style="font-weight:600;color:var(--text-strong);"><?= h($asset->description ?: ($asset->brand . ' ' . $asset->model)) ?: '—' ?></span>
            <span><?= h($row->categoryName) ?></span>
            <span><?= h($row->responsibleName) ?></span>
            <span><?= h($row->operationCenterName) ?></span>
            <span><span class="pill <?= h($row->statusBadgeClass) ?>"><?= h($row->statusLabel) ?></span></span>
        </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-pc-display" aria-hidden="true"></i></div>
        <div class="es-title">Sin activos</div>
        <div class="es-msg">No hay activos que coincidan con los filtros.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>
```

- [ ] **Step 6: Write the view template (info + historial + documentos; sin acciones de movimiento todavía)**

`templates/Assets/view.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\AssetViewViewModel $viewModel
 */
use App\Constants\AssetConstants;
use App\View\Presentation\AssetPresentation;

$asset = $viewModel->asset;
[$statusLabel, $statusPill] = $viewModel->currentStatusBadge;
$this->assign('title', 'Activo ' . $asset->code);
?>
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:16px;">
    <div style="min-width:0;">
        <div class="d-flex align-items-center gap-1" style="font-size:11.5px;color:var(--text-faint);margin-bottom:4px;">
            <?= $this->Html->link('Activos', ['action' => 'index'], ['style' => 'color:inherit;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:10px;"></i>
            <span><?= h($asset->code) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <h1 class="spi-page-title">Detalle del Activo</h1>
            <span class="mono" style="font-size:14px;color:var(--text-muted);padding:3px 8px;background:var(--bg-subtle);"><?= h($asset->code) ?></span>
            <span class="pill <?= h($statusPill) ?>"><?= h($statusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?php if ($viewModel->canEdit): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $asset->id],
            ['class' => 'btn btn-secondary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="spi-invoice-view-grid">
    <aside class="spi-invoice-view-left">
        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:12px;">Resumen</div>
            <div class="field-row"><span class="k">Estado</span><span class="v"><span class="pill <?= h($statusPill) ?>"><?= h($statusLabel) ?></span></span></div>
            <div class="field-row"><span class="k">Categoría</span><span class="v"><?= h($asset->asset_category->name ?? '—') ?></span></div>
            <div class="field-row"><span class="k">Responsable</span><span class="v"><?= h(trim(($asset->responsible_employee->first_name ?? '') . ' ' . ($asset->responsible_employee->last_name ?? ''))) ?: '—' ?></span></div>
            <div class="field-row is-last"><span class="k">Sede</span><span class="v"><?= h($asset->operation_center->name ?? '—') ?></span></div>
        </div>
        <?php // Las acciones de movimiento se inyectan en la Task 18. ?>
    </aside>

    <main class="spi-invoice-view-right">
        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:16px;">Información</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div class="field-row"><span class="k">Código</span><span class="v mono"><?= h($asset->code) ?></span></div>
                <div class="field-row"><span class="k">N° de serie</span><span class="v mono"><?= h($asset->serial_number) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Marca</span><span class="v"><?= h($asset->brand) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Modelo</span><span class="v"><?= h($asset->model) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Centro de costo</span><span class="v"><?= h($asset->cost_center->name ?? '—') ?></span></div>
                <div class="field-row"><span class="k">Adquisición</span><span class="v mono"><?= $asset->acquisition_date?->format('d/m/Y') ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Descripción</span><span class="v"><?= h($asset->description) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Observaciones</span><span class="v"><?= h($asset->observations) ?: '—' ?></span></div>
            </div>
        </div>

        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:12px;">Historial de movimientos (<?= count($viewModel->movements) ?>)</div>
            <?php if ($viewModel->movements === []): ?>
                <div style="color:var(--text-faint);font-size:13px;">Sin movimientos registrados.</div>
            <?php else: ?>
                <?php foreach ($viewModel->movements as $m): ?>
                <div class="d-flex justify-content-between align-items-start" style="padding:8px 0;border-bottom:1px solid var(--border-subtle);">
                    <div>
                        <span style="font-weight:600;"><?= h(AssetConstants::MOVEMENT_LABELS[$m->movement_type] ?? $m->movement_type) ?></span>
                        <?php if ($m->acta_status): ?>
                            <span class="pill <?= h(AssetPresentation::ACTA_BADGES[$m->acta_status] ?? 'pill-secondary-soft') ?> pill-sm">Acta: <?= h(\App\Constants\Domain\Asset\ActaStatus::from($m->acta_status)->label()) ?></span>
                        <?php endif; ?>
                        <div style="font-size:12px;color:var(--text-faint);"><?= h($m->reason) ?></div>
                    </div>
                    <div class="mono" style="font-size:12px;color:var(--text-muted);"><?= $m->movement_date?->format('d/m/Y H:i') ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:12px;">Documentos y actas (<?= count($viewModel->documents) ?>)</div>
            <?php if ($viewModel->documents === []): ?>
                <div style="color:var(--text-faint);font-size:13px;">Sin documentos.</div>
            <?php else: ?>
                <?php foreach ($viewModel->documents as $doc): ?>
                <div class="d-flex justify-content-between align-items-center" style="padding:6px 0;">
                    <span><i class="bi bi-file-earmark me-1" aria-hidden="true"></i><?= h($doc->name) ?></span>
                    <span class="mono" style="font-size:12px;color:var(--text-faint);"><?= h($doc->document_type) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
```

- [ ] **Step 7: Write add and edit templates**

`templates/Assets/add.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Asset $asset
 */
$this->assign('title', 'Nuevo Activo');
?>
<?= $this->element('cdn_select2') ?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nuevo Activo</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($asset) ?>
    <?= $this->element('forms/assets') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Crear activo</button>
    <?= $this->Form->end() ?>
</div>
```

`templates/Assets/edit.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Asset $asset
 */
$this->assign('title', 'Editar Activo');
?>
<?= $this->element('cdn_select2') ?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Editar Activo <?= h($asset->code) ?></span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'view', $asset->id],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($asset) ?>
    <?= $this->element('forms/assets') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar cambios</button>
    <?= $this->Form->end() ?>
</div>
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetsControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 9: Manual smoke check (optional but recommended)**

Run `php bin/cake server`, log in as admin, visit `/assets`, `/assets/add` (crear un activo), `/assets/view/{id}`, `/assets/edit/{id}`. Verifica que el listado filtra y la ficha muestra info/historial/documentos.

- [ ] **Step 10: Commit**

```bash
git add src/Controller/AssetsController.php templates/Assets templates/element/forms/assets.php tests/TestCase/Controller/AssetsControllerTest.php
git commit -m "feat(itam): AssetsController CRUD + templates (index/view/add/edit)"
```

---

### Task 18: Acciones de movimiento de activos (modales)

**Files:**
- Modify: `src/Controller/AssetsController.php` (añadir 6 acciones + helper `_flashResult`)
- Create: `templates/element/asset/movement_modals.php`
- Modify: `templates/Assets/view.php` (botones de acción en el aside + incluir modales)
- Test: `tests/TestCase/Controller/AssetsMovementActionsTest.php`

**Interfaces:**
- Consumes: `AssetInventoryService` (Tasks 10-11), `AssetViewViewModel` (employees/operationCenters/canCreateMovement).
- Produces (añade a `AssetsController`): acciones `assign`, `lend`, `returnAsset`, `transfer`, `dispose`, `registerIngress` (todas `#[Permission(action: 'edit')]`, POST-only, redirigen a `view`), y helper `_flashResult(ServiceResult): void`. URLs (DashedRoute): `/assets/assign/5`, `/assets/lend/5`, `/assets/return-asset/5`, `/assets/transfer/5`, `/assets/dispose/5`, `/assets/register-ingress/5`.

> `return` es palabra reservada en PHP: la acción se llama `returnAsset` (URL `/assets/return-asset/{id}`).

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Controller/AssetsMovementActionsTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetsMovementActionsTest extends TestCase
{
    use IntegrationTestTrait;

    public function testAssignRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/assets/assign/1', ['to_employee_id' => 1]);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testGetOnAssignIsNotAllowed(): void
    {
        // Sin sesión, el gate de auth corre antes que allowMethod → redirect login.
        $this->get('/assets/assign/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetsMovementActionsTest.php`
Expected: FAIL — acciones no existen (routing error).

- [ ] **Step 3: Add the actions and helper to AssetsController**

Add the import at the top of `src/Controller/AssetsController.php`:

```php
use App\Service\AssetInventoryService;
use App\Service\ServiceResult;
```

Add these methods (after `delete()`, before `_setFormDropdowns()`):

```php
    #[Permission(action: 'edit')]
    public function assign($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->assign(
            (int)$id,
            (int)$this->request->getData('to_employee_id'),
            $this->_movementData(),
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'edit')]
    public function lend($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->lend(
            (int)$id,
            (int)$this->request->getData('to_employee_id'),
            $this->_movementData(),
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'edit')]
    public function returnAsset($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->returnAsset((int)$id, $this->_movementData(), $this->_currentUserId());
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'edit')]
    public function transfer($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->transfer(
            (int)$id,
            (int)$this->request->getData('to_operation_center_id'),
            $this->_movementData(),
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'edit')]
    public function dispose($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->dispose((int)$id, $this->_movementData(), $this->_currentUserId());
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'edit')]
    public function registerIngress($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->registerIngress((int)$id, $this->_movementData(), $this->_currentUserId());
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Metadatos comunes del movimiento desde el POST.
     *
     * @return array<string, mixed>
     */
    protected function _movementData(): array
    {
        return [
            'reason' => $this->request->getData('reason'),
            'movement_date' => $this->request->getData('movement_date') ?: null,
        ];
    }

    protected function _currentUserId(): int
    {
        return (int)$this->Authentication->getIdentity()->getIdentifier();
    }

    protected function _flashResult(ServiceResult $result): void
    {
        if ($result->success) {
            $message = is_array($result->data) ? ($result->data['message'] ?? 'Operación realizada.') : 'Operación realizada.';
            $this->Flash->success($message);

            return;
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo completar la operación.');
    }
```

- [ ] **Step 4: Write the movement modals element**

`templates/element/asset/movement_modals.php`:

```php
<?php
/**
 * Modales de movimientos de un activo. Cada uno postea a su acción del
 * AssetsController. Selects en texto plano (form-select) para funcionar dentro
 * del modal sin inicializar select2.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\AssetViewViewModel $viewModel
 */
$asset = $viewModel->asset;
$assetId = $asset->id;

$modal = function (string $id, string $title, string $action, callable $body) use ($asset, $assetId): string {
    $html = '<div class="modal fade" id="' . h($id) . '" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">';
    $html .= $this->Form->create(null, ['url' => ['action' => $action, $assetId]]);
    $html .= '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>';
    $html .= '<div class="modal-body">' . $body() . '</div>';
    $html .= '<div class="modal-footer"><button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>';
    $html .= '<button type="submit" class="btn btn-primary">Confirmar</button></div>';
    $html .= $this->Form->end();
    $html .= '</div></div></div>';

    return $html;
};

$employeeSelect = function (string $label) use ($viewModel): string {
    return $this->Form->control('to_employee_id', [
        'options' => $viewModel->employees,
        'empty' => 'Seleccione…',
        'class' => 'form-select',
        'required' => true,
        'label' => ['text' => $label, 'class' => 'input-label'],
    ]);
};

$reasonField = function () {
    return $this->Form->control('reason', [
        'type' => 'textarea', 'rows' => 2, 'class' => 'form-control',
        'label' => ['text' => 'Motivo / observación', 'class' => 'input-label'],
    ]);
};
?>
<?= $modal('modal-assign', 'Asignar activo', 'assign', fn() => $employeeSelect('Responsable') . $reasonField()) ?>
<?= $modal('modal-lend', 'Prestar activo', 'lend', fn() => $employeeSelect('Responsable') . $reasonField()) ?>
<?= $modal('modal-return', 'Devolver activo', 'returnAsset', fn() => $reasonField()) ?>
<?= $modal('modal-transfer', 'Trasladar activo', 'transfer', fn() => $this->Form->control('to_operation_center_id', [
    'options' => $viewModel->operationCenters, 'empty' => 'Seleccione…', 'class' => 'form-select', 'required' => true,
    'label' => ['text' => 'Centro de operación destino', 'class' => 'input-label'],
]) . $reasonField()) ?>
<?= $modal('modal-dispose', 'Dar de baja', 'dispose', fn() => '<p class="text-muted">Esta acción es irreversible.</p>' . $reasonField()) ?>
```

- [ ] **Step 5: Wire actions + modals into the view**

In `templates/Assets/view.php`, replace the placeholder line:

```php
        <?php // Las acciones de movimiento se inyectan en la Task 18. ?>
```

with the actions panel:

```php
        <?php if ($viewModel->canCreateMovement && $asset->status !== AssetConstants::STATUS_DADO_DE_BAJA): ?>
        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:12px;">Acciones</div>
            <div class="d-flex flex-column gap-2">
                <?php if ($asset->status === AssetConstants::STATUS_DISPONIBLE): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-assign"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Asignar</button>
                    <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-lend"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Prestar</button>
                <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-return"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Devolver</button>
                <?php endif; ?>
                <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-transfer"><i class="bi bi-truck me-1" aria-hidden="true"></i>Trasladar</button>
                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modal-dispose"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Dar de baja</button>
            </div>
        </div>
        <?php endif; ?>
```

And add at the very end of the file (after the closing `</div>` of `.spi-invoice-view-grid`):

```php
<?php if ($viewModel->canCreateMovement && $asset->status !== AssetConstants::STATUS_DADO_DE_BAJA): ?>
    <?= $this->element('asset/movement_modals', ['viewModel' => $viewModel]) ?>
<?php endif; ?>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetsMovementActionsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Manual smoke check**

Con el server corriendo: en `/assets/view/{id}` de un activo disponible, usa "Asignar" → selecciona empleado → confirma. Verifica el flash, el cambio de estado a "Asignado" y la nueva fila en el historial con "Acta: Pendiente".

- [ ] **Step 8: Commit**

```bash
git add src/Controller/AssetsController.php templates/element/asset/movement_modals.php templates/Assets/view.php tests/TestCase/Controller/AssetsMovementActionsTest.php
git commit -m "feat(itam): acciones de movimiento de activos (asignar/prestar/devolver/trasladar/baja)"
```

---

### Task 19: Documentos y actas de activos (subir / descargar / eliminar)

**Files:**
- Modify: `src/Controller/AssetsController.php` (3 acciones de documento)
- Modify: `templates/Assets/view.php` (botón subir + descarga/eliminar por documento + modal de subida)
- Test: `tests/TestCase/Controller/AssetsDocumentActionsTest.php`

**Interfaces:**
- Consumes: `AssetDocumentService` (Task 13).
- Produces (añade a `AssetsController`): `uploadDocument` (`#[Permission('edit')]`, POST), `deleteDocument` (`#[Permission('edit')]`, POST), `downloadDocument` (`#[Permission('view')]`, GET, sirve el archivo privado vía `response->withFile`). URLs: `/assets/upload-document/5`, `/assets/delete-document/9`, `/assets/download-document/9`.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Controller/AssetsDocumentActionsTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetsDocumentActionsTest extends TestCase
{
    use IntegrationTestTrait;

    public function testUploadRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/assets/upload-document/1', []);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testDownloadRequiresAuthentication(): void
    {
        $this->get('/assets/download-document/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetsDocumentActionsTest.php`
Expected: FAIL — acciones no existen.

- [ ] **Step 3: Add the document actions to AssetsController**

Add imports at the top of `src/Controller/AssetsController.php`:

```php
use App\Service\AssetDocumentService;
use Cake\Http\Exception\NotFoundException;
```

Add these methods (after `registerIngress()`):

```php
    #[Permission(action: 'edit')]
    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $file = $this->request->getUploadedFile('document');
        if ($file === null) {
            $this->Flash->error('Seleccione un archivo.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $movementId = $this->request->getData('asset_movement_id');
        $result = (new AssetDocumentService())->uploadDocument(
            (int)$id,
            $file,
            (string)$this->request->getData('document_type'),
            ($movementId !== null && $movementId !== '') ? (int)$movementId : null,
            $this->_currentUserId(),
        );

        if ($result->success) {
            $this->Flash->success('Documento subido correctamente.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo subir el documento.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'edit')]
    public function deleteDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $document = $this->fetchTable('AssetDocuments')->get($id);
        $result = (new AssetDocumentService())->deleteDocument((int)$document->asset_id, (int)$id);

        if ($result->success) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'view', $document->asset_id]);
    }

    #[Permission(action: 'view')]
    public function downloadDocument($id = null)
    {
        $document = $this->fetchTable('AssetDocuments')->get($id);
        $absolutePath = (new AssetDocumentService())->resolveStoragePath($document->file_path);

        if (!is_file($absolutePath)) {
            throw new NotFoundException('El archivo no existe.');
        }

        return $this->response->withFile($absolutePath, ['download' => true, 'name' => $document->name]);
    }
```

- [ ] **Step 4: Wire upload/download/delete into the view**

In `templates/Assets/view.php`, near the top (after `[$statusLabel, $statusPill] = ...`), build the document-type options:

```php
$docTypeOptions = [];
foreach (AssetConstants::DOCUMENT_TYPES as $dt) {
    $docTypeOptions[$dt] = \App\Constants\Domain\Asset\DocumentType::from($dt)->label();
}
```

Replace the documents card header line:

```php
            <div style="font-weight:600;margin-bottom:12px;">Documentos y actas (<?= count($viewModel->documents) ?>)</div>
```

with a header that includes the upload button:

```php
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:12px;">
                <span style="font-weight:600;">Documentos y actas (<?= count($viewModel->documents) ?>)</span>
                <?php if ($viewModel->canCreateMovement): ?>
                <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-upload-doc"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
                <?php endif; ?>
            </div>
```

Replace the per-document row:

```php
                <div class="d-flex justify-content-between align-items-center" style="padding:6px 0;">
                    <span><i class="bi bi-file-earmark me-1" aria-hidden="true"></i><?= h($doc->name) ?></span>
                    <span class="mono" style="font-size:12px;color:var(--text-faint);"><?= h($doc->document_type) ?></span>
                </div>
```

with download + delete controls:

```php
                <div class="d-flex justify-content-between align-items-center" style="padding:6px 0;">
                    <span><i class="bi bi-file-earmark me-1" aria-hidden="true"></i><?= h($doc->name) ?>
                        <span class="pill pill-secondary-soft pill-sm"><?= h(\App\Constants\Domain\Asset\DocumentType::from($doc->document_type)->label()) ?></span>
                    </span>
                    <span class="d-flex gap-1">
                        <?= $this->Html->link('<i class="bi bi-download" aria-hidden="true"></i>',
                            ['action' => 'downloadDocument', $doc->id],
                            ['class' => 'btn-icon', 'escape' => false, 'title' => 'Descargar']) ?>
                        <?php if ($viewModel->canCreateMovement): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                            ['action' => 'deleteDocument', $doc->id],
                            ['confirm' => '¿Eliminar este documento?', 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </span>
                </div>
```

Add the upload modal at the end of the file (after the movement modals include):

```php
<?php if ($viewModel->canCreateMovement): ?>
<div class="modal fade" id="modal-upload-doc" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $asset->id], 'type' => 'file']) ?>
        <div class="modal-header">
            <h5 class="modal-title">Subir documento</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
            <?= $this->Form->control('document_type', [
                'options' => $docTypeOptions, 'class' => 'form-select',
                'label' => ['text' => 'Tipo de documento', 'class' => 'input-label'],
            ]) ?>
            <?= $this->Form->control('document', [
                'type' => 'file', 'class' => 'form-control mt-2',
                'label' => ['text' => 'Archivo (PDF, imagen, Word o Excel)', 'class' => 'input-label'],
            ]) ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary">Subir</button>
        </div>
        <?= $this->Form->end() ?>
    </div></div>
</div>
<?php endif; ?>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetsDocumentActionsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Manual smoke check**

Sube un PDF a un activo, confirma que aparece en "Documentos y actas", descárgalo (debe servir el archivo desde `storage/assets/...`, NO desde una URL pública), y elimínalo.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/AssetsController.php templates/Assets/view.php tests/TestCase/Controller/AssetsDocumentActionsTest.php
git commit -m "feat(itam): subida/descarga/eliminación de documentos de activos (storage privado)"
```

---

### Task 20: ConsumablesController (CRUD + stock) + Presentation + templates

**Files:**
- Create: `src/View/Presentation/ConsumablePresentation.php`
- Create: `src/View/Presentation/ConsumableRowView.php`
- Create: `src/Controller/ConsumablesController.php`
- Create: `templates/element/forms/consumables.php`
- Create: `templates/Consumables/index.php`
- Create: `templates/Consumables/view.php`
- Create: `templates/Consumables/add.php`
- Create: `templates/Consumables/edit.php`
- Test: `tests/TestCase/Controller/ConsumablesControllerTest.php`
- Test: `tests/TestCase/View/Presentation/ConsumablePresentationTest.php`

**Interfaces:**
- Consumes: `ConsumablesTable` (finder `findLowStock`), `ConsumableMovementsTable` (finder `findForConsumable`), `ConsumableStockService` (Task 12), `ConsumableConstants`.
- Produces:
  - `App\View\Presentation\ConsumablePresentation` (final) con `static forRow(Consumable): ConsumableRowView` y `static stockBadge(Consumable): array` (`[label, pillClass]`).
  - `App\View\Presentation\ConsumableRowView` (final readonly): `stockLabel`, `stockBadgeClass`, `operationCenterName`.
  - `App\Controller\ConsumablesController` con `index` (filtro `low_stock`), `view`, `add`, `edit`, `delete`, `stockIn` (`#[Permission('edit')]`, POST), `stockOut` (`#[Permission('edit')]`, POST). URLs: `/consumables/stock-in/5`, `/consumables/stock-out/5`.

- [ ] **Step 1: Write the failing tests**

`tests/TestCase/View/Presentation/ConsumablePresentationTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Model\Entity\Consumable;
use App\View\Presentation\ConsumablePresentation;
use PHPUnit\Framework\TestCase;

final class ConsumablePresentationTest extends TestCase
{
    public function testStockBadgeFlagsLowStock(): void
    {
        $low = new Consumable(['current_stock' => 2, 'minimum_stock' => 5]);
        $ok = new Consumable(['current_stock' => 9, 'minimum_stock' => 5]);

        $this->assertSame('pill-danger-soft', ConsumablePresentation::stockBadge($low)[1]);
        $this->assertSame('pill-accent-soft', ConsumablePresentation::stockBadge($ok)[1]);
    }
}
```

`tests/TestCase/Controller/ConsumablesControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ConsumablesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/consumables');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testStockInRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/consumables/stock-in/1', ['quantity' => 5]);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Controller/ConsumablesControllerTest.php tests/TestCase/View/Presentation/ConsumablePresentationTest.php`
Expected: FAIL — clases no existen.

- [ ] **Step 3: Write Presentation + RowView**

`src/View/Presentation/ConsumableRowView.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

final readonly class ConsumableRowView
{
    public function __construct(
        public string $stockLabel,
        public string $stockBadgeClass,
        public string $operationCenterName,
    ) {
    }
}
```

`src/View/Presentation/ConsumablePresentation.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Model\Entity\Consumable;

final class ConsumablePresentation
{
    /**
     * @return array{0:string, 1:string} [label, pillClass]
     */
    public static function stockBadge(Consumable $consumable): array
    {
        return $consumable->isLowStock()
            ? ['Stock bajo', 'pill-danger-soft']
            : ['Disponible', 'pill-accent-soft'];
    }

    public static function forRow(Consumable $consumable): ConsumableRowView
    {
        [$label, $class] = self::stockBadge($consumable);

        return new ConsumableRowView(
            stockLabel: $label,
            stockBadgeClass: $class,
            operationCenterName: $consumable->operation_center->name ?? '—',
        );
    }
}
```

- [ ] **Step 4: Write the controller**

`src/Controller/ConsumablesController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Service\ConsumableStockService;
use App\Service\ServiceResult;

class ConsumablesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $lowStockOnly = $this->request->getQuery('low_stock') === '1';
        $query = $lowStockOnly ? $this->Consumables->find('lowStock') : $this->Consumables->find();
        $query->contain(['OperationCenters'])->orderBy(['Consumables.description' => 'ASC']);

        $consumables = $this->paginate($query);

        $this->set(compact('consumables', 'lowStockOnly'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $consumable = $this->Consumables->get($id, contain: ['OperationCenters']);
        $movements = $this->fetchTable('ConsumableMovements')->find('forConsumable', consumableId: (int)$consumable->id)
            ->contain(['PerformedByUsers'])->all()->toArray();
        $canEdit = $this->_checkPermission('consumables', 'edit');

        $this->set(compact('consumable', 'movements', 'canEdit'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $consumable = $this->Consumables->newEmptyEntity();
        if ($this->request->is('post')) {
            $consumable = $this->Consumables->patchEntity($consumable, $this->request->getData());
            if ($this->Consumables->save($consumable)) {
                $this->Flash->success(__('El consumible ha sido creado.'));

                return $this->redirect(['action' => 'view', $consumable->id]);
            }
            $this->Flash->error(__('No se pudo crear el consumible. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('consumable'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $consumable = $this->Consumables->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $consumable = $this->Consumables->patchEntity($consumable, $this->request->getData());
            if ($this->Consumables->save($consumable)) {
                $this->Flash->success(__('El consumible ha sido actualizado.'));

                return $this->redirect(['action' => 'view', $consumable->id]);
            }
            $this->Flash->error(__('No se pudo actualizar el consumible. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('consumable'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $consumable = $this->Consumables->get($id);
        if ($this->Consumables->delete($consumable)) {
            $this->Flash->success(__('El consumible ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el consumible. Si tiene movimientos, no puede borrarse.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    #[Permission(action: 'edit')]
    public function stockIn($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new ConsumableStockService())->registerIngress(
            (int)$id,
            (int)$this->request->getData('quantity'),
            ['reason' => $this->request->getData('reason')],
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'edit')]
    public function stockOut($id = null)
    {
        $this->request->allowMethod(['post']);
        $result = (new ConsumableStockService())->registerOutput(
            (int)$id,
            (int)$this->request->getData('quantity'),
            ['reason' => $this->request->getData('reason')],
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    protected function _setFormDropdowns(): void
    {
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();
        $this->set(compact('operationCenters'));
    }

    protected function _currentUserId(): int
    {
        return (int)$this->Authentication->getIdentity()->getIdentifier();
    }

    protected function _flashResult(ServiceResult $result): void
    {
        if ($result->success) {
            $message = is_array($result->data) ? ($result->data['message'] ?? 'Operación realizada.') : 'Operación realizada.';
            $this->Flash->success($message);

            return;
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo completar la operación.');
    }
}
```

- [ ] **Step 5: Write the form element and templates**

`templates/element/forms/consumables.php`:

```php
<?php
/**
 * Campos del form de Consumible (add/edit). El stock actual no se edita aquí:
 * se gestiona con entradas/salidas. Asume estar dentro de Form->create($entity).
 *
 * @var \App\View\AppView $this
 * @var array<int, string> $operationCenters
 */
?>
<div class="row g-3">
    <div class="col-md-4">
        <?= $this->Form->control('reference', ['class' => 'form-control', 'label' => ['text' => 'Referencia', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-8">
        <?= $this->Form->control('description', ['class' => 'form-control', 'label' => ['text' => 'Descripción', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('minimum_stock', ['type' => 'number', 'min' => 0, 'class' => 'form-control', 'label' => ['text' => 'Stock mínimo', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('maximum_stock', ['type' => 'number', 'min' => 0, 'class' => 'form-control', 'label' => ['text' => 'Stock máximo', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('unit', ['class' => 'form-control', 'label' => ['text' => 'Unidad', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('operation_center_id', [
            'options' => $operationCenters, 'empty' => 'Sin sede', 'class' => 'form-select',
            'label' => ['text' => 'Sede', 'class' => 'input-label'],
        ]) ?>
    </div>
</div>
```

`templates/Consumables/index.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Consumable> $consumables
 * @var bool $lowStockOnly
 */
use App\View\Presentation\ConsumablePresentation;

$this->assign('title', 'Consumibles');

$canCreate = !empty($userPermissions['consumables']['can_create']);
$gridCols = '130px 1fr 90px 90px 1fr 110px';
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Consumibles</span>
    <?php if ($canCreate): ?>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Consumible',
        ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
    <?php endif; ?>
</div>

<div class="d-flex flex-wrap" style="gap:8px;margin-bottom:14px;" role="tablist">
    <?= $this->Html->link('Todos', ['action' => 'index'],
        ['class' => 'chip' . ($lowStockOnly ? '' : ' is-active'), 'role' => 'tab']) ?>
    <?= $this->Html->link('Stock bajo', ['action' => 'index', '?' => ['low_stock' => 1]],
        ['class' => 'chip' . ($lowStockOnly ? ' is-active' : ''), 'role' => 'tab']) ?>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Referencia</span>
        <span>Descripción</span>
        <span>Stock</span>
        <span>Mínimo</span>
        <span>Sede</span>
        <span>Estado</span>
    </div>

    <?php $rowCount = 0; foreach ($consumables as $consumable): $rowCount++; ?>
        <?php $row = ConsumablePresentation::forRow($consumable); ?>
        <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
             data-href="<?= $this->Url->build(['action' => 'view', $consumable->id]) ?>" role="row">
            <span class="mono" style="color:var(--text-muted);"><?= h($consumable->reference) ?></span>
            <span style="font-weight:600;color:var(--text-strong);"><?= h($consumable->description) ?></span>
            <span class="mono"><?= $this->Number->format($consumable->current_stock) ?></span>
            <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($consumable->minimum_stock) ?></span>
            <span><?= h($row->operationCenterName) ?></span>
            <span><span class="pill <?= h($row->stockBadgeClass) ?>"><?= h($row->stockLabel) ?></span></span>
        </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-droplet" aria-hidden="true"></i></div>
        <div class="es-title">Sin consumibles</div>
        <div class="es-msg">No hay registros para mostrar.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>
```

`templates/Consumables/view.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Consumable $consumable
 * @var array<int, \App\Model\Entity\ConsumableMovement> $movements
 * @var bool $canEdit
 */
use App\Constants\ConsumableConstants;
use App\View\Presentation\ConsumablePresentation;

$this->assign('title', 'Consumible ' . $consumable->reference);
[$stockLabel, $stockPill] = ConsumablePresentation::stockBadge($consumable);
?>
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:16px;">
    <div>
        <h1 class="spi-page-title">Consumible <?= h($consumable->reference) ?></h1>
        <span class="pill <?= h($stockPill) ?>"><?= h($stockLabel) ?></span>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $consumable->id], ['class' => 'btn btn-secondary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="spi-card" style="margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;">
        <div class="field-row"><span class="k">Descripción</span><span class="v"><?= h($consumable->description) ?></span></div>
        <div class="field-row"><span class="k">Stock actual</span><span class="v mono"><?= $this->Number->format($consumable->current_stock) ?> <?= h($consumable->unit) ?></span></div>
        <div class="field-row"><span class="k">Mínimo / Máximo</span><span class="v mono"><?= $this->Number->format($consumable->minimum_stock) ?> / <?= $consumable->maximum_stock !== null ? $this->Number->format($consumable->maximum_stock) : '—' ?></span></div>
        <div class="field-row"><span class="k">Sede</span><span class="v"><?= h($consumable->operation_center->name ?? '—') ?></span></div>
    </div>
    <?php if ($canEdit): ?>
    <div class="d-flex gap-2 mt-3">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-stock-in"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Entrada</button>
        <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-stock-out"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Salida</button>
    </div>
    <?php endif; ?>
</div>

<div class="spi-card">
    <div style="font-weight:600;margin-bottom:12px;">Movimientos de stock (<?= count($movements) ?>)</div>
    <?php if ($movements === []): ?>
        <div style="color:var(--text-faint);font-size:13px;">Sin movimientos.</div>
    <?php else: ?>
        <?php foreach ($movements as $m): ?>
        <div class="d-flex justify-content-between align-items-center" style="padding:6px 0;border-bottom:1px solid var(--border-subtle);">
            <span><?= h(ConsumableConstants::MOVEMENT_LABELS[$m->movement_type] ?? $m->movement_type) ?>
                <span class="mono" style="color:var(--text-faint);">(<?= $m->quantity > 0 ? '+' : '' ?><?= $this->Number->format($m->quantity) ?> → <?= $this->Number->format($m->balance_after) ?>)</span>
            </span>
            <span class="mono" style="font-size:12px;color:var(--text-muted);"><?= $m->movement_date?->format('d/m/Y H:i') ?></span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<?php
$stockModal = function (string $id, string $title, string $action) use ($consumable): string {
    $html = '<div class="modal fade" id="' . $id . '" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">';
    $html .= $this->Form->create(null, ['url' => ['action' => $action, $consumable->id]]);
    $html .= '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>';
    $html .= '<div class="modal-body">';
    $html .= $this->Form->control('quantity', ['type' => 'number', 'min' => 1, 'required' => true, 'class' => 'form-control', 'label' => ['text' => 'Cantidad', 'class' => 'input-label']]);
    $html .= $this->Form->control('reason', ['type' => 'textarea', 'rows' => 2, 'class' => 'form-control mt-2', 'label' => ['text' => 'Motivo', 'class' => 'input-label']]);
    $html .= '</div><div class="modal-footer"><button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Confirmar</button></div>';
    $html .= $this->Form->end() . '</div></div></div>';

    return $html;
};
?>
<?= $stockModal('modal-stock-in', 'Entrada de stock', 'stockIn') ?>
<?= $stockModal('modal-stock-out', 'Salida de stock', 'stockOut') ?>
<?php endif; ?>
```

`templates/Consumables/add.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Consumable $consumable
 */
$this->assign('title', 'Nuevo Consumible');
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nuevo Consumible</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'], ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>
<div class="spi-card">
    <?= $this->Form->create($consumable) ?>
    <?= $this->element('forms/consumables') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Crear</button>
    <?= $this->Form->end() ?>
</div>
```

`templates/Consumables/edit.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Consumable $consumable
 */
$this->assign('title', 'Editar Consumible');
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Editar Consumible <?= h($consumable->reference) ?></span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'view', $consumable->id], ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>
<div class="spi-card">
    <?= $this->Form->create($consumable) ?>
    <?= $this->element('forms/consumables') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar cambios</button>
    <?= $this->Form->end() ?>
</div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Controller/ConsumablesControllerTest.php tests/TestCase/View/Presentation/ConsumablePresentationTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/ConsumablesController.php src/View/Presentation/ConsumablePresentation.php src/View/Presentation/ConsumableRowView.php templates/Consumables templates/element/forms/consumables.php tests/TestCase/Controller/ConsumablesControllerTest.php tests/TestCase/View/Presentation/ConsumablePresentationTest.php
git commit -m "feat(itam): ConsumablesController (CRUD + stock in/out) + Presentation + templates"
```

---

### Task 21: Sidebar — sección "Inventario TI"

**Files:**
- Create: `templates/element/sidebar/itam.php`
- Modify: `templates/layout/default.php` (incluir el element)

**Interfaces:**
- Consumes: closures `$canView`/`$navLink` (provistos por `default.php`). Badge de alertas abiertas vía `$openAlertsCount` (lo provee la Task 25; aquí se usa con fallback `?? 0`).
- Produces: sección de navegación "Inventario TI" con links a Activos, Consumibles, Categorías de Activos y Alertas, cada uno gateado por `can_view`.

> Es un cambio puramente de template; la verificación es manual (render del layout autenticado). No lleva test automatizado.

- [ ] **Step 1: Write the sidebar element**

`templates/element/sidebar/itam.php`:

```php
<?php
/**
 * Sidebar — sección Inventario TI (ITAM).
 *
 * @var \App\View\AppView $this
 * @var \Closure $canView
 * @var \Closure $navLink
 */
$itamItems = array_filter([
    $canView('assets') ? 'assets' : null,
    $canView('consumables') ? 'consumables' : null,
    $canView('asset_categories') ? 'asset_categories' : null,
    $canView('asset_alerts') ? 'asset_alerts' : null,
]);
if (empty($itamItems)) {
    return;
}
$openAlertsCount = $openAlertsCount ?? 0;
?>
<li class="sb-section-head">Inventario TI</li>
    <?php if ($canView('assets')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-pc-display" aria-hidden="true"></i></span><span class="grow">Activos</span>',
            ['controller' => 'Assets', 'action' => 'index'],
            ['class' => $navLink('Assets'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('consumables')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-droplet-half" aria-hidden="true"></i></span><span class="grow">Consumibles</span>',
            ['controller' => 'Consumables', 'action' => 'index'],
            ['class' => $navLink('Consumables'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('asset_alerts')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><span class="grow">Alertas</span>' .
            ($openAlertsCount > 0 ? '<span class="sb-badge is-danger">' . (int)$openAlertsCount . '</span>' : ''),
            ['controller' => 'AssetAlerts', 'action' => 'index'],
            ['class' => $navLink('AssetAlerts'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('asset_categories')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-tags" aria-hidden="true"></i></span><span class="grow">Categorías de Activos</span>',
            ['controller' => 'AssetCategories', 'action' => 'index'],
            ['class' => $navLink('AssetCategories'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
```

- [ ] **Step 2: Include the element in the layout**

In `templates/layout/default.php`, after the `sidebar/catalogos` element include, add:

```php
                <?= $this->element('sidebar/itam', ['canView' => $canView, 'navLink' => $navLink, 'openAlertsCount' => $openAlertsCount ?? 0]) ?>
```

- [ ] **Step 3: Manual verification**

Run `php bin/cake server`, log in as admin. Confirma que aparece la sección "Inventario TI" con los 4 links y que cada uno navega a su index. (El badge de alertas se llena en la Task 25.)

- [ ] **Step 4: Run the full Fase 3 controller suite + cs-check**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetCategoriesControllerTest.php tests/TestCase/Controller/AssetsControllerTest.php tests/TestCase/Controller/AssetsMovementActionsTest.php tests/TestCase/Controller/AssetsDocumentActionsTest.php tests/TestCase/Controller/ConsumablesControllerTest.php` then `composer cs-check`
Expected: todo verde.

- [ ] **Step 5: Commit**

```bash
git add templates/element/sidebar/itam.php templates/layout/default.php
git commit -m "feat(itam): sección Inventario TI en el sidebar"
```

---

# FASE 4 — Alertas (cálculo, persistencia, comando y UI)

Resultado verificable: `bin/cake itam_generate_alerts` calcula y persiste alertas; la UI las lista y permite resolverlas; el sidebar muestra el badge de abiertas. **Sin push a n8n** (queda para el plan "ITAM").

---

### Task 22: AssetAlertService (cálculo + persistencia) + AssetMovementFactory

**Files:**
- Create: `src/Service/AssetAlertService.php`
- Modify: `src/Constants/AssetAlertConstants.php` (añadir `ACTA_OVERDUE_DAYS`)
- Create: `tests/Factory/AssetMovementFactory.php`
- Test: `tests/TestCase/Service/Integration/AssetAlertServiceTest.php`

**Interfaces:**
- Consumes: `ConsumablesTable` (`findLowStock`), `AssetMovementsTable`, `AssetsTable`, `AssetAlertsTable`, `AssetConstants`, `AssetAlertConstants`.
- Produces:
  - `App\Service\AssetAlertService::generate(): array` → `['created' => int, 'overdue' => int]`. Aplica las reglas (stock bajo, acta pendiente, activo sin responsable, registro incompleto) creando alertas **sin duplicar** las ya `abierta`, y marca `vencida` las actas pendientes muy viejas. **No** hace push a n8n.
  - `App\Constants\AssetAlertConstants::ACTA_OVERDUE_DAYS` (int).
  - `App\Test\Factory\AssetMovementFactory` con helpers `forAsset(int)`, `withType(string)`, `withActaStatus(?string)`, `withMovementDate(string)`.

- [ ] **Step 1: Add ACTA_OVERDUE_DAYS to AssetAlertConstants**

In `src/Constants/AssetAlertConstants.php`, after `public const ACTA_PENDING_DAYS = 3;` add:

```php
    /** Días tras los cuales un acta pendiente se considera vencida (movimiento_sin_cerrar). */
    public const ACTA_OVERDUE_DAYS = 15;
```

- [ ] **Step 2: Write AssetMovementFactory and the failing test**

`tests/Factory/AssetMovementFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\AssetConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de AssetMovement. Auto-crea asset_id y performed_by_user_id (NOT NULL).
 */
class AssetMovementFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'AssetMovements';
    }

    public static function new(mixed $makeParameter = [], int $times = 1): static
    {
        return parent::new($makeParameter, $times)->withRequiredParents();
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'movement_type' => AssetConstants::MOVEMENT_ENTREGA,
            'movement_date' => date('Y-m-d H:i:s'),
            'source' => AssetConstants::SOURCE_WEB,
        ];
    }

    public function forAsset(int $assetId): static
    {
        return $this->setField('asset_id', $assetId);
    }

    public function withType(string $type): static
    {
        return $this->setField('movement_type', $type);
    }

    public function withActaStatus(?string $status): static
    {
        return $this->setField('acta_status', $status);
    }

    public function withMovementDate(string $date): static
    {
        return $this->setField('movement_date', $date);
    }
}
```

`tests/TestCase/Service/Integration/AssetAlertServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AssetAlertConstants;
use App\Constants\AssetConstants;
use App\Service\AssetAlertService;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\AssetMovementFactory;
use App\Test\Factory\ConsumableFactory;
use Cake\TestSuite\TestCase;

final class AssetAlertServiceTest extends TestCase
{
    private function service(): AssetAlertService
    {
        return new AssetAlertService();
    }

    public function testLowStockCreatesAlertAndDoesNotDuplicate(): void
    {
        ConsumableFactory::new()->withStock(1, 5)->save();

        $first = $this->service()->generate();
        $second = $this->service()->generate();

        $alerts = $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_STOCK_BAJO])->all()->toArray();

        $this->assertGreaterThanOrEqual(1, $first['created']);
        $this->assertCount(1, $alerts, 'No debe duplicar la alerta de stock bajo en la segunda corrida.');
        $this->assertSame(0, $this->_createdOfType($second, AssetAlertConstants::TYPE_STOCK_BAJO));
    }

    public function testAssignedAssetWithoutResponsibleCreatesAlert(): void
    {
        AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)->save(); // sin responsable

        $this->service()->generate();

        $this->assertSame(1, $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_ACTIVO_SIN_RESPONSABLE])->count());
    }

    public function testOldPendingActaCreatesAlert(): void
    {
        $asset = AssetFactory::new()->save();
        AssetMovementFactory::new()->forAsset($asset->id)
            ->withType(AssetConstants::MOVEMENT_ENTREGA)
            ->withActaStatus(AssetConstants::ACTA_PENDIENTE)
            ->withMovementDate(date('Y-m-d H:i:s', strtotime('-5 days')))
            ->save();

        $this->service()->generate();

        $this->assertSame(1, $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_ACTA_PENDIENTE])->count());
    }

    public function testIncompleteAssetWithoutSerialCreatesAlert(): void
    {
        AssetFactory::new()->setField('serial_number', null)->save();

        $this->service()->generate();

        $this->assertGreaterThanOrEqual(1, $this->fetchTable('AssetAlerts')
            ->find()->where(['alert_type' => AssetAlertConstants::TYPE_REGISTRO_INCOMPLETO])->count());
    }

    /**
     * Helper: cuántas alertas de un tipo se crearon en una corrida (aprox vía
     * recuento total; usado solo para aserción de no-duplicación).
     */
    private function _createdOfType(array $stats, string $type): int
    {
        return $stats['created_by_type'][$type] ?? 0;
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetAlertServiceTest.php`
Expected: FAIL — service no existe.

- [ ] **Step 4: Write the service**

`src/Service/AssetAlertService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetAlertConstants;
use App\Constants\AssetConstants;
use Cake\ORM\TableRegistry;

/**
 * Calcula y persiste alertas del inventario. Idempotente: no duplica alertas ya
 * abiertas. NO hace push a n8n (eso vive en el plan ITAM posterior).
 */
class AssetAlertService
{
    /**
     * @return array{created: int, overdue: int, created_by_type: array<string, int>}
     */
    public function generate(): array
    {
        $byType = [
            AssetAlertConstants::TYPE_STOCK_BAJO => $this->_lowStockAlerts(),
            AssetAlertConstants::TYPE_ACTA_PENDIENTE => $this->_pendingActaAlerts(),
            AssetAlertConstants::TYPE_ACTIVO_SIN_RESPONSABLE => $this->_assetsWithoutResponsible(),
            AssetAlertConstants::TYPE_REGISTRO_INCOMPLETO => $this->_incompleteRecords(),
        ];

        return [
            'created' => array_sum($byType),
            'overdue' => $this->_markOverdueActas(),
            'created_by_type' => $byType,
        ];
    }

    private function _lowStockAlerts(): int
    {
        $consumables = TableRegistry::getTableLocator()->get('Consumables')->find('lowStock')->all();
        $created = 0;
        foreach ($consumables as $consumable) {
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_STOCK_BAJO,
                ['consumable_id' => $consumable->id],
                sprintf('Stock bajo: %s (%d ≤ %d).', $consumable->reference, $consumable->current_stock, $consumable->minimum_stock),
                AssetAlertConstants::PRIORITY_ALTA,
            );
        }

        return $created;
    }

    private function _pendingActaAlerts(): int
    {
        $pendingThreshold = date('Y-m-d H:i:s', strtotime('-' . AssetAlertConstants::ACTA_PENDING_DAYS . ' days'));
        $overdueThreshold = date('Y-m-d H:i:s', strtotime('-' . AssetAlertConstants::ACTA_OVERDUE_DAYS . ' days'));

        $movements = TableRegistry::getTableLocator()->get('AssetMovements')->find()
            ->where([
                'acta_status' => AssetConstants::ACTA_PENDIENTE,
                'movement_date <=' => $pendingThreshold,
                'movement_date >' => $overdueThreshold,
            ])
            ->all();

        $created = 0;
        foreach ($movements as $movement) {
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_ACTA_PENDIENTE,
                ['asset_id' => $movement->asset_id, 'asset_movement_id' => $movement->id],
                'Acta pendiente de cargar para el movimiento registrado.',
                AssetAlertConstants::PRIORITY_MEDIA,
            );
        }

        return $created;
    }

    private function _assetsWithoutResponsible(): int
    {
        $assets = TableRegistry::getTableLocator()->get('Assets')->find()
            ->where(['status' => AssetConstants::STATUS_ASIGNADO, 'responsible_employee_id IS' => null])
            ->all();

        $created = 0;
        foreach ($assets as $asset) {
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_ACTIVO_SIN_RESPONSABLE,
                ['asset_id' => $asset->id],
                sprintf('El activo %s está asignado pero no tiene responsable.', $asset->code),
                AssetAlertConstants::PRIORITY_ALTA,
            );
        }

        return $created;
    }

    private function _incompleteRecords(): int
    {
        $assets = TableRegistry::getTableLocator()->get('Assets')->find()
            ->where(['OR' => ['serial_number IS' => null, 'serial_number' => '']])
            ->all();

        $created = 0;
        foreach ($assets as $asset) {
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_REGISTRO_INCOMPLETO,
                ['asset_id' => $asset->id],
                sprintf('El activo %s está incompleto (sin número de serie).', $asset->code),
                AssetAlertConstants::PRIORITY_BAJA,
            );
        }

        return $created;
    }

    /**
     * Marca como vencida toda alerta de acta pendiente abierta cuyo movimiento
     * supera el umbral de vencimiento y sigue sin acta cargada.
     */
    private function _markOverdueActas(): int
    {
        $alertsTable = TableRegistry::getTableLocator()->get('AssetAlerts');
        $overdueThreshold = date('Y-m-d H:i:s', strtotime('-' . AssetAlertConstants::ACTA_OVERDUE_DAYS . ' days'));

        $alerts = $alertsTable->find()
            ->where([
                'AssetAlerts.alert_type' => AssetAlertConstants::TYPE_ACTA_PENDIENTE,
                'AssetAlerts.status' => AssetAlertConstants::STATUS_ABIERTA,
                'AssetAlerts.asset_movement_id IS NOT' => null,
            ])
            ->contain(['AssetMovements'])
            ->all();

        $overdue = 0;
        foreach ($alerts as $alert) {
            $movement = $alert->asset_movement;
            if ($movement === null || $movement->acta_status !== AssetConstants::ACTA_PENDIENTE) {
                continue;
            }
            if ($movement->movement_date->format('Y-m-d H:i:s') > $overdueThreshold) {
                continue;
            }
            $alert->status = AssetAlertConstants::STATUS_VENCIDA;
            if ($alertsTable->save($alert)) {
                $overdue++;
            }
        }

        return $overdue;
    }

    /**
     * Crea una alerta si no existe una ABIERTA del mismo tipo y entidad.
     *
     * @param array<string, int> $entityKeys Llaves de entidad (asset_id, consumable_id, asset_movement_id).
     */
    private function _createIfAbsent(string $alertType, array $entityKeys, string $message, string $priority): int
    {
        $alertsTable = TableRegistry::getTableLocator()->get('AssetAlerts');

        $conditions = ['alert_type' => $alertType, 'status' => AssetAlertConstants::STATUS_ABIERTA] + $entityKeys;
        if ($alertsTable->exists($conditions)) {
            return 0;
        }

        $alert = $alertsTable->newEntity(['alert_type' => $alertType, 'message' => $message, 'priority' => $priority] + $entityKeys);
        $alert->status = AssetAlertConstants::STATUS_ABIERTA;

        return $alertsTable->save($alert) ? 1 : 0;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Integration/AssetAlertServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Service/AssetAlertService.php src/Constants/AssetAlertConstants.php tests/Factory/AssetMovementFactory.php tests/TestCase/Service/Integration/AssetAlertServiceTest.php
git commit -m "feat(itam): AssetAlertService (cálculo y persistencia de alertas, sin push)"
```

---

### Task 23: Comando `itam_generate_alerts`

**Files:**
- Create: `src/Command/ItamGenerateAlertsCommand.php`
- Test: `tests/TestCase/Command/ItamGenerateAlertsCommandTest.php`

**Interfaces:**
- Consumes: `AssetAlertService::generate()`.
- Produces: comando `bin/cake itam_generate_alerts` que ejecuta `generate()` e imprime las estadísticas. Exit `CODE_SUCCESS`.

> El push a n8n se hará en el plan ITAM: este comando solo calcula y persiste. Se agenda en el cron del servidor.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Command/ItamGenerateAlertsCommandTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use App\Constants\AssetConstants;
use App\Test\Factory\AssetFactory;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ItamGenerateAlertsCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public function testRunGeneratesAlertsAndReportsStats(): void
    {
        AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)->save(); // sin responsable

        $this->exec('itam_generate_alerts');

        $this->assertExitSuccess();
        $this->assertOutputContains('Alertas');
        $this->assertSame(1, $this->fetchTable('AssetAlerts')->find()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Command/ItamGenerateAlertsCommandTest.php`
Expected: FAIL — comando no existe.

- [ ] **Step 3: Write the command**

`src/Command/ItamGenerateAlertsCommand.php`:

```php
<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\AssetAlertService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Calcula y persiste las alertas del inventario TI (stock bajo, actas
 * pendientes, activos sin responsable, registros incompletos) y marca las actas
 * vencidas. Pensado para agendarse en el cron del servidor.
 *
 * El push a n8n NO se hace aquí (vive en el plan ITAM posterior).
 */
class ItamGenerateAlertsCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Genera y persiste las alertas del inventario TI.');

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $io->info('Generando alertas de inventario…');

        $stats = (new AssetAlertService())->generate();

        $io->success(sprintf(
            'Alertas: %d creadas, %d marcadas como vencidas.',
            $stats['created'],
            $stats['overdue'],
        ));

        return self::CODE_SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Command/ItamGenerateAlertsCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Verify the command is discoverable**

Run: `php bin/cake itam_generate_alerts`
Expected: imprime "Alertas: N creadas, M marcadas como vencidas." y sale con código 0.

- [ ] **Step 6: Commit**

```bash
git add src/Command/ItamGenerateAlertsCommand.php tests/TestCase/Command/ItamGenerateAlertsCommandTest.php
git commit -m "feat(itam): comando itam_generate_alerts"
```

---

### Task 24: AssetAlertsController + UI de alertas (index + resolver)

**Files:**
- Create: `src/View/Presentation/AssetAlertPresentation.php`
- Create: `src/Controller/AssetAlertsController.php`
- Create: `templates/AssetAlerts/index.php`
- Test: `tests/TestCase/Controller/AssetAlertsControllerTest.php`

**Interfaces:**
- Consumes: `AssetAlertsTable` (finders `findOpen`/`findByStatus`), `AssetAlertConstants`.
- Produces:
  - `App\View\Presentation\AssetAlertPresentation` (final) con consts `PRIORITY_BADGES` y `STATUS_BADGES`.
  - `App\Controller\AssetAlertsController` con `index` (filtro por `status`, default `abierta`) y `resolve` (`#[Permission('edit')]`, POST → marca `resuelta` + `resolved_at`). URL `/asset-alerts/resolve/5`.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Controller/AssetAlertsControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetAlertsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/asset-alerts');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testResolveRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/asset-alerts/resolve/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetAlertsControllerTest.php`
Expected: FAIL — controller no existe.

- [ ] **Step 3: Write Presentation and Controller**

`src/View/Presentation/AssetAlertPresentation.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AssetAlertConstants;

final class AssetAlertPresentation
{
    /** @var array<string, string> */
    public const PRIORITY_BADGES = [
        AssetAlertConstants::PRIORITY_ALTA => 'pill-danger-soft',
        AssetAlertConstants::PRIORITY_MEDIA => 'pill-warning-soft',
        AssetAlertConstants::PRIORITY_BAJA => 'pill-secondary-soft',
    ];

    /** @var array<string, string> */
    public const STATUS_BADGES = [
        AssetAlertConstants::STATUS_ABIERTA => 'pill-warning-soft',
        AssetAlertConstants::STATUS_RESUELTA => 'pill-accent-soft',
        AssetAlertConstants::STATUS_VENCIDA => 'pill-danger-soft',
    ];
}
```

`src/Controller/AssetAlertsController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\AssetAlertConstants;
use Cake\I18n\DateTime;

class AssetAlertsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $status = (string)$this->request->getQuery('status', AssetAlertConstants::STATUS_ABIERTA);

        $query = $this->AssetAlerts->find()
            ->contain(['Assets', 'Consumables'])
            ->orderBy(['AssetAlerts.priority' => 'ASC', 'AssetAlerts.created' => 'DESC']);
        if ($status !== '' && in_array($status, AssetAlertConstants::STATUSES, true)) {
            $query->where(['AssetAlerts.status' => $status]);
        }

        $alerts = $this->paginate($query);
        $statusLabels = AssetAlertConstants::STATUS_LABELS;
        $typeLabels = AssetAlertConstants::TYPE_LABELS;

        $this->set(compact('alerts', 'status', 'statusLabels', 'typeLabels'));
    }

    #[Permission(action: 'edit')]
    public function resolve($id = null)
    {
        $this->request->allowMethod(['post']);
        $alert = $this->AssetAlerts->get($id);
        $alert->status = AssetAlertConstants::STATUS_RESUELTA;
        $alert->resolved_at = DateTime::now();

        if ($this->AssetAlerts->save($alert)) {
            $this->Flash->success('Alerta resuelta.');
        } else {
            $this->Flash->error('No se pudo resolver la alerta.');
        }

        return $this->redirect(['action' => 'index']);
    }
}
```

- [ ] **Step 4: Write the index template**

`templates/AssetAlerts/index.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AssetAlert> $alerts
 * @var string $status
 * @var array<string, string> $statusLabels
 * @var array<string, string> $typeLabels
 */
use App\Constants\AssetAlertConstants;
use App\View\Presentation\AssetAlertPresentation;

$this->assign('title', 'Alertas de Inventario');

$canResolve = !empty($userPermissions['asset_alerts']['can_edit']);
$gridCols = '160px 1fr 90px 90px 110px';
$tabs = [
    [AssetAlertConstants::STATUS_ABIERTA, 'Abiertas'],
    [AssetAlertConstants::STATUS_VENCIDA, 'Vencidas'],
    [AssetAlertConstants::STATUS_RESUELTA, 'Resueltas'],
    ['', 'Todas'],
];
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Alertas de Inventario</span>
</div>

<div class="d-flex flex-wrap" style="gap:8px;margin-bottom:14px;" role="tablist">
    <?php foreach ($tabs as [$value, $label]): ?>
        <?= $this->Html->link(h($label),
            ['action' => 'index', '?' => $value !== '' ? ['status' => $value] : []],
            ['class' => 'chip' . ($status === $value ? ' is-active' : ''), 'role' => 'tab']) ?>
    <?php endforeach; ?>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Tipo</span>
        <span>Mensaje</span>
        <span>Prioridad</span>
        <span>Estado</span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($alerts as $alert): $rowCount++; ?>
    <div class="row-fact" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span style="font-weight:600;"><?= h($typeLabels[$alert->alert_type] ?? $alert->alert_type) ?></span>
        <span style="color:var(--text-muted);"><?= h($alert->message) ?></span>
        <span><span class="pill <?= h(AssetAlertPresentation::PRIORITY_BADGES[$alert->priority] ?? 'pill-secondary-soft') ?>"><?= h(AssetAlertConstants::PRIORITY_LABELS[$alert->priority] ?? $alert->priority) ?></span></span>
        <span><span class="pill <?= h(AssetAlertPresentation::STATUS_BADGES[$alert->status] ?? 'pill-secondary-soft') ?>"><?= h($statusLabels[$alert->status] ?? $alert->status) ?></span></span>
        <span class="d-flex justify-content-end">
            <?php if ($canResolve && $alert->status !== AssetAlertConstants::STATUS_RESUELTA): ?>
            <?= $this->Form->postLink('<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Resolver',
                ['action' => 'resolve', $alert->id],
                ['confirm' => '¿Marcar la alerta como resuelta?', 'class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-bell-slash" aria-hidden="true"></i></div>
        <div class="es-title">Sin alertas</div>
        <div class="es-msg">No hay alertas para el filtro seleccionado.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AssetAlertsControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Controller/AssetAlertsController.php src/View/Presentation/AssetAlertPresentation.php templates/AssetAlerts tests/TestCase/Controller/AssetAlertsControllerTest.php
git commit -m "feat(itam): AssetAlertsController + UI de alertas (listar/resolver)"
```

---

### Task 25: Badge de alertas en el sidebar + widget en el dashboard

**Files:**
- Modify: `src/Service/SidebarCounterService.php` (añadir `openAlertsCount`)
- Modify: `templates/Dashboard/index.php` (stat card de alertas abiertas)

**Interfaces:**
- Consumes: `AssetAlertsTable`, `AssetAlertConstants`. La view var `openAlertsCount` ya es consumida por `templates/element/sidebar/itam.php` (Task 21) y por `templates/layout/default.php`.
- Produces: la clave `openAlertsCount` en `SidebarCounterService::getCounters()` (queda como view var en todas las vistas autenticadas vía `AppController::_setSidebarCounters`).

> Wiring + template; verificación manual (no hay harness de sesión para tests de sidebar/dashboard).

- [ ] **Step 1: Add the counter to SidebarCounterService**

In `src/Service/SidebarCounterService.php`, ensure `use Cake\ORM\TableRegistry;` is imported (lo está). In the array returned by `getCounters()`, add:

```php
            'openAlertsCount' => TableRegistry::getTableLocator()->get('AssetAlerts')
                ->find()->where(['status' => \App\Constants\AssetAlertConstants::STATUS_ABIERTA])->count(),
```

(El contador se cachea junto al resto por rol; se refresca con el TTL/invalidación existente de la caché `sidebar`.)

- [ ] **Step 2: Verify the sidebar badge appears**

Run `php bin/cake server`. Genera al menos una alerta (`php bin/cake itam_generate_alerts` tras crear un activo asignado sin responsable). Recarga la app: el link "Alertas" del sidebar debe mostrar el badge rojo con el conteo.

- [ ] **Step 3: Add a dashboard stat card**

In `templates/Dashboard/index.php`, within the existing stats grid (junto a las demás `.spi-stat-card`), add a card linking to the alerts list:

```php
<?= $this->Html->link(
    '<div class="spi-stat-card">'
    . '<div class="spi-stat-label"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Alertas de inventario</div>'
    . '<div class="spi-stat-value">' . (int)($openAlertsCount ?? 0) . '</div>'
    . '<div class="spi-stat-sub">abiertas</div>'
    . '</div>',
    ['controller' => 'AssetAlerts', 'action' => 'index'],
    ['escape' => false, 'class' => 'spi-stat-card-link'],
) ?>
```

> Ajusta el markup a las clases reales de las stat cards del dashboard si difieren; lo esencial es mostrar `$openAlertsCount` enlazando a `/asset-alerts`.

- [ ] **Step 4: Run the full ITAM test suite + cs-check**

Run:
```bash
vendor/bin/phpunit tests/TestCase/Constants tests/TestCase/Model tests/TestCase/Service/Integration/AssetInventoryServiceTest.php tests/TestCase/Service/Integration/AssetInventoryServiceTransitionsTest.php tests/TestCase/Service/Integration/ConsumableStockServiceTest.php tests/TestCase/Service/Integration/AssetDocumentServiceTest.php tests/TestCase/Service/Integration/AssetAlertServiceTest.php tests/TestCase/Service/AuthorizationServiceModulesTest.php tests/TestCase/View/Presentation tests/TestCase/Command/ItamGenerateAlertsCommandTest.php tests/TestCase/Controller/Asset* tests/TestCase/Controller/ConsumablesControllerTest.php
composer cs-check
```
Expected: todo verde. Luego corre la suite completa una vez (`composer test`) para descartar regresiones (ver memoria: re-correr limpio si hay errores cascade entre suites).

- [ ] **Step 5: Commit**

```bash
git add src/Service/SidebarCounterService.php templates/Dashboard/index.php
git commit -m "feat(itam): badge de alertas en sidebar + widget en dashboard"
```

---

# Verificación final del módulo

Tras completar las 25 tareas:

- [ ] **Migraciones limpias:** `php bin/cake migrations migrate` y `rollback` corren sin error (7 tablas + seed de permisos).
- [ ] **Suite completa verde:** `composer test` pasa (ver memoria del proyecto: si aparecen errores en cascada entre suites consecutivas, re-correr limpio antes de concluir regresión).
- [ ] **Estilo:** `composer cs-check` sin findings (auto-fix con `composer cs-fix`).
- [ ] **Smoke manual** (logueado como Administrador):
  - Crear categoría → crear activo → asignar a empleado (acta pendiente) → subir acta (PDF) → descargarla (sirve desde `storage/assets`, NO URL pública) → trasladar → dar de baja.
  - Crear consumible → entrada y salida de stock → ver historial → filtro "Stock bajo".
  - `php bin/cake itam_generate_alerts` → ver alertas en `/asset-alerts` → resolver una → badge del sidebar refleja el conteo.

## Cobertura del spec (Fases 1-4)

| Sección del spec | Cubierto por |
|---|---|
| §4 Modelo de datos (7 tablas, enums, transiciones) | Tasks 1-9 |
| §5 Servicios (AssetInventory, ConsumableStock, AssetDocument, AssetAlert) | Tasks 10-13, 22 |
| §5 Controllers web | Tasks 15, 17-20, 24 |
| §8 Alertas (reglas, comando, UI, widget) | Tasks 22-25 |
| §9 Permisos (RBAC, seed) | Task 14 |
| §10 UI / templates / sidebar | Tasks 15, 17-21, 24 |
| §6 API REST + §7 IA + push n8n + usuario de servicio | **Fuera de alcance** → plan "ITAM" posterior |

## Deuda y decisiones registradas

- **Almacenamiento privado** (`ROOT/storage/assets`): replica `EmployeeDocumentService`. Cuando S3 entre a producción, introducir el puerto de almacenamiento compartido (ver memoria [[storage-port-s3-debt]]) y migrar ambos servicios juntos — no antes.
- **Push de alertas a n8n** (`N8nService::sendData('itam_alert', …)` + `notified_at`): intencionalmente omitido aquí; pertenece al plan ITAM (capa de integración).
- **`adjust` de consumibles** existe en `ConsumableStockService` pero no se expone en la UI (solo entrada/salida). Se reusará desde la API del agente.
- **Tests de controller** verifican solo el gate de autenticación (el proyecto no tiene harness de sesión autenticada); la lógica vive en los tests de servicio.
