# Códigos autogenerados con centro de operación

**Fecha:** 2026-05-04
**Estado:** Diseño validado — listo para plan de implementación
**Alcance:** Caja menor, Reintegros, Pago programado, Anticipos

---

## 1. Problema

Hoy los módulos de caja menor, reintegros y pago programado generan códigos que el usuario puede modificar libremente, y el módulo de anticipos usa `invoice_number` también editable. Esto rompe la trazabilidad contable y permite duplicados o saltos de consecutivo no controlados.

Adicionalmente, las tablas de caja menor, reintegros y pago programado **no almacenan el centro de operación** al que pertenece cada registro.

## 2. Solución

Patrón único de código para los cuatro módulos:

```
{PREFIX}-{YY}-{CCC}-{NNNN}
```

| Componente | Significado | Ejemplo |
|------------|-------------|---------|
| `PREFIX` | Abreviación del módulo | `CM`, `REI`, `PRO`, `ANT` |
| `YY` | Año de creación, 2 dígitos | `26` (= 2026) |
| `CCC` | Código del centro de operación, 3 dígitos numéricos con padding | `001`, `002`, `045` |
| `NNNN` | Consecutivo, 4 dígitos con padding | `0001`, `0042` |

### Reglas comunes

- El código se asigna **una sola vez** al crear el registro y nunca se regenera.
- El `operation_center_id` es **obligatorio al crear** y **inmutable después** (deshabilitado en formularios de edición).
- El consecutivo `NNNN` es **único por (módulo, año, centro)** — cada centro lleva su propia secuencia anual; en enero de cada año vuelve a `0001`.
- Los registros existentes (legados) **no se tocan**: conservan su código viejo y `operation_center_id` queda en `NULL`.

### Mapa por módulo

| Módulo | Prefijo | Tabla / columna | Disparador |
|--------|---------|-----------------|------------|
| Caja menor | `CM` | `petty_cash_records.code` | Crear registro |
| Reintegros | `REI` | `refunds.code` | Crear registro |
| Pago programado | `PRO` | `payment_schedulings.code` | Crear registro |
| Anticipos | `ANT` | `invoices.invoice_number` | `document_type = ANTICIPO` |

### Ejemplos

- Primer reintegro de 2026 en centro 002 → `REI-26-002-0001`
- Tercer registro de caja menor de 2026 en centro 001 → `CM-26-001-0003`
- Factura-anticipo creada en centro 005 → `invoice_number = ANT-26-005-0001`

## 3. Migraciones (schema)

Una sola migración consolidada: **`AddOperationCenterToCodeGeneratedModules`**

```
petty_cash_records:
  + operation_center_id INT NULL, FK -> operation_centers(id) ON DELETE RESTRICT
  + INDEX (operation_center_id)
  ~ code: ya es VARCHAR(30) — sin cambios

refunds:
  + operation_center_id INT NULL, FK -> operation_centers(id) ON DELETE RESTRICT
  + INDEX (operation_center_id)
  ~ code: ya es VARCHAR(30) — sin cambios

payment_schedulings:
  + operation_center_id INT NULL, FK -> operation_centers(id) ON DELETE RESTRICT
  + INDEX (operation_center_id)
  ~ code: VARCHAR(20) -> VARCHAR(30)  (necesario para el nuevo formato)

invoices:
  ~ operation_center_id: ya existe — sin cambios
  ~ invoice_number: validar que VARCHAR sea >= 30 (es)
```

**Notas:**

- `NULL` permitido en `operation_center_id` para no romper legados; los nuevos registros se validan a nivel de aplicación con `requirePresence(field, 'create')`.
- FK con `ON DELETE RESTRICT` para evitar borrar centros con registros enlazados.
- No se añade `UNIQUE` compuesto sobre `(year, operation_center_id, sequence)` porque el `code` ya es `UNIQUE` y eso protege contra duplicados.
- Sin backfill, sin renumeración: lo legado queda intacto.

**Down:** elimina FKs y columnas nuevas, devuelve `payment_schedulings.code` a `VARCHAR(20)`.

## 4. Generador centralizado

**`src/Service/CodeGeneratorService.php`** expone un método por módulo:

```php
public function generatePettyCashCode(int $operationCenterId): string;
public function generateRefundCode(int $operationCenterId): string;
public function generatePaymentSchedulingCode(int $operationCenterId): string;
public function generateAdvanceInvoiceNumber(int $operationCenterId): string;
```

**Algoritmo:**

1. Buscar `code` del `operation_center` por id, normalizar a 3 dígitos numéricos con `str_pad`.
2. Calcular el año actual de 2 dígitos: `(int) date('y')`.
3. Construir el patrón base `{PREFIX}-{YY}-{CCC}-` y consultar el último consecutivo en la tabla destino:
   ```sql
   SELECT code FROM <tabla>
   WHERE code LIKE 'PREFIX-YY-CCC-%'
   ORDER BY code DESC LIMIT 1
   ```
4. Extraer los últimos 4 dígitos del último código encontrado, sumar 1 (o `1` si no había nada).
5. Devolver el código formateado.

**Concurrencia:** se ejecuta dentro de la transacción de creación. Como `code` es `UNIQUE`, en caso de colisión simultánea (rarísimo en este sistema) MySQL devuelve error y el servicio reintenta hasta 3 veces. La validación `UNIQUE` es la red de seguridad final.

**Dónde se invoca:**

- `PettyCashRecordsTable::beforeSave` — cuando `isNew()` y `code` está vacío.
- `RefundsTable::beforeSave` — reescribir el bloque actual para usar el servicio.
- `PaymentSchedulingsTable::beforeSave` — reemplaza `generateNextCode` actual.
- `InvoicesTable::beforeSave` — solo cuando `isNew() && document_type === ANTICIPO && empty(invoice_number)`. Las facturas no-anticipo conservan su flujo actual.

