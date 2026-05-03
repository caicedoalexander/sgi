# Recibo de Caja — Unificar "Documento Equivalente" en Tipo de Documento

## Contexto

Hoy la tabla `invoices` tiene un checkbox `is_equivalent_document`. Al activarse, expone
un sub-formulario con `equivalent_holder_type` (`provider` | `employee` | `manual`) y,
según el holder, un selector de empleado o un input de cédula manual.

El select `document_type` y el checkbox son dos controles independientes que en la
práctica describen el mismo concepto. Esta duplicidad causa formularios condicionales
cruzados (Legalización desactiva la fila equivalent, etc.) y un campo booleano que es
redundante con la propia clasificación del documento.

## Objetivo

Eliminar el campo booleano `is_equivalent_document` y consolidar el flujo de
"Documento Equivalente" como una opción más del select `document_type`, renombrada a
**Recibo de Caja**. El sub-formulario actual (holder + empleado/manual) se conserva
intacto y pasa a dispararse por el valor del select.

El doc type existente `Recibo` se conserva sin cambios; conviven ambos.

## Alcance

- Modelo de datos: nueva constante, migración que elimina la columna y migra datos.
- Templates `Invoices/add.php`, `Invoices/edit.php`, `Invoices/view.php`.
- Validación en `InvoicesTable.php` y entidad `Invoice.php`.

Fuera de alcance: cambios en pipeline, permisos, reportes o servicios.

## Diseño

### 1. Modelo de datos

- Agregar constante en `src/Constants/InvoiceConstants.php`:
  - `DOCTYPE_RECIBO_CAJA = 'Recibo de Caja'`.
  - Incluir en el array `DOCUMENT_TYPES`.
- Nueva migración `DropIsEquivalentDocumentFromInvoices`:
  1. `UPDATE invoices SET document_type = 'Recibo de Caja' WHERE is_equivalent_document = 1` (data migration previa al drop).
  2. `removeColumn('is_equivalent_document')`.
  3. `down()` recrea la columna `boolean default 0 not null` y, para preservar reversibilidad mínima, marca `is_equivalent_document = 1` en filas con `document_type = 'Recibo de Caja'`. No restaura el valor original de `document_type` (no hay forma de recuperarlo).
- Los campos `equivalent_holder_type`, `employee_id`, `manual_document_number` se conservan tal cual. Sólo cambia el disparador (select en lugar del checkbox).

### 2. Formularios `add.php` y `edit.php`

- Eliminar el checkbox `is_equivalent_document` y su `<label>`.
- La fila `#equivalent-doc-row` (holder_type + employee + manual_doc) se conserva, pero su visibilidad pasa a depender de `document_type === 'Recibo de Caja'`.
- JS:
  - Listener sobre el select `document_type`.
  - Si valor `=== 'Recibo de Caja'`: mostrar fila equivalent. La lógica interna (mostrar `employee` o `manual_document_number` según `holder_type`) se conserva sin cambios.
  - Si valor `!== 'Recibo de Caja'`: ocultar fila y resetear `equivalent_holder_type`, `employee_id`, `manual_document_number` para no enviar valores fantasma.
- Simplificar la lógica de Legalización en `add.php` (~línea 245): ya no hace falta `setDisabled` cruzado entre Legalización y la fila equivalent porque ambos son valores mutuamente excluyentes del mismo select.
- En `edit.php` el campo `provider_id` que hoy se desactiva con `disabled` cuando `is_equivalent_document=true` pasa a desactivarse cuando `document_type === 'Recibo de Caja'` **y** `holder_type !== 'provider'`. Mantener exactamente el comportamiento previo (con holder=provider el campo sigue habilitado).

### 3. Vista `view.php`

- Eliminar el badge `'Doc. Equivalente'` (línea ~123). El doc type ya se muestra en la cabecera, el badge sería redundante.
- Las dos ramas que renderizan "Empleado" / "Cédula manual" cambian su condición de `is_equivalent_document && holder_type === 'X'` a `document_type === 'Recibo de Caja' && holder_type === 'X'`.

### 4. Validación y entidad

- `InvoicesTable.php`: eliminar las reglas de `is_equivalent_document` (líneas 157–158).
- Las reglas que dependían de `is_equivalent_document` (obligatoriedad de `equivalent_holder_type`, `employee_id` cuando holder=employee, `manual_document_number` cuando holder=manual) cambian su predicado a `document_type === 'Recibo de Caja'`.
- `Invoice.php`: quitar `'is_equivalent_document' => true` de `$_accessible`.

## Validación manual

1. `php bin/cake migrations migrate`. Verificar que la columna `is_equivalent_document` desapareció y que las filas previamente marcadas quedaron con `document_type = 'Recibo de Caja'`.
2. `/invoices/add`: el select Tipo de Documento contiene `Recibo` y `Recibo de Caja` como opciones distintas. El checkbox "Es Documento Equivalente" ya no existe.
3. Seleccionar `Recibo de Caja`: aparece la fila holder_type. Probar las tres ramas (provider, employee, manual) y guardar — los valores se persisten correctamente.
4. Cambiar el tipo de `Recibo de Caja` a `Factura`: la fila equivalent se oculta y al guardar no se persisten valores fantasma en `equivalent_holder_type`, `employee_id`, `manual_document_number`.
5. Crear factura `Recibo de Caja` + holder=employee. Abrir `/invoices/edit/<id>`: el sub-formulario se rehidrata con los valores guardados.
6. `/invoices/view/<id>`: en una factura `Recibo de Caja` se muestra empleado o cédula manual según corresponda. En una factura `Factura` normal esos campos no aparecen y no hay badge "Doc. Equivalente".
7. Seleccionar tipo `Legalización`: la fila equivalent permanece oculta.
8. `composer cs-check` pasa.

## Riesgos

- **Datos heredados**: filas con `is_equivalent_document=1` tienen `document_type` actual variado (Factura, Recibo, etc.). La migración pisa ese valor con `Recibo de Caja`. Si alguna fila legítimamente tenía `document_type='Factura'` y `is_equivalent_document=1`, perderá el `Factura`. Asumimos que el booleano era la fuente de verdad funcional, lo que valida el merge en una sola opción.
- **Reportes y filtros existentes**: ningún service ni controller filtra por `is_equivalent_document` (verificado por grep). Sin riesgo conocido.
