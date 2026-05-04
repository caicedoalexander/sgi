# Auditoría — Flujo de Control de Facturas

**Proyecto:** Sistema de Gestión Interna (SGI)
**Stack:** CakePHP 5.3 / PHP 8.2+ / MySQL · Layered monolith · Spanish UI
**Modo:** PATH · **Nivel:** HIGH
**Fecha original:** 2026-05-04 · **Última actualización:** 2026-05-04 (post-fixes Critical + Major)
**Veredicto actual:** ✅ **APPROVE** (era ❌ REQUEST CHANGES → ⚠️ APPROVE WITH COMMENTS)

---

## 1. Resumen ejecutivo

Auditoría focalizada en el flujo de control de facturas: pipeline 5-state, políticas de campos editables y bloqueo, validación de transiciones, registro/autorización de pagos, aprobaciones internas y externas, e historial de cambios.

La arquitectura del pipeline está **muy bien diseñada** — separación clara de responsabilidades (`InvoicePipelineService` coordinador delgado + `StateRegistry` + `FieldAccessPolicy` + `LockPolicy` + `TransitionValidator` + `DocumentTypePolicyFactory`). Los hallazgos son mayoritariamente robustez transaccional y consistencia, no defectos estructurales.

| Severidad | Original | Resueltos | Pendientes |
|---|---:|---:|---:|
| 🔴 Critical | 3 | **3** ✅ | 0 |
| 🟠 Major | 8 | **8** ✅ (1 falso positivo + 7 fixes) | 0 |
| 🟡 Minor | 10 | 0 | 10 |
| 🟢 Suggestion | 6 | 0 | 6 |
| **Total** | **27** | **11** | **16** |

Critical y Major resueltos (plan `docs/plans/2026-05-04-invoice-flow-major-fixes.md`). Quedan solo Minor + Suggestion no-bloqueantes.

---

## 2. Alcance

**Archivos auditados (12, ~3.000 LOC):**

- `src/Controller/InvoicesController.php`
- `src/Service/InvoicePipelineService.php`
- `src/Service/InvoiceFieldAccessPolicy.php`
- `src/Service/InvoiceLockPolicy.php`
- `src/Service/InvoiceTransitionValidator.php`
- `src/Service/InvoicePaymentService.php`
- `src/Service/InvoiceApprovalService.php`
- `src/Service/InvoiceHistoryService.php`
- `src/Service/InvoiceDocumentService.php`
- `src/Service/InvoiceFilterService.php`
- `src/Service/ApprovalTokenService.php`
- `src/Service/Strategy/InvoiceApprovalStrategy.php`

**Verificaciones aplicadas (HIGH):**
- PSR + encapsulación + code smells
- Bug hunting (logic, null, boundary, races, exceptions, SQLi, type juggling)
- Readability (naming, length, nesting, magic values)
- SOLID
- Security (OWASP Top 10, especialmente A01/A03/A04/A07/A08)
- Performance (N+1, query efficiency, caching, batch processing)
- Testabilidad estructural (DI, side effects, pureza) — sin reportes de cobertura por política del proyecto
- DDD alignment (state machine, layering)
- Architecture (cohesión y duplicación entre los servicios `Invoice*`)

---

## 3. Mapa del flujo

