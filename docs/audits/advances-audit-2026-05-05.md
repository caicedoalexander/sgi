# Auditoría — Módulo Anticipos

**Fecha:** 2026-05-05
**Modo:** PATH · **Nivel:** HIGH
**Branch:** `main`
**Archivos revisados:** 17 (Controller×1, Services×2, Entities×2, Tables×2, Constants×1, Event×1, Templates×6, Migrations×5)

## Alcance

| Categoría | Archivos |
|-----------|----------|
| Controllers | `src/Controller/AdvancesController.php` |
| Services | `src/Service/AdvanceLegalizationService.php`, `src/Service/Subscriber/LegalizationInitializerSubscriber.php` |
| Domain / ORM | `src/Model/Entity/AdvanceLegalization.php`, `src/Model/Entity/AdvanceLegalizationSignature.php`, `src/Model/Table/AdvanceLegalizationsTable.php`, `src/Model/Table/AdvanceLegalizationSignaturesTable.php`, `src/Constants/AdvanceConstants.php`, `src/Event/AdvanceLegalizedEvent.php` |
| Templates | `templates/Advances/{index,add,view,legalization}.php`, `templates/element/advance_legalization_progress.php`, `templates/element/advance_link_modal.php` |
| Migrations | `config/Migrations/20260429141941_AddAdvanceFieldsToInvoices.php`, `…143219_CreateAdvanceLegalizations.php`, `…145637_CreateAdvanceLegalizationSignatures.php`, `…151623_SeedAdvancesPermissions.php`, `…205042_AlignAdvanceLegalizationSignaturesColumns.php` |

## Resumen del módulo

Pipeline propio de 6 estados sobre `advance_legalizations`, paralelo al de facturas. Inicialización automática vía evento al pagar la factura-anticipo, vinculación N:1 con `invoices.advance_id`, firmas digitales con historial, casos exacto/faltante/sobrante, reintegro reusando `InvoicePayments` con `is_refund=true`, y promoción de linked invoices al cerrar (`AdvanceLegalizedEvent` → `LinkedInvoicesPromoterSubscriber`).

---

## Hallazgos por severidad

### Critical (4)

| ID | Archivo:Línea | Issue | Recomendación |
|----|---------------|-------|---------------|
| **CR-001** | `AdvancesController.php:127-141` | **Mass-assignment:** `patchEntity` con todo `request->getData()` sin lista blanca. La sobre-escritura posterior de `pipeline_status` y `registered_by` no protege a `approver_id`, `area_approval`, `payment_status`, `confirmed_by`, `accrued`, `advance_id`, etc. (todos `_accessible`). | Pasar `accessibleFields` explícitos al `patchEntity` con solo los campos del formulario: `provider_id`, `employee_id`, `operation_center_id`, `expense_type_id`, `cost_center_id`, `amount`, `detail`, `issue_date`, `due_date`, `document_type`, `registered_by`, `pipeline_status`, `registration_date`. |
| **CR-002** | `AdvanceLegalizationService.php` (todo el archivo) | **Falta atomicidad:** solo `registerRefundPayment` está en `transactional()`. `linkInvoices`, `attachRelationDocument`, `markSigned`, `confirmShortageReceipt`, `_setStatus` combinan 2+ statements sin transacción. Si el subscriber del `AdvanceLegalizedEvent` falla, el leg ya commiteó pero las facturas vinculadas quedan sin promover. | Envolver cada operación que modifica >1 fila en `connection->transactional()`. Mover el dispatch del evento `AdvanceLegalized` dentro del transactional del `_setStatus` para que un fallo del subscriber rollbackee la legalización. |
| **CR-003** | `AdvancesController.php:301-532` + `AppController.php:99-108` | **Autorización rota:** todas las acciones POST mapean a `advances.edit`. Cualquier rol con ese permiso (Tesorería, Registro/Revisión, Contador, Coord. AyF) puede ejecutar `markExact`, `registerShortage`, `linkInvoices`, etc. sin importar la matriz por estado/rol — los chequeos viven solo en el template. | Añadir helper `_assertCanForState($leg, $action, $roleName)` o delegar al service que reciba `roleName`. Mínimo: `markSigned`, `returnToValidacion`, `linkInvoices`, `unlinkInvoice`, `markExact`, `registerShortage`, `registerSurplus` requieren rol Contabilidad/Admin; `confirmShortage`, `registerRefund` requieren Tesorería/Admin. |
| **CR-004** | `AdvanceLegalizationService.php:499-517` + `Service/Pipeline/LinkedInvoiceLegalizer.php:32-66` | **Race / inconsistencia:** `_setStatus(LEGALIZADA)` guarda y luego dispara el evento fuera de transacción. Si el subscriber lanza, queda anticipo `legalizada` con linked invoices en `contabilidad`. Adicionalmente `LinkedInvoiceLegalizer` solo promueve las que están exactamente en `contabilidad`, sin warning si quedaron rezagadas. | (a) Mover el dispatch del evento dentro de un `transactional` que envuelva el save del leg + la promoción. (b) Loggear advertencia cuando hay facturas vinculadas fuera de `contabilidad` al cierre. (c) Codificar la invariante "todas las linked deben estar en contabilidad antes de cerrar" como pre-condición en `markExact`/`confirmShortageReceipt`/`closeOnRefundAuthorized`. |

