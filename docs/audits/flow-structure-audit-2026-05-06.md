# Auditoría de Estructura de Flujos — SGI

**Creado:** 2026-05-06
**Solicitada por:** Necesidad de unificar la estructura de los 6 flujos principales del sistema (Invoices, PettyCash, Advances, Refunds, Novelties, PaymentSchedulings), que hoy difieren en organización de servicios, ViewModels y aplicación del Pipeline State pattern.
**Decisión adoptada:** Opción 2 — Migrar **solo PaymentSchedulings + Novelties** a la base canónica. Refunds y Advances quedan en backlog.

---

## 1. Servicios por dominio

| Flujo | Servicio principal | Document | History | Payment / Aux | Pipeline State | Otros |
|-------|---|---|---|---|---|---|
| **Invoices** | `InvoicePipelineService` | ✅ | ✅ | `InvoicePaymentService`, `InvoiceApprovalService` | ✅ `Pipeline/State/*` + `Policy/*` | `InvoiceFilterService`, `InvoiceLockPolicy`, `InvoiceFieldAccessPolicy`, `InvoiceTransitionValidator` |
| **PettyCash** | `PettyCashService` | ✅ | ✅ | — | ✅ `Pipeline/PettyCash/*` | — |
| **Advances** | `AdvanceLegalizationService` | ❌ (reusa `InvoiceDocumentService`) | ✅ | — | ✅ `Pipeline/Advance/*` + `Policy/AdvanceLegalizationActionPolicy` | — |
| **Refunds** | `RefundService` | ✅ | ✅ | `RefundPaymentService` | ❌ usa `Trait/RefundPipelineHelpersTrait` | `Dto/RefundSyntheticPayment`, `Subscriber/RefundOutcomeSubscriber` |
| **Novelties** | `NoveltyPipelineService` (mezcla pipeline + lógica) | ✅ | ✅ | — | ❌ monolítico en el service | `NoveltyObservationService`, `NoveltySignatureService` |
| **PaymentSchedulings** | `PaymentSchedulingService` + `PaymentSchedulingPipelineService` | `PaymentSchedulingAttachmentService` (otro nombre) | ❌ | — | ❌ monolítico | — |

## 2. ViewModels

| Flujo | Add VM | Edit VM | Notas |
|---|---|---|---|
| **Invoices** | ❌ | ✅ `InvoiceEditViewModel` | Asimétrico — Add construye el set inline en el controller |
| **PettyCash** | ✅ `PettyCashAddViewModel` | ✅ `PettyCashEditViewModel` | El **único** simétrico |
| **Advances** | ❌ | ⚠️ `_buildLegalizationViewModel` privado en el controller (no es clase) | |
| **Refunds** | ❌ | ❌ | Todo inline en el controller |
| **Novelties** | ❌ | ❌ | |
| **PaymentSchedulings** | ❌ | ❌ | |

## 3. Tamaño de controllers

```
EmployeeNovelties     922 líneas  ← obeso
Advances              822
Refunds               797
Invoices              735
PettyCashRecords      681
PaymentSchedulings    512
```

## 4. Inconsistencias clave

