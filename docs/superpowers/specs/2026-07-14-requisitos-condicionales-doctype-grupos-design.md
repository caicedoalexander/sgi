# Requisitos condicionales por tipo de documento + resolución inline en grupos

**Fecha:** 2026-07-14
**Estado:** Aprobado (diseño validado en sesión de brainstorming)
**Módulos afectados:** Invoices (pipeline), Refunds, Advances/Legalizaciones, PettyCash

---

## 1. Contexto y problemas

El pipeline de facturas exige hoy `dian_validation === 'Aprobada'` de forma global e incondicional
para avanzar de `aprobacion` a `contabilidad` (`Invoice/State/AprobacionState::validateAdvance()`),
sin distinción por `document_type`. No existe ningún gate de "soporte cargado" en ningún punto del
sistema. La tabla de facturas hijas en la vista de un registro padre (Reintegro, Caja Menor) es de
solo lectura, y corregir el DIAN de una hija exige navegar factura por factura. Caja Menor solo
permite vincular facturas que estén exactamente en `contabilidad`.

Cuatro problemas a resolver:

1. **DIAN condicional por tipo de documento** — caso concreto: "Recibo de Caja" no debe exigir DIAN.
2. **Resolver DIAN + soporte inline** desde la tabla de hijas en la vista del padre.
3. **Gate real de soporte cargado**, condicional por tipo de documento igual que DIAN.
4. **Caja Menor salta "Aprobación"**: vincular desde `aprobacion` con auto-avance de la hija.

### Estado real del código (verificado, corrige supuestos previos)

- El gate DIAN individual vive en `src/Service/Pipeline/Invoice/State/AprobacionState.php`
  (`validateAdvance()` + `getTransitionRules()`), invocado vía
  `InvoiceTransitionValidator::validateAdvance()` desde `InvoicePipelineService`.
- Existen **4** `DocumentTypePolicy` (no 3): `Standard`, `Anticipo`, `Legalizacion` y
  `ReciboCajaDocumentTypePolicy`, resueltas por `DocumentTypePolicyFactory::for(?string $documentType)`.
  Ninguna condiciona DIAN hoy.
- El pipeline de Reintegro es `agrupacion → aprobacion → contabilidad → …`; el gate DIAN de las
  hijas está en **aprobación→contabilidad**, en `Refund/State/AprobacionState` vía
  `RefundApprovalGuard::childInvoicesFailingDian()`. Anticipo tiene gate idéntico
  (`AdvanceLegalizationApprovalGuard`). **PettyCash y PaymentScheduling no validan nada de las hijas**
  (PettyCash `AgrupacionState::validateAdvance()` devuelve `[]`).
- Reglas de vinculación (igualdad exacta de un único estado, vía `GroupedInvoiceService` o
  equivalente): Caja Menor=`contabilidad`, Reintegro=`aprobacion`, Anticipo=`aprobacion` +
  `advance_id IS NULL` (`AdvanceLegalizationService::linkInvoices()`), Programación=`tesoreria`
  (`PaymentSchedulingImportService`).
- La tabla de hijas es **markup inline duplicado** en `templates/Refunds/view.php:118-160` y
  `templates/PettyCashRecords/view.php:278-320` (5 columnas, filas `clickable-row` sin acciones).
  Anticipo usa element propio `templates/element/advance_legalization/_linked_invoices.php`.
- `invoice_documents.document_type` es **texto libre opcional**; no existe categoría formal
  "soporte". La segregación real es por `pipeline_status` de subida.
- Los `document_type` de factura son constantes PHP (`InvoiceConstants::DOCUMENT_TYPES`), no tabla.

## 2. Decisiones tomadas