### Major (10)

| ID | Archivo:Línea | Issue | Recomendación |
|----|---------------|-------|---------------|
| **MA-001** | `AdvanceLegalizationService.php:361-371` | Upload de comprobante en `confirmShortageReceipt` **bypassa** `DocumentUploadTrait`: sin validar MIME/tamaño, usa la extensión del cliente, persiste directo en `webroot/uploads`. Riesgo RCE si nginx/Apache ejecutan PHP en uploads. | Reutilizar `uploadAndSave()` del trait (que valida MIME contra `ALLOWED_DOC_MIMES` y tamaño 20MB). Persistir en `AdvanceLegalizationSignatures` o tabla aparte. Verificar `.htaccess`/nginx en `webroot/uploads/` para denegar ejecución PHP. |
| **MA-002** | `AdvancesController.php` (`_loadLegalization`) + `:537-544` | **IDOR:** `firstOrFail()` sólo verifica existencia por `advance_invoice_id`. Cualquier usuario con `advances.edit` puede operar cualquier anticipo del sistema. | Si la regla es "todos ven todo", documentarlo. Si no, agregar filtro por scope (`operation_center_id` ∈ user.operation_centers, o `created_by`). |
| **MA-003** | `AdvancesController.php:449-451, 500-501` | **Bug parsing COP:** `str_replace([',', '.'], ['.', ''], $raw)` borra todos los puntos *después* de convertir comas, perdiendo decimales. "1.234,56" → "123456" (×100). | Usar `(float)str_replace(['.', ','], ['', '.'], $raw)` o leer `data-numeric-value` que AutoNumeric expone en el cliente. |
| **MA-004** | `AdvanceLegalizationService.php:158-164` | Tras subir nuevo PDF, `deleteAll(signature_status=PENDING)` **no borra los archivos físicos** previos — huérfanos en `webroot/uploads/advances/{id}/`. | Antes del `deleteAll`, recoger los `file_path` y `unlink()` en disco. Decidir además si bloquear reemplazos cuando ya hay un firmado vigente. |
| **MA-005** | `AdvanceLegalizationService.php:323, 382` | `registerShortage`/`registerSurplus` no validan que `case_type` no esté ya seteado (doble registro racing) ni invocan `_ensureExpectedStatus`. | Añadir guard `if ($leg->case_type !== null) return fail('Ya se declaró un caso')` y agregar `_ensureExpectedStatus($leg->status)` en cada handler. |
| **MA-006** | `AdvanceLegalizationService.php:175-216` | `moveToRevisionFirmas` acepta linked en estado `legalizada`, lo que rompe la invariante de que `LinkedInvoiceLegalizer` solo promueve desde `contabilidad`. Podría declararse `legalizada` el anticipo con linked sin promover. | Restringir `allowedStatuses` solo a `STATUS_CONTABILIDAD`. Si se quiere tolerar `legalizada`, validar al cierre que ninguna linked quedó atrás. |
| **MA-007** | `AdvancesController.php:213` | Branch de flash en `legalization()` cuando `!$leg` es código muerto (el `view()` ya redirige antes). | Confirmar la intención. Si el flash es para acceso directo por URL, mantener pero documentar; si no, eliminar. |
| **MA-008** | `templates/element/advance_link_modal.php:6-14` | Carga **todas** las facturas tipo Legalización con `advance_id IS NULL` del sistema, sin filtros ni paginación, con contains de Providers/Employees/OperationCenters. | Paginar el modal o usar autocomplete AJAX (select2 con endpoint de búsqueda). Como mínimo, filtrar por mismo `operation_center_id` que el anticipo. |
| **MA-009** | `AdvancesController.php:47-55` | `pendingLegalization()` combina `matching('AdvanceLegalization', …)` + `contain(['AdvanceLegalization'])` → posible JOIN duplicado. | Reemplazar por `innerJoinWith('AdvanceLegalization', …)` y `contain(['AdvanceLegalization'])` separados. |
| **MA-010** | `AdvanceLegalizationService.php` (528 LOC) | God Service + entidad anémica. Concentra pipeline transitions, vinculación, uploads, delete físico, cálculo de diferencia, creación de pagos y dispatch de eventos. | Extraer `AdvanceLegalizationPipelineState` siguiendo el patrón de `Service/Pipeline/State/` ya implementado para Invoices. Mover predicates como `canMarkExact()`, `canConfirmShortage()` a la entidad. |

