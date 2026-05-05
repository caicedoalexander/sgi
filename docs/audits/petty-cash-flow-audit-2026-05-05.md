# Auditoría — Flujo de Caja Menor

**Fecha:** 2026-05-05
**Modo:** PATH
**Nivel:** HIGH
**Branch:** `main`
**Archivos revisados:** 15 (controller, service, constants, entities, tables, templates, element)

---

## Resumen del flujo

**Caja Menor (CM)** consolida facturas tipo `caja_menor` (que ya pasaron flujo individual hasta `contabilidad`) en un registro pagable único, dispara un solo pago bancario para todas, y al autorizar materializa `invoice_payments` por factura hija.

**Pipeline de 5 estados:**

```
agrupacion → contabilidad → tesoreria → aut_pago → pagado
```

**Actores y flujo:**
- **Registro/Revisión** (`agrupacion`): crea el registro, vincula/desvincula facturas, edita notas.
- **Contabilidad** (`contabilidad`): edita `accrued`, `accrual_date`, `ready_for_payment`, notas.
- **Tesorería** (`tesoreria`): registra un pago pendiente (banking_entity, amount, date) — el registro avanza automáticamente a `aut_pago`.
- **Contador** (`aut_pago`): autoriza o rechaza el pago. Al autorizar, materializa `invoice_payments` para cada factura hija, marca facturas como `pagada` con `payment_status = Pago total`, y avanza el registro a `pagado`. Al rechazar, vuelve a `tesoreria` con `payment_rejection_reason`.
- **Regresión:** cualquier estado salvo `agrupacion` y `pagado` permite volver al anterior, con motivo obligatorio (10–500 chars). Bloqueado de `tesoreria` si hay `payment_amount` pendiente.

**Patrones particulares:**
- Pago **único por registro**, almacenado como columnas en `petty_cash_records`. Al autorizar, se **expanden** a filas individuales de `invoice_payments` por factura hija.
- Centralización vía `GroupedInvoiceService` (compartido con Refunds).
- `pipelineAuth` (matriz `pipeline_permissions`) regula autorización fina por estado pero solo se consulta en vistas y para regresar; **no** para avance ni para pagos.

---

## Hallazgos por severidad

### 🔴 Critical (2)

#### CR-001 — Falta autorización por rol en endpoints de pago (RBAC bypass)

- **Archivo:** `src/Service/PettyCashService.php:275-431`, `src/Controller/PettyCashRecordsController.php:413-477`
- **Descripción:** `registerPayment`, `authorizePayment` y `rejectPayment` **solo validan el estado del registro**, no verifican si el rol que invoca tiene permiso de operar el paso correspondiente vía `PipelineAuthorizationService::canOperate(roleId, roleName, PIPELINE_PETTY_CASH, $step)`.
- **Vector:** Un rol con `petty_cash.can_edit = true` pero sin permiso de operar `tesoreria` o `aut_pago` puede llamar:
  - `POST /petty-cash-records/register-payment/{id}` — registra un pago si el registro está en `tesoreria` (avanzando a `aut_pago`).
  - `POST /petty-cash-records/authorize-payment/{id}` — autoriza pagos y materializa `invoice_payments` aunque no sea Contador.
  - `POST /petty-cash-records/reject-payment/{id}` — rechaza pagos.
- **Impacto:** Pago no autorizado por el rol correcto. Compromete trazabilidad financiera y el modelo de aprobación dual.
- **Sugerencia:** Llamar `pipelineAuth->canOperate($roleId, $roleName, PIPELINE_PETTY_CASH, STATUS_TESORERIA)` al inicio de `registerPayment` y `STATUS_AUT_PAGO` al inicio de `authorizePayment`/`rejectPayment`. Devolver `ServiceResult::fail` si no tiene permisos.

#### CR-002 — `delete()` no es transaccional: deja facturas huérfanas si la eliminación del registro falla

- **Archivo:** `src/Controller/PettyCashRecordsController.php:479-504`
- **Descripción:** El controlador hace `Invoices.updateAll(petty_cash_record_id = NULL, ...)` y luego `$this->PettyCashRecords->delete($record)` sin transacción. Si el delete falla (FK pendiente, callback que aborta), las facturas quedan desvinculadas pero el record sigue existiendo.
- **Impacto:** Estado inconsistente; potencial pérdida del cálculo de `total_amount`; doble agrupación.
- **Sugerencia:** Mover toda la lógica a un método `PettyCashService::deleteRecord(PettyCashRecord $record): ServiceResult` que envuelva ambas operaciones en `$connection->transactional()`.

---

### 🟠 Major (8)

