# Causación en el paso Contabilidad de la legalización de anticipos (+ fix del monto)

**Fecha:** 2026-07-09
**Módulo:** Anticipos — sub-pipeline de legalización (`legalizations` en `pipeline_permissions`, `advances` en `permissions`).
**Clasificación del módulo:** módulo de flujo (pipeline sobre `advance_legalizations`).
**Migración:** sí — 3 columnas nuevas en `advance_legalizations`. Sin backfill.
**Alcance:** solo el paso `contabilidad` del pipeline de legalización y el bug de precarga del monto en `templates/Advances/legalization.php`. No se tocan las facturas hijas ni `LinkedInvoiceLegalizer`.

## Problema

Dos defectos independientes en la misma vista (`Advances::legalization`, estado `contabilidad`):

**1. El paso Contabilidad no captura la causación.** Todos los demás módulos con paso de contabilidad (Facturas, Caja Menor, Reintegros) exigen tres campos antes de avanzar: `accrued` (checkbox "Marcar como causada"), `accrual_date` (fecha de causación) y `ready_for_payment` ("Lista para Pago"). El paso `contabilidad` de la legalización solo pide el monto del faltante/sobrante y no registra causación en ninguna parte. `Pipeline/Advance/State/ContabilidadState::validateAdvance()` devuelve `[]` — no hay gate.

**2. El monto del faltante/sobrante se persiste con los ceros truncados.** Los inputs se precargan con `number_format($diff, 0, ',', '.')`, es decir `value="336.500"`. AutoNumeric, al inicializar sobre ese valor, lo trata como número JS válido (`Number("336.500") === 336.5`) y con `decimalPlaces: 0` lo redondea a `337`. Como el widget usa `unformatOnSubmit: true`, el POST lleva `337` y eso es lo que se guarda en `surplus_amount` / `shortage_amount`.

El defecto solo se manifiesta cuando la diferencia tiene **un** separador de miles. Con dos (`2.336.500`) el string no es un número JS válido, AutoNumeric cae a su ruta de desformateo y el valor sobrevive intacto. De ahí que el error sea intermitente.

`_parseCop()` en `AdvancesController` es correcto y no se toca. `templates/Advances/legalization.php` es el único template del proyecto que preformatea un `.currency-input`; los demás (`Invoices/add.php:177`, `Invoices/edit.php:422`, `element/payment_section.php:160`) emiten el valor crudo.

## Decisiones acordadas

1. **La causación se guarda en la legalización**, en columnas nuevas de `advance_legalizations`. No se propaga a las facturas hijas ni se lee de la factura Anticipo padre.
2. **Es un gate en las tres salidas** del paso Contabilidad: «Marcar legalizada (caso exacto)», «Registrar faltante» y «Registrar sobrante». Sin los tres campos no se sale del paso. Esto obliga a convertir `markExact` de `postLink` a formulario.
3. **Tras salir de Contabilidad los valores se muestran en un card de solo lectura** en los estados posteriores (Tesorería, Autorización de pago, Verificación de pago, Legalizada).

## Diseño

### 1. Migración — `AddAccountingFieldsToAdvanceLegalizations`

Espejo exacto de `config/Migrations/20260312000002_AddAccountingFieldsAndObservationsToPettyCash.php`. Extiende `Migrations\BaseMigration` y guarda con `hasTable('advance_legalizations')`.

| Columna | Tipo | Null | Default | `after` |
|---|---|---|---|---|
| `accrued` | `boolean` | no | `false` | `case_type` |
| `accrual_date` | `date` | sí | `null` | `accrued` |
| `ready_for_payment` | `string` (limit 50) | sí | `null` | `accrual_date` |

Sin backfill. Las legalizaciones que ya superaron `contabilidad` quedan con `accrual_date = null`, y por eso su card de solo lectura no se renderiza (ver §7).

`down()` elimina las tres columnas.

### 2. Entidad — `src/Model/Entity/AdvanceLegalization.php`

Las tres columnas se declaran en `$_accessible` con valor `false`, junto a `case_type`, `shortage_amount` y `surplus_amount`. Son campos controlados por el pipeline: solo `AdvanceLegalizationService` los muta por asignación directa de propiedad, que bypassa `_accessible` (invariante MI-002 — evita que un `patchEntity` con datos del cliente falsifique la causación).