| Componente | Responsabilidad |
|---|---|
| `InvoicesController` | Orquesta vista/edición/avance/regresión, sube/borra documentos, expone endpoints multi-aprobador (`sendApprovalLinks`, `modifyApprovers`, `resetFlow`). |
| `InvoicePipelineService` | Coordinador delgado. Delega a `InvoicePipelineStateRegistry`, `DocumentTypePolicyFactory`, `InvoiceLockPolicy`, `InvoiceTransitionValidator`, `InvoiceFieldAccessPolicy`. Implementa `saveAndAdvance`, `advance`, `regress`. |
| `InvoiceFieldAccessPolicy` | Mapeo `step → campos editables` y `step → sección`. Aplica `pipelineAuth` para autorización. |
| `InvoiceLockPolicy` | Bloqueos por petty cash y programación pagada (edición y regresión). |
| `InvoiceTransitionValidator` | Rejection + doctype block + state requirements; filtra errores resolubles por rol. |
| `InvoicePaymentService` | Registro/edición/rechazo/autorización de pagos. Recalcula `payment_status`. Idempotencia con `idempotency_key`. Avanza/regresa pipeline tras autorización. |
| `InvoiceApprovalService` | Multi-aprobador (`invoice_approvals`). Tokens 64-hex/48h. `processResponse` con `FOR UPDATE`. `modifyApprovers` invalida y reemplaza, `resetFlow` desbloquea rechazo. |
| `InvoiceHistoryService` | Audit field-by-field y status changes. Lista de campos rastreados hardcoded. |
| `InvoiceDocumentService` | Upload/delete de soportes; permite borrar solo en mismo `pipeline_status`. |
| `InvoiceFilterService` | Search + filtros + date range sobre listings. |
| `ApprovalTokenService` | Token único por entidad (legacy/genérico). SHA-bin → 64 hex. Strategy pattern (Invoice/Novelty). |
| `InvoiceApprovalStrategy` | Aplica `approve`/`reject` externo invocando `pipeline->saveAndAdvance` o seteando rejection directo. |

---

## 4. Findings

### 🔴 Critical — RESUELTOS (3/3)

| ID | Ubicación | Estado | Fix aplicado |
|---|---|---|---|
| **CR-001** | `src/Service/Strategy/InvoiceApprovalStrategy.php:46-56` | ✅ Resuelto | Reordenados los argumentos de `saveAndAdvance(invoice, data, roleId, roleName, userId)`. Helper `_getAdminRoleId()` resuelve el id real del rol admin desde la tabla `Roles` con cache en propiedad. Antes: `TypeError` bajo `strict_types=1` por orden incorrecto. |
| **CR-002** | `src/Service/ApprovalTokenService.php:104-153` (`consumeToken`) | ✅ Resuelto | Envuelto en `$table->getConnection()->transactional()` con `find()->epilog('FOR UPDATE')`. Validación de `expires_at` añadida. El `strategy->apply()` queda fuera de la TX (correcto: side-effects largos). El token se marca consumido atómicamente. |
| **CR-003** | `src/Controller/InvoicesController.php:417-498` (`delete`, `regressStatus`) | ✅ Resuelto | `delete`: rechaza si `pipeline_status != aprobacion` o si hay `invoice_payments`. `regressStatus`: agregado `_ensureExpectedStatus`. `deleteDocument` confirmado como falso positivo (cubierto por `_enforcePermission` global). |

**Diff aplicado:** 3 archivos · +70 / −18 líneas.

### 🟠 Major — RESUELTOS (7/7 + 1 falso positivo)

| ID | Ubicación | Estado | Fix aplicado |
|---|---|---|---|
| **MJ-001** | `src/Service/InvoicePaymentService.php` (`authorizePayment`) | ✅ Resuelto | Guards `if (!$this->recalculatePaymentStatus(...)) return false` y `if (!$invoicesTable->save($invoice)) return false` dentro del closure de `transactional`. |
| **MJ-002** | `src/Service/InvoicePaymentService.php` (`rejectPayment`) | ✅ Resuelto | Rama no-refund envuelta en `transactional()` con guards en ambos saves; comentario obsoleto eliminado. |
| **MJ-003** | `src/Service/InvoicePipelineService.php` | ✅ Resuelto | Captura `$intermediateStatus = $advanceNextStatus` antes de reasignar y se usa en `recordStatusChange` (en vez del literal `STATUS_PAGADA`). |
| **MJ-004** | `src/Controller/InvoicesController.php` (`_buildInvoiceQuery`) | ✅ Resuelto | Subquery parametrizada con `:uidJoin`/`:uidWhere` vía `bind('integer')`. Firma cambiada a `int $userId = 0` con guard `> 0`. |
| ~~MJ-005~~ | ~~`deleteDocument`~~ | ✅ Falso positivo | Cubierto por `_enforcePermission` global vía `_actionToPermission`. |
| **MJ-006** | `src/Service/InvoiceApprovalService.php` (`processResponse`) | ✅ Resuelto | `InvoiceHistoryService` inyectado en constructor (nullable + `??`). `recordFieldChange('area_approval', PENDING → REJECTED/APPROVED)` registrado en ambas ramas dentro de la TX. |
| **MJ-007** | `src/Service/InvoiceApprovalService.php` | ✅ Resuelto | `_persistApprovers` y `_sendApprovalEmails` cambiados a `private`. |
| **MJ-008** | `src/Controller/InvoicesController.php` (`_getApprovalSummaries`) | ✅ Resuelto | Nuevo `InvoiceApprovalService::getApprovalSummariesBatch(array)` con 1 query `IN (...)` + helper privado `_summaryFromApprovals`. Controller pasa de O(N) queries a 1. |

