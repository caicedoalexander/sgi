# Plan de unificación — Sistema de permisos (segunda ola)

**Fecha:** 2026-05-12
**Continuación de:** `permissions-audit-2026-05-11.md` (cerrado RESUELTO) + `permissions-pattern-inventory-2026-05-12.md` (inventario read-only).
**Alcance:** 6 módulos de pipeline + 3 sub-controllers. 7 dimensiones decididas. 5 PRs propuestas.
**Decisión rectora:** consolidar el "estado vive en la entity" como modelo canónico (modelo Advance) y propagarlo al resto. Eliminar los 3 puntos donde `canAdvance` ignora permisos del rol.

---

## Resumen de decisiones

| Dim | Decisión | Resultado |
|-----|----------|-----------|
| **D1** | Modelo B: estado en entity (`$entity->canX()`). Policy compone `_canOperate && entity->canX()`. | Migrar 4 entities + 4 policies |
| **D2** | WONTFIX existencia (Advance y PaymentScheduling no tienen form edit con secciones por rol×step). | Aceptar divergencia legítima |
| **D2 sub** | 3 limpiezas cosméticas en `InvoiceFieldAccessPolicy`. | Move + eliminar 2 métodos redundantes |
| **D3** | Extender `DenialReason` con `REQUIRES_PAYMENT` y `MANAGED_ELSEWHERE`. Extraer `denialReasonForAdvance` en `RefundService`. | Refund completa el patrón |
| **D3 sub** | WONTFIX: Novelty regress (no existe en dominio), Advance (modelo de transiciones específicas). | Documentar criterio |
| **D4.a** | Extender policies del dominio padre (no crear sub-policies). | InvoiceActionPolicy + NoveltyActionPolicy ganan métodos |
| **D4.b** | Investigar y unificar `canAdvance` de Novelty (confirmado: hoy ignora permisos). | Bug latente, corregir |
| **D5** | WONTFIX. Patrón natural ya unificado (helper donde hay Facade directo, ad hoc donde hay policy). | Sin cambios |
| **D6** | Controllers computan TODOS los flags. ViewModels reciben bools. AdvanceLegalizationViewModel pierde dependencia a ActionPolicy. | Migrar 3 ViewModels |
| **D7** | Flash en advance/regress fallidos consume `$denial->message()`. | Migrar 3 controllers |

---

## 🔴 Bug latente confirmado (motivación principal del plan)

**3 módulos calculan `canAdvance` sin chequear permisos del rol:**

| Módulo | Cómputo actual de `canAdvance` | Chequea permisos |
|---|---|:--:|
| **PettyCash** (`PettyCashEditViewModel:99`) | `$nextStatus !== null` | ❌ |
| **Refund** (`RefundEditViewModel:113`) | `$nextStatus !== null` | ❌ |
| **Novelty** (`EmployeeNoveltiesController:439-442`) | `!isRejected() && !isPaid() && !isGrouped() && nextStatus !== null` | ❌ |

vs el patrón correcto:

| Módulo | Cómputo |
|---|---|
| **Invoice** | `denialReasonForAdvance === null` |
| **PaymentScheduling** | `denialReasonForAdvance === null` |

**Consecuencia UX:** roles sin permiso de avanzar **ven el botón "Avanzar"** en el template. El server-side bloquea, pero el mensaje de error puede ser confuso ("No tiene permisos" hardcoded vs el motivo real del denial).

PR3 cierra este bug.

---

## Plan de migración (orden de PRs)

Una PR por bloque lógico. El orden importa por dependencias.

### PR1 — Entity predicates (foundation)

**Por qué primero:** todo el resto depende de que existan los predicates `canX()` en las entities.

**Cambios:**
- `src/Model/Entity/Invoice.php` → agregar `canRegisterPayment()`, `canAuthorizePayment()`, `canConfirmPayment()`
- `src/Model/Entity/Refund.php` → idem 3 métodos
- `src/Model/Entity/PettyCashRecord.php` → idem 3 métodos
- `src/Model/Entity/PaymentScheduling.php` → agregar `canReject()`, `canConfirmPayment()`
- `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php` → composer `_canOperate && $invoice->canX()`
- `src/Service/Pipeline/Refund/Policy/RefundActionPolicy.php` → idem
- `src/Service/Pipeline/PettyCash/Policy/PettyCashActionPolicy.php` → idem
- `src/Service/Pipeline/PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php` → idem