| # | Decisión | Elección |
|---|----------|----------|
| 1 | Dónde vive la configuración de requisitos por doctype | **En código, vía `DocumentTypePolicy`** (cero migraciones, sigue el patrón existente) |
| 2 | Qué cuenta como "soporte cargado" | **≥1 fila en `invoice_documents`** para la factura, sin importar fase ni tipo |
| 3 | Semántica del salto de Aprobación en Caja Menor | **Auto-avance al vincular** (patrón "PO-backed"): el vínculo certifica la aprobación; desvincular NO regresa |
| 4 | UX de guardado inline en la tabla de hijas | **Por fila, acción inmediata** (POST AJAX por select + modal de upload existente; sin estado sucio ni Guardar global) |
| 5 | Alcance del gate de soporte | **Sueltas y agrupadas** — el requisito es del doctype, no del contexto. Cambio de comportamiento aceptado para facturas sueltas existentes |

Alternativa elegida: **A (extensión mínima del patrón existente) + préstamo de B** (los guards
devuelven un reporte estructurado, no strings, para que gate y checklist UI salgan del mismo dato).
Se descartó el motor declarativo completo de `TransitionRequirement` (YAGNI para 2 booleanos) y la
variante grupo-céntrica (dejaba sin resolver el Recibo de Caja suelto).

## 3. Diseño

### 3.1 Modelo de reglas: dos métodos nuevos en `DocumentTypePolicy`

La interfaz `src/Service/Pipeline/Invoice/DocumentTypePolicy.php` gana:

```php
public function requiresDianValidation(): bool;
public function requiresSupportDocument(): bool;
```

Implementación por policy:

| Policy | `requiresDianValidation()` | `requiresSupportDocument()` |
|---|---|---|
| `StandardDocumentTypePolicy` | `true` | `true` |
| `AnticipoDocumentTypePolicy` | `true` | `true` |
| `LegalizacionDocumentTypePolicy` | `true` | `true` |
| `ReciboCajaDocumentTypePolicy` | **`false`** | `true` |

Un tercer requisito condicional futuro = un método más en la interfaz y un barrido por 4 clases.

`DocumentTypePolicyFactory` expone dos derivados para los gates de grupo (única fuente: las
policies mismas, calculado iterando las registradas — **prohibido** duplicar la lista como constante):

```php
public function dianExemptDocumentTypes(): array;    // doctypes con requiresDianValidation() === false
public function supportExemptDocumentTypes(): array; // ídem para soporte
```

Los guards convierten esas listas en condiciones SQL `document_type NOT IN (...)`.

**Regla de no-ocultamiento:** cuando DIAN no es requerido, el campo `dian_validation` sigue
existiendo y siendo editable en el formulario de la factura — deja de ser bloqueante, no se oculta
ni se borra data.

### 3.2 Gate individual: `AprobacionState` consulta la policy

`src/Service/Pipeline/Invoice/State/AprobacionState.php`:

- Constructor con DI (`?? new`, convención del repo): `DocumentTypePolicyFactory` y el nuevo
  `InvoiceGuard` (abajo).
- `validateAdvance()`:
  - Exige `dian_validation === InvoiceConstants::DIAN_APPROVED` **solo si**
    `$policy->requiresDianValidation()`.
  - Nuevo: si `$policy->requiresSupportDocument()`, exige `InvoiceGuard::hasAnyDocument($invoiceId)`.
  - `area_approval === APPROVAL_APPROVED` queda **intacto e incondicional** (fuera de alcance).
- `getTransitionRules()` se vuelve condicional por policy igual que `validateAdvance()`, y gana la
  regla `support_document`.

Nuevo `src/Service/Pipeline/Invoice/Guard/InvoiceGuard.php` (patrón `Guard/` de la estructura
canónica — los States son puros, el conteo de documentos es IO):

```php
public function hasAnyDocument(int $invoiceId): bool; // EXISTS en invoice_documents
```

Semántica deliberadamente laxa (Decisión 2): cualquier documento de cualquier fase/tipo satisface
el gate — incluso uno subido en un ciclo previo tras una regresión. La disciplina fina la pone el
revisor humano; si algún día se necesita precisión, el punto de cambio es este único método.

`InvoiceTransitionValidator::REQUIREMENT_FIELDS` gana la entrada para `support_document`; como no
es un campo de formulario sino una acción de upload, su mapeo de campos es vacío (el error no se
resuelve tecleando, se resuelve subiendo documento).

