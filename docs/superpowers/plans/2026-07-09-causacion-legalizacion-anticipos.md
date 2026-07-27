# Causación en el paso Contabilidad de la legalización de anticipos — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir los campos de causación (`accrued`, `accrual_date`, `ready_for_payment`) al paso `contabilidad` del pipeline de legalización de anticipos como gate obligatorio de sus tres salidas, y arreglar el bug que trunca los ceros del monto del faltante/sobrante.

**Architecture:** Tres columnas nuevas en `advance_legalizations` (no en las facturas hijas). El gate vive en `Pipeline/Advance/State/ContabilidadState::validateAdvance()`, invocado desde un helper privado `_applyAccounting()` del coordinador `AdvanceLegalizationService`, que también captura los valores originales para el audit trail. Los tres endpoints del controller (`markExact`, `registerShortage`, `registerSurplus`) reciben el payload de causación desde un formulario compartido en la vista.

**Tech Stack:** CakePHP 5.3, PHP 8.4, MySQL/MariaDB, PHPUnit + cakephp-fixture-factories, AutoNumeric + Flatpickr en frontend.

**Spec:** `docs/superpowers/specs/2026-07-09-causacion-legalizacion-anticipos-design.md`

## Global Constraints

- **Nunca correr `composer test`.** Impone un process-timeout de 300s y la DB de test es una RDS remota; mata la suite a mitad. Usar siempre `vendor/bin/phpunit` directo.
- **Cargar las credenciales de test antes de correr phpunit** (el dotenv loader de `config/bootstrap.php` está comentado en el working tree). Desde la herramienta Bash:
  ```bash
  set -a; source <(sed -E "s/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/\1='\2'/" config/.env); set +a
  ```
- **El schema de `sgi_test` es persistente y se migra a mano:** `php bin/cake.php migrations migrate -c test` (ojo: `bin/cake` es un wrapper, usar `php bin/cake.php`).
- **Leer el resultado, no el exit code.** La suite verde igual devuelve exit 1 por ~126 PHPUnit Notices preexistentes. Buscar `0 failures / 0 errors` en la línea `Tests: N, Assertions: M`.
- `InvoiceConstants::READY_FOR_PAYMENT_SI` vale `'Si'` **sin tilde**. Es un valor persistido. No "corregirlo".
- Los tres campos nuevos van con `_accessible => false` (invariante MI-002): solo el service los asigna por propiedad directa, nunca vía `patchEntity`.
- Los `.currency-input` se precargan con el valor **crudo** (`(int)round($x)`), nunca con `number_format($x, 0, ',', '.')`.
- Los servicios devuelven `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`.
- Estilo de código: `composer cs-check` debe quedar en verde sobre los archivos tocados. `composer cs-fix` autocorrige. Ojo con los `use` nuevos: el estándar exige orden alfabético y `cs-check` falla si no lo está.
- Commits en formato conventional: `feat:`, `fix:`, `test:`, `refactor:`.

---

## File Structure

| Archivo | Responsabilidad | Tarea |
|---|---|---|
| `templates/Advances/legalization.php` | Vista operativa: precarga cruda del monto, formularios del paso, card read-only | 1, 6 |
| `config/Migrations/<ts>_AddAccountingFieldsToAdvanceLegalizations.php` | 3 columnas nuevas | 2 |
| `src/Model/Entity/AdvanceLegalization.php` | `_accessible => false` de los 3 campos | 2 |
| `src/Model/Table/AdvanceLegalizationsTable.php` | Reglas de validación defensivas | 2 |
| `src/Service/Pipeline/Advance/State/ContabilidadState.php` | El gate (fuente única de la regla) | 3 |
| `src/Service/AdvanceLegalizationService.php` | `_applyAccounting()` + nuevas firmas + audit | 4 |
| `src/Controller/AdvancesController.php` | `_accountingPayload()` + `expected_status` en `markExact` | 5 |
| `src/ViewModel/AdvanceLegalizationViewModel.php` | `readyForPaymentOptions`, `showAccountingCard` | 6 |
| `templates/element/advance_legalization/_accounting_fields.php` | Los 3 inputs, compartidos por las 3 salidas | 6 |
| `tests/Factory/AdvanceLegalizationFactory.php` | `withAccounting()` para sembrar los campos | 2 |

**Orden:** la Tarea 1 es independiente del resto (bugfix aislado) y va primera para dejarla commiteada aparte. Luego 2 (schema) → 3 (gate) → 4 (servicio) → 5 (controller) → 6 (vista).

---

### Task 1: Fix de la precarga del monto (`.currency-input`)

Bug independiente. `number_format($diff, 0, ',', '.')` emite `value="336.500"`; AutoNumeric lo lee como el número JS `336.5` y con `decimalPlaces: 0` lo formatea a `337`, que es lo que se envía y persiste.