**LoC aprox:** +60 / -30
**Riesgo:** Bajo (predicates triviales basados en `is*` ya existentes)

**Validación manual:**
1. Como Tesorería en factura en `tesoreria`: ver botón "Registrar pago" → click → registra OK.
2. Como Contabilidad (sin permiso de pipeline `tesoreria`) en la misma factura: ver botón ausente o disabled.
3. Como Contador en factura en `autorizacion_pago`: ver botón "Autorizar pago" → autoriza OK.
4. Repetir flujos equivalentes en Refund, PettyCash, PaymentScheduling.

---

### PR2 — DenialReason extension + Refund completo

**Cambios:**
- `src/Constants/Domain/Pipeline/DenialReason.php` → agregar casos `REQUIRES_PAYMENT` y `MANAGED_ELSEWHERE` con `message()` correspondiente.
- `src/Service/RefundService.php` → extraer `denialReasonForAdvance(Refund, int $roleId): ?DenialReason` desde los 4 ifs inline de `advanceStatus()`. `advanceStatus()` lo consume internamente y traduce el `?DenialReason` a `['success', 'error']` para no romper el caller.

**LoC aprox:** +40 / -15
**Riesgo:** Bajo

**Validación manual:**
1. En Refund en estado final (`pagada`): `denialReasonForAdvance` retorna `TERMINAL_STATE`.
2. En Refund en `tesoreria` sin pagos registrados: retorna `REQUIRES_PAYMENT`.
3. En Refund en `autorizacion_pago` (avance vía botón advance, no via sección pagos): retorna `MANAGED_ELSEWHERE`.
4. Rol sin permiso para el step actual: retorna `UNAUTHORIZED`.
5. `RefundsController::advanceStatus` sigue mostrando los mismos mensajes en Flash que antes (delegados a `$denial->message()`).

---

### PR3 — Controllers computan flags + Flash unificado (cierra el bug latente)

**Cambios:**
- `src/Controller/PettyCashRecordsController.php::_buildEditViewModel` → computar `$canAdvance = $service->denialReasonForAdvance($record, $roleId) === null` y pasarlo al ViewModel.
- `src/ViewModel/PettyCashEditViewModel.php` → recibir `canAdvance` en lugar de computarlo internamente.
- `src/Controller/RefundsController.php::_buildEditViewModel` → idem.
- `src/ViewModel/RefundEditViewModel.php` → idem.
- `src/Controller/EmployeeNoveltiesController.php::_buildEditViewModel` → reemplazar el cómputo manual (líneas 439-442) por `denialReasonForAdvance === null`.
- Acciones `advance*`/`regress*` en `InvoicesController`, `PettyCashRecordsController`, `RefundsController`, `EmployeeNoveltiesController`: cuando `$denial !== null`, `Flash->error($denial->message())` en vez de `firstError()`.
- `src/ViewModel/AdvanceLegalizationViewModel.php` → eliminar dependencia a `AdvanceLegalizationActionPolicy`. Constructor recibe `bool $canRegisterRefund`, `bool $canAuthorizeRefundPayment`, `bool $canConfirmRefundPayment` ya computados.
- `src/Controller/AdvancesController.php` → computar esos 3 flags antes de instanciar el ViewModel.

**LoC aprox:** +50 / -40
**Riesgo:** Medio (cambia firmas de ViewModels — verificar que no haya callers fuera del controller)

**Validación manual:**
1. Como rol sin permiso de avanzar en cualquier módulo: **botón "Avanzar" no aparece** (antes aparecía en PettyCash/Refund/Novelty).
2. Acción `advanceStatus` con denial: Flash dice el motivo específico (`->message()`) y no string genérico.
3. Acción `regress` con denial: idem.
4. Flujo de Advance en CASE_SOBRANTE (rol Contabilidad registrar reintegro): los 3 botones siguen apareciendo/no según rol exactamente como antes.

---

### PR4 — Sub-controllers migran a ActionPolicy

