# Eliminación completa del módulo de Legalizaciones

**Fecha:** 2026-04-24
**Estado:** Diseño validado, listo para implementar

## Contexto

El módulo de Legalizaciones (`LegalizationRecords`, `LegalizationPayments`, `LegalizationDocuments`, `LegalizationObservations`) se elimina por completo del sistema. La base de datos no tiene facturas vinculadas a este módulo, por lo que no hay data migration.

## Decisiones

| Decisión | Opción elegida |
|----------|----------------|
| Estrategia BD | Migración nueva con drops + borrar los 6 archivos de migraciones viejas |
| Valores de enum "Legalización"/"No Legalización" en Invoices/PettyCash | Intactos (son conceptos contables independientes del módulo) |
| `GroupedInvoiceService` | Se mantiene tal cual, solo se actualiza doccomment |
| `phinxlog` | Limpiar rows huérfanas dentro de la migración nueva |

## Archivos a eliminar (~22 de código + 6 migraciones)

### Código propio

**Controllers:**
- `src/Controller/LegalizationRecordsController.php`
- `src/Controller/LegalizationPaymentsController.php`

**Services:**
- `src/Service/LegalizationService.php`
- `src/Service/LegalizationPaymentService.php`
- `src/Service/LegalizationDocumentService.php`

**Tables + Entities:**
- `src/Model/Table/LegalizationRecordsTable.php` + `src/Model/Entity/LegalizationRecord.php`
- `src/Model/Table/LegalizationPaymentsTable.php` + `src/Model/Entity/LegalizationPayment.php`
- `src/Model/Table/LegalizationDocumentsTable.php` + `src/Model/Entity/LegalizationDocument.php`
- `src/Model/Table/LegalizationObservationsTable.php` + `src/Model/Entity/LegalizationObservation.php`

**Constants:**
- `src/Constants/LegalizationConstants.php`

**Templates:**
- Carpeta completa `templates/LegalizationRecords/` (index, add, edit, view)
- `templates/element/legalization_progress.php`

### Migraciones viejas (borrar archivo)

- `config/Migrations/20260320000001_CreateLegalizationRecords.php`
- `config/Migrations/20260320000002_AddLegalizationRecordIdToInvoices.php`
- `config/Migrations/20260320000003_CreateLegalizationDocuments.php`
- `config/Migrations/20260320000004_CreateLegalizationObservations.php`
- `config/Migrations/20260320000005_AddLegalizationPermissions.php`
- `config/Migrations/20260414000003_CreateLegalizationPayments.php`

### Cache ORM (regenera automáticamente)

- `tmp/cache/models/myapp_cake_model_default_legalization_records`
- `tmp/cache/models/myapp_cake_model_default_legalization_documents`
- `tmp/cache/models/myapp_cake_model_default_legalization_observations`
- `tmp/cache/models/myapp_cake_model_default_legalization_payments`

## Refactors en archivos compartidos (15 archivos)

### `src/Controller/AppController.php`
Eliminar líneas 49 y 54 de `$controllerModuleMap`:
- `'LegalizationRecords' => 'legalizations'`
- `'LegalizationPayments' => 'legalizations'`

### `src/Service/AuthorizationService.php`
Eliminar `'legalizations' => 'Legalizaciones'` del array `MODULES` (línea 36).

### `src/Service/SidebarCounterService.php`
- Remover `use App\Constants\LegalizationConstants`.
- Eliminar `legalizationCount` de ambas ramas (líneas 47-49 y 66).

### `src/Service/PaymentRegistryService.php`
- Borrar método `_queryLegalizationPayments()` completo.
- Quitar el `array_merge` que lo invoca (línea 23).
- Remover `'LegalizationRecords'` del `contain` de invoice payments (línea 45).
- Borrar rama `legalization` de los `match` para type/label/url (líneas 66, 72, 78).

### `src/Service/InvoicePipelineService.php`
- Eliminar método `isLockedByLegalization()`.
- En `getLockReason()`: quitar el bloque que chequea legalization y actualizar doccomment (`Lock priority: petty cash → scheduling`).

### `src/Service/GroupedInvoiceService.php`
Actualizar doccomment: *"Shared logic for services that group invoices (Petty Cash)."*

### `src/Model/Table/InvoicesTable.php`
Eliminar `belongsTo('LegalizationRecords', [...])` (líneas 75-80 aprox).

