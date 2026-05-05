# Plan — Resolución Minor + Suggestions (Audit Invoice Flow)

**Proyecto:** SGI — Flujo de control de facturas
**Fecha:** 2026-05-04
**Referencia:** `docs/audits/invoice-flow-audit-2026-05-04.md`
**Ítems:** 10 Minor + 6 Suggestion → 16 hallazgos en 3 PRs

---

## PR-1: Quick wins

**7 cambios de 1-5 líneas. Sin riesgo de regresión.**

### MN-003 — Magic numbers en `regress()`
- **Archivo:** `src/Constants/InvoiceConstants.php` + `src/Service/InvoicePipelineService.php:416-420`
- **Fix:** Añadir `REGRESS_REASON_MIN = 10` y `REGRESS_REASON_MAX = 500` a `InvoiceConstants`. Reemplazar literales en `regress()`.

### MN-004 — Propiedad sin tipo en `InvoiceApprovalService`
- **Archivo:** `src/Service/InvoiceApprovalService.php:25`
- **Fix:** `private $invoiceApprovalsTable;` → `private \Cake\ORM\Table $invoiceApprovalsTable;`

### MN-005 — `idempotency_key` sin filtro por `invoice_id`
- **Archivo:** `src/Service/InvoicePaymentService.php:214-216`
- **Fix:** Añadir `'invoice_id' => $invoice->id` al `where()` de la query de idempotencia.

### MN-010 — `approver_ids` sin sanitización
- **Archivo:** `src/Controller/InvoicesController.php:682` y `~712`
- **Fix:** `(array)$this->request->getData('approver_ids')` → `array_map('intval', (array)$this->request->getData('approver_ids'))`

### MN-008 — Naming confuso `assignApprovers` vs `sendApprovalLinks`
- **Archivo:** `src/Service/InvoiceApprovalService.php:46` y `405`
- **Fix:** Verificar si `assignApprovers` es alias de `sendApprovalLinks`. Si sí: eliminar el alias y actualizar el controller para llamar directamente a `sendApprovalLinks`. Si tienen lógica distinta: añadir docblock que documente la diferencia.

### SG-005 — `isLockedByPaidScheduling` llamado 2-3× por request
- **Archivo:** `src/Service/InvoiceLockPolicy.php:31`
- **Fix:** Añadir `private array $lockCache = []`. Envolver la query en `array_key_exists($invoiceId, $this->lockCache)` antes de ejecutar.

### SG-003 — Logging en `authorizePayment`
- **Archivo:** `src/Service/InvoicePaymentService.php`
- **Pre-condición:** Verificar si `StructuredLogger` ya está inyectado en el servicio.
- **Fix:** Añadir log estructurado en los puntos clave de autorización (inicio, éxito, fallo). Si no está inyectado, añadirlo al constructor con patrón nullable + `?? new StructuredLogger()`.

**Validación manual PR-1:**
- Enviar links de aprobación y verificar que `approver_ids` llegan como enteros.
- Registrar dos pagos con el mismo `idempotency_key` en facturas distintas → deben tratarse como pagos independientes.
- Regresar una factura con motivo de 9 caracteres → debe rechazar. Con 10 → debe aceptar.

---

## PR-2: Consolidaciones y correcciones de lógica

**4 cambios con mayor superficie. Revisión cuidadosa.**

### MN-001 + SG-006 — Consolidar `STATUS_LABELS` y labels de documentos
- **Archivos:** `src/Constants/InvoiceConstants.php`, `src/Service/InvoicePipelineService.php`, `src/Controller/InvoicesController.php`
- **Pre-condición:** `grep -r STATUS_LABELS src/` para mapear todas las referencias.
- **Fix:**
  1. Mover `STATUS_LABELS` de `InvoicePipelineService` a `InvoiceConstants`.
  2. Mover `_invoiceDocumentLabels` del controller a `InvoiceConstants`.
  3. Actualizar todas las referencias a `InvoiceConstants::STATUS_LABELS`.

### MN-006 — Comparaciones de `amount` con `float` → `bcmath`
- **Archivo:** `src/Service/InvoicePaymentService.php:55` y `87`
- **Fix exacto:**
  ```php
  // Línea 55
  if (bccomp((string)$totalPaid, (string)$invoice->amount, 2) >= 0 && $totalPaid > 0)
  // Línea 87
  return max(0, (float)bcsub((string)$invoice->amount, (string)$totalPaid, 2));
  ```