### Minor (12)

| ID | Archivo:Línea | Issue | Recomendación |
|----|---------------|-------|---------------|
| **MI-001** | `config/Migrations/20260429151623_SeedAdvancesPermissions.php:25-41` | SQL construido con concatenación + `addslashes` aunque el input sea constante. | Usar `getQueryBuilder()` o sentencias preparadas. |
| **MI-002** | `Model/Entity/AdvanceLegalization.php:11-27` | `_accessible` permisivo: marca `status`, `case_type`, `shortage_amount`, `surplus_amount`, `legalized_at`, `surplus_payment_id` como accesibles — solo el service debería mutarlos. | Marcar como `false` los campos sensibles del pipeline. |
| **MI-003** | `AdvanceLegalizationService.php:295` | `$invoices->get($leg->advance_invoice_id)` puede arrojar `RecordNotFoundException` sin manejo. | Capturar y devolver `ServiceResult::fail` con mensaje claro. |
| **MI-004** | `AdvanceLegalizationService.php:55-58, 433, 442, 504-505` | Mensajes de error mezclan texto user-facing con `json_encode($entity->getErrors())` (texto crudo del framework). | Helper que extraiga el primer mensaje legible (`array_shift(array_values($errors))`). |
| **MI-005** | `AdvancesController.php:193-288` (`legalization`) | 95 LOC: mezcla carga, ordenamiento, queries, cálculo de totales, lookups y set de role. | Extraer `_buildLegalizationViewModel()` privado o pasar al service `getLegalizationView($leg)`. |
| **MI-006** | `templates/Advances/legalization.php:383, 403` | Lógica "puede registrar reintegro" duplicada entre service/template. | Mover al service: `canRegisterRefund(roleName)` y exponer al template. Alinea con CR-003. |
| **MI-007** | `AdvancesController.php:537-544` | `firstOrFail()` devuelve 404 genérico sin contexto. | Capturar `RecordNotFoundException` y devolver flash + redirect a `index`. |
| **MI-008** | `Model/Table/AdvanceLegalizationsTable.php:58-107` | No valida coherencia entre `case_type` y `shortage_amount`/`surplus_amount`, ni que `legalized_at` esté solo si `status=legalizada`. | Añadir reglas en `buildRules` o validador custom. |
| **MI-009** | `Model/Entity/AdvanceLegalizationSignature.php` | Entidad anémica: solo `_accessible`. Faltan predicates `isPending()`, `isSigned()`, `isRejected()`. | Añadir métodos de comportamiento que eviten literales en el template (`legalization.php:465`). |
| **MI-010** | `config/Migrations/20260429143219_CreateAdvanceLegalizations.php:25-26` | `created_by`/`updated_by` sin `signed` explícito. `users.id` suele ser unsigned por convención. | Verificar y forzar consistencia. |
| **MI-011** | `AdvanceLegalizationService.php:160-164` | Ventana sin firma activa entre `save` y `deleteAll`: request concurrente podría sumar antes del delete. | Usar `transactional()` (alinea con CR-002). |
| **MI-012** | `AdvanceLegalizationService.php:344, 401` | `array $data` sin shape. | Documentar shape con phpdoc `array{receipt_number?:string, received_at?:string, receipt_file?:UploadedFile}`. |