**Contrato de reglas y errores (cambio de firma en cadena):**

- `getTransitionRules()` hoy no recibe la factura en ninguna parte de la cadena
  (`InvoicePipelineState::getTransitionRules()` → `InvoiceTransitionValidator::getTransitionRules(string $fromStatus)`
  → `InvoicePipelineService` → 2 llamadas en `InvoicesController`). Para condicionarlas por
  doctype, la cadena completa pasa a recibir el invoice:
  `getTransitionRules(object $invoice)` en el State y
  `getTransitionRules(string $fromStatus, object $invoice)` en el validator. Son 4 sitios,
  todos internos al módulo Invoice.
- **Errores keyed, no posicionales:** `InvoiceTransitionValidator::filterErrorsForRole()` hoy
  correlaciona errores con reglas por índice (`$errors[$i]` ↔ `$rules[$i]`), lo cual ya
  misatribuye errores cuando un requisito pasa y otro falla (los índices se corren). Con una
  tercera regla condicional esto se amplifica. El contrato cambia: `validateAdvance()` de los
  States de Invoice devuelve `array<string,string>` keyed por requisito
  (`'dian_validation' => 'mensaje'`, `'support_document' => …`, `'area_approval' => …`) y
  `filterErrorsForRole()` correlaciona por key contra `REQUIREMENT_FIELDS`. Los States sin
  requisitos siguen devolviendo `[]`. Los consumidores que solo hacen `implode`/flash de los
  mensajes usan `array_values()` — el cambio es de forma, no de contenido.
- **Pass-through de errores del validator:** por el mismo pipe de `filterErrorsForRole()` viajan
  también los early-returns del propio `InvoiceTransitionValidator` (factura rechazada y
  `blocksAdvance()` de la doctype policy), que hoy son posicionales; con correlación por key
  quedarían con key `0` y se droparían en silencio. Contrato: esos errores usan keys reservadas
  (`'_rejected'`, `'_doctype_block'`) que `filterErrorsForRole()` **siempre deja pasar** — no son
  resolubles por campo de formulario, así que ningún rol debe dejar de verlos.
- Nota de alcance del cambio de firma: además de la cadena de 4 capas, `getTransitionRules(object
  $invoice)` toca las 7 implementaciones de `InvoicePipelineState`. El contrato keyed aplica solo
  al pipeline de Invoice; los States de Refund/Advance/PettyCash no pasan por
  `filterErrorsForRole()` y siguen devolviendo listas.

Resultado del caso concreto: un Recibo de Caja **suelto** avanza de `aprobacion` a `contabilidad`
sin marcar DIAN, pero no sin soporte.

### 3.3 Gate de grupo: `GroupReadinessReport` (préstamo de B)

Nuevo DTO en `src/Service/Dto/GroupReadinessReport.php`:

```php
final readonly class GroupReadinessReport
{
    /** @var array<int,string> invoice_id => invoice_number */
    public array $dianPending;
    /** @var array<int,string> invoice_id => invoice_number */
    public array $supportMissing;

    public function isBlocked(): bool;
    public function toMessages(): array; // strings ES para errores de transición / flash
}
```

Los guards ganan `childRequirements(int $parentId): GroupReadinessReport`:

- **`RefundApprovalGuard`** — hijas por `refund_id`. (Se queda en `src/Service/`, donde vive hoy —
  migrarlo al path canónico queda fuera de alcance.)
- **`AdvanceLegalizationApprovalGuard`** — hijas por `advance_id` + `document_type IN
  ADVANCE_LINKABLE_DOCTYPES` (filtro existente). (Ídem, se queda donde está.)
- **`PettyCashGuard`** (nuevo) — hijas por `petty_cash_record_id`. Al ser nuevo, nace en el path
  canónico: `src/Service/Pipeline/PettyCash/Guard/PettyCashGuard.php`.

Criterios de las queries:

- `dianPending`: doctype **no** en `dianExemptDocumentTypes()` y `dian_validation != 'Aprobada'`.
- `supportMissing`: doctype **no** en `supportExemptDocumentTypes()` y `NOT EXISTS` en
  `invoice_documents`.
- **Lista de exentos vacía ⇒ se omite la cláusula `NOT IN`** (CakePHP lanza excepción con
  `IN`/`NOT IN` sobre array vacío; el día uno `supportExemptDocumentTypes()` devuelve `[]`).
- Nota de realismo: la exención DIAN solo se ejercita de verdad en el guard de Anticipo (hijas
  `Legalización`/`Recibo de Caja`); las hijas de Reintegro y Caja Menor son de doctype homogéneo
  no exento (`validateGrouping()` lo fuerza). Se implementa igual en los tres por uniformidad —
  el filtro es inocuo donde no aplica.

Consumidores (mismo dato, cero drift):

1. `Refund/State/AprobacionState` y `Advance/State/AprobacionState`: reemplazan la llamada actual a
   `childInvoicesFailingDian()` y suman el chequeo de soporte. **`childInvoicesFailingDian()` se
   elimina** (sus dos únicos llamadores migran al reporte).
2. `PettyCash/State/AgrupacionState::validateAdvance()`: pasa de `[]` a exigir **solo soporte** de
   las hijas (DIAN no — sus hijas saltan Aprobación por diseño, §3.5). Aplica al avance del padre
   `agrupacion → contabilidad`.
3. El checklist de la vista del padre (§3.4).

**PaymentScheduling no se toca**: vincula en `tesoreria`, sus hijas ya pasaron los gates
individuales — coherente sin trabajo extra.

### 3.4 Tabla de hijas con acciones inline + checklist

**Extracción anti-duplicación:** el markup de `Refunds/view.php` y `PettyCashRecords/view.php` se
unifica en `templates/element/grouped_invoices_table.php`. El element de Anticipo
(`_linked_invoices.php`) **no se fusiona** (markup bespoke legítimo según el canon) pero adopta las
mismas dos celdas nuevas.

**Dos celdas nuevas por fila:**

- **DIAN**:
  - Doctype exento → texto "No aplica" en muted.
  - Rol sin permiso → pill de solo lectura (comportamiento visual actual).
  - Rol con permiso y factura en `aprobacion` → select compacto (Pendiente/Aprobada/Rechazada) que
    hace POST AJAX al cambiar, con toast `SpiToast` y refresh del checklist.
- **Soporte**: badge con conteo (`✓ N` verde / `Falta` naranja) + botón icono que abre el
  `element('upload_doc_modal')` existente apuntando a esa factura hija. El badge enlaza a la vista
  de la factura (ver/borrar documentos inline queda **fuera de alcance**).

La fila sigue siendo `clickable-row` con `data-href` a la factura; los controles internos hacen
`stopPropagation`.

**Endpoint inline:** `InvoicesController::updateDianInline` (POST, respuesta JSON con layout
`ajax`). Autorización en capas, replicando **exactamente** lo que exige la edición directa:

0. **Atributo de gate**: la action declara el mismo atributo de permiso que `Invoices::edit`
   (módulo `invoices`, `can_edit`) — en este repo toda action sin `#[Permission]`,
   `#[PipelineAction]` o `#[NoAuthGate]` lanza `LogicException` en `_enforcePermission()`, así que
   el atributo no es opcional.
1. **Anti-IDOR**: la factura debe pertenecer al registro padre indicado en el request (verificación
   de pertenencia real, no solo del id crudo — mismo patrón de agujero conocido en los controllers
   de pago).