| # | Archivo | Problema | Fix |
|---|---|---|---|
| MA-001 | `PettyCashService.php:121-203` | `advanceStatus` no valida `canAdvance` por rol server-side | Añadir verificación contra `pipelineAuth` antes de la transacción |
| MA-002 | `PettyCashRecordsController.php:189-357` | Lógica de dominio en controller (170 líneas en `edit()`) | Crear `saveAndAdvance()` en servicio + `PettyCashEditViewModel` |
| MA-003 | `PettyCashRecordsTable.php:77-113` | Falta `$validator->date('payment_date')` (sí existe en `RefundsTable`) | Añadir regla de validación |
| MA-004 | `PettyCashService.php:275-313` | `payment_amount` puede ser negativo o ≠ `total_amount` (`forceFullAmount` solo en UI) | Validar/forzar igualdad server-side |
| MA-005 | `PettyCashService.php:342-390` | Facturas hijas con `amount <= 0` se omiten silenciosamente, registro queda `pagado` igual | Marcar como `pagada` sin crear `invoice_payment` o validar al ingreso |
| MA-006 | `PettyCashService.php:233-255` | `_validateTransition` con `case` vacíos para `agrupacion` y `tesoreria` | Documentar o eliminar ruido |
| MA-007 | `PettyCashRecordsTable.php:115-121` | Falta `existsIn('operation_center_id', 'OperationCenters')` | Añadir buildRule |
| MA-008 | `PettyCashRecordsController.php:149-187` | `add()` descarta `$record->getErrors()` | Pasar errores a la vista |

---

### 🟡 Minor (10)

- **MI-001** — `index.php:8` y `view.php:10`: `$statusBadge` no incluye `aut_pago` (cae a `bg-dark`).
- **MI-002** — Cientos de `style="..."` inline en plantillas, contradiciendo el sistema de diseño.
- **MI-003** — Falta `PettyCashRecord::isAutPago()` (los demás estados sí lo tienen).
- **MI-004** — `removeInvoice` filtra info del estado vía mensaje de error.
- **MI-005** — `_getCurrentUser()` retorna `object` en vez de `User`.
- **MI-006** — Construcción de label de `<option>` duplicada entre `add.php` y `edit.php`.
- **MI-007** — `view.php:151` compara contra strings literales (`'aprobacion'`, `'pagada'`) en vez de `InvoiceConstants::STATUS_*`.
- **MI-008** — `ROLE_VISIBLE_STATUSES[ADMIN]` excluye `pagado` sin documentar.
- **MI-009** — Validación de motivo de regresión duplicada (servicio + tabla).
- **MI-010** — `PettyCashService` mezcla retornos: unos métodos devuelven `ServiceResult`, otros `array`. CLAUDE.md exige `ServiceResult` consistente.

---

### 🟢 Suggestions (8)

- **SU-001** — Migrar a State pattern como `InvoicePipelineService` (`Service/Pipeline/State/`).
- **SU-002** — Crear `PettyCashEditViewModel` (paridad con `InvoiceEditViewModel`, commit `be5a4e8`).
- **SU-003** — Centralizar `PettyCashConstants::STATUS_BADGES`.
- **SU-004** — Tabla `petty_cash_histories` (hoy solo hay observaciones de regresión).
- **SU-005** — Limitar/paginar `getAvailableInvoices` (carga todas las facturas).
- **SU-006** — Documentar mejor el grafo de `BACKWARD_TRANSITIONS`.
- **SU-007** — `payment_section` recibe `forceFullAmount = true` pero servicio no lo enforca (ver MA-004).
- **SU-008** — Mostrar bloque de pago y `rejection_reason` también en `view.php`.

---

## Resumen por categoría

| Categoría | 🔴 | 🟠 | 🟡 | 🟢 | Total |
|---|---|---|---|---|---|
| Security / RBAC | 1 | 1 | 1 | 0 | 3 |
| Bug / Consistencia | 1 | 3 | 0 | 1 | 5 |
| Architecture / SRP | 0 | 1 | 0 | 2 | 3 |
| Validation | 0 | 3 | 1 | 1 | 5 |
| Readability / Style | 0 | 0 | 4 | 1 | 5 |
| Auditoría / Historial | 0 | 0 | 0 | 1 | 1 |
| Performance | 0 | 0 | 0 | 1 | 1 |
| API / ServiceResult | 0 | 0 | 1 | 0 | 1 |
| UX | 0 | 0 | 3 | 1 | 4 |
| **Total** | **2** | **8** | **10** | **8** | **28** |

---

## Análisis de paridad vs flujos hermanos