### 🟡 Minor — Pendientes (10)

| ID | Ubicación | Issue |
|---|---|---|
| MN-001 | `InvoicesController:740-746` vs `InvoicePipelineService::STATUS_LABELS` | `STATUS_LABELS` duplicado en controller y service ("Aut. Pago"). Consolidar. |
| MN-002 | `InvoicesController::edit:220-382` | 162 líneas, 6 servicios, 25 variables `compact()`. Extraer `InvoiceEditViewModel`. |
| MN-003 | `InvoicePipelineService:415-420` (`regress`) | Magic numbers `10`/`500` en validación de motivo. Mover a `InvoiceConstants::REGRESS_REASON_MIN/MAX`. |
| MN-004 | `InvoiceApprovalService:25` | `private $invoiceApprovalsTable;` sin tipo. Añadir `\Cake\ORM\Table`. |
| MN-005 | `InvoicePaymentService:208-214` | `idempotency_key` sin filtrar por `invoice_id`. Riesgo teórico de colisión cruzada. |
| MN-006 | `InvoicePaymentService:55-65, 87` | Comparación de `amount` con `float`. Riesgo de imprecisión de centavos. Migrar a int (centavos) o `bcmath`. |
| MN-007 | `InvoicePipelineService::saveAndAdvance:257-263` | Auto-set de `area_approval_date` debería vivir en la entidad `Invoice` (Tell Don't Ask). |
| MN-008 | `InvoiceApprovalService` | `assignApprovers` vs `sendApprovalLinks` con naming confuso (uno alias del otro). |
| MN-009 | `InvoicesController::edit` | Long method (cubierto por MN-002). |
| MN-010 | `InvoicesController:661,691` | `(array)$this->request->getData('approver_ids')` sin sanitización. Aplicar `array_map('intval', ...)`. |

### 🟢 Suggestions — Pendientes (6)

| ID | Sugerencia |
|---|---|
| SG-001 | Modelar la state machine como métodos en la entidad `Invoice` (`canAdvance()`, `advance()`). Ya hay base con `Invoice::isRejected()`. |
| SG-002 | `validateTransitionRequirements` con array de overrides en lugar de `clone $invoice + patchEntity` (duplica trabajo). |
| SG-003 | `InvoicePaymentService::authorizePayment` — usar `StructuredLogger` con correlation ID. |
| SG-004 | Documentar plan de deprecación de `ApprovalTokenService` legacy a favor de `InvoiceApprovalService` multi-aprobador. |
| SG-005 | `InvoiceLockPolicy::isLockedByPaidScheduling` se llama 2-3× por request. Cachear por request. |
| SG-006 | `_invoiceDocumentLabels` extraer a `InvoiceConstants` (cubierto por MN-001). |

---

## 5. Category Summary

| Categoría | 🔴 | 🟠 | 🟡 | 🟢 | Total | Pendientes |
|---|---:|---:|---:|---:|---:|---:|
| Security (Access Control / Injection / Auth) | 2 ✅ | 1 ✅ (MJ-004) | 0 | 0 | 3 | 0 |
| Bug / Lógica de pipeline | 1 ✅ | 3 ✅ (MJ-001/002/003) | 2 | 0 | 6 | 2 |
| Performance | 0 | 1 ✅ (MJ-008) | 0 | 2 | 3 | 2 |
| Encapsulamiento / DDD | 0 | 1 ✅ (MJ-007) | 4 | 2 | 7 | 6 |
| Arquitectura / DRY / Cohesión | 0 | 1 ✅ (MJ-006) | 2 | 2 | 5 | 4 |
| Readability / Naming | 0 | 0 | 2 | 0 | 2 | 2 |
| **Total** | **3 ✅** | **7 ✅** | **10** | **6** | **26** | **16** |

---

## 6. Task Match Analysis — 100 % (era 85 % → 96 %)

| Característica esperada | Estado actual | Status |
|---|---|---|
| Pipeline 5-state | `InvoicePipelineService::TRANSITIONS` | ✅ |
| Bloqueo por rejection (`area_approval='Rechazada'`) | `InvoiceTransitionValidator::validateAdvance:45` | ✅ |
| Reset desde Registro/Revisión | `InvoiceApprovalService::resetFlow` | ✅ |
| Campos editables por rol/estado | `InvoiceFieldAccessPolicy::FIELDS_BY_STEP` + `pipelineAuth` | ✅ |
| Pago parcial → regresión a `tesoreria` | `InvoicePipelineService:303-318` (MJ-003 resuelto) | ✅ |
| `registerPayment` siempre avanza a `autorizacion_pago` | `InvoicePaymentService:259-267` | ✅ |
| `editPayment` requiere motivo | `InvoicePaymentService:355-356` | ✅ |
| `rejectPayment` persiste motivo y devuelve a `tesoreria` | `InvoicePaymentService` (MJ-002 resuelto: TX en ambas ramas) | ✅ |
| Aprobación externa vía token (48h, 64-hex) | `ApprovalTokenService` + `InvoiceApprovalService` | ✅ (era ❌ — CR-001/CR-002 resueltos) |
| Historial campo-a-campo | `InvoiceHistoryService::recordChanges` | ✅ |
| `modifyApprovers` requiere motivo | `InvoiceApprovalService:371-373` | ✅ |
| Rechazo dispara historial | `processResponse` registra `area_approval` change vía `recordFieldChange` (MJ-006 resuelto) | ✅ |
| Multi-aprobador con TOCTOU prevention | `processResponse` con `FOR UPDATE` ✅ + `consumeToken` con `FOR UPDATE` ✅ | ✅ (era ⚠️ — CR-002 resuelto) |
| `delete` protege facturas en estados avanzados | `InvoicesController::delete:468-486` | ✅ (era ❌ — CR-003 resuelto) |

---

## 7. Verdict

### ✅ APPROVE *(antes ❌ REQUEST CHANGES → ⚠️ APPROVE WITH COMMENTS)*

**Mergeable.** Los 3 Critical y los 7 Major están resueltos. Quedan solo 10 Minor + 6 Suggestion no-bloqueantes (mejoras oportunistas).

- Aprobación externa legacy operativa (CR-001).
- Doble-approve por race en token legacy bloqueado (CR-002).
- `delete` y `regressStatus` con guards apropiados (CR-003).
- Robustez TX en autorización y rechazo de pagos (MJ-001/002).
- Historial fiel en transiciones y aprobaciones (MJ-003/006).
- Subquery parametrizada (MJ-004).
- N+1 en listing de aprobaciones eliminado (MJ-008).
- Convención de visibilidad respetada (MJ-007).

**Notas constructivas:**
- La separación `InvoicePipelineService` (coordinador delgado) + `StateRegistry` + `FieldAccessPolicy` + `LockPolicy` + `TransitionValidator` + `DocumentTypePolicyFactory` es de alta calidad — modela bien la state machine.
- La idempotencia con UUID + retry sobre código 23000 en `registerPayment` está bien implementada.
- TX wrapping con `transactional()` mayormente consistente — los huecos se cierran con MJ-001/002.

---

## 8. Validación manual sugerida (para los 3 Critical ya aplicados)

1. **Aprobación externa legacy** (CR-001): generar un token de invoice con `ApprovalTokenService::generateToken('invoices', $id, $userId)`, abrir `/external/{token}` y aprobar. Antes lanzaba `TypeError`; ahora debe avanzar la factura y registrar historial con el `userId` real.
2. **Race en consumeToken** (CR-002): un segundo POST con el mismo token debe devolver `false` y no disparar la strategy de nuevo.
3. **Delete bloqueado** (CR-003): intentar borrar una factura en `tesoreria` o con pagos → flash de error y redirect a `view`.
4. **regressStatus stale** (CR-003): abrir 2 pestañas en `edit`, regresar en una y luego intentar regresar en la otra → "El registro cambió de estado".