**Cambios:**
- `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php` → agregar `canAddPayment`, `canEditPayment`, `canRejectPayment`, `canDeletePayment`. Componer `_canOperate && $invoice->canX()` (predicates añadidos en PR1 si aplican; o crear los faltantes en este PR).
- `src/Service/Pipeline/Novelty/Policy/NoveltyActionPolicy.php` → agregar métodos para 4 acciones de `NoveltyLiquidationDocsController` + 4 de `LiquidationDocPaymentsController`.
- `src/Model/Entity/Invoice.php` → predicates faltantes para los 4 nuevos métodos (si no fueron creados en PR1).
- `src/Model/Entity/NoveltyLiquidationDoc.php` → predicates si aplica.
- `src/Controller/InvoicePaymentsController.php` → eliminar los 6 `authFacade->canOperate` inline. Reemplazar por `actionPolicy->canX` que ya incluye el chequeo de estado del registro.
- `src/Controller/LiquidationDocPaymentsController.php` → idem (4 calls).
- `src/Controller/NoveltyLiquidationDocsController.php` → idem (3 calls).

**LoC aprox:** +80 / -60
**Riesgo:** Medio (3 controllers tocados, pero el cambio es mecánico)

**Validación manual:**
1. Tesorería registra pago en factura en `tesoreria`: OK.
2. Tesorería intenta registrar pago en factura ya pagada: bloqueado con `Flash->error` razonable.
3. Contador autoriza pago en factura en `autorizacion_pago`: OK.
4. Contador intenta autorizar pago en factura en `contabilidad` (step incorrecto): bloqueado.
5. Equivalentes en LiquidationDocPayments y NoveltyLiquidationDocs.

---

### PR5 — Limpiezas cosméticas D2

**Cambios:**
- `mv src/Service/InvoiceFieldAccessPolicy.php src/Service/Pipeline/Invoice/Policy/InvoiceFieldAccessPolicy.php`
- Actualizar namespace `App\Service\Pipeline\Invoice\Policy`.
- Actualizar `use` en consumers (`InvoicePipelineService`, `InvoiceTransitionValidator`, controllers, DI container).
- Eliminar método `getCollapsibleSections()` (retorna `[]` vacío, sin consumers reales).
- Eliminar el caller `getCollapsibleSections()` en `InvoicePipelineService` si existe.
- Eliminar override redundante de `getEditableFields()` — el guard `PipelineStatus::tryFrom($status) === null` es redundante con el chequeo de `canOperate` en la base (verificar primero que no haya un edge case donde difieran).

**LoC aprox:** -50 / +5
**Riesgo:** Bajo (refactor mecánico, sin cambio de comportamiento)

**Validación manual:**
1. `composer dump-autoload`.
2. Levantar server, abrir un invoice edit → secciones se renderizan idénticas a antes.
3. Roles parciales (Contabilidad, Tesorería, Contador) ven exactamente las mismas secciones que antes del refactor.

---

## Riesgo global y orden

**Saldo estimado:** ~180 LoC añadidos, ~145 eliminados, neto ~+35. Conceptualmente, el sistema queda con:
- **Un solo modelo** para combinar "estado del agregado" con "rol × paso" (modelo Advance generalizado).
- **Una sola fuente** para `canAdvance`/`canRegress` (`denialReasonForX === null`).
- **Una sola convención** de Flash en acciones de pipeline fallidas (`$denial->message()`).
- **Una sola ubicación** para las FieldPolicies (`src/Service/Pipeline/{Module}/Policy/`).

**Dependencias entre PRs:** PR1 → PR4 (PR4 reusa los predicates de PR1, con posibles extensiones). PR2 → PR3 (PR3 consume `denialReasonForAdvance` en Refund). PR5 es independiente.

**Orden recomendado de merge:** PR1 → PR2 → PR3 → PR4 → PR5.

---

## Excepciones permitidas (documentadas)

