# Auditoría — Flujo de documentos de liquidación (Refunds)

**Fecha:** 2026-05-05
**Modo:** PATH (cambios uncommitted en branch `main`)
**Nivel:** HIGH (PSR + Encapsulation + Code Smells + Bugs + Readability + SOLID + Security + Performance + Testability + DDD + Architecture)
**Alcance:** Módulo `Refunds` — pipeline de reintegros/liquidaciones, upload/delete de soportes, transiciones de estado, autorización por rol/estado.

## Archivos revisados

| Archivo | Cambios | Categoría |
|---|---|---|
| `src/Service/RefundService.php` | +394/-90 | Service / Domain |
| `src/Controller/RefundsController.php` | +159/-35 | Controller |
| `src/Model/Entity/Refund.php` | +43/-10 | Entity |
| `src/Model/Table/RefundsTable.php` | +24/-0 | Model |
| `src/Service/RefundDocumentService.php` | +17/-2 | Service |
| `templates/Refunds/edit.php` | +1/-0 | View |
| **Total** | **+638/-137** (493 netas) | — |

---

## Resumen de cambios

Esta auditoría cubre cambios pendientes que endurecen el flujo de reintegros (liquidaciones). Cambios destacados:

- **Endurecimiento de mass-assignment** en `Refund` (`status`, `total_amount`, todos los campos de pago, `created_by` y asociaciones pasan de `true` a `false`). Asignaciones críticas se hacen explícitamente desde controller/servicios.
- **Concurrencia transaccional** en `RefundService`: las cuatro acciones críticas (`advanceStatus`, `registerPayment`, `authorizePayment`, `rejectPayment`) ahora usan lock pesimista (`FOR UPDATE`) y revalidación TOCTOU.
- **Enforcement de RBAC vía pipeline** integrado en cada operación de servicio mediante `PipelineAuthorizationService::canOperate()`. Tanto controller como servicio chequean (defensa en profundidad).
- **Gate de upload/delete de documentos** por estado/rol mediante `_canOperateRefundStep()` y bloqueo en estado `pagado`.
- **Endurecimiento del filtro LIKE** en `_applyListFilters` (escape de `%`/`_`/`\`) y validación de `status`/fechas.
- **Validación reforzada de monto** en `registerPayment` (compara contra `total_amount` con tolerancia 0.01) y en `authorizePayment` (rechaza facturas hijas con `amount<=0`, antes saltadas silenciosamente).
- **Reset idempotente** de campos de pago en `rejectPayment` (incluye `payment_status`, `payment_authorized_by`, `payment_authorized_date`).
- **`buildSyntheticPayments` extraído** del controller al servicio.
- **`deleteDocument` ahora valida pertenencia** del documento al refund (anti-IDOR) cuando `$refundId` se pasa.
- **Reglas FK adicionales** en `RefundsTable::buildRules` (`existsIn` para 6 FKs).
- Template: campo oculto `expected_status` agregado al modal de regresión.

---

## Hallazgos por severidad

### 🔴 Critical (1)

#### CR-001 — Redirect descartado en branch de validación

**Ubicación:** `src/Controller/RefundsController.php:297-299`
**Categoría:** Bug

El branch "accrual_date requerido cuando accrued=true" llama `$this->redirect(...)` pero no lo retorna; luego hace `return;`. En CakePHP 5 sólo el valor retornado por la acción dispara la redirección — el método continúa la ejecución sin las variables de la vista, provocando renderizado fallido (`$record`/`$availableInvoices`/etc. no seteadas, o lógica de carga posterior no ejecutada).

**Resultado:** error en la vista al intentar acceder a variables no definidas, o renderizado parcial inconsistente.

**Recomendación:** usar `return $this->redirect(['action' => 'edit', $id]);` (eliminar el `return;` posterior).

---

### 🟠 Major (5)

#### MA-001 — MIME spoofing en upload (heredado de DocumentUploadTrait)

**Ubicación:** `src/Service/Trait/DocumentUploadTrait.php:47` (consumido por `RefundDocumentService::uploadDocument`)
**Categoría:** Security

La validación de tipo usa `$file->getClientMediaType()`, que es el header `Content-Type` enviado por el cliente y es trivialmente falsificable (un atacante puede subir un `.php` o `.exe` declarando `application/pdf`). Además la validación se hace por MIME pero se preserva la **extensión del nombre original** vía `pathinfo($originalName, PATHINFO_EXTENSION)`, **sin lista blanca**: un archivo nombrado `evil.phtml` con `Content-Type: image/png` pasa el filtro y queda en disco con extensión `.phtml`. Si Apache está configurado para ejecutar `.phtml` (o `.php`, `.shtml`, `.svg` con XSS, etc.) en `webroot/uploads`, hay RCE potencial.

**Recomendación:**
1. Validar MIME real con `finfo_open(FILEINFO_MIME_TYPE)` sobre el archivo movido, no con el client header.
2. Aplicar lista blanca de extensiones (`pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx`) y normalizar a minúsculas.
3. Verificar que `webroot/uploads/` tenga `.htaccess` con `php_flag engine off` o servido fuera de webroot.

(Aplica también a otros módulos que usan el trait — fuera del scope de este diff pero crítico globalmente.)

#### MA-002 — Extensión controlada por cliente

**Ubicación:** `src/Service/Trait/DocumentUploadTrait.php:58-60`
**Categoría:** Security (path/extension)

`pathinfo($originalName, PATHINFO_EXTENSION)` confía 100% en la extensión enviada por el cliente. Combinado con MA-001, un nombre `payload.php` con MIME `image/png` produce un archivo `rf_xxxx.php` en disco. Aunque `uniqid()` no permite path traversal en sí, la extensión sí queda controlada por el atacante.

**Recomendación:** construir extensión únicamente desde el MIME validado (mapa MIME→ext). Sanitizar/normalizar a minúsculas y aplicar lista blanca.

#### MA-003 — TOCTOU en validación "tiene al menos una factura"

**Ubicación:** `src/Service/RefundService.php:140-153, 311-322`
**Categoría:** Concurrencia

El check de RBAC (`_canOperate`) y la verificación "tiene al menos una factura" se hacen **fuera** del bloque transaccional con `FOR UPDATE`. Entre ese check y el lock interno, otra request podría desvincular las facturas o cambiar el estado. La revalidación TOCTOU interna chequea `status`, pero NO el conteo de facturas hijas. Por ello el avance puede ejecutarse contra un set de hijas vacío si una request concurrente ejecutó `removeInvoice`.

**Recomendación:** mover el conteo de facturas dentro del callback transaccional, después del `FOR UPDATE`, y abortar si vacío.

#### MA-004 — Pérdida de errores específicos en authorizePayment

**Ubicación:** `src/Service/RefundService.php:572-585`
**Categoría:** Bug / Diagnóstico

Cuando un `save()` interno (de `invoicePayment` o `invoice`) falla, la closure retorna `false`. Cake revierte la transacción, pero `transactional()` retorna `false`, no un `ServiceResult`. La rama `if ($result instanceof ServiceResult)` cae al fallback `ServiceResult::fail('No se pudo autorizar el pago.')` — el mensaje es genérico y se pierden los `getErrors()` específicos de la entidad fallida (banking_entity FK inválido, payment_date fuera de rango, etc.). Difícil de diagnosticar en producción.

**Recomendación:** antes de `return false`, capturar `$invoice->getErrors()` o `$invoicePayment->getErrors()` y retornar `ServiceResult::fail($detailedMessage)`. Coherente con el patrón usado en `registerPayment` (líneas 457-469).

#### MA-005 — Brecha potencial RBAC módulo en upload/delete

**Ubicación:** `src/Controller/RefundsController.php:650-721, 723-767`
**Categoría:** Authorization

`uploadDocument`/`deleteDocument` chequean `_canOperateRefundStep($record->status)` y bloquean en `pagado`, pero **no requieren explícitamente el permiso de módulo** `refunds.create`/`refunds.delete`. Si un rol tiene `pipeline_permissions` para el step pero el módulo `refunds` está marcado `can_view=true, can_edit=false, can_delete=false` en `permissions`, el usuario podría subir/eliminar soportes. La política RBAC de SGI documenta que las acciones se mapean a `can_view/can_create/can_edit/can_delete` (CLAUDE.md). El `AppController::beforeFilter` enforcement por módulo aplica vía `$controllerModuleMap`, pero falta verificar si `uploadDocument` y `deleteDocument` están en la lista de acciones que requieren `can_create`/`can_delete` o si se rigen por `can_edit` por defecto.

**Recomendación:** auditar el mapeo acción→permiso en `AppController::_enforcePermission`. Documentar explícitamente que `uploadDocument` requiere `can_edit` (o `can_create`) y `deleteDocument` requiere `can_delete`, además del check de pipeline.

---

### 🟡 Minor (10)

| ID | Categoría | Ubicación | Issue | Recomendación |
|---|---|---|---|---|
| MI-001 | Convención SGI | `src/Service/RefundService.php` (general) | Las firmas reciben `int $roleId, string $roleName` aunque `PipelineAuthorizationService::canOperate` documenta `$roleName` como "no consultado tras cleanup 2026-05-02". Mantener `$roleName` solo "compat" agrega ruido en 4 firmas públicas. | Eliminar `$roleName` del contrato del servicio (mantener solo `int $roleId`). |
| MI-002 | Bug menor (race) | `src/Service/RefundService.php:441-447` | La validación `abs($paymentAmount - $totalAmount) > 0.01` usa `$record->total_amount`, calculado vía `GroupedInvoiceService::calculateAndSaveTotal()` con `SUM(amount)`. Si una factura hija fue editada entre el cálculo y este check, el total puede estar desfasado. | Aceptar el desfase (ya hay revalidación en `authorize`), o recalcular `SUM(amount)` también acá dentro del lock. |
| MI-003 | Readability / SRP | `src/Service/RefundService.php` | El servicio tiene 14 métodos públicos y mezcla pipeline (advance/regress), pagos (register/authorize/reject), agregación, helpers de vista (`buildSyntheticPayments`, `getRegressionLockMessage`), políticas (`canDelete`, `canRegress`, `getVisibleStatuses`). Tendencia a "God Service". | Considerar extraer `RefundPaymentService` (register/authorize/reject) — análogo a `InvoicePaymentService` que ya existe en el proyecto. |
| MI-004 | Code Smell (duplicación) | `src/Service/RefundService.php:184, 560` | `date('Y-m-d')` aparece 2 veces como "today". El proyecto ya usa `Cake\I18n\Date` y `\DateTimeImmutable` en otros sitios. | Centralizar como helper o inyectar Clock. |
| MI-005 | Encapsulation | `src/Service/RefundService.php:259-275` | `buildSyntheticPayments` retorna `(object)[...]` (stdClass cast). Rompe el tipado fuerte y pierde IDE-completion. Si el view element espera ciertos campos, refactor en una de las propiedades silenciosamente rompe la vista. | Crear un Value Object liviano (DTO/readonly class) `RefundSyntheticPayment` con propiedades tipadas. |
| MI-006 | Bug menor (sentinela) | `src/Service/RefundService.php:175-182` | El mensaje del error TOCTOU se devuelve dentro del transactional como un array `['success'=>false, 'error'=>...]`. Cake `transactional()` interpreta cualquier valor truthy como commit. El array truthy se commitea aunque sea un caso de error — sin cambios a perder, pero el patrón confunde. | Para casos de error TOCTOU, retornar `false` para forzar rollback explícito y traducir afuera. |
| MI-007 | Style / DRY | `src/Controller/RefundsController.php:655-665, 728-738` | Bloque "isPagado → JSON o Flash" se repite en `uploadDocument` y `deleteDocument`. Mismo patrón con `_canOperateRefundStep`. | Extraer un helper `_documentGate(Refund $record, string $actionLabel): ?Response` que retorne null si pasa o el response de error. |
| MI-008 | Style | `src/Controller/RefundsController.php:359` | `$advanced ? [] : [$id]` con spread + ternary es legible pero inusual. | Usar `if/else` explícito o helper `_redirectAfterEdit($advanced, $id)`. |
| MI-009 | DDD / Encapsulation | `src/Model/Entity/Refund.php:46-65` | La entidad expone `isAgrupacion/Contabilidad/Tesoreria/Pagado`, pero las decisiones de transición viven en el servicio. `Refund::canTransitionTo($status)` o `Refund::isInPaymentPhase()` reducirían los `if ($record->status === ...)` dispersos. | Empujar reglas de invariante al entity (e.g. `canBeDeleted()`, `requiresPaymentReason()`). |
| MI-010 | Defensa | `src/Service/RefundDocumentService.php:38-46` | El parámetro `?int $refundId = null` con comentario "caso legacy" es una bomba de tiempo: cualquier futuro caller sin `$refundId` rompe el aislamiento IDOR. El controller siempre pasa `$refundId` actualmente. | Hacer el parámetro requerido (no nullable). |

---

### 🟢 Suggestions (7)

| ID | Categoría | Ubicación | Sugerencia |
|---|---|---|---|
| SU-001 | Validación manual | `RefundService::advanceStatus` y `regress` | Criterios manuales: (1) abrir 2 pestañas con el mismo refund en `contabilidad`, ejecutar advance simultáneamente; verificar que la 2ª request retorne "El registro fue modificado por otro usuario". (2) En `tesoreria`, registrar pago, regresar — debe ser bloqueado por `getRegressionLockMessage`. |
| SU-002 | Performance | `RefundService::authorizePayment` | El loop sobre `$childInvoices` hace `save()` por cada factura individualmente (N queries de update + N de insert). Para reintegros con muchas facturas, considerar `saveMany` o `updateAll` para el cambio de pipeline_status, dejando solo los `invoicePayments` en loop. |
| SU-003 | Performance / Index | `src/Service/RefundService.php:530, 802` | `where(['refund_id' => ...])` sobre `invoices`. Verificar que `invoices.refund_id` tiene índice en BD. |
| SU-004 | Auditoría | `RefundService::registerPayment, authorizePayment, rejectPayment` | A diferencia de `InvoicePaymentService` (que persiste historial vía `InvoiceHistoryService`), las acciones de pago de Refund no escriben audit trail propio (solo en regress). Considerar persistir cambios de status en una tabla `refund_histories` (paralelo a `invoice_histories` y `petty_cash_histories` recién creados). |
| SU-005 | Coherencia | `src/Service/RefundDocumentService.php` | A diferencia de `InvoiceDocumentService`, no hay método `canDeleteDocument(document, status)` (la decisión vive en el controller con `isPagado()`). Mover esa decisión al servicio centraliza la política. |
| SU-006 | Convención | `src/Controller/RefundsController.php:39-42` | `_getCurrentUser(): object` es genérico. Cuando se haga el cleanup de `$roleName` (MI-001), considerar pasar la entidad `User` directamente. |
| SU-007 | HTTP | `RefundsController::_jsonResponse` | Siempre retorna 200 — no se diferencian errores 400/403/404 a nivel HTTP. Considerar `withStatus()` para AJAX. |

---

## Tabla resumen por categoría

| Categoría | 🔴 | 🟠 | 🟡 | 🟢 | Total |
|---|---|---|---|---|---|
| Security (Upload/IDOR/RBAC) | 0 | 3 | 1 | 0 | 4 |
| Bug | 1 | 1 | 1 | 0 | 3 |
| Concurrencia / Transacciones | 0 | 1 | 1 | 1 | 3 |
| Performance | 0 | 0 | 0 | 2 | 2 |
| Readability / Style | 0 | 0 | 3 | 1 | 4 |
| Encapsulation / DDD | 0 | 0 | 2 | 0 | 2 |
| Convenciones SGI | 0 | 0 | 1 | 1 | 2 |
| Audit trail | 0 | 0 | 0 | 1 | 1 |
| Defensa / Hardening | 0 | 0 | 1 | 0 | 1 |
| **Total** | **1** | **5** | **10** | **7** | **23** |

---

## Match con la tarea

**Tarea esperada:** Auditoría del flujo de documentos de liquidación (reintegros) — upload/delete/asociación, transiciones de pipeline, RBAC por rol/estado, riesgos de seguridad (path traversal, MIME, IDOR, mass assignment), respuesta JSON, convenciones SGI.

**Match score:** **95%**

| Aspecto solicitado | Cobertura | Estado |
|---|---|---|
| 1. Carga/borrado/asociación de documentos | RefundDocumentService + uploadDocument/deleteDocument; MA-001/MA-002 (MIME/extensión), MI-010 (IDOR), MA-005 (RBAC módulo) | ✅ |
| 2. Transiciones y consistencia transaccional | advanceStatus/regress/registerPayment/authorizePayment/rejectPayment; MA-003 (TOCTOU), MA-004 (errores), MI-002 (race), MI-006 (sentinela) | ✅ |
| 3. Autorización por rol y por estado | `_canOperateRefundStep` correcto; MA-005 destaca brecha pendiente, doble enforcement (controller+service) confirmado | ✅ |
| 4. Riesgos seguridad (path/MIME/IDOR/mass-assign) | MA-001, MA-002, MI-010, mass-assignment correcto (tightening de `_accessible`) | ✅ |
| 5. Respuesta JSON / CSRF / códigos HTTP | Consistente con el resto del proyecto; CSRF gestionado por middleware Cake; SU-007 sobre códigos HTTP | ✅ |
| 6. Cumplimiento convenciones SGI | ServiceResult ✅, TableRegistry ✅, paginación 15 ✅, prefijo `_` ✅, sin tests ✅. MI-001, MI-003 | ✅ |
| 7. Coherencia con InvoicePipelineService / patrones | Mayormente coherente; SU-004 (audit trail), SU-005 (canDeleteDocument), MI-003 (split en RefundPaymentService) | ✅ |

**Sin cobertura en el diff (no requerido):**
- No se agregó audit trail propio (`refund_histories`).
- No se cubrió el resto de módulos que usan `DocumentUploadTrait` (MA-001/MA-002 son globales).

---

## Veredicto final

### ❌ REQUEST CHANGES

Los cambios endurecen significativamente el módulo (mass-assignment, locks pesimistas, validaciones de monto, anti-IDOR en delete), pero hay **1 bug Critical** que rompe el flujo de edición en estado contabilidad y **3 issues Major de seguridad** en el manejo de uploads (heredados del trait compartido pero igual aplican aquí) que deben atenderse antes del merge.

### Acciones requeridas (Critical + Major)

1. **CR-001** — Corregir el `return;` en `RefundsController.php:299`. Cambiar a `return $this->redirect(['action' => 'edit', $id]);` y eliminar la línea 299.
2. **MA-001 / MA-002** — Reemplazar `getClientMediaType()` por validación con `finfo` sobre el archivo en disco; aplicar lista blanca de extensiones derivada del MIME validado. Verificar que `webroot/uploads/` no permita ejecución PHP/scripts.
3. **MA-003** — Mover el conteo de "al menos una factura agrupada" dentro del callback transaccional con `FOR UPDATE`.
4. **MA-004** — Capturar `getErrors()` en los `save()` fallidos dentro del callback de `authorizePayment` y propagarlos vía `ServiceResult::fail($detail)`.
5. **MA-005** — Auditar manualmente el mapeo acción→permiso para `uploadDocument`/`deleteDocument` en `AppController::_enforcePermission`.

### Acciones recomendadas (próximo PR)

- MI-001: limpiar parámetro `$roleName` (4 firmas).
- MI-003: extraer `RefundPaymentService`.
- MI-005: tipar `buildSyntheticPayments` como readonly class.
- MI-010: hacer `$refundId` requerido en `RefundDocumentService::deleteDocument`.
- SU-004: agregar audit trail `refund_histories` consistente con petty cash/invoice.
- SU-005: mover `canDeleteDocument` al servicio.

### Criterios de validación manual

1. Crear refund, agregar 2 facturas con `amount` distinto, registrar pago con monto incorrecto en Tesorería → debe rechazar con mensaje claro.
2. En `aut_pago`, abrir dos pestañas, autorizar en la primera y luego en la segunda → segunda debe fallar con "no está en estado Autorización de Pago".
3. Subir un archivo `.txt` renombrado a `.pdf` con MIME `application/pdf` falsificable → confirmar que tras MA-001/MA-002 el sistema lo rechaza.
4. Como rol Tesorería, intentar `uploadDocument` cuando refund está en `contabilidad` → debe rechazar con 403/error JSON.
5. Como rol Contabilidad, marcar `accrued=true` sin `accrual_date` → debe redirigir a edit con flash error (CR-001 actualmente lo rompe).
6. Intentar borrar un documento de un refund A pasando `documentId` que pertenece al refund B → debe retornar `success: false`.
7. En estado `pagado`, intentar upload/delete de soportes → deben rechazarse con mensaje específico.