1. **Pipeline State pattern aplicado solo a 3 de 6 flujos.** Refunds usa Trait; Novelty y PaymentScheduling siguen monolíticos en un único `…PipelineService`.
2. **ViewModels caprichosos.** Caja Menor es el único con simetría Add/Edit. Facturas tiene Edit pero no Add. Anticipos tiene VM "fantasma" como método privado del controller. Refunds, Novelties y PaymentSchedulings nada.
3. **Naming inconsistente del servicio principal.** Conviven `…Service` (PettyCash, Refund), `…PipelineService` (Invoice, Novelty) y mezclas (`PaymentSchedulingService` + `PaymentSchedulingPipelineService`).
4. **Document Service.** Anticipos no tiene el suyo y reusa `InvoiceDocumentService` (acoplamiento cruzado). PaymentScheduling lo llama `Attachment`.
5. **Dto/, Subscriber/, Trait/** existen solo para Refund. Patrones aplicados como excepción, no como convención.

---

## 5. Estructura canónica adoptada

Ninguna referencia (Facturas / Caja Menor) sirve 100% como plantilla:

- **Facturas** tiene servicios extra (`Filter`, `Lock`, `FieldAccessPolicy`, `TransitionValidator`, `Approval`, `Payment`) justificados por su complejidad única (pagos parciales, autorización por Contador, DIAN, links externos). Forzar ese set en otros módulos sería over-engineering.
- **Caja Menor** es la más limpia y completa **sin sobre-diseñar** — pero le falta simetría Add/Edit que sí es buena práctica y debería extenderse a Facturas también.

**Base canónica = Caja Menor + extensiones opcionales:**

```
src/
├── Constants/{Domain}Constants.php                ← OBLIGATORIO
├── ViewModel/{Domain}AddViewModel.php             ← OBLIGATORIO
├── ViewModel/{Domain}EditViewModel.php            ← OBLIGATORIO
├── Service/
│   ├── {Domain}Service.php                        ← OBLIGATORIO (CRUD + transiciones)
│   ├── {Domain}DocumentService.php                ← OBLIGATORIO si tiene archivos
│   ├── {Domain}HistoryService.php                 ← OBLIGATORIO si registra auditoría
│   └── Pipeline/{Domain}/                         ← OBLIGATORIO si tiene pipeline
│       ├── {Domain}PipelineState.php              ← interfaz/abstract
│       ├── {Domain}PipelineStateRegistry.php
│       └── State/{Estado}State.php                ← uno por estado
│
│   Extensiones OPCIONALES (sólo si la complejidad lo justifica):
│   ├── {Domain}PaymentService.php                 ← cuando hay pagos
│   ├── {Domain}ApprovalService.php                ← cuando hay aprobación externa
│   ├── {Domain}FilterService.php                  ← cuando index() tiene filtros complejos
│   ├── {Domain}FieldAccessPolicy.php              ← cuando hay campos editables por rol
│   └── Pipeline/{Domain}/Policy/*.php             ← cuando hay políticas por tipo doc
└── Controller/{Domain}Controller.php              ← delgado, delega a servicios
```

### Reglas de naming firmes

- Servicio principal **siempre** `{Domain}Service` (no `{Domain}PipelineService`). El State pattern queda en `Pipeline/{Domain}/`.
- ViewModels **siempre simétricos**: `{Domain}AddViewModel` y `{Domain}EditViewModel`. No `_build…ViewModel` privados en controllers.
- DocumentService **propio** por dominio (sin reuso cruzado). Si dos dominios comparten, extraer una interfaz en `Service/Interface/`.
- `Dto/`, `Subscriber/`, `Trait/` se aceptan, pero el flujo "promedio" no debe necesitarlos. Si aparecen, justificar en este archivo o en un ADR.

---

## 6. Plan de migración por prioridades

| Prioridad | Flujo | Acciones | Estado |
|---|---|---|---|
| 🔴 Alta | **PaymentSchedulings** | Renombrar `Attachment` → `Document`. Fusionar `…PipelineService` en `…Service` + extraer `Pipeline/PaymentScheduling/State/*`. Crear Add/Edit ViewModels. Crear `…HistoryService` si aplica. | **Pendiente — Plan A** |
| 🔴 Alta | **Novelties** | Renombrar `NoveltyPipelineService` → `NoveltyService`. Extraer `Pipeline/Novelty/State/*`. Crear Add/Edit ViewModels. Adelgazar controller (922 → ~600). | **Pendiente — Plan B** |
| 🟠 Media | **Refunds** | Reemplazar `Trait/RefundPipelineHelpersTrait` por `Pipeline/Refund/State/*`. Crear Add/Edit ViewModels. Evaluar si `Dto/RefundSyntheticPayment` y `Subscriber/RefundOutcomeSubscriber` se generalizan o se quedan como caso particular justificado. | **Completado — Plan C** |
| 🟡 Media | **Advances** | Crear `AdvanceLegalizationDocumentService` propio. Convertir `_buildLegalizationViewModel` privado en `AdvanceLegalizationViewModel`. Crear `AdvanceAddViewModel`. | **Completado — Plan D** |
| 🟢 Baja | **Invoices** | Crear `InvoiceAddViewModel` para simetría. Resto se mantiene — sus servicios extra están justificados. | Backlog (no entra en opción 2) |
| 🟢 Baja | **PettyCash** | Ya cumple. Ningún cambio. | ✅ OK |

---

## 7. Alcance de la opción 2 elegida

Solo se ejecutan **Plan A (PaymentSchedulings)** y **Plan B (Novelties)**.

**Razón:** Son los más rotos (sin ViewModels, sin State pattern, controllers obesos), tocan dominios independientes entre sí, y dejarlos al final hace más costoso cualquier cambio futuro. Refunds tiene Trait que funciona y Advances tiene VM "fantasma" tolerable — pueden esperar a que se toquen por otro motivo.

**Cada plan se trabajará en sesión propia** siguiendo el flujo del proyecto: brainstorming → spec → writing-plans → ejecución → merge.

**Validación:** Al ser proyecto sin tests automatizados (ver `CLAUDE.md` — sección Testing Policy), cada plan debe incluir criterios de validación manual (pasos concretos en el navegador / `curl`) en lugar de fixtures de PHPUnit.

---

## 8. Estado de los planes

| Plan | Flujo | Estado | Fecha cierre |
|---|---|---|---|
| Plan A | PaymentSchedulings | 🟢 Completado | 2026-05-06 |
| Plan B | Novelties | 🟢 Completado | 2026-05-06 |
| Plan C | Refunds | 🟢 Completado | 2026-05-06 |
| Plan D | Advances | 🟢 Completado | 2026-05-06 |

---

## 9. Cambios a esta auditoría

> Cualquier desviación (fusionar planes, cambiar el alcance, mover ítems del backlog al activo) debe quedar registrada aquí con fecha y razón.

- **2026-05-06** — Creación inicial. Adopción de opción 2.
- **2026-05-06** — Activación Plan C (Refunds). Se promueve desde Backlog. Justificación: continuación natural tras Plan A/B completados; los hallazgos de Refunds (DTO mal generalizado en PettyCash) salieron a la luz solo al rediseñarlo.
- **2026-05-06** — Desviación Plan C: el `Trait/RefundPipelineHelpersTrait` se elimina por completo (audit pidió "reemplazar por State/*"; en realidad el trait también tenía RBAC + helpers no-pipeline que se inlinearon en cada service).
- **2026-05-06** — Hallazgo Plan C: `Dto/RefundSyntheticPayment` se renombra a `BulkPaymentView` y se promueve a convención compartida del proyecto. **PettyCash queda con `_buildSyntheticPayments` legacy `(object)[...]`** pendiente de adoptar el Dto. No es parte de este plan; se anota como deuda para su próxima sesión.
- **2026-05-06** — Plan C también deja constancia de que `Subscriber/RefundOutcomeSubscriber` se conserva como patrón sano del proyecto (3 Subscribers ya: `LegalizationInitializer`, `LinkedInvoicesPromoter`, `RefundOutcome` — convención, no excepción).
- **2026-05-06** — Activación Plan D (Advances). Se promueve desde Backlog. Justificación: continuación natural tras Plan C; cierra el cuarto flujo del audit.
- **2026-05-06** — Desviación Plan D: ViewModels nombrados `AdvanceAddViewModel` + `AdvanceLegalizationViewModel` (no `AdvanceEditViewModel`). Razón: un Anticipo es internamente una `Invoice` con `document_type=ANTICIPO`; `AdvancesController::edit()` solo redirige a `InvoicesController::edit()`. La simetría real del flujo es Add (crear anticipo) + Legalization (proceso post-pago), no Add/Edit clásico.
- **2026-05-06** — Hallazgo Plan D: el item original "Advances reusa `InvoiceDocumentService`" estaba **desactualizado** — la realidad era que `AdvanceLegalizationService` usaba `DocumentUploadTrait` directamente (no había service de documentos en absoluto). El nuevo `AdvanceLegalizationDocumentService` cierra la deuda real.
- **2026-05-06** — Desviación Plan D: el método `addLegalizationItem` referenciado en el plan no existe en el código actual (probablemente quedó del diseño preliminar). Solo se migró `attachRelationDocument` al document service. `confirmShortageReceipt` sigue usando `validateAndMoveUpload` directo del trait — se anota como deuda menor; no estaba en alcance del audit.