### Suggestions (8)

| ID | Sugerencia |
|----|------------|
| **SU-001** | Extraer `AdvanceLegalizationPipelineState` siguiendo `Service/Pipeline/State/` (Invoices). |
| **SU-002** | `getDifference` ejecuta 2 queries; aceptar el total como parámetro o exponer método único. |
| **SU-003** | Modal de vinculación: diferir query a AJAX al abrir, no en cada render del template. |
| **SU-004** | **Audit trail dedicado** — crear `advance_legalization_histories` espejo a `petty_cash_histories`/`invoice_histories`. Actualmente no hay traza de cambios de estado, casos ni montos. |
| **SU-005** | `templates/Advances/index.php:64` — `$advances->count()` sobre `PaginatedResultSet`; usar `iterator_count` o `empty(...)`. |
| **SU-006** | `legBadgeMap` en `legalization.php:21-27` no incluye `STATUS_AUTORIZACION_PAGO` — fallback `bg-dark` "Desconocido". |
| **SU-007** | Falta regla `existsIn('surplus_payment_id', 'SurplusPayments')` en `AdvanceLegalizationsTable::buildRules`. |
| **SU-008** | `<input type="hidden" name="_csrfToken">` flotante fuera de `<form>` — aclarar comentario o usar `meta` / `data-` attribute. |

---

## Category Summary

| Categoría | 🔴 | 🟠 | 🟡 | 🟢 | Total |
|-----------|----|----|----|----|-------|
| Security | 1 | 2 | 1 | 0 | 4 |
| Bug / Lógica | 0 | 4 | 1 | 0 | 5 |
| Atomicidad / Concurrencia | 2 | 1 | 1 | 0 | 4 |
| Autorización / RBAC | 1 | 0 | 1 | 0 | 2 |
| Performance / N+1 | 0 | 2 | 0 | 2 | 4 |
| DDD / Architecture | 0 | 1 | 0 | 1 | 2 |
| Encapsulación / ORM | 0 | 0 | 4 | 1 | 5 |
| Readability / Smells | 0 | 0 | 4 | 0 | 4 |
| Auditoría / UX | 0 | 0 | 0 | 4 | 4 |
| **Total** | **4** | **10** | **12** | **8** | **34** |

---

## Task Match Analysis — 85%

