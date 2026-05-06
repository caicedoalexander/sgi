# Plan D — Estructura canónica para Advances

**Fecha:** 2026-05-06
**Audit base:** [`docs/audits/flow-structure-audit-2026-05-06.md`](../audits/flow-structure-audit-2026-05-06.md) (sección 6, prioridad 🟡)
**Alcance:** Mínimo viable — solo lo que el audit marcó. Sin adelgazar controller, sin tocar el cruce funcional con `InvoiceDocuments`.

---

## 1. Contexto

Advance es el cuarto flujo migrado a la base canónica (después de PaymentSchedulings, Novelties y Refunds). Diferencias respecto a los anteriores:

- Un **Anticipo es internamente una `Invoice`** con `document_type=ANTICIPO`. La edición del anticipo en sí (campos comunes de factura) vive en `InvoicesController::edit()`. `AdvancesController::edit()` solo redirige.
- Lo único propio de Advance es el **proceso de legalización post-pago**: firmas, items legalizados, documentos vinculados, cálculo de surplus.
- Por tanto **no aplica la simetría Add/Edit clásica**. La simetría real es **Add (crear anticipo) + Legalization (proceso post-pago)**.

## 2. Entregables

### 2.1 ViewModels

#### `src/ViewModel/AdvanceAddViewModel.php`

Encapsula la preparación de datos para `AdvancesController::add()`.

- **Fábricas estáticas:**
  - `forForm(InvoicesTable $invoicesTable, array $dropdowns): self` — usado en GET. Construye `Invoice` vacía + dropdowns.
  - `fromRequest(InvoicesTable $invoicesTable, array $data, int $userId, array $dropdowns): self` — usado en POST. Aplica defaults, valida beneficiario, aplica lista blanca de campos accesibles.
- **Defaults aplicados en `fromRequest`:**
  - `document_type = InvoiceConstants::DOCTYPE_ANTICIPO`
  - `pipeline_status = InvoiceConstants::STATUS_APROBACION`
  - `registered_by = $userId`
  - `registration_date = date('Y-m-d')`
  - `due_date = $data['issue_date']` si `due_date` viene vacío e `issue_date` no.
- **Validación:** `provider_id` o `employee_id` debe estar presente. Si no, `errors[] = 'Debe seleccionar un proveedor o un empleado como beneficiario.'`.
- **Lista blanca de `accessibleFields`** (audit CR-001 — bloquea mass-assignment de `approver_id`, `area_approval`, `payment_status`, `confirmed_by`, `accrued`, `advance_id`):
  ```
  Aceptados: provider_id, employee_id, operation_center_id, expense_type_id,
             cost_center_id, amount, detail, issue_date, due_date,
             document_type, registered_by, pipeline_status, registration_date
  ```
- **Propiedades públicas readonly:** `invoice`, `dropdowns`, `errors`.
- **DI:** recibe `InvoicesTable` y dropdowns desde el controller (mismo patrón que `RefundAddViewModel`). No usa `TableRegistry` adentro.

#### `src/ViewModel/AdvanceLegalizationViewModel.php`

Reemplaza el método privado `_buildLegalizationViewModel` (controller líneas 291-356).

- **Constructor:** `__construct(Invoice $invoice, AdvanceLegalization $leg, string $roleName)`.
- **Método `build(): array`** — devuelve el set actual usado por `templates/Advances/legalization.php`:
  ```
  invoice, leg, linkedInvoices, linkedTotal, advanceTotal, diff,
  relationDocument, signatureHistory, bankingEntities, surplusPayment, roleName
  ```
- **Internals (movidos del método privado):**
  - Query `Invoices` para `linkedInvoices` (where `document_type = LEGALIZACION` and `advance_id = invoice.id`).
  - Cálculo de `linkedTotal`, `advanceTotal`, `diff`.
  - Separación de signature activa (pendiente o firmada más reciente) vs historial.
  - Query `BankingEntities` (find list).
  - Query `InvoicePayments` para `surplusPayment` cuando `leg->surplus_payment_id` está set.

### 2.2 DocumentService

#### `src/Service/AdvanceLegalizationDocumentService.php`

Centraliza la gestión de archivos relacionada a la legalización.

- **`use DocumentUploadTrait`** se mueve aquí. Sale de `AdvanceLegalizationService`.
- **API pública** (retorna `ServiceResult`):
  - `attachRelationDocument(AdvanceLegalization $leg, UploadedFile $file, int $userId): ServiceResult`
    - Mueve tal cual el método actual de `AdvanceLegalizationService:182-247`.
    - Mantiene `$leg->canUploadRelationDocument()` como guarda.
    - Mantiene `getConnection()->transactional(...)`.
    - Mantiene la limpieza de huérfanos en `webroot/uploads/` (audit MA-004).
  - `attachItemReceipt(UploadedFile $file, int $userId): ServiceResult`
    - Extrae el bloque de upload de `addLegalizationItem()` (líneas 483+).
    - Devuelve la entidad `InvoiceDocument` persistida.
- **Constructor:** sin dependencias obligatorias (el trait usa `WWW_ROOT`). Vacío por ahora; abierto a recibir `EventManagerInterface` si se necesita para webhooks futuros.

#### Cambios en `AdvanceLegalizationService`

- Pierde `use DocumentUploadTrait`.
- Pierde el método `attachRelationDocument` (queda como pass-through opcional o se elimina y el controller llama directo al document service — preferible eliminar para evitar fachadas vacías).
- `addLegalizationItem` consume `attachItemReceipt` del nuevo service: orquesta la subida, recibe el `InvoiceDocument`, persiste el item con `invoice_document_id`.
- Constructor agrega:
  ```php
  ?AdvanceLegalizationDocumentService $documentService = null
  ```
  con fallback `?? new AdvanceLegalizationDocumentService()` (convención del proyecto).