### `src/Model/Entity/Invoice.php`
- Quitar `'legalization_record_id' => true` del `$_accessible`.
- Eliminar método `isInLegalization()`.

### `src/Model/Table/InvoicePaymentsTable.php`
- Eliminar `belongsTo('LegalizationRecords', ...)` (líneas 43-44).
- Quitar validación `integer('legalization_record_id')` + `allowEmptyString` (líneas 90-91).

### `src/Model/Entity/InvoicePayment.php`
Quitar `'legalization_record_id' => true` del `$_accessible`.

### `src/Controller/InvoicesController.php`
- Remover `'LegalizationRecords'` de los 4 `contain` (líneas 148, 164, 220, 235).
- Eliminar `$isLockedByLegalization`, quitarla del `compact()` y del cálculo de `$isLocked` (usar solo `$isLockedByPettyCash || $isLockedByScheduling`).
- Borrar comentario/lógica de exclusión de Legalización (línea 68).

### `config/routes.php`
Eliminar bloque completo de rutas custom de LegalizationRecords (~líneas 319-343).

### `templates/layout/default.php`
- Eliminar `$legalizationCount = $legalizationCount ?? 0;` (línea 14).
- Eliminar bloque completo del nav link de Legalizaciones (líneas 155-162).

### `templates/Invoices/view.php`
- Eliminar bloque `isLockedByLegalization` (líneas 76-85).
- Eliminar rama `elseif (!empty($payment->legalization_record_id))` (líneas 381-385).

### `templates/element/payment_section.php`
- Eliminar rama `elseif (!empty($payment->legalization_record_id))` (líneas 214-218).
- Quitar condición `|| !empty($payment->legalization_record_id)` (línea 228).

### `templates/PaymentRegistry/index.php`
- Quitar `'legalization' => 'bg-secondary'` y `'legalization' => 'journal-check'` de los arrays map.
- Eliminar `<option value="legalization">Legalizacion</option>` del filtro.

## Migración nueva

**Archivo:** `config/Migrations/YYYYMMDDHHMMSS_RemoveLegalizationModule.php`
**Clase base:** `Migrations\BaseMigration`

**Orden en `up()`:**

1. Drop FK + column `invoice_payments.legalization_record_id`
2. Drop FK + column `invoices.legalization_record_id`
3. Drop table `legalization_payments`
4. Drop table `legalization_observations`
5. Drop table `legalization_documents`
6. Drop table `legalization_records`
7. `DELETE FROM permissions WHERE module = 'legalizations'`
8. `DELETE FROM phinxlog WHERE migration_name LIKE 'CreateLegalization%' OR migration_name LIKE 'AddLegalization%'`

**Guardas defensivas:**
- `hasTable()` antes de cada drop.
- `hasForeignKey()` antes de cada `dropForeignKey`.
- `hasColumn()` antes de cada `removeColumn`.

**`down()`:** lanza `RuntimeException('Irreversible: módulo eliminado')`.

## Plan de ejecución

```
1. Crear migración nueva (RemoveLegalizationModule)
2. php bin/cake migrations migrate
3. Borrar los 6 archivos de migraciones viejas
4. Borrar los ~22 archivos de código propio
5. Aplicar refactors a los 15 archivos compartidos
6. Borrar tmp/cache/models/myapp_cake_model_default_legalization_*
7. composer check (PHPUnit + cs-check)
```

## Verificación

1. **Estático:** `composer cs-check` + `composer test`.
2. **Runtime smoke test** (dev server):
   - Login → navegar a Facturas (contains/isLocked no explotan).
   - Registro de Pagos (filtro sin legalization carga bien).
   - Factura con payments (payment_section.php sin errores).
   - Sidebar (sin item "Legalizaciones", sin badge fantasma).
3. **BD:** `SHOW TABLES LIKE 'legalization_%'` → vacío. `SELECT * FROM permissions WHERE module='legalizations'` → vacío.
4. **Grep final:** `grep -ri "egaliz" src/ templates/ config/` → solo enum values intactos (`DOCTYPE_LEGALIZACION`, `'No Legalización'`, `'Legalización'` como forma de pago).

## Riesgos

- **Bajo:** no hay facturas vinculadas, no hay data migration.
- **Medio:** ambientes que corrieron las migraciones viejas deben ejecutar `migrations migrate` antes de hacer pull del código PHP. Documentar en commit message.

## Commit

Un solo commit cohesivo:

```
feat(legalizations): eliminar módulo completo (BD, código, referencias)
```