**Files:**
- Modify: `templates/Advances/legalization.php:243` y `:259`
- Test: `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: nada (cambio interno de template).

- [ ] **Step 1: Write the failing test**

Añadir este método a `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`, después de `testOperativeViewRenders()`:

```php
    /**
     * El input del monto del sobrante se precarga con el valor CRUDO. Si se
     * precargara con number_format(..., ',', '.') → "336.500", AutoNumeric lo
     * interpretaría como el número JS 336.5 y lo enviaría como 337.
     */
    public function testSurplusAmountInputRendersRawValue(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(2336500.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('name="surplus_amount"');
        $this->assertResponseContains('value="336500"');
        $this->assertResponseNotContains('value="336.500"');
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
set -a; source <(sed -E "s/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/\1='\2'/" config/.env); set +a
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php --filter testSurplusAmountInputRendersRawValue
```

Expected: FAIL — `Failed asserting that response body contains 'value="336500"'` (el body trae `value="336.500"`).

- [ ] **Step 3: Write minimal implementation**

En `templates/Advances/legalization.php`, línea 243 (rama del faltante):

```php
                    <input type="text" name="shortage_amount" class="form-control currency-input"
                           value="<?= (int)round($diff) ?>" required>
```

Y línea 259 (rama del sobrante):

```php
                    <input type="text" name="surplus_amount" class="form-control currency-input"
                           value="<?= (int)round(abs($diff)) ?>" required>
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
```

Expected: PASS — `OK (2 tests, ...)`.

- [ ] **Step 5: Verify style and commit**

```bash
composer cs-check
git add templates/Advances/legalization.php tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
git commit -m "fix: precargar el monto del faltante/sobrante crudo para que AutoNumeric no trunque los ceros"
```

---

### Task 2: Schema — migración, entidad, tabla y factory

**Files:**
- Create: `config/Migrations/<timestamp>_AddAccountingFieldsToAdvanceLegalizations.php`
- Modify: `src/Model/Entity/AdvanceLegalization.php:18-34` (`$_accessible`)
- Modify: `src/Model/Table/AdvanceLegalizationsTable.php` (`validationDefault`)
- Modify: `tests/Factory/AdvanceLegalizationFactory.php`
- Test: `tests/TestCase/Model/Table/AdvanceLegalizationsTableAccountingTest.php` (nuevo)

**Interfaces:**
- Consumes: `InvoiceConstants::READY_FOR_PAYMENT_OPTIONS` (ya existe: `['Si', 'Pago PSE', 'Pago prioritario']`).
- Produces:
  - Columnas `advance_legalizations.accrued` (bool), `.accrual_date` (date, nullable), `.ready_for_payment` (string(50), nullable).
  - Propiedades homónimas en `AdvanceLegalization`, **no** mass-assignable.
  - `AdvanceLegalizationFactory::withAccounting(bool $accrued, ?string $accrualDate, ?string $readyForPayment): static`

- [ ] **Step 1: Write the failing test**

Crear `tests/TestCase/Model/Table/AdvanceLegalizationsTableAccountingTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use Cake\TestSuite\TestCase;

/**
 * Los 3 campos de causación de la legalización son pipeline-controlled: se
 * persisten por asignación directa de propiedad desde el service (MI-002) y
 * NO deben poder llegar por mass-assignment desde un patchEntity con datos
 * del cliente.
 */
final class AdvanceLegalizationsTableAccountingTest extends TestCase
{
    public function testAccountingFieldsAreNotMassAssignable(): void
    {
        $entity = $this->fetchTable('AdvanceLegalizations')->newEntity([
            'advance_invoice_id' => 1,
            'created_by' => 1,
            'accrued' => true,
            'accrual_date' => '2026-06-23',
            'ready_for_payment' => InvoiceConstants::READY_FOR_PAYMENT_SI,
        ]);

        $this->assertNull($entity->accrued);
        $this->assertNull($entity->accrual_date);
        $this->assertNull($entity->ready_for_payment);
    }

    public function testAccountingFieldsPersistWhenAssignedDirectly(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $table = $this->fetchTable('AdvanceLegalizations');
        $leg->accrued = true;
        $leg->accrual_date = '2026-06-23';
        $leg->ready_for_payment = InvoiceConstants::READY_FOR_PAYMENT_SI;
        $table->saveOrFail($leg);

        $persisted = $table->get($leg->id);
        $this->assertTrue((bool)$persisted->accrued);
        $this->assertSame('2026-06-23', $persisted->accrual_date->format('Y-m-d'));
        $this->assertSame(InvoiceConstants::READY_FOR_PAYMENT_SI, $persisted->ready_for_payment);
    }

    public function testFactoryCanSeedAccountingFields(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withAccounting(true, '2026-06-23', InvoiceConstants::READY_FOR_PAYMENT_SI)
            ->save();

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertTrue((bool)$persisted->accrued);
        $this->assertSame('2026-06-23', $persisted->accrual_date->format('Y-m-d'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
set -a; source <(sed -E "s/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/\1='\2'/" config/.env); set +a
vendor/bin/phpunit tests/TestCase/Model/Table/AdvanceLegalizationsTableAccountingTest.php
```

Expected: FAIL — `testAccountingFieldsPersistWhenAssignedDirectly` y `testFactoryCanSeedAccountingFields` fallan (columna inexistente / `Call to undefined method withAccounting()`). `testAccountingFieldsAreNotMassAssignable` puede pasar ya (`_accessible` implícito), pero se mantiene como guarda de regresión.

- [ ] **Step 3: Crear la migración**

```bash
php bin/cake.php migrations create AddAccountingFieldsToAdvanceLegalizations
```

Reemplazar el contenido del archivo generado (`config/Migrations/<timestamp>_AddAccountingFieldsToAdvanceLegalizations.php`) por:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAccountingFieldsToAdvanceLegalizations extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('advance_legalizations')) {
            return;
        }

        $table = $this->table('advance_legalizations');
        $table->addColumn('accrued', 'boolean', [
            'null' => false,
            'default' => false,
            'after' => 'case_type',
        ]);
        $table->addColumn('accrual_date', 'date', [
            'null' => true,
            'default' => null,
            'after' => 'accrued',
        ]);
        $table->addColumn('ready_for_payment', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'after' => 'accrual_date',
        ]);
        $table->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('advance_legalizations')) {
            return;
        }

        $table = $this->table('advance_legalizations');
        $table->removeColumn('accrued');
        $table->removeColumn('accrual_date');
        $table->removeColumn('ready_for_payment');
        $table->update();
    }
}
```

- [ ] **Step 4: Correr la migración en las dos conexiones**

```bash
php bin/cake.php migrations migrate
php bin/cake.php migrations migrate -c test
```

Expected: ambas terminan en `All Done.` Verificar el schema **físico** (el log `migrations status` puede estar drifted):

```bash
php bin/cake.php migrations status -c test | tail -3
```

- [ ] **Step 5: Declarar los campos como no mass-assignable**

En `src/Model/Entity/AdvanceLegalization.php`, dentro de `$_accessible`, tras la línea `'surplus_amount' => false,`:

```php
        'accrued' => false,
        'accrual_date' => false,
        'ready_for_payment' => false,
```

- [ ] **Step 6: Añadir las reglas de validación**

En `src/Model/Table/AdvanceLegalizationsTable.php`, añadir `use App\Constants\InvoiceConstants;` a los imports y, en `validationDefault()`, tras el bloque de `surplus_amount`:

```php
        $validator
            ->boolean('accrued')
            ->allowEmptyString('accrued');

        $validator
            ->date('accrual_date')
            ->allowEmptyDate('accrual_date');

        $validator
            ->scalar('ready_for_payment')
            ->inList('ready_for_payment', InvoiceConstants::READY_FOR_PAYMENT_OPTIONS)
            ->allowEmptyString('ready_for_payment');
```

- [ ] **Step 7: Añadir el helper al factory**

En `tests/Factory/AdvanceLegalizationFactory.php`, al final de la clase:

```php
    public function withAccounting(
        bool $accrued = true,
        ?string $accrualDate = '2026-06-23',
        ?string $readyForPayment = 'Si',
    ): static {
        return $this->setField('accrued', $accrued)
            ->setField('accrual_date', $accrualDate)
            ->setField('ready_for_payment', $readyForPayment);
    }
```

- [ ] **Step 8: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/TestCase/Model/Table/AdvanceLegalizationsTableAccountingTest.php
```

Expected: PASS — `OK (3 tests, ...)`.

- [ ] **Step 9: Verify style and commit**

```bash
composer cs-check
git add config/Migrations src/Model/Entity/AdvanceLegalization.php src/Model/Table/AdvanceLegalizationsTable.php tests/Factory/AdvanceLegalizationFactory.php tests/TestCase/Model/Table/AdvanceLegalizationsTableAccountingTest.php
git commit -m "feat: columnas de causacion en advance_legalizations"
```

---

### Task 3: El gate en `ContabilidadState`

**Files:**
- Modify: `src/Service/Pipeline/Advance/State/ContabilidadState.php:27-30`
- Test: `tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php:41-49`

**Interfaces:**
- Consumes: propiedades `accrued` / `accrual_date` / `ready_for_payment` de `AdvanceLegalization` (Task 2).
- Produces: `ContabilidadState::validateAdvance(AdvanceLegalization $leg): array<string>` — devuelve `[]` con los tres campos presentes; si no, uno o más de estos mensajes exactos, en este orden:
  1. `'La legalización debe estar marcada como Causada'`
  2. `'Fecha de Causación es requerida'`
  3. `'Campo "Lista para Pago" es requerido'`

- [ ] **Step 1: Write the failing test**

En `tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php`, **reemplazar** el método `testContabilidadBranchesSoNextIsNull()` (líneas 41-49) por estos cuatro métodos. La aserción `assertSame([], $s->validateAdvance(...))` que estaba embebida ahí desaparece: con el gate nuevo devuelve tres errores.

```php
    public function testContabilidadBranchesSoNextIsNull(): void
    {
        $s = new ContabilidadState();

        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getStatus());
        $this->assertNull($s->getNextStatus());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $s->getPreviousStatus());
    }

    public function testContabilidadRequiresAllThreeAccountingFields(): void
    {
        $errors = (new ContabilidadState())->validateAdvance($this->leg());

        $this->assertSame([
            'La legalización debe estar marcada como Causada',
            'Fecha de Causación es requerida',
            'Campo "Lista para Pago" es requerido',
        ], $errors);
    }

    public function testContabilidadReportsOnlyTheMissingField(): void
    {
        // Los 3 campos son non-accessible: se asignan por propiedad directa.
        $leg = $this->leg();
        $leg->accrued = true;
        $leg->accrual_date = '2026-06-23';

        $errors = (new ContabilidadState())->validateAdvance($leg);

        $this->assertSame(['Campo "Lista para Pago" es requerido'], $errors);
    }

    public function testContabilidadPassesWhenAccountingIsComplete(): void
    {
        $leg = $this->leg();
        $leg->accrued = true;
        $leg->accrual_date = '2026-06-23';
        $leg->ready_for_payment = 'Si';

        $this->assertSame([], (new ContabilidadState())->validateAdvance($leg));
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php
```

Expected: FAIL — `testContabilidadRequiresAllThreeAccountingFields` falla con `Failed asserting that two arrays are equal` (recibe `[]`).

- [ ] **Step 3: Write minimal implementation**

Reemplazar el cuerpo de `validateAdvance()` en `src/Service/Pipeline/Advance/State/ContabilidadState.php`:

```php
    /**
     * Gate del paso Contabilidad: la causación (causada + fecha + lista para
     * pago) es obligatoria para cualquiera de las tres salidas del paso (caso
     * exacto, faltante, sobrante). Espejo de
     * `Pipeline\Invoice\State\ContabilidadState::validateAdvance()`.
     *
     * @return array<string>
     */
    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        if (!(bool)($leg->accrued ?? false)) {
            $errors[] = 'La legalización debe estar marcada como Causada';
        }
        $accrualDate = $leg->accrual_date ?? null;
        if ($accrualDate === null || $accrualDate === '' || $accrualDate === false) {
            $errors[] = 'Fecha de Causación es requerida';
        }
        $readyForPayment = $leg->ready_for_payment ?? null;
        if ($readyForPayment === null || $readyForPayment === '' || $readyForPayment === false) {
            $errors[] = 'Campo "Lista para Pago" es requerido';
        }

        return $errors;
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php
```

Expected: PASS — `OK (9 tests, ...)`.

- [ ] **Step 5: Verify style and commit**

```bash
composer cs-check
git add src/Service/Pipeline/Advance/State/ContabilidadState.php tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php
git commit -m "feat: gate de causacion en el paso Contabilidad de la legalizacion"
```

---

### Task 4: Servicio — `_applyAccounting()` y las tres firmas

> **Las Tareas 4 y 5 forman una sola unidad de commit.** Esta tarea rompe la firma de tres métodos públicos y su único consumidor en `src/` es `AdvancesController`, que se arregla en la Tarea 5. Entre ambas, cualquier POST a `markExact` / `registerShortage` / `registerSurplus` revienta con `TypeError` en runtime, y **ningún test lo detecta** (los tests de servicio ya usan la firma nueva; el test de render solo hace GET). Por eso la Tarea 4 **no commitea**: el commit único va al final de la Tarea 5. No dejar el árbol en ese estado intermedio ni empujarlo a CI.

Los consumidores en `tests/` (verificados por grep) son exactamente tres archivos.

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php` (métodos `markExact:503`, `registerShortage:525`, `registerSurplus:588`; nuevo privado `_applyAccounting`)
- Test: `tests/TestCase/Service/Integration/AdvanceLegalizationTransitionsTest.php:66,92`
- Test: `tests/TestCase/Service/Integration/AdvanceLegalizationShortageTest.php:66,90,114`
- Test: `tests/TestCase/Service/Integration/AdvanceLegalizationSurplusTest.php:67,95,136,172`

`tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php` **no** llama a ninguno de los tres métodos. No tocarlo.

**Interfaces:**
- Consumes: `ContabilidadState::validateAdvance()` (Task 3); `AdvanceLegalizationPipelineStateRegistry::get(PipelineStatus): AdvanceLegalizationPipelineState`.
- Produces (firmas públicas nuevas — la Tarea 5 y los tests dependen de ellas):
  ```php
  markExact(AdvanceLegalization $leg, array $accounting, int $userId): ServiceResult
  registerShortage(AdvanceLegalization $leg, float $amount, array $accounting, int $userId): ServiceResult
  registerSurplus(AdvanceLegalization $leg, float $amount, array $accounting, int $userId): ServiceResult
  ```
  donde `$accounting` es `array{accrued: bool, accrual_date: string|null, ready_for_payment: string|null}` con `accrual_date` en formato `Y-m-d`.

- [ ] **Step 1: Write the failing test**

En `tests/TestCase/Service/Integration/AdvanceLegalizationSurplusTest.php`, añadir primero esta constante **como primer miembro de la clase**, antes de `buildService()`:

```php
    /** Payload de causación válido para las salidas del paso Contabilidad. */
    private const ACCOUNTING = [
        'accrued' => true,
        'accrual_date' => '2026-06-23',
        'ready_for_payment' => 'Si',
    ];
```

Y luego estos dos tests, después de `testRegisterSurplusDeclaresSurplusAndMovesToTesoreria()`:

```php
    /**
     * registerSurplus persiste los 3 campos de causación y deja una fila de
     * historial por cada uno. Si el service leyera el `oldValue` DESPUÉS de
     * mutar la entidad, recordFieldChange() los descartaría en silencio por
     * old === new y estas 3 filas no existirían.
     */
    public function testRegisterSurplusPersistsAccountingAndAuditsEachField(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerSurplus($leg, 250.0, self::ACCOUNTING, $user->id);
        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertTrue((bool)$persisted->accrued);
        $this->assertSame('2026-06-23', $persisted->accrual_date->format('Y-m-d'));
        $this->assertSame('Si', $persisted->ready_for_payment);

        $audited = $this->fetchTable('AdvanceLegalizationHistories')->find()
            ->where(['legalization_id' => $leg->id])
            ->all()
            ->extract('field_changed')
            ->toList();
        $this->assertContains('accrued', $audited);
        $this->assertContains('accrual_date', $audited);
        $this->assertContains('ready_for_payment', $audited);
    }

    /**
     * registerSurplus con causación incompleta falla contra el gate del
     * ContabilidadState y no mueve la legalización.
     */
    public function testRegisterSurplusFailsWhenAccountingIsIncomplete(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $incomplete = ['accrued' => true, 'accrual_date' => null, 'ready_for_payment' => 'Si'];
        $result = $this->buildService()->registerSurplus($leg, 250.0, $incomplete, $user->id);

        $this->assertFalse($result->success);
        $this->assertSame('Fecha de Causación es requerida', $result->firstError());

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }
```

Y actualizar las 4 llamadas existentes de ese archivo a la nueva firma:

```php
// línea ~67
$result = $this->buildService()->registerSurplus($leg, 250.0, self::ACCOUNTING, $user->id);
// línea ~95
$surplus = $service->registerSurplus($leg, 300.0, self::ACCOUNTING, $user->id);
// línea ~136
$this->assertTrue($service->registerSurplus($leg, 200.0, self::ACCOUNTING, $user->id)->success);
// línea ~172
$this->assertTrue($service->registerSurplus($leg, 150.0, self::ACCOUNTING, $user->id)->success);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/TestCase/Service/Integration/AdvanceLegalizationSurplusTest.php
```

Expected: FAIL — `ArgumentCountError` / `TypeError: registerSurplus(): Argument #3 ($userId) must be of type int, array given`.

- [ ] **Step 3: Implementar `_applyAccounting()` y las nuevas firmas**

En `src/Service/AdvanceLegalizationService.php`, añadir el helper privado junto a `_firstErrorMessage()`:

```php
    /**
     * Asigna los campos de causación del paso Contabilidad sobre la legalización
     * y devuelve los errores del gate más los cambios listos para el audit trail.
     *
     * Los 3 campos son non-accessible (MI-002): se asignan por propiedad directa.
     *
     * Los valores originales se capturan ANTES de mutar. `recordFieldChange()`
     * descarta los cambios donde old === new, así que leerlos después de asignar
     * dejaría el historial vacío sin ningún error visible.
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     * @return array{errors: array<string>, changes: array<string, array{0: scalar|null, 1: scalar|null}>}
     */
    private function _applyAccounting(AdvanceLegalization $leg, array $accounting): array
    {
        $oldAccrued = (bool)($leg->accrued ?? false);
        $oldDate = $leg->accrual_date;
        $oldReady = $leg->ready_for_payment;

        $newAccrued = (bool)($accounting['accrued'] ?? false);
        $rawDate = trim((string)($accounting['accrual_date'] ?? ''));
        $newDate = $rawDate !== '' ? date('Y-m-d', (int)strtotime($rawDate)) : null;
        $rawReady = trim((string)($accounting['ready_for_payment'] ?? ''));
        $newReady = $rawReady !== '' ? $rawReady : null;

        $leg->accrued = $newAccrued;
        $leg->accrual_date = $newDate;
        $leg->ready_for_payment = $newReady;

        $oldDateStr = $oldDate === null
            ? null
            : (is_string($oldDate) ? $oldDate : $oldDate->format('Y-m-d'));

        return [
            'errors' => $this->stateRegistry
                ->get(AdvancePipelineStatus::CONTABILIDAD)
                ->validateAdvance($leg),
            'changes' => [
                'accrued' => [$oldAccrued ? 'Sí' : 'No', $newAccrued ? 'Sí' : 'No'],
                'accrual_date' => [$oldDateStr, $newDate],
                'ready_for_payment' => [$oldReady, $newReady],
            ],
        ];
    }
```

Reemplazar `markExact()` completo:

```php
    /**
     * Close as caso exacto when difference is zero. La causación del paso
     * Contabilidad es obligatoria (gate en ContabilidadState).
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     */
    public function markExact(AdvanceLegalization $leg, array $accounting, int $userId): ServiceResult
    {
        if (!$leg->canMarkExact()) {
            return ServiceResult::fail('La legalización no permite marcarse como exacta.');
        }

        if (abs($this->getDifference($leg)) > 0.005) {
            return ServiceResult::fail('La diferencia no es cero. Use Faltante o Sobrante.');
        }

        $applied = $this->_applyAccounting($leg, $accounting);
        if (!empty($applied['errors'])) {
            return ServiceResult::fail($applied['errors'][0]);
        }

        $leg->case_type = AdvanceConstants::CASE_EXACTO;
        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId, array_merge([
            'case_type' => [null, AdvanceConstants::CASE_EXACTO],
        ], $applied['changes']));
    }
```

Reemplazar `registerShortage()` completo:

```php
    /**
     * Contabilidad declares a shortage (anticipo > linked invoices). The legalization
     * jumps to Tesorería awaiting the beneficiary's deposit. La causación del paso
     * es obligatoria (gate en ContabilidadState).
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     */
    public function registerShortage(
        AdvanceLegalization $leg,
        float $amount,
        array $accounting,
        int $userId,
    ): ServiceResult {
        // canRegisterShortage cubre status=contabilidad + case_type=null (MA-005).
        if (!$leg->canRegisterShortage()) {
            return ServiceResult::fail('La legalización no permite declarar un faltante.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del faltante debe ser mayor a cero.');
        }

        $applied = $this->_applyAccounting($leg, $accounting);
        if (!empty($applied['errors'])) {
            return ServiceResult::fail($applied['errors'][0]);
        }

        $leg->case_type = AdvanceConstants::CASE_FALTANTE;
        $leg->shortage_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId, array_merge([
            'case_type' => [null, AdvanceConstants::CASE_FALTANTE],
            'shortage_amount' => [null, (string)$amount],
        ], $applied['changes']));
    }
```

Reemplazar `registerSurplus()` completo:

```php
    /**
     * Contabilidad declares a surplus (linked invoices > anticipo). The legalization
     * jumps to Tesorería awaiting the company's refund payment to the beneficiary.
     * La causación del paso es obligatoria (gate en ContabilidadState).
     *
     * @param array{accrued?: bool, accrual_date?: string|null, ready_for_payment?: string|null} $accounting
     */
    public function registerSurplus(
        AdvanceLegalization $leg,
        float $amount,
        array $accounting,
        int $userId,
    ): ServiceResult {
        // canRegisterSurplus cubre status=contabilidad + case_type=null (MA-005).
        if (!$leg->canRegisterSurplus()) {
            return ServiceResult::fail('La legalización no permite declarar un sobrante.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del sobrante debe ser mayor a cero.');
        }

        $applied = $this->_applyAccounting($leg, $accounting);
        if (!empty($applied['errors'])) {
            return ServiceResult::fail($applied['errors'][0]);
        }

        $leg->case_type = AdvanceConstants::CASE_SOBRANTE;
        $leg->surplus_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId, array_merge([
            'case_type' => [null, AdvanceConstants::CASE_SOBRANTE],
            'surplus_amount' => [null, (string)$amount],
        ], $applied['changes']));
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/TestCase/Service/Integration/AdvanceLegalizationSurplusTest.php
```

Expected: PASS — `OK (6 tests, ...)`.

- [ ] **Step 5: Actualizar `AdvanceLegalizationShortageTest`**

Añadir la constante como primer miembro de la clase, antes de `buildService()`:

```php
    /** Payload de causación válido para las salidas del paso Contabilidad. */
    private const ACCOUNTING = [
        'accrued' => true,
        'accrual_date' => '2026-06-23',
        'ready_for_payment' => 'Si',
    ];
```

Actualizar las 3 llamadas:

```php
// línea ~66
$result = $this->buildService()->registerShortage($leg, 250.0, self::ACCOUNTING, $user->id);
// línea ~90
$result = $this->buildService()->registerShortage($leg, 0.0, self::ACCOUNTING, $user->id);
// línea ~114
$result = $this->buildService()->registerShortage($leg, 250.0, self::ACCOUNTING, $user->id);
```

Y añadir el caso del gate al final de la clase:

```php
    /**
     * registerShortage con causación incompleta falla contra el gate del
     * ContabilidadState y la legalización no se mueve.
     */
    public function testRegisterShortageFailsWhenAccountingIsIncomplete(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $incomplete = ['accrued' => false, 'accrual_date' => '2026-06-23', 'ready_for_payment' => 'Si'];
        $result = $this->buildService()->registerShortage($leg, 250.0, $incomplete, $user->id);

        $this->assertFalse($result->success);
        $this->assertSame('La legalización debe estar marcada como Causada', $result->firstError());

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }
```

- [ ] **Step 6: Actualizar `AdvanceLegalizationTransitionsTest`**

Añadir la constante como primer miembro de la clase, antes de `buildService()`:

```php
    /** Payload de causación válido para las salidas del paso Contabilidad. */
    private const ACCOUNTING = [
        'accrued' => true,
        'accrual_date' => '2026-06-23',
        'ready_for_payment' => 'Si',
    ];
```

Actualizar las 2 llamadas:

```php
// línea ~66 (testMarkExactClosesLegalizationWhenDifferenceIsZero)
$result = $this->buildService()->markExact($leg, self::ACCOUNTING, $user->id);
// línea ~92 (testMarkExactFailsWhenDifferenceIsNotZero)
$result = $this->buildService()->markExact($leg, self::ACCOUNTING, $user->id);
```

Y añadir el caso del gate después de `testMarkExactFailsWhenDifferenceIsNotZero()`:

```php
    /**
     * markExact con causación incompleta falla contra el gate del
     * ContabilidadState aunque la diferencia sea cero.
     */
    public function testMarkExactFailsWhenAccountingIsIncomplete(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $incomplete = ['accrued' => true, 'accrual_date' => '2026-06-23', 'ready_for_payment' => null];
        $result = $this->buildService()->markExact($leg, $incomplete, $user->id);

        $this->assertFalse($result->success);
        $this->assertSame('Campo "Lista para Pago" es requerido', $result->firstError());

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }
```

- [ ] **Step 7: Correr los tres archivos de integración**

```bash
vendor/bin/phpunit tests/TestCase/Service/Integration/AdvanceLegalizationSurplusTest.php tests/TestCase/Service/Integration/AdvanceLegalizationShortageTest.php tests/TestCase/Service/Integration/AdvanceLegalizationTransitionsTest.php
```

Expected: `0 failures / 0 errors`.

- [ ] **Step 8: Verificar el estilo — NO commitear todavía**

```bash
composer cs-check
```

Expected: sin errores (si los hay, `composer cs-fix`). **No commitear.** El controller todavía llama la firma vieja; el commit se hace al final de la Tarea 5, que es la que cierra la unidad. Continuar directamente a la Tarea 5.

---

### Task 5: Controller — payload y guard de concurrencia

> Continúa la Tarea 4. Cierra la unidad de commit: el árbol vuelve a ser consistente al final de esta tarea.

**Files:**
- Modify: `src/Controller/AdvancesController.php` (`markExact:801`, `registerShortage:825`, `registerSurplus:899`; nuevo privado `_accountingPayload`)

**Interfaces:**
- Consumes: las tres firmas de la Tarea 4.
- Produces: `AdvancesController::_accountingPayload(): array{accrued: bool, accrual_date: string|null, ready_for_payment: string|null}` (privado).

No hay test de controller para el POST: las tres acciones pasan por `AdvanceLegalizationActionPolicy`, que consulta `pipeline_permissions`, y sembrar ese permiso excede el valor del test. La cobertura del comportamiento vive en los tests de servicio (Tarea 4) y la del render del formulario en la Tarea 6.

- [ ] **Step 1: Añadir el helper**

En `src/Controller/AdvancesController.php`, justo después de `_parseCop()` (línea 92):

```php
    /**
     * Payload de causación del paso Contabilidad: checkbox + fecha + lista para
     * pago. Compartido por las 3 salidas del paso (markExact, registerShortage,
     * registerSurplus). El gate lo aplica ContabilidadState vía el service.
     *
     * @return array{accrued: bool, accrual_date: string|null, ready_for_payment: string|null}
     */
    private function _accountingPayload(): array
    {
        $date = trim((string)$this->request->getData('accrual_date', ''));
        $ready = trim((string)$this->request->getData('ready_for_payment', ''));

        return [
            'accrued' => (bool)$this->request->getData('accrued'),
            'accrual_date' => $date !== '' ? $date : null,
            'ready_for_payment' => $ready !== '' ? $ready : null,
        ];
    }
```

- [ ] **Step 2: Actualizar `markExact()`**

`markExact` pasa a recibir un formulario real, así que gana el guard de concurrencia `_ensureExpectedStatus()` que las otras dos ya tienen. Reemplazar el cuerpo desde el chequeo del policy:

```php
        if (!$this->actionPolicy->canMarkExact($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        if (!$this->_ensureExpectedStatus($leg->status)) {
            return $this->redirect(['action' => 'legalization', $id]);
        }
        $result = $this->legalizationService->markExact(
            $leg,
            $this->_accountingPayload(),
            (int)$this->_getCurrentUser()->id,
        );
```

- [ ] **Step 3: Actualizar `registerShortage()` y `registerSurplus()`**

En `registerShortage()`, reemplazar la línea de la llamada al service:

```php
        $amount = $this->_parseCop((string)$this->request->getData('shortage_amount'));
        $result = $this->legalizationService->registerShortage(
            $leg,
            $amount,
            $this->_accountingPayload(),
            (int)$this->_getCurrentUser()->id,
        );
```

En `registerSurplus()`:

```php
        $amount = $this->_parseCop((string)$this->request->getData('surplus_amount'));
        $result = $this->legalizationService->registerSurplus(
            $leg,
            $amount,
            $this->_accountingPayload(),
            (int)$this->_getCurrentUser()->id,
        );
```

- [ ] **Step 4: Verificar que no queda ninguna llamada con la firma vieja**

```bash
grep -rn "markExact(\$leg, (int)\|registerShortage(\$leg, \$amount, (int)\|registerSurplus(\$leg, \$amount, (int)" src/
```

Expected: sin resultados.

- [ ] **Step 5: Correr los tests de servicio para confirmar que nada se rompió**

```bash
set -a; source <(sed -E "s/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/\1='\2'/" config/.env); set +a
vendor/bin/phpunit tests/TestCase/Service/Integration/ tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
```

Expected: `0 failures / 0 errors`.

- [ ] **Step 6: Verify style and commit (Tareas 4 + 5 juntas)**

```bash
composer cs-check
git add src/Service/AdvanceLegalizationService.php src/Controller/AdvancesController.php tests/TestCase/Service/Integration/
git commit -m "feat: causacion obligatoria en las tres salidas del paso Contabilidad"
```

---

### Task 6: Vista — formulario compartido y card read-only

**Files:**
- Create: `templates/element/advance_legalization/_accounting_fields.php`
- Modify: `src/ViewModel/AdvanceLegalizationViewModel.php` (imports + `build()`)
- Modify: `templates/Advances/legalization.php` (destructuring `:13-43`, card de acción `:222-269`, card read-only tras `:384`)
- Test: `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`

**Interfaces:**
- Consumes: `PaymentOptions::readyForPayment(): array<string,string>` (ya existe en `src/ViewModel/Support/PaymentOptions.php`); `AdvanceLegalizationFactory::withAccounting()` (Task 2).
- Produces: dos claves nuevas en `AdvanceLegalizationViewModel::build()`:
  - `readyForPaymentOptions` → `array<string,string>`
  - `showAccountingCard` → `bool`

- [ ] **Step 1: Write the failing test**

Añadir a `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`. Nota: `_seedViewer()` es un helper nuevo que hay que extraer del `testOperativeViewRenders()` existente y reusar en los tres tests, para no repetir la siembra de rol + permiso + usuario.

Primero, añadir el helper privado a la clase:

```php
    /**
     * Siembra un rol con `advances.can_view` y devuelve un usuario con ese rol.
     */
    private function _seedViewer(): \App\Model\Entity\User
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }
```

Luego los dos tests nuevos:

```php
    /**
     * En el paso Contabilidad, el card de acción muestra los 3 campos de
     * causación junto al monto, y NO el card read-only.
     */
    public function testContabilidadRendersAccountingFields(): void
    {
        $user = $this->_seedViewer();
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(2336500.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('name="accrued"');
        $this->assertResponseContains('name="accrual_date"');
        $this->assertResponseContains('name="ready_for_payment"');
        $this->assertResponseContains('Marcar como causada');
        $this->assertResponseContains('Fecha de Causación');
        $this->assertResponseContains('Lista para Pago');
    }

    /**
     * Fuera de Contabilidad y con causación registrada, la vista muestra el card
     * read-only en lugar de los inputs.
     */
    public function testTesoreriaRendersReadOnlyAccountingCard(): void
    {
        $user = $this->_seedViewer();
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withCaseType(AdvanceConstants::CASE_FALTANTE)
            ->withShortageAmount(336500.0)
            ->withAccounting(true, '2026-06-23', InvoiceConstants::READY_FOR_PAYMENT_SI)
            ->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Causación');
        $this->assertResponseContains('23/06/2026');
        $this->assertResponseNotContains('name="accrual_date"');
    }
```

Reescribir `testOperativeViewRenders()` y `testSurplusAmountInputRendersRawValue()` para que usen `$user = $this->_seedViewer();` en lugar de las 8 líneas de siembra inline.

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
```

Expected: FAIL — `testContabilidadRendersAccountingFields` falla con `Failed asserting that response body contains 'name="accrued"'`.

- [ ] **Step 3: Exponer los datos desde el ViewModel**

En `src/ViewModel/AdvanceLegalizationViewModel.php`, añadir el import:

```php
use App\ViewModel\Support\PaymentOptions;
```

y, en el array que devuelve `build()`, dentro del bloque `// Derivaciones de presentación.`, tras `'caseLabels' => ...`:

```php
            'readyForPaymentOptions' => PaymentOptions::readyForPayment(),
            'showAccountingCard' => $this->leg->accrual_date !== null
                && (string)$this->leg->status !== AdvanceConstants::STATUS_CONTABILIDAD,
```

- [ ] **Step 4: Crear el elemento con los tres campos**

Crear `templates/element/advance_legalization/_accounting_fields.php`:

```php
<?php
/**
 * Campos de causación del paso Contabilidad de la legalización. Compartido por
 * las 3 salidas del paso (caso exacto, faltante, sobrante), por eso vive en un
 * elemento y no inline: los 3 formularios deben enviar exactamente lo mismo.
 *
 * El select tiene 4 opciones (< 7) → `form-select` plano, sin `select2-enable`.
 * Las opciones vienen del ViewModel (fuente única: InvoiceConstants), nunca
 * escritas a mano acá.
 *
 * @var \App\View\AppView $this
 * @var array<string, string> $readyForPaymentOptions
 */
?>
<div class="row g-2 g-md-3 mb-3">
    <div class="col-md-4">
        <label class="input-label">Causada</label>
        <div class="form-check">
            <input type="checkbox" name="accrued" value="1" id="leg-accrued" class="form-check-input">
            <label for="leg-accrued" class="form-check-label">Marcar como causada</label>
        </div>
    </div>
    <div class="col-md-4">
        <label class="input-label">Fecha de Causación</label>
        <input type="text" name="accrual_date" class="form-control flatpickr-date" value="" required>
    </div>
    <div class="col-md-4">
        <label class="input-label">Lista para Pago</label>
        <select name="ready_for_payment" class="form-select" required>
            <?php foreach ($readyForPaymentOptions as $value => $label): ?>
            <option value="<?= h($value) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
```

- [ ] **Step 5: Desestructurar las claves nuevas en el template**

En `templates/Advances/legalization.php`, dentro del bloque `[...] = $viewModel->build();` (líneas 13-43), añadir tras `'caseLabels' => $caseLabels,`:

```php
    'readyForPaymentOptions' => $readyForPaymentOptions,
    'showAccountingCard' => $showAccountingCard,
```

- [ ] **Step 6: Insertar el elemento en las 3 salidas del paso Contabilidad**

Reemplazar el bloque completo entre `<?php if (abs($diff) < 0.005): ?>` (línea 230) y el `<?php endif; ?>` de la línea 268 por:

```php
            <?php if (abs($diff) < 0.005): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'markExact', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <?= $this->element('advance_legalization/_accounting_fields', [
                'readyForPaymentOptions' => $readyForPaymentOptions,
            ]) ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar legalizada (caso exacto)
            </button>
            <?= $this->Form->end() ?>
            <?php elseif ($diff > 0.005): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerShortage', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <?= $this->element('advance_legalization/_accounting_fields', [
                'readyForPaymentOptions' => $readyForPaymentOptions,
            ]) ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="input-label">Monto del faltante (consignación pendiente)</label>
                    <input type="text" name="shortage_amount" class="form-control currency-input"
                           value="<?= (int)round($diff) ?>" required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Registrar faltante
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
            <?php else: ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerSurplus', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <?= $this->element('advance_legalization/_accounting_fields', [
                'readyForPaymentOptions' => $readyForPaymentOptions,
            ]) ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="input-label">Monto del sobrante (reintegro a beneficiario)</label>
                    <input type="text" name="surplus_amount" class="form-control currency-input"
                           value="<?= (int)round(abs($diff)) ?>" required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-arrow-up-circle me-1" aria-hidden="true"></i>Registrar sobrante
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
            <?php endif; ?>
```

- [ ] **Step 7: Insertar el card read-only**

En `templates/Advances/legalization.php`, entre el `<?php endif; ?>` de la línea 384 (cierre de la cadena de cards «Acción del paso actual») y el comentario `<!-- Soportes -->` de la línea 386:

```php
        <?php if ($showAccountingCard): ?>
        <div class="spi-card">
            <div class="spi-section-head">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-calculator" aria-hidden="true"></i>Causación
                </span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="field-row">
                        <span class="k">Causada</span>
                        <span class="v"><?= $leg->accrued ? 'Sí' : 'No' ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Fecha de Causación</span>
                        <span class="v mono"><?= h($leg->accrual_date?->format('d/m/Y') ?? '—') ?></span>
                    </div>
                </div>
                <div>
                    <div class="field-row is-last">
                        <span class="k">Lista para Pago</span>
                        <span class="v"><?= h($leg->ready_for_payment ?? '—') ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
```

- [ ] **Step 8: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
```

Expected: PASS — `OK (4 tests, ...)`.

- [ ] **Step 9: Verify style and commit**

```bash
composer cs-check
git add templates/ src/ViewModel/AdvanceLegalizationViewModel.php tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
git commit -m "feat: campos de causacion y card read-only en la vista de legalizacion"
```

---

### Task 7: Verificación final

- [ ] **Step 1: Correr la suite completa**

```bash
set -a; source <(sed -E "s/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/\1='\2'/" config/.env); set +a
vendor/bin/phpunit
```

Expected: `0 failures / 0 errors`. El baseline previo es de **843 tests**; este plan añade 10 (1 en Task 1, 3 en Task 2, 3 en Task 3, 3 en Task 4, 2 en Task 6, menos 1 aserción movida), así que esperar ~853. **El exit code será 1** por los ~126 PHPUnit Notices preexistentes: leer la línea `Tests: N, Assertions: M` y confirmar `0 failures / 0 errors`, no el exit code.

Si aparecen errores en cascada de tablas bloqueadas (SQLSTATE 1205), es contaminación entre suites consecutivas: volver a correr en limpio antes de concluir que hay regresión.

- [ ] **Step 2: Verificar el estilo global**

```bash
composer cs-check
```

Expected: sin errores. Si los hay: `composer cs-fix` y volver a verificar.

- [ ] **Step 3: Prueba manual del bug del monto**

Levantar el servidor (`php bin/cake server`), abrir una legalización en Contabilidad con diferencia de `-336.500` y confirmar que el input muestra `$ 336.500` (no `$ 337`). Marcar la causación, registrar el sobrante y verificar en `advance_legalizations` que `surplus_amount = 336500.00`.