| Feature esperada | Encontrado | Status |
|------------------|------------|--------|
| Creación de Anticipo (proveedor o empleado) | `AdvancesController::add` + `Invoices` con `document_type=Anticipo` | ✅ |
| Pipeline de pago del Anticipo (aprobación → … → pagada) | Reusa `InvoicePipelineService` | ✅ |
| Inicialización automática de legalización al pagar | `LegalizationInitializerSubscriber` reacciona a `Invoice.paid` | ✅ |
| Vinculación N:1 facturas reales ↔ anticipo | `linkInvoices` / `unlinkInvoice` con `invoices.advance_id` FK | ✅ |
| Subida de "Relación de facturas" PDF | `attachRelationDocument` + `DocumentUploadTrait` | ✅ |
| Firmas digitales con historial (rechazadas) | `advance_legalization_signatures` + `signature_status` PENDING/SIGNED/REJECTED | ✅ |
| Casos exacto / faltante / sobrante | `markExact`, `registerShortage`/`confirmShortageReceipt`, `registerSurplus`/`registerRefundPayment` | ✅ |
| Reintegro al beneficiario (caso sobrante) | `InvoicePayments` con `is_refund=true` + autorización Contador | ✅ |
| Promoción automática de facturas vinculadas a `legalizada` | `AdvanceLegalizedEvent` + `LinkedInvoicesPromoterSubscriber` | ✅ |
| Permisos por rol | Migración seed + `AppController::_actionToPermission` | ⚠️ Parcial — granularidad solo CRUD, no por estado/acción (CR-003) |
| Atomicidad de operaciones (legalización/firma/vínculo) | Solo `registerRefundPayment` es transactional | ❌ Incompleto (CR-002) |
| Audit trail dedicado | No existe `advance_legalization_histories` | ❌ Falta (SU-004) |
| Validación robusta de uploads de firma | `attachRelationDocument` reusa trait ✅; `confirmShortageReceipt` la **bypassa** | ⚠️ Parcial (MA-001) |
| Defensa anti-IDOR / scope-aware | `firstOrFail` solo verifica existencia, no autorización fina | ⚠️ (MA-002, CR-003) |

---

## Verdict

### ❌ REQUEST CHANGES

El módulo está funcionalmente completo y bien diseñado a nivel de pipeline + eventos, pero presenta vulnerabilidades de seguridad explotables (mass-assignment en `add`, upload sin validación en `confirmShortageReceipt`), falta autorización fina por estado/rol (CR-003 permite a roles equivocados ejecutar transiciones críticas con un POST directo), y carece de atomicidad transaccional en operaciones que modifican múltiples filas — lo que puede dejar el sistema en estados inconsistentes.

### Acciones críticas obligatorias

1. **CR-001** — `accessibleFields` explícitos en `AdvancesController::add()`.
2. **CR-002** — `transactional()` en `linkInvoices`, `attachRelationDocument`, `markSigned`, `confirmShortageReceipt`, `_setStatus`. Mover dispatch del evento dentro de la transacción.
3. **CR-003** — Validación rol×estado por acción.
4. **CR-004** — Loggear/rollbackear si `LinkedInvoiceLegalizer` no promueve todas las linked.
5. **MA-001** — Reusar `DocumentUploadTrait::uploadAndSave` en `confirmShortageReceipt`.
6. **MA-003** — Corregir parsing de montos COP.
7. **MA-002** — Decidir y aplicar scope (OC o `created_by`) para evitar IDOR.

### Acciones recomendadas

MA-004 (huérfanos en disco), MA-008/MA-009 (paginación modal y `matching`+`contain`), MI-002 (`_accessible` restrictivo), MI-008 (coherencia case/amount), SU-004 (audit trail dedicado).

---

## Validación manual sugerida (post-fixes)

Dado que el proyecto no usa tests automatizados, validar manualmente:

1. **CR-001** — Crear un anticipo enviando vía `curl`/Postman campos extra (`pipeline_status=pagada`, `area_approval=Aprobada`, `approver_id=99`); verificar que la BD ignora esos valores.
2. **CR-002** — Provocar fallo en `LinkedInvoiceLegalizer` (p.ej. una linked invoice borrada) y verificar que el anticipo NO queda en `legalizada`.
3. **CR-003** — Loguearse como Tesorería e intentar POST a `/advances/mark-exact/{id}`; debe rechazarse.
4. **MA-001** — Subir archivo `comprobante.php` renombrado a `.pdf` en confirmación de faltante; verificar rechazo por MIME.
5. **MA-003** — Registrar faltante con monto "1.234,56" y verificar que la BD almacena `1234.56` (no `123456`).
6. **MA-002** — Loguearse como usuario de OC=A e intentar acceder a `/advances/legalization/{id-de-OC-B}`; debe redirigir o 403.