No se añaden predicates nuevos. Los existentes (`canMarkExact()`, `canRegisterShortage()`, `canRegisterSurplus()`) ya cubren la dimensión de estado y siguen siendo la única fuente de la regla de estado.

### 3. Tabla — `src/Model/Table/AdvanceLegalizationsTable.php`

En `validationDefault()`, en el mismo estilo que las reglas ya presentes:

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

Nota: `Table::save()` no ejecuta `validationDefault()` (la validación corre en `newEntity`/`patchEntity`), y aquí los campos se asignan por propiedad directa. Estas reglas son defensivas y documentales, coherentes con las que ya existen para `shortage_amount` / `case_type`. El gate real vive en el State (§4).

`ready_for_payment` reusa `InvoiceConstants::READY_FOR_PAYMENT_OPTIONS` (`'Si'`, `'Pago PSE'`, `'Pago prioritario'`). **`READY_FOR_PAYMENT_SI` vale `'Si'` sin tilde** — es un valor persistido; no se "corrige".

### 4. Gate — `src/Service/Pipeline/Advance/State/ContabilidadState.php`

`validateAdvance(AdvanceLegalization $leg): array` deja de devolver `[]` y valida los tres campos, calcando la lógica y el orden de `src/Service/Pipeline/Invoice/State/ContabilidadState.php:26-42`:

```php
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

Único cambio de copy respecto a Facturas: el sujeto ("La legalización" en vez de "La factura").

### 5. Servicio — `src/Service/AdvanceLegalizationService.php`

Las tres salidas del paso reciben el payload de causación:

```php
markExact(AdvanceLegalization $leg, array $accounting, int $userId): ServiceResult
registerShortage(AdvanceLegalization $leg, float $amount, array $accounting, int $userId): ServiceResult
registerSurplus(AdvanceLegalization $leg, float $amount, array $accounting, int $userId): ServiceResult
```

donde `$accounting` es `array{accrued: bool, accrual_date: ?string, ready_for_payment: ?string}` con `accrual_date` en formato `Y-m-d`.

El argumento se inserta **antes** de `$userId` para que la firma mantenga la convención del archivo (el `int $userId` siempre último). Los únicos consumidores son `AdvancesController` y tres tests, enumerados en «Criterios de verificación».

Nuevo método privado compartido:

```php
private function _applyAccounting(AdvanceLegalization $leg, array $accounting): array
```

que (a) asigna los tres campos por propiedad directa, normalizando `accrual_date` con `date('Y-m-d', strtotime($raw))` cuando no está vacío y a `null` cuando sí — mismo enfoque de string que ya usa `confirmShortageReceipt()` para `shortage_received_at`; y (b) devuelve los errores de `$this->stateRegistry->get(AdvancePipelineStatus::CONTABILIDAD)->validateAdvance($leg)`.

Orden de guardas en cada una de las tres, sin alterar las que ya existen:

1. Predicate de estado de la entidad (`canMarkExact()` / `canRegisterShortage()` / `canRegisterSurplus()`) → `fail` si no aplica.
2. Guarda de monto ya existente (`abs(diff) > 0.005` en exacto; `$amount <= 0` en faltante/sobrante).
3. `_applyAccounting()` → si devuelve errores, `ServiceResult::fail($errors[0])`.
4. Asignación de `case_type` / montos / `legalized_at` como hoy.
5. `_setStatus()` con los tres campos añadidos a `$extraChanges`, de modo que `recordFieldChange()` los audita dentro de la misma transacción que el cambio de estado.

**Captura del `oldValue` (crítico).** `recordFieldChange()` descarta silenciosamente los cambios donde `oldValue === newValue`. Como el paso 3 ya mutó la entidad, leer `$leg->accrued` en el paso 5 devolvería el valor **nuevo** y no se escribiría ninguna fila de historial. Por eso `_applyAccounting()` captura los tres valores originales **antes** de asignar y los devuelve junto a los errores:

```php
/** @return array{errors: list<string>, changes: array<string, array{0: scalar|null, 1: scalar|null}>} */
private function _applyAccounting(AdvanceLegalization $leg, array $accounting): array
```

`changes` viene ya en el formato `[field => [oldValue, newValue]]` que espera el `$extraChanges` de `_setStatus()`, que a su vez castea a `(string)` antes de auditar. El caller hace `array_merge` de `changes` con sus propios cambios (`case_type`, `shortage_amount` / `surplus_amount`). Alternativa equivalente y aceptable: leer `$leg->getOriginal('accrued')` antes del save; se prefiere el retorno explícito porque hace la dependencia visible en la firma.

### 6. Controller — `src/Controller/AdvancesController.php`

Helper privado junto a `_parseCop()`:

```php
/** @return array{accrued: bool, accrual_date: ?string, ready_for_payment: ?string} */
private function _accountingPayload(): array
```

que lee `accrued` (checkbox → `(bool)$this->request->getData('accrued')`), `accrual_date` y `ready_for_payment` del request, normalizando string vacío a `null`.

- `registerShortage()` y `registerSurplus()`: pasan `$this->_accountingPayload()` al servicio. Sin otros cambios.
- `markExact()`: ahora recibe un formulario real. Se le añade la comprobación `_ensureExpectedStatus($leg->status)` — que las otras dos ya tienen — y pasa el payload. `allowMethod(['post'])` y el `#[PipelineAction]` se mantienen.

