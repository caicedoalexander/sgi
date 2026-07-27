# Recibos de Caja vinculables a Caja Menor

**Fecha:** 2026-07-15
**Estado:** Aprobado (diseño)
**Depende de:** feature `2026-07-14-requisitos-condicionales-doctype-grupos` (multi-estado en `GroupedInvoiceService`, auto-avance de Caja Menor, gate de soporte). Ya en `dev`.

## 1. Contexto y problema

Hoy un registro de **Caja Menor** solo puede agrupar facturas de `document_type = 'Caja menor'`. Operativamente hay gastos de caja menor que se documentan con un **Recibo de Caja** (`DOCTYPE_RECIBO_CAJA = 'Recibo de Caja'`), que no se pueden vincular. Se pide permitir elegir Recibos de Caja como facturas vinculables a un registro de Caja Menor, además de las de tipo "Caja menor".

El Recibo de Caja tiene **doble vida** en el sistema, y eso es lo único no trivial de esta feature:
- Ya es vinculable a la **legalización de un anticipo** (`ADVANCE_LINKABLE_DOCTYPES = [Legalización, Recibo de Caja]`), quedando con `advance_id != null`.
- `ReciboCajaDocumentTypePolicy::blocksAdvance()` lo **congela en `contabilidad`** cuando `advance_id != null` (es justificación de un gasto ya cubierto por el anticipo). Un RC **sin** `advance_id` usa el pipeline normal de 6 pasos.

### Estado real del código (verificado)

- **`src/Service/GroupedInvoiceService.php`** filtra las candidatas por un **único** `document_type` (string):
  - `getAvailableInvoices()` (`:203`): `'Invoices.document_type' => $this->documentType` + `pipeline_status IN $linkableStatuses` + `{fkField} IS NULL`.
  - `validateGrouping()` (`:78`): `$invoice->document_type !== $this->documentType` → error *"no es de tipo Caja Menor"*; además rechaza si `!empty($invoice->{$this->fkField})` (ya vinculado a otro registro del mismo tipo).
  - El parámetro `linkableStatus` **ya** acepta `string|array` (introducido en la feature del 2026-07-14). `documentType` **no** — sigue siendo `string`.
- **`src/Service/PettyCashPipelineService.php`** (`:49-55`) construye el servicio con `documentType: DOCTYPE_CAJA_MENOR`, `fkField: 'petty_cash_record_id'`, `fkLabel: 'Caja Menor'`, `linkableStatus: [STATUS_APROBACION, STATUS_CONTABILIDAD]`. Al vincular, `addInvoices()` auto-avanza transaccionalmente las hijas en `aprobacion` a `contabilidad`.
- **Lado Anticipo (exclusividad inversa):**
  - `AdvancesController::linkCandidates()` (`:579-586`): candidatas con `advance_id IS null` + `pipeline_status = APROBACION` + `document_type IN [Legalización, Recibo de Caja]`. **No** filtra `petty_cash_record_id`.
  - `AdvanceLegalizationService::linkInvoices()` (`:133-144`): gate server-side vía `updateAll` **condicional** (`advance_id IS null` + `pipeline_status = APROBACION` + `document_type IN [...]`). **No** filtra `petty_cash_record_id`.

## 2. Decisiones tomadas

- **D1 — Exclusividad "un recibo = un solo padre" (excluyente).** Un RC vinculado a un anticipo (`advance_id != null`) **no** puede vincularse a Caja Menor, y viceversa. Un mismo recibo nunca cuenta en dos agregados a la vez. Consecuencia: la feature toca **ambos** módulos (Caja Menor y Anticipos), aunque el pedido nombre solo Caja Menor.
- **D2 — El RC vinculado a Caja Menor se comporta idéntico a una factura "Caja menor".** Auto-avance `aprobacion`→`contabilidad` al vincular, gate de soporte aplicable, exento de DIAN (Caja Menor ya usa `includeDian: false`). Sin lógica de flujo nueva. Como el RC de Caja Menor tiene `advance_id = null`, `ReciboCajaDocumentTypePolicy::blocksAdvance()` **no** lo congela — usa el flujo de Caja Menor con normalidad.
- **D3 — Ampliar `documentType` a `string|array`**, reutilizando el patrón exacto ya presente para `linkableStatus`. No se introduce abstracción nueva.
- **D4 — Sin migraciones de esquema.** `advance_id` y `petty_cash_record_id` ya son columnas de `invoices`.

## 3. Diseño

### 3.1 `GroupedInvoiceService`: filtro multi-doctype + exclusión atómica por `advance_id`

Espejo del refactor `string|array` de `linkableStatus`:

- Constructor: `string|array $documentType` → normalizar a `private readonly array $documentTypes = array_values((array)$documentType);`.
- `getAvailableInvoices()`: `'Invoices.document_type IN' => $this->documentTypes` (en vez de `=`). **Añadir** `'Invoices.advance_id IS' => null`. Este filtro es **inocuo** para las facturas "Caja menor" (nunca tienen `advance_id`) y excluye los RC ya vinculados a un anticipo — implementa la exclusividad del lado del selector.
- `validateGrouping()` (feedback temprano, mensajes claros):
  - Doctype: `!in_array($invoice->document_type, $this->documentTypes, true)` → mensaje **genérico derivado de `fkLabel` + `documentTypes`** (NO hardcodear "Caja Menor" — el servicio también sirve a Reintegros con `fkLabel='Reintegro'`), p. ej. `sprintf('La factura #%s no es un tipo vinculable a %s (%s).', num, $this->fkLabel, implode(' o ', $this->documentTypes))`.
  - Rechazar si `!empty($invoice->advance_id)` → *"La factura #X ya está vinculada a un anticipo."*.
  - Se conserva el check existente de `!empty($invoice->{$this->fkField})` (doble-vínculo al mismo registro).

**Punto de enforcement REAL — escritura atómica en `addInvoices()` (compare-and-set):** el gate anterior es solo lectura (`validateGrouping` = SELECT). Como la escritura y la validación no son atómicas, un RC libre podría adquirir `advance_id` (vía el módulo de anticipos) **entre** el SELECT y el UPDATE, dejando el RC con **ambos** FKs a la vez (doble conteo, viola D1). Hoy `addInvoices` escribe con un `updateAll` **incondicional por id** (`['id' => $invoiceId]`) → vulnerable. Se convierte en **compare-and-set**, espejo del gate del anticipo (§3.3):

```php
$affected = $invoicesTable->updateAll(
    [$this->fkField => $record->id],
    ['id IN' => $invoiceIds, $this->fkField . ' IS' => null, 'advance_id IS' => null],
);
if ($affected !== count($invoiceIds)) {
    // Alguna factura dejó de estar libre entre la validación y la escritura → abortar.
    return ['Una o más facturas ya no están disponibles para vincular. Refresque e intente de nuevo.'];
}
```

(El `advance_id IS null` es inocuo para "Caja menor"/"Reintegro"; solo muerde a los RC. El `updateAll` pasa de N updates por-id a **uno** guardado — también corrige el N+1 preexistente del loop.) `PettyCashPipelineService::addInvoices` ya envuelve esta llamada en su propia transacción y computa `$toPromote` **dentro** de ella (feature 2026-07-14), así que el compare-and-set corre bajo el mismo lock de transacción y el auto-avance solo promueve lo efectivamente vinculado.

**Compatibilidad:** Refund (`RefundPipelineService`) sigue construyendo con un `string` — `(array)` lo normaliza. El nuevo compare-and-set y el filtro `advance_id IS NULL` son correctos para Reintegros (una factura de reintegro no lleva `advance_id`) y endurecen su vínculo sin cambiar su semántica.

### 3.2 `PettyCashPipelineService`: registrar los dos doctypes

`documentType: [InvoiceConstants::DOCTYPE_CAJA_MENOR, InvoiceConstants::DOCTYPE_RECIBO_CAJA]` en el `new GroupedInvoiceService(...)`. Nada más cambia en este servicio (el auto-avance y el gate de soporte ya son doctype-agnósticos).

### 3.3 Exclusividad inversa en el módulo de Anticipos

Para que la exclusividad sea real y bidireccional:

- `AdvancesController::linkCandidates()` (`:579`): añadir `'Invoices.petty_cash_record_id IS' => null` al `$conditions` → el selector de candidatas del anticipo no ofrece RC ya vinculados a Caja Menor.
- `AdvanceLegalizationService::linkInvoices()` (`:137`): añadir `'petty_cash_record_id IS' => null` al `WHERE` del `updateAll` condicional → gate server-side; un RC con `petty_cash_record_id` no se vincula (el `updateAll` no lo alcanza, `count` no lo incluye).

Ambos son adiciones de una condición a queries/updates que ya existen — mismo patrón, sin código estructural nuevo.

**Feedback de vínculo parcial:** `linkInvoices` del anticipo retorna `ok(['linked' => count])` y hoy descarta en silencio las filas que su `WHERE` no alcanza (patrón preexistente para `advance_id`/`pipeline_status`). Al añadir `petty_cash_record_id IS null`, un intento con un RC ya en Caja Menor baja el `count` sin avisar. Alineado con el compare-and-set de §3.1, cuando `linked < seleccionadas` se debe informar al usuario (flash tipo *"N de M facturas vinculadas; el resto ya no estaba disponible"*) en vez de reportar éxito liso.