- Solo estos dos puntos. Sin migrar columnas de BD.

### MN-007 — `area_approval_date` en la entidad `Invoice`
- **Archivos:** `src/Model/Entity/Invoice.php`, `src/Service/InvoicePipelineService.php:257-263`
- **Fix:** Añadir método a la entidad:
  ```php
  public function setApprovalResult(string $approval): void
  {
      $this->area_approval = $approval;
      if (in_array($approval, [InvoiceConstants::APPROVAL_APPROVED, InvoiceConstants::APPROVAL_REJECTED])) {
          $this->area_approval_date = date('Y-m-d');
      }
  }
  ```
  El service reemplaza las 3 asignaciones sueltas por `$invoice->setApprovalResult($newApproval)`.

### SG-002 — `validateTransitionRequirements` sin clone
- **Archivo:** `src/Service/InvoiceTransitionValidator.php`
- **Fix:** Añadir parámetro `array $overrides = []` a la firma. Aplicar overrides internamente al check sin clonar el invoice. Actualizar el caller en `InvoicePipelineService::saveAndAdvance` para pasar los campos nuevos directamente.

**Validación manual PR-2:**
- Verificar que `InvoiceConstants::STATUS_LABELS` se muestra correctamente en breadcrumbs y flash messages.
- Registrar un pago con monto exacto igual al de la factura → debe quedar en "Pago total" sin error de centavos.
- Aprobar/rechazar una factura → `area_approval_date` debe setearse correctamente.

---

## PR-3: Refactors estructurales

**3 ítems de mayor alcance.**

### MN-002 / MN-009 — Extraer `InvoiceEditViewModel`
- **Archivos:** nuevo `src/ViewModel/InvoiceEditViewModel.php`, `src/Controller/InvoicesController.php:220-382`, `templates/Invoices/edit.php`
- **Fix:** Crear clase de datos inmutable:
  ```php
  class InvoiceEditViewModel
  {
      public function __construct(
          public readonly Invoice $invoice,
          public readonly array $providers,
          public readonly array $operationCenters,
          public readonly array $visibleSections,
          public readonly array $editableFields,
          // ... resto de datos de vista
      ) {}
  }
  ```
  El controller construye el ViewModel y lo pasa a la vista. Método `edit()` baja de 162 a ~60 líneas. La template accede con `$viewModel->providers` etc.
- **Riesgo:** Requiere actualizar `templates/Invoices/edit.php`. Validación manual en todos los estados del pipeline.

### SG-001 — Métodos de estado puro en la entidad `Invoice`
- **Archivo:** `src/Model/Entity/Invoice.php`
- **Alcance acotado** (sin dependencias de servicio en la entidad):
  ```php
  public function isEditable(): bool { ... }
  public function isInFinalState(): bool { ... }
  public function requiresApproval(): bool { ... }
  ```
  La lógica de transición pesada (`canAdvance()` real con validadores) permanece en `InvoicePipelineService`.
- **Nota:** Si se quiere ir más allá de métodos de consulta pura, se requiere un plan separado antes de implementar.

### SG-004 — Plan de deprecación de `ApprovalTokenService` legacy
- **Archivos:** `src/Service/ApprovalTokenService.php:1`, nuevo `docs/decisions/approval-service-migration.md`
- **Fix:** Añadir `@deprecated` docblock en `ApprovalTokenService` apuntando al doc. Crear documento de ~20 líneas con: estado actual, qué bloquea la migración completa, criterio de éxito para retirar el legacy.

**Validación manual PR-3:**
- Ejercitar el formulario `edit` en todos los estados del pipeline (aprobacion, contabilidad, tesoreria, autorizacion_pago, pagada) y verificar que los datos de vista se renderizan correctamente.
- Verificar que `isEditable()`, `isInFinalState()`, `requiresApproval()` retornan los valores correctos para cada estado.

---

## Orden de ejecución sugerido

```
PR-1  →  PR-2  →  PR-3
```

Cada PR es independiente y mergeable por separado. PR-3 puede posponerse sin afectar los anteriores.