Sin cambios de RBAC: los tres endpoints ya comparten el permiso de pipeline `legalizations` × `contabilidad` a través de `AdvanceLegalizationActionPolicy::_canOperate()`.

### 7. Vista — `templates/Advances/legalization.php`

**Card «Acción del paso actual» en `contabilidad` (líneas ~222-269).** Se extrae el bloque de los tres campos a un elemento nuevo, `templates/element/advance_legalization/_accounting_fields.php`, porque debe aparecer idéntico dentro de los tres formularios (exacto, faltante, sobrante):

```php
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

- El select **itera `$readyForPaymentOptions`** (que el ViewModel deriva de `PaymentOptions::readyForPayment()`, y ésta de `InvoiceConstants::READY_FOR_PAYMENT_OPTIONS`). Nunca se escriben `<option>` a mano: fuente única, cero arrays literales inline en el template.
- Se usan inputs crudos en vez de `$this->Form->control()` (el elemento vive dentro del `Form->create()`, que ya aporta el token CSRF) para garantizar los `name` exactos sin la mangling del FormHelper. El `required` es solo una ayuda client-side; el gate real es `ContabilidadState::validateAdvance()`.
- El select tiene 4 opciones (`-- Seleccione --` + 3) → `form-select` plano, **sin** `select2-enable` (regla del proyecto: buscador solo con ≥7 opciones).
- `flatpickr-date` ya se usa en este template (`:304`), así que el widget está cargado.
- Los labels y el copy son los mismos que en `templates/Invoices/edit.php:744-781`, salvo el sujeto de los mensajes de error.

**Caso exacto.** El `$this->Form->postLink('Marcar legalizada (caso exacto)', …)` de la línea 231 pasa a ser un `$this->Form->create(null, ['url' => ['action' => 'markExact', $leg->advance_invoice_id]])` con el hidden `expected_status`, el elemento de causación y un `<button type="submit" class="btn btn-primary">` con el mismo icono y texto.

**Fix del monto.** En los dos inputs de moneda:

```php
value="<?= (int)round($diff) ?>"          // faltante  (línea 243)
value="<?= (int)round(abs($diff)) ?>"     // sobrante  (línea 259)
```

**Card de solo lectura "Causación".** Nuevo `.spi-card` que se renderiza cuando `$leg->accrual_date !== null` y el estado no es `contabilidad`. Campos en `.field-row` (`.k` / `.v`) dentro de un grid inline `1fr 1fr; gap:28px`, conforme al canon visual de la vista VIEW. Muestra Causada (Sí/No), Fecha de Causación (`d/m/Y`) y Lista para Pago.

### 8. ViewModel — `src/ViewModel/AdvanceLegalizationViewModel.php`

`build()` añade dos claves al array que consume el template:

- `readyForPaymentOptions` → `PaymentOptions::readyForPayment()`.
- `showAccountingCard` → `bool`, verdadero cuando `$leg->accrual_date !== null && $leg->status !== AdvanceConstants::STATUS_CONTABILIDAD`.

El template las desestructura junto a las demás en su bloque de cabecera. No se añaden badges ni mapas estado→pill, así que `AdvancePresentation` no cambia.

## Flujo de datos

```
POST /advances/mark-exact|register-shortage|register-surplus/{id}
  → AdvancesController::_accountingPayload()
  → AdvanceLegalizationService::markExact|registerShortage|registerSurplus(leg, [amount,] accounting, userId)
      → predicate de estado (entidad)
      → guarda de monto
      → _applyAccounting() → ContabilidadState::validateAdvance()   ← gate
      → _setStatus(leg, nuevoEstado, userId, extraChanges)
          [transacción] save + recordStatusChange + recordFieldChange × (case_type, monto, accrued, accrual_date, ready_for_payment)
                        + AdvanceLegalizedEvent si nuevoEstado = legalizada
  → Flash + redirect a Advances::legalization