### 3.4 RBAC / permisos

No se introduce permiso nuevo. Se reutilizan los gates vigentes: Caja Menor vincula bajo `PettyCashRecordsController::linkInvoices` con `#[Permission(action:'edit')]` del módulo `petty_cash`; Anticipo bajo `AdvancesController::linkCandidates`/`linkInvoices` con la autorización de pipeline `legalizations`. Los dos compare-and-set de §3.1/§3.3 son los únicos puntos que **transfieren** una factura existente a un padre, y bastan para la invariante D1. Existe un tercer escritor de `advance_id` — el path "crear factura ya vinculada" (`InvoicesController` F3, `patchEntity([... 'advance_id' => ...])`) — que **no** viola D1 porque crea una entidad nueva con un solo FK (`petty_cash_record_id` nace null); no es una transferencia y queda fuera del alcance de esta feature.

## 4. Casos borde

- **RC libre (`advance_id = null`, `petty_cash_record_id = null`):** candidato válido para Anticipo **o** Caja Menor (lo que ocurra primero lo captura; el otro deja de verlo).
- **RC con `advance_id` intenta vincularse a Caja Menor por POST manual:** rechazado por `validateGrouping` (§3.1), no solo oculto en la UI.
- **RC con `petty_cash_record_id` intenta vincularse a un anticipo por POST manual:** el `updateAll` condicional (§3.3) no lo alcanza → no se vincula.
- **Carrera vincular-en-ambos-a-la-vez (la razón del compare-and-set de §3.1):** ambos lados escriben el FK con un `updateAll` **condicionado** por el estado libre del otro FK (`advance_id IS null` en Caja Menor; `petty_cash_record_id IS null` en Anticipo). El primero en commitear gana; el segundo ve su condición incumplida, no escribe, y reporta. Sin el compare-and-set del lado Caja Menor, este orden concreto —Anticipo commitea primero, Caja Menor validó contra un snapshot previo— dejaría el RC con ambos FKs (el link de anticipo **no** cambia el `pipeline_status`, así que un gate por-estado no lo atrapa). El compare-and-set es la única garantía real de D1 bajo concurrencia.
- **RC ya en Caja Menor, se desvincula:** vuelve a quedar libre (`petty_cash_record_id = null`); `removeInvoice` **no** regresa el estado (asimetría deliberada existente). Queda de nuevo elegible para un anticipo si su estado lo permite.
- **Factura "Caja menor" con `advance_id`:** imposible por dominio (nunca se le asigna), y el filtro `advance_id IS NULL` no la afecta.

## 5. Testing (criterios de aceptación)

- **Unit `GroupedInvoiceServiceTest`:** (a) construido con `[Caja menor, Recibo de Caja]`, `validateGrouping` acepta un RC libre y una factura "Caja menor"; (b) rechaza un RC con `advance_id != null` con mensaje que menciona el anticipo; (c) el mensaje de doctype inválido se deriva de `fkLabel`/`documentTypes` (verificar que un servicio con `fkLabel='Reintegro'` produce el copy con "Reintegro", no "Caja Menor"); (d) `getAvailableInvoices` incluye RC libres y excluye RC con `advance_id`.
- **Escritura atómica (la garantía de D1):** `addInvoices` con un `advance_id` seteado en la fila **entre** validación y escritura (simular sembrando el FK antes de llamar, o un id que ya no cumple el WHERE) → retorna error de "ya no disponible" y **no** deja el RC con ambos FKs. Assert directo sobre el estado final de la fila.
- **Integración `PettyCashPipelineServiceTest`:** vincular un RC libre en `aprobacion` con soporte → queda en `contabilidad` con `petty_cash_record_id` seteado y auditoría; un RC con `advance_id` no es vinculable ni auto-avanza.
- **Integración lado Anticipo:** `AdvanceLegalizationService::linkInvoices` no vincula un RC con `petty_cash_record_id` (compare-and-set); `linkCandidates` no lo ofrece; cuando `linked < seleccionadas` se informa parcial.
- **Regresión:** las facturas "Caja menor" siguen vinculándose exactamente igual; Reintegros no cambia su semántica (solo gana el endurecimiento atómico).

## 6. Fuera de alcance

- Migraciones de esquema (no se necesitan).
- Cambiar el comportamiento del RC vinculado a un anticipo (freeze en `contabilidad`) — intacto.
- Vincular otros doctypes a Caja Menor (solo `Caja menor` + `Recibo de Caja`).
- Backfill / migración de datos históricos.
- Cualquier cambio en la vista de la tabla de hijas: el RC ya se renderiza correctamente con la infraestructura de `forGroupedRow` (DIAN 'na' por exención, badge de soporte normal).