### 2.3 Cambios en el controller

`AdvancesController`:

- `add()` queda en ~25 líneas: instanciar VM → si POST y `$vm->errors` vacíos → `Table->save($vm->invoice)` → redirect; si no, set vars y render.
- `legalization()` queda en ~15 líneas: cargar invoice + leg → instanciar VM → `$this->set($vm->build())`.
- `_buildLegalizationViewModel` se elimina.
- `uploadRelationDocument` action: el `attachRelationDocument` del service principal ya no existe; la action llama directamente a `$this->documentService->attachRelationDocument(...)`. El controller recibe el nuevo service por constructor (mismo patrón que ya usa `actionPolicy`).

**Impacto en líneas:**
- `AdvancesController`: 822 → ~700 (-120 entre `add` + `_buildLegalizationViewModel`).
- `AdvanceLegalizationService`: 755 → ~600 (sale el trait + bloques de upload).
- Nuevo `AdvanceLegalizationDocumentService`: ~150 líneas, foco único.

## 3. Lo que NO cambia

- Pipeline State pattern — ya existe en `src/Service/Pipeline/Advance/` con 6 estados. Sin tocar.
- `AdvanceLegalizationHistoryService` — sin tocar.
- Lógica de negocio: `initialize`, `confirmRelation`, `markRelationSigned`, `addLegalizationItem` (queda como orquestador), `legalize`, transiciones.
- Cruce funcional con `InvoiceDocuments`: las legalizaciones siguen guardando documentos en `invoice_documents` (es por diseño — los docs van ligados a la factura padre). Decisión documentada en sección 9 del audit.
- `AdvancesController::edit()` sigue redirigiendo a `InvoicesController::edit()`.

## 4. Orden de ejecución

1. Crear `AdvanceLegalizationDocumentService` con `attachRelationDocument` y `attachItemReceipt`.
2. Inyectar el nuevo service en `AdvanceLegalizationService` (constructor) y en `AdvancesController` (constructor).
3. Refactor `addLegalizationItem` para usar `attachItemReceipt`.
4. Eliminar `attachRelationDocument` de `AdvanceLegalizationService`. Apuntar `AdvancesController::uploadRelationDocument` al nuevo service.
5. Quitar `use DocumentUploadTrait` de `AdvanceLegalizationService`.
6. Crear `AdvanceLegalizationViewModel`. Refactor `legalization()` para usarlo. Eliminar `_buildLegalizationViewModel`.
7. Crear `AdvanceAddViewModel`. Refactor `add()` para usarlo.
8. Actualizar audit (secciones 6, 8 y 9).
9. Validación manual.

## 5. Validación manual

Tras el merge, levantar `php bin/cake server` y ejercitar:

1. **Crear anticipo con provider** — `/advances/add` con `provider_id` (sin employee) → debe crear y redirigir a `view`.
2. **Crear anticipo con employee** — `/advances/add` con `employee_id` (sin provider) → debe crear y redirigir a `view`.
3. **Beneficiario faltante** — POST sin `provider_id` ni `employee_id` → flash error "Debe seleccionar un proveedor o un empleado como beneficiario.", no se crea registro.
4. **Mass-assignment guard** — POST con `approver_id`, `area_approval`, `payment_status`, `confirmed_by`, `accrued`, `advance_id` en el body → confirmar via DB que NO se persistieron.
5. **Default `due_date`** — POST sin `due_date` y con `issue_date='2026-05-06'` → DB muestra `due_date='2026-05-06'`.
6. **Vista legalización OK** — anticipo en `pagada` con legalización iniciada → `/advances/legalization/{id}` renderiza igual que antes: linked invoices, `linkedTotal`, `advanceTotal`, `diff`, signature activa separada del historial, surplus payment cargado cuando aplica, dropdown de banking entities.
7. **Vista legalización defensiva** — `/advances/legalization/{id}` cuando aún no existe `advance_legalization` → redirect a `view` con flash info.
8. **Adjuntar relation document** — subir PDF → guarda en `webroot/uploads/`, registra fila en `invoice_documents`, elimina archivos huérfanos previos en disco (audit MA-004).
9. **Adjuntar receipt en item** — `addLegalizationItem` con `receipt_file` → item creado con `invoice_document_id` apuntando al documento subido.

## 6. Actualización del audit

`docs/audits/flow-structure-audit-2026-05-06.md`:

- **Sección 6** — marcar Advance como Completado y agregar fila al checklist.
- **Sección 8** — nueva fila Plan D, estado 🟢 Completado, fecha 2026-05-06.
- **Sección 9** — agregar entradas:
  - Activación Plan D (promoción desde Backlog).
  - Desviación de naming: `AdvanceAddViewModel` + `AdvanceLegalizationViewModel` (no `EditViewModel`). Razón: Anticipo comparte entidad con Invoice; `AdvancesController::edit()` redirige a `InvoicesController::edit()`. La simetría real del flujo es Add + Legalization.
  - Hallazgo: el item original del audit "Advances reusa `InvoiceDocumentService`" estaba desactualizado. La realidad: usa `DocumentUploadTrait` directamente en el service principal. El nuevo `AdvanceLegalizationDocumentService` cierra la deuda real (no había service propio).
  - Decisión: el cruce con la tabla `InvoiceDocuments` se conserva (es por diseño funcional — documentos ligados a la factura padre, no acoplamiento accidental).