```

## Manejo de errores

Un fallo de causación devuelve `ServiceResult::fail($errors[0])`, el controller lo vuelca en `Flash->error()` y redirige a la vista de legalización. Lo tecleado se pierde, exactamente como ocurre hoy cuando el monto es inválido. Es el patrón vigente del módulo y no se cambia.

## Fuera de alcance

- No se propaga la causación a las facturas hijas (Legalización / Recibo de Caja), que siguen llegando a `legalizada` vía `LinkedInvoiceLegalizer` sin causar. Decisión explícita.
- No se hace backfill de las legalizaciones que ya pasaron de `contabilidad`.
- No se toca `_parseCop()`, ni la configuración de AutoNumeric en `webroot/js/spi-common.js`, ni ningún otro template con `.currency-input`.

## Invariantes que no se deben romper

- `InvoiceConstants::READY_FOR_PAYMENT_SI === 'Si'` (sin tilde) — valor persistido.
- Los tres campos nuevos van con `_accessible => false` (MI-002).
- Los `.currency-input` se precargan con el valor crudo, nunca con `number_format(..., ',', '.')`.
- El slug CRUD del módulo es `advances`; el del pipeline es `legalizations`. No se toca ninguno.

## Criterios de verificación

### Tests que rompen con el cambio de firma

Estos son **todos** los consumidores de los tres métodos en `tests/` (verificado por grep). Los tres archivos deben actualizarse a la nueva firma y sembrar un payload de causación válido en sus happy-paths, que de lo contrario fallarían contra el gate nuevo:

| Archivo | Método | Llamadas |
|---|---|---|
| `tests/TestCase/Service/Integration/AdvanceLegalizationTransitionsTest.php` | `markExact` | líneas 66, 92 |
| `tests/TestCase/Service/Integration/AdvanceLegalizationShortageTest.php` | `registerShortage` | líneas 66, 90, 114 |
| `tests/TestCase/Service/Integration/AdvanceLegalizationSurplusTest.php` | `registerSurplus` | líneas 67, 95, 136, 172 |

`AdvanceLegalizationLifecycleTest.php` **no** llama a ninguno de los tres (cubre `initialize` / `linkInvoices` / `getDifference`) y no debe tocarse.

### Tests a añadir o modificar

- `tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php` — la aserción `assertSame([], $s->validateAdvance($this->leg()))` embebida en `testContabilidadBranchesSoNextIsNull` (línea ~48) pasa a devolver tres errores; hay que separarla del assert de `getNextStatus()` y reescribirla. Nuevos casos: un error por cada campo faltante, y `[]` con los tres presentes. Como los campos son `_accessible => false`, el test debe asignarlos **por propiedad directa** sobre la entidad, no vía el constructor.
- En cada uno de los tres archivos de la tabla anterior: un caso que compruebe que la transición **falla** y el estado **no cambia** cuando la causación está incompleta.
- Test nuevo: tras un `registerSurplus()` exitoso, la fila de `advance_legalizations` tiene los tres campos persistidos y existen las tres filas correspondientes en `advance_legalization_histories` (esto ejercita la captura del `oldValue` descrita en §5 — si se hiciera mal, `recordFieldChange()` descartaría las filas en silencio y este test lo detecta).
- `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php` — (a) el input de moneda renderiza `336500`, no `336.500`; (b) los tres campos de causación aparecen en `contabilidad`; (c) el card de solo lectura aparece en `tesoreria` y no aparece en `contabilidad`.
- `composer cs-check` en verde sobre los archivos tocados.