2. La factura debe estar en `aprobacion` (si avanzó con la tabla stale → 409 con toast "refresca la
   página"; nunca escritura silenciosa).
3. RBAC de pipeline: `PipelineAuthorizationService::canOperate` sobre `invoices`/`aprobacion` **y**
   `InvoiceFieldAccessPolicy` permite `dian_validation` para ese rol.
4. Persistencia + auditoría vía `InvoiceHistoryService` (`dian_validation` ya está en
   `FIELDS_TO_TRACK` — mismo trail que la edición normal).

El upload reusa la action de subida de documentos existente (`InvoiceDocumentService` + su
controller actual) con sus permisos vigentes, apuntando a la hija con su `pipeline_status` actual —
cero lógica nueva de documentos ni de permisos. **Render condicionado = enforcement duplicado**: el
select de DIAN y el botón de upload solo se renderizan si el usuario pasa los mismos checks que el
servidor va a aplicar (un usuario con solo `can_view` de refunds ve la tabla en modo lectura, como
hoy).

**Checklist de bloqueo:** encima de la tabla, alimentado por `GroupReadinessReport`. Entra a la
vista **por el ViewModel del padre** (uniforme `set('viewModel', $vm)`, no como variable suelta):
*"3 de 5 facturas con DIAN pendiente · 1 sin soporte"*; filas ofensoras marcadas con icono de
alerta. El botón de avanzar el padre **no se deshabilita** (patrón del sistema: validación
server-side con flash) — el checklist da el "por qué" antes de intentarlo.

**Anti-drift:** los mapeos visuales nuevos (badge de soporte `✓ N`/`Falta`, "No aplica" de DIAN
exento, icono de alerta por fila) viven como const + factory en `InvoicePresentation`
(p. ej. `SUPPORT_BADGES` y un `forGroupedRow()` que produce el DTO de fila), **nunca** como
literales inline en el element. Las opciones del select DIAN salen de `InvoiceConstants`
(las mismas del formulario de factura).

### 3.5 Caja Menor: vínculo desde Aprobación con auto-avance

- `GroupedInvoiceService`: `linkableStatus` (string) se generaliza a `linkableStatuses` (array; el
  constructor normaliza string→array para no romper llamadores). PettyCash pasa
  `['aprobacion', 'contabilidad']`; Refund queda idéntico con `['aprobacion']`. El mensaje de error
  de `validateGrouping()` (hoy interpola `STATUS_LABELS[$this->linkableStatus]` asumiendo string)
  se redefine para lista: *"no está en un estado vinculable (Aprobación o Contabilidad)"*.
- **Boundary transaccional explícito** (hoy `GroupedInvoiceService::addInvoices()` hace N
  `updateAll` sueltos sin transacción): `PettyCashPipelineService::addInvoices()` envuelve
  vínculo + auto-avance + historial en un único `$connection->transactional()`, igual que hace
  `AdvanceLegalizationService::linkInvoices()`. Refund no cambia de comportamiento.
- Al vincular una hija en `aprobacion`, dentro de esa transacción se **auto-avanza a
  `contabilidad`**: set directo de estado — deliberadamente **sin** pasar por
  `InvoicePipelineService::advance()`, que aplicaría los gates que justamente se saltan — +
  entrada de historial: *"Avance automático por vinculación a Caja Menor {código}"*. Precedente:
  `AdvanceLegalizationService` ya re-encuadra estados de hijas al vincular/desvincular.
- ⚠️ Trampa para el plan: existe un gemelo muerto `src/Service/PettyCashService.php` (con su propio
  `addInvoices` y su propia construcción de `GroupedInvoiceService`) sin referencias vivas — el
  controller usa `PettyCashPipelineService`. **No parchear la clase muerta ni borrarla** en este
  alcance; solo tocar `PettyCashPipelineService`.
- El modal de vinculación lista facturas en ambos estados; las que están en `aprobacion` llevan
  nota visible de que se avanzarán automáticamente.
- **Desvincular NO regresa** la factura: queda en `contabilidad` con su historial; si hace falta,
  Registro la regresa manualmente.
- **Distinción conceptual:** el salto es propiedad del **tipo de vínculo** (vincular a Caja Menor
  certifica la aprobación — patrón "PO-backed invoice"), no del `document_type` de la factura. Por
  eso vive en el flujo de vinculación de PettyCash y no en una `DocumentTypePolicy`. Los otros tres
  destinos no certifican nada y no saltan nada.
- **Contrapeso:** como las hijas saltan también el gate de soporte individual, el gate de grupo de
  PettyCash (§3.3) lo exige antes de que el padre avance de `agrupacion` a `contabilidad`.

## 4. Casos borde

| Caso | Resolución |
|---|---|
| Recibo de Caja agrupado a un padre | El único padre que admite RC es el Anticipo (`ADVANCE_LINKABLE_DOCTYPES`; el link de Reintegro fuerza doctype `Reintegro`, el de Caja Menor `Caja menor`). El guard de Anticipo lo exime del chequeo DIAN automáticamente — guard y gate individual consultan la misma factory. Soporte sí se le exige. |
| Recibo de Caja vinculado a Anticipo (freeze en `contabilidad`) | Sin conflicto: la exención DIAN es ortogonal al freeze existente de `ReciboCajaDocumentTypePolicy::blocksAdvance()`. |
| Hija desvinculada de Caja Menor tras auto-avance | Queda en `contabilidad` sin aprobación — aceptado, trazado en historial, regresable a mano. |
| Soporte borrado después de subido | El gate evalúa al momento de la transición, no al subir — se re-bloquea solo. |
| Factura avanzó mientras la tabla del grupo estaba abierta | El endpoint inline revalida estado y pertenencia → error 409 explícito. |
| Facturas existentes en `aprobacion` sin documentos | **Cambio de comportamiento aceptado**: quedarán bloqueadas hasta subir soporte. Socializar con usuarios antes del deploy. |
| DIAN marcada `Rechazada` inline | Bloquea igual que hoy (solo `Aprobada` pasa donde es requerido); el checklist la cuenta como pendiente. |
| Programación de Pago | Sin cambios: vincula en `tesoreria`, hijas ya gateadas individualmente. |

## 5. Testing

**Unit:**

- Flags de las 4 policies y listas de exención de la factory (derivadas, no hardcodeadas).
- `AprobacionState::validateAdvance()`: doctype exento sin DIAN pasa; no exento sin DIAN bloquea;
  sin documento bloquea si requiere soporte; con documento pasa.
- `GroupReadinessReport` vía guards con fixtures: hija exenta+sin DIAN no cuenta (escenario real:
  RC en el guard de Anticipo), no exenta+sin soporte cuenta, combinaciones mixtas, lista de
  exentos vacía no rompe la query.
- Contrato keyed de `validateAdvance()`/`filterErrorsForRole()`: requisito que pasa + requisito
  que falla no misatribuye el error al rol equivocado.
- `GroupedInvoiceService` con `linkableStatuses` multi-estado (y retrocompatibilidad string).
- Auto-avance de PettyCash con entrada de historial, y atomicidad: si el historial falla, el
  vínculo y el avance se revierten juntos.

**Integración:**

- `updateDianInline`: rol sin permiso → 403; factura de otro padre → 404; factura fuera de
  `aprobacion` → 409; camino feliz escribe `invoice_histories`.
- Avance de Reintegro bloqueado por el reporte y desbloqueado al resolver.
- Vínculo Caja Menor desde `aprobacion` con auto-avance + gate de soporte del padre.
- Recibo de Caja suelto avanza sin DIAN pero no sin soporte.

Suite: `vendor/bin/phpunit` directo (timeout 300s; exit 1 en verde por notices preexistentes es
esperado, baseline 843).

## 6. Fuera de alcance

- Condicionar `area_approval` por doctype.
- Categorías formales de documento en `invoice_documents` (sigue texto libre).
- Ver/borrar documentos inline desde la tabla de hijas.
- Acciones masivas ("marcar DIAN en todas").
- Regresión automática al desvincular de Caja Menor.
- Cambios en PaymentScheduling y en el pipeline de Novedades.
- Tabla administrable de reglas por doctype (si los requisitos empiezan a cambiar con frecuencia,
  promover los flags a BD leyéndolos desde las mismas policies — la interfaz no cambiaría).

**Sin migraciones de esquema.** Todo es código + templates.