| Aspecto | Facturas | Novedades | Refunds | **Caja Menor** | Brecha |
|---|---|---|---|---|---|
| Pipeline service usa State pattern | ✅ | ❌ | ❌ | ❌ | OK (consistente con sister) |
| `canAdvance` server-side via `pipelineAuth` | ✅ | ✅ | ❌ | ❌ | **GAP** |
| `canOperate` en `register/authorize/reject` payment | ✅ | N/A | ❌ | ❌ | **GAP** |
| Retorno `ServiceResult` consistente | ✅ | ✅ (mostly) | ❌ mezclado | ❌ mezclado | **GAP** |
| Historial de cambios (tabla dedicada) | ✅ `invoice_histories` | ✅ `novelty_histories` | ❌ solo observations | ❌ solo observations | **GAP** |
| `_ensureExpectedStatus` (concurrencia) | ✅ | ✅ | ✅ | ✅ | OK |
| ViewModel para edit | ✅ `InvoiceEditViewModel` | ❌ | ❌ | ❌ | **GAP (común)** |
| `payment_section` shared element | ✅ | ✅ | ✅ | ✅ | OK |
| Observations con tipo | ❌ | ❌ | ✅ | ✅ | OK |
| `BACKWARD_TRANSITIONS` constante | ❌ inline | ❌ inline | ✅ | ✅ | OK |
| `forceFullAmount` enforce server-side | ✅ | N/A | ❌ | ❌ | **GAP (común)** |
| Validador `date('payment_date')` en table | ✅ | ✅ | ✅ Refunds | ❌ | **GAP** |

**Conclusión:** Caja Menor es prácticamente clon de **Refunds** (comparten `GroupedInvoiceService`). Las brechas Critical/Major (CR-001, MA-001, MA-004) son **sistémicas en el módulo de pagos agrupados**, no exclusivas de Caja Menor — corregirlas exige refactor paralelo en Refunds.

---

## Veredicto

### ❌ REQUEST CHANGES

El flujo es funcional y respeta el patrón del proyecto, pero **CR-001** (RBAC bypass en endpoints de pago) y **CR-002** (delete no transaccional) deben resolverse antes de production-grade. Adicionalmente **MA-001/MA-003/MA-004/MA-007** son brechas de validación financiera que no deberían quedar en backlog.

### Acciones requeridas (Critical/Major)

1. **CR-001** — `pipelineAuth->canOperate` en `registerPayment`, `authorizePayment` y `rejectPayment`. Replicar fix en `RefundService`.
2. **CR-002** — `PettyCashService::deleteRecord()` transaccional.
3. **MA-001** — `canAdvance(roleId, roleName, status)` consultado en `advanceStatus`.
4. **MA-003** — `$validator->date('payment_date')` en `PettyCashRecordsTable`.
5. **MA-004** — Validar/forzar `payment_amount === total_amount` en `registerPayment`.
6. **MA-007** — `existsIn('operation_center_id', 'OperationCenters')` en `buildRules`.
7. **MA-002, MA-005, MA-006, MA-008** — Refactor a service layer, manejo de facturas con `amount = 0` y propagación de errores de validación.

### Acciones recomendadas (post-merge)

- Centralizar `STATUS_BADGES` en constantes.
- Añadir `isAutPago()` a la entidad.
- Normalizar todos los retornos de `PettyCashService` a `ServiceResult`.
- Considerar tabla `petty_cash_histories` para auditoría completa.
- Crear `PettyCashEditViewModel` para descargar el controlador.

---

## Archivos revisados

**Backend:**
- `src/Controller/PettyCashRecordsController.php`
- `src/Service/PettyCashService.php`
- `src/Service/PettyCashDocumentService.php`
- `src/Constants/PettyCashConstants.php`
- `src/Model/Entity/PettyCashRecord.php`
- `src/Model/Entity/PettyCashDocument.php`
- `src/Model/Entity/PettyCashObservation.php`
- `src/Model/Table/PettyCashRecordsTable.php`
- `src/Model/Table/PettyCashDocumentsTable.php`
- `src/Model/Table/PettyCashObservationsTable.php`

**Frontend:**
- `templates/PettyCashRecords/index.php`
- `templates/PettyCashRecords/view.php`
- `templates/PettyCashRecords/add.php`
- `templates/PettyCashRecords/edit.php`
- `templates/element/petty_cash_progress.php`

**Referencias para paridad:**
- `src/Service/RefundService.php`
- `src/Service/GroupedInvoiceService.php`
- `src/Service/InvoicePipelineService.php`
- `src/Service/PipelineAuthorizationService.php`
- `src/Constants/PipelineStepConstants.php`
- `src/Controller/AppController.php`