| Excepción | Justificación |
|-----------|---------------|
| Advance no usa `advance/regress` ni `denialReasonForX` | Su dominio es 13 transiciones específicas, no advance/regress binario. La policy ya modela cada transición. |
| Novelty no tiene `regress` ni `denialReasonForRegress` | El dominio no permite regresar. Confirmado: `grep -r regress src/Service/NoveltyService.php` retorna 0. |
| Advance y PaymentScheduling no tienen `FieldAccessPolicy` | Ninguno tiene form edit con secciones por rol×step. Sus formularios usan acciones discretas (Advance) o gating por estado del registro, no por rol (PaymentScheduling). |
| `_userContext()` se usa solo en sub-controllers y AppController | Es el punto natural — los controllers principales delegan en `actionPolicy` que construye `UserContext` internamente. |

**Criterio de reapertura para cualquier excepción:** si surge un caso de uso futuro donde la divergencia genera bugs o ambigüedad, abrir nuevo audit. Mientras tanto, la documentación de esta excepción es suficiente.

---

## Estado de los Tasks

| Task | Estado | PR asociada |
|------|--------|-------------|
| #9 PR1 — Entity predicates | pending | PR1 |
| #10 PR2 — DenialReason + Refund | pending | PR2 |
| #11 PR3 — Controllers + Flash | pending | PR3 |
| #12 PR4 — Sub-controllers | pending | PR4 |
| #13 PR5 — Limpiezas D2 | pending | PR5 |
| #14 Documentación del plan | in_progress | (este archivo) |

---

## Anexo — Refinamientos pre-implementación (revisión 2026-05-12)

Resultado de una revisión de coherencia plan↔código. Cierra ambigüedades detectadas antes de empezar PR1. **No cambia las decisiones rectoras**, solo precisa contrato y reasigna 4 predicates entre PRs.

### A1 — Convención de nombres: "Advance" ⇒ `AdvanceLegalization`

A lo largo del plan, "modelo Advance" se refiere al **dominio de legalizaciones de anticipos** y a la entity `AdvanceLegalization` (no existe `Advance.php`). Para un dev nuevo: cuando el plan dice "los predicates `canX()` que Advance ya tiene", el archivo a leer es `src/Model/Entity/AdvanceLegalization.php` (13 predicates: `canLinkInvoices`, `canMoveToRevision`, `canRegisterRefund`, `canAuthorizeRefundPayment`, `canConfirmRefundPayment`, etc.).

### A2 — Contrato de los `canX()` en entity (decisión D1, precisión)

Los predicates en entity **no reciben rol ni `UserContext`**. Solo encapsulan estado del agregado.

```php
// ✅ Correcto — solo estado
public function canRegisterPayment(): bool
{
    return $this->isTesoreria() && !$this->isRejected();
}

// ❌ Incorrecto — el rol vive en la policy
public function canRegisterPayment(int $roleId): bool { ... }
```

La composición con rol vive en la policy: `_canOperate($roleId, $step) && $entity->canX()`. Modelo verificado en `AdvanceLegalization::canRegisterRefund()` y similares.

### A3 — Reasignación de predicates Invoice: PR4 ⇒ PR1

PR1 cubre los 3 predicates de pipeline avance/regreso. Pero PR4 introduce 4 predicates más para sub-controllers (`canAddPayment`, `canEditPayment`, `canRejectPayment`, `canDeletePayment`) que también son **estado del agregado**, no responsabilidad de policy. Para mantener una única "capa entity layer" en PR1:

- **PR1 ahora cubre los 7 predicates de Invoice** (3 pipeline + 4 pagos), no solo 3.
- **PR4 queda puramente como "controllers + policies extendidas"**, sin tocar entities.
- Mismo criterio para `NoveltyLiquidationDoc` si los 4 métodos de su sub-controller necesitan predicates: van a PR1.

**LoC PR1 ajustado:** +75 / -30 (era +60 / -30). **LoC PR4 ajustado:** +60 / -60 (era +80 / -60).

### A4 — Mapeo de los 4 ifs de Refund (PR2, snippet)

Los 4 ifs actuales en `RefundService::advanceStatus()` (líneas 122-142) y su mapeo al enum extendido:

| If actual (líneas) | Mensaje hardcoded actual | `DenialReason` propuesto |
|---|---|---|
| `$nextStatus === null` (122) | `'Este registro ya está en su estado final.'` | `TERMINAL_STATE` |
| `$nextStatus === STATUS_AUTORIZACION_PAGO` (126) | `'Debe registrar un pago para avanzar desde Tesorería.'` | `REQUIRES_PAYMENT` |
| `$currentStatus === STATUS_AUTORIZACION_PAGO` (130) | `'La autorización de pago se gestiona desde la sección de pagos.'` | `MANAGED_ELSEWHERE` |
| `!auth->canOperate(...)` (134) | `'No tiene permisos para avanzar este registro.'` | `UNAUTHORIZED` |

`DenialReason::message()` debe coincidir literal con los mensajes actuales para no cambiar UX. Casos nuevos a agregar:

```php
case REQUIRES_PAYMENT = 'requires_payment';
case MANAGED_ELSEWHERE = 'managed_elsewhere';

// en message():
self::REQUIRES_PAYMENT  => 'Debe registrar un pago para avanzar desde Tesorería.',
self::MANAGED_ELSEWHERE => 'La autorización de pago se gestiona desde la sección de pagos.',
```

`denialReasonForRegress` (líneas 362-379) **queda como está** — ya devuelve `TERMINAL_STATE`/`UNAUTHORIZED` correctamente.

### A5 — Verificación de callers de los 3 ViewModels (PR3, riesgo bajado a Bajo)

```text
$ grep -rn "new (PettyCashEditViewModel|RefundEditViewModel|AdvanceLegalizationViewModel)" src/
src\Controller\AdvancesController.php:299:        $vm = new AdvanceLegalizationViewModel(
src\Controller\PettyCashRecordsController.php:318:        return new PettyCashEditViewModel(
src\Controller\RefundsController.php:417:        return new RefundEditViewModel(
```

Cada ViewModel se construye en exactamente 1 lugar (su controller). PR3 puede cambiar firmas con confianza. **Riesgo PR3 baja de Medio a Bajo.**

### A6 — `getCollapsibleSections` SÍ tiene consumers (PR5, ajuste obligatorio)

La afirmación del plan PR5 *"retorna `[]` vacío, sin consumers reales"* es **parcialmente falsa**. El método retorna `[]` (correcto), pero la cadena de consumers existe:

```text
src\Service\InvoiceFieldAccessPolicy.php:107      → return [];
src\Service\InvoicePipelineService.php:69-71      → delegate público
src\Controller\InvoicesController.php:407         → lo pasa al ViewModel como collapsibleSections
src\Model\ViewModel\InvoiceEditViewModel          → recibe collapsibleSections en constructor
```

Eliminar el método requiere:

1. Borrar `InvoiceFieldAccessPolicy::getCollapsibleSections()` (109).
2. Borrar `InvoicePipelineService::getCollapsibleSections()` (69-71).
3. Borrar la línea `collapsibleSections: ...` en `InvoicesController:407`.
4. Borrar el parámetro `collapsibleSections` del constructor de `InvoiceEditViewModel` y la propiedad asociada.
5. Buscar usos del campo `collapsibleSections` en templates (`grep -rn collapsibleSections templates/`) y removerlos.

**LoC PR5 ajustado:** -65 / +5 (era -50 / +5). **Riesgo PR5 sigue Bajo** porque el contrato siempre devuelve `[]` — los consumers nunca recibieron data útil, solo la firma.

### Resumen de cambios al plan original

| Cambio | Origen | Impacto |
|---|---|---|
| 4 predicates de pagos Invoice se mueven de PR4 → PR1 | A3 | PR1 +15 LoC, PR4 -20 LoC |
| `getCollapsibleSections` requiere 5 puntos de borrado, no 2 | A6 | PR5 -15 LoC adicionales |
| Naming `AdvanceLegalization` aclarado | A1 | Cero LoC |
| Contrato predicate sin parámetros fijado | A2 | Cero LoC, evita iteraciones de PR |
| Mapping ifs Refund explícito | A4 | Cero LoC nuevo, evita una vuelta de revisión |
| Callers de ViewModels confirmados (1 cada uno) | A5 | Riesgo PR3 baja a Bajo |

**Saldo neto del plan revisado:** ~+25 LoC (era ~+35). Orden de PRs y dependencias se mantienen.