**Constantes:** `CODE_PREFIX` ya existe en `PettyCashConstants`, `RefundConstants`, `PaymentSchedulingConstants`. Agregar `CODE_PREFIX = 'ANT'` en `AdvanceConstants` (o equivalente).

## 5. Cambios en UI, controladores y servicios

### Caja menor (`PettyCashRecordsController` + templates)

- `add.php`: quitar input de `code` (autogenerado). Añadir `<select>` de `OperationCenters` (requerido).
- `edit.php`: campo `code` siempre read-only. `operation_center_id` deshabilitado tras crear. Para legados con `operation_center_id = NULL` se muestra "—" sin permitir asignar.
- `add()` ya no asigna `code`; sí asigna `operation_center_id` desde el form.
- `edit()` bloquea cambios al `code` y al `operation_center_id` en el `patchEntity` (lista blanca de campos editables).
- Index: añadir filtro opcional por centro en `_buildQuery`. Mostrar columna "Centro" en la tabla.

### Reintegros (`RefundsController` + templates)

Mismos cambios. El `beforeSave` actual de `RefundsTable` ya autogenera, solo cambia el formato.

### Pago programado (`PaymentSchedulingsController` + templates)

Mismos cambios.

### Anticipos (`AdvancesController::add`)

- Quitar input de `invoice_number` del formulario de creación de anticipos.
- El `operation_center_id` ya existe en el formulario de facturas; se mantiene **requerido** y se vuelve **inmutable tras crear** solo cuando `document_type = ANTICIPO`. Las facturas normales conservan su comportamiento actual.
- `InvoicesTable::beforeSave` decide si autogenerar `invoice_number`: solo cuando `isNew() && document_type === ANTICIPO && empty(invoice_number)`.

### Tablas (validaciones)

- `requirePresence('operation_center_id', 'create')` en `PettyCashRecordsTable`, `RefundsTable`, `PaymentSchedulingsTable`. Solo aplica al crear, así los legados no rompen.

### Lo que NO cambia

- Estados, pipeline, permisos, observaciones, documentos: intactos.
- `invoice_number` para facturas no-anticipo sigue siendo input libre del usuario.
- Reportes / PDF / Excel: heredan el nuevo formato automáticamente al imprimir el campo `code`.

## 6. Validación manual

(Este proyecto no usa tests automatizados — ver "Testing Policy" en `CLAUDE.md`.)

### Preparación

1. Levantar `php bin/cake server`.
2. `php bin/cake migrations migrate`. Verificar columnas con `SHOW CREATE TABLE petty_cash_records;` (y las otras tres).
3. Asegurar que existen centros de operación con códigos `001`, `002`, `005`.

### Casos golden path

| # | Acción | Resultado esperado |
|---|--------|-------------------|
| 1 | Crear caja menor con centro 001 | `code = CM-26-001-0001`, `operation_center_id` guardado |
| 2 | Crear segunda caja menor en centro 001 | `code = CM-26-001-0002` |
| 3 | Crear caja menor en centro 002 | `code = CM-26-002-0001` (secuencia independiente) |
| 4 | Crear reintegro en centro 002 | `code = REI-26-002-0001` |
| 5 | Crear pago programado en centro 003 | `code = PRO-26-003-0001` |
| 6 | Crear factura-anticipo en centro 005 | `invoice_number = ANT-26-005-0001`, sin input manual |
| 7 | Crear factura normal en centro 005 | `invoice_number` lo digita el usuario, sin prefijo `ANT` |

### Casos de borde

1. Editar un registro nuevo: el `code` aparece read-only, `operation_center_id` deshabilitado. Si se manipula el form con DevTools y se envía, el servidor ignora ambos campos.
2. Editar un legado (`code = REI-2026-0042`, `operation_center_id = NULL`): el `code` se conserva, el centro muestra "—" sin permitir asignar.
3. Crear sin centro de operación: validación falla con mensaje claro.
4. Borrar un centro de operación con registros enlazados: MySQL bloquea (FK RESTRICT).
5. Filtro por centro en index: lista solo los registros del centro elegido.

## 7. Despliegue y rollback

### Despliegue

1. Backup de BD.
2. `git pull` + `composer install` (sin deps nuevas).
3. `php bin/cake migrations migrate`.
4. Ejecutar los 7 + 5 casos anteriores en producción con un usuario de pruebas.
5. `php bin/cake cache clear_all` si hay templates compilados.

### Rollback

- `git revert` del commit aplicativo **primero**.
- `php bin/cake migrations rollback` después.
- Los registros creados con el formato nuevo conservarían su `code` como string (no se rompe), pero perderían `operation_center_id`. Por eso el orden importa.

## 8. Decisiones tomadas durante el brainstorming

- **Anticipos:** se decide aplicar el patrón al `invoice_number` de las facturas-anticipo (en lugar de crear un campo `advance_code` separado o aplicarlo a `advance_legalizations`).
- **Numeración del consecutivo:** por módulo + año + centro, reinicio anual.
- **Datos legados:** no se tocan. `operation_center_id` queda `NULL` y el `code` viejo se conserva.
- **Mutabilidad del centro de operación:** inmutable tras crear (se elige solo en el formulario de creación, deshabilitado en edición).
- **Pago programado:** se incluye en el cambio aunque no haya estado en la propuesta inicial, para uniformar.
- **Normalización del `CCC`:** siempre 3 dígitos numéricos con padding de ceros.
- **Migraciones:** una sola consolidada en lugar de una por módulo.
- **Generador:** servicio centralizado con un método explícito por módulo (más testeable que un método genérico acoplado a `TableRegistry`).
