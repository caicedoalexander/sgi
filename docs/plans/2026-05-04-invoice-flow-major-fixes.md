# Fixes Major — Flujo de Control de Facturas — Plan de implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Cerrar los 7 Major pendientes de la auditoría `docs/audits/invoice-flow-audit-2026-05-04.md` — robustez transaccional en pagos, fidelidad de historial en aprobaciones multi-aprobador, eliminación del N+1 en listings y endurecimientos defensivos en queries y visibilidad.

**Architecture:** Cambios quirúrgicos en servicios existentes — no se introducen capas, abstracciones ni patrones nuevos. Cada tarea aísla su archivo y se valida manualmente antes de continuar.

**Tech Stack:** PHP 8.2 / CakePHP 5.3 / MySQL — `transactional()`, `epilog('FOR UPDATE')`, `bind()` con expresiones SQL, ORM contain/where existentes.

**Política de testing:** Este proyecto **no usa tests automatizados** (ver `CLAUDE.md`). Cada tarea cierra con un bloque de validación manual concreto (navegador o `curl`).

**Auditoría base:** `docs/audits/invoice-flow-audit-2026-05-04.md`

**Convenciones a respetar:**
- Servicios obtienen tablas vía `TableRegistry::getTableLocator()->get(...)`.
- Servicios retornan `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`.
- Métodos privados con prefijo `_`.
- Constantes en `src/Constants/` — no hardcodear strings de estado.
- `composer cs-check` debe estar limpio en los archivos tocados.

---

## Tarea 0: Preparación

**Step 1: Verificar rama limpia**

```bash
git status
```

Esperado: working tree clean (los Critical ya están commiteados o stashed).

**Step 2: Confirmar que la auditoría está en disco**

```bash
ls docs/audits/invoice-flow-audit-2026-05-04.md
```

Esperado: el archivo existe.

**Step 3: Snapshot del estado actual**

Anotar el hash actual: `git rev-parse HEAD`. Sirve como punto de retorno si algún fix rompe algo.

---

## Tarea 1 (MJ-001): Verificar retornos en `authorizePayment`

**Files:**
- Edit: `src/Service/InvoicePaymentService.php`

**Problema:** En el closure de `transactional` (líneas 121-178), `recalculatePaymentStatus` y `$invoicesTable->save($invoice)` retornan bool ignorado. Si fallan, la TX continúa con datos inconsistentes y el método retorna éxito.

**Step 1: Verificar la firma de `recalculatePaymentStatus`**

```bash
grep -n "function recalculatePaymentStatus" src/Service/InvoicePaymentService.php
```

Confirmar que retorna `bool`. Si no retorna nada útil para detectar fallo, agregar una verificación equivalente (ver step 3 del bug-fixer).

**Step 2: Forzar rollback ante fallo de save de la factura**

En `src/Service/InvoicePaymentService.php`, dentro del closure de `authorizePayment`, reemplazar:

```php
$invoice->pipeline_status = $newPipelineStatus;
$invoicesTable->save($invoice);
```

por:

```php
$invoice->pipeline_status = $newPipelineStatus;
if (!$invoicesTable->save($invoice)) {
    return false; // → rollback
}
```

**Step 3: Forzar rollback ante fallo de `recalculatePaymentStatus`**

Si `recalculatePaymentStatus` retorna bool, cambiar:

```php
$this->recalculatePaymentStatus($payment->invoice_id);
```

por:

```php
if (!$this->recalculatePaymentStatus($payment->invoice_id)) {
    return false;
}
```

Si no retorna bool, hacer que retorne bool (o agregar excepción explícita ante fallo) y aplicar el mismo guard. Preferir bool para mantener el patrón del closure.

**Step 4: Lint y style**

```bash
php -l src/Service/InvoicePaymentService.php
composer cs-check -- src/Service/InvoicePaymentService.php
```

Esperado: sin errores nuevos. Errores preexistentes en otros métodos pueden ignorarse (no agravar).

**Validación manual:**

1. Levantar servidor: `php bin/cake server`.
2. Como rol Contador, autorizar un pago de una factura en `autorizacion_pago` — debe avanzar a `pagada` (o regresar a `tesoreria` si parcial). Comportamiento idéntico al previo.
3. Verificar `invoice_histories`: hay un `recordStatusChange` por la operación (sin duplicados).
4. Caso negativo (opcional): introducir temporalmente un error de validación en `Invoice` (p. ej. setear un campo requerido a null) y autorizar otro pago — verificar que NO se persiste el cambio de pipeline_status (rollback efectivo). Revertir el cambio temporal.

---

## Tarea 2 (MJ-002): Envolver rama no-refund de `rejectPayment` en `transactional()`

**Files:**
- Edit: `src/Service/InvoicePaymentService.php`

**Problema:** Líneas 327-345 — la rama no-refund persiste `payment.status=rejected` y luego intenta regresar la factura a `tesoreria`. Si el segundo `save` falla, queda inconsistencia. Hay un comentario explícito reconociéndolo.

**Step 1: Reemplazar la rama no-refund por una versión transaccional**

En `src/Service/InvoicePaymentService.php`, reemplazar el bloque desde el comentario `// No-refund: comportamiento original (sin transactional, ...)` (línea ~327) hasta el `return ServiceResult::ok(...)` final, por:

```php
// No-refund: marcar pago rechazado, regresar la factura a tesorería
// y registrar historial — todo dentro de una sola TX.
$connection = $paymentsTable->getConnection();
$ok = $connection->transactional(function () use (
    $paymentsTable,
    $invoicesTable,
    $payment,
    $invoice,
    $invoiceId,
    $previousStatus,
    $reason,
    $rejectedBy,
): bool {
    $payment->status = InvoiceConstants::PAYMENT_RECORD_REJECTED;
    $payment->rejection_reason = $reason;

    if (!$paymentsTable->save($payment)) {
        return false;
    }

    $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
    if (!$invoicesTable->save($invoice)) {
        return false;
    }

    $this->historyService->recordStatusChange(
        $invoiceId,
        $previousStatus,
        InvoiceConstants::STATUS_TESORERIA,
        $rejectedBy,
    );

    return true;
});

if ($ok === false) {
    return ServiceResult::fail('No se pudo rechazar el pago.');
}

return ServiceResult::ok('Pago rechazado. Factura devuelta a Tesorería.');
```

**Step 2: Eliminar el comentario obsoleto**

El comentario `// No-refund: comportamiento original (sin transactional, fuera del scope del Plan 5).` ya no aplica — reemplazado por el nuevo comentario en el step 1.

**Step 3: Lint y style**

```bash
php -l src/Service/InvoicePaymentService.php
composer cs-check -- src/Service/InvoicePaymentService.php
```

**Validación manual:**

1. Como Contador, rechazar un pago no-refund (factura no-Anticipo) en `autorizacion_pago` con un motivo válido — la factura debe regresar a `tesoreria` y el pago debe quedar `rejected` con `rejection_reason` poblado.
2. Verificar entrada en `invoice_histories` con field `pipeline_status` y old/new correctos.
3. Verificar que el rechazo de un refund (factura Anticipo) sigue funcionando (no se rompe la rama refund preexistente).

---

## Tarea 3 (MJ-003): Status correcto en regresión post-autorización

**Files:**
- Edit: `src/Service/InvoicePipelineService.php`

**Problema:** Línea 313 — `recordStatusChange(..., InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_TESORERIA, ...)`. El literal `STATUS_PAGADA` asume que el avance previo fue a `pagada`, pero el destino real está en `$advanceNextStatus`.

**Step 1: Reemplazar el literal por la variable**

En `src/Service/InvoicePipelineService.php`, dentro del closure de `saveAndAdvance`, en el bloque "After advancing from autorizacion_pago", cambiar:

```php
$this->historyService->recordStatusChange(
    $invoice->id,
    InvoiceConstants::STATUS_PAGADA,
    InvoiceConstants::STATUS_TESORERIA,
    $userId,
);
```

por:

```php
$this->historyService->recordStatusChange(
    $invoice->id,
    $advanceNextStatus,            // estado al que se avanzó (puede no ser pagada)
    InvoiceConstants::STATUS_TESORERIA,
    $userId,
);
```

> **Nota:** `$advanceNextStatus` está disponible en el scope porque ya se reasigna a `STATUS_TESORERIA` 3 líneas arriba; **debe leerse antes** de la reasignación. Reordenar si fuese necesario, o introducir variable local `$intermediateStatus` capturando el valor previo.

**Step 2: Aplicar reordenamiento si aplica**

Si `$advanceNextStatus` ya fue reasignado a `STATUS_TESORERIA` antes del `recordStatusChange`, capturar el valor previo:

```php
if ($refreshed->payment_status === InvoiceConstants::PAYMENT_PARTIAL) {
    $intermediateStatus = $advanceNextStatus; // capturar antes de reasignar
    $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
    $advanceNextStatus = InvoiceConstants::STATUS_TESORERIA;
    $invoicesTable->save($invoice);
    $this->historyService->recordStatusChange(
        $invoice->id,
        $intermediateStatus,
        InvoiceConstants::STATUS_TESORERIA,
        $userId,
    );
}
```

**Step 3: Lint y style**

```bash
php -l src/Service/InvoicePipelineService.php
composer cs-check -- src/Service/InvoicePipelineService.php
```

**Validación manual:**

1. Como Contador, autorizar un pago parcial — la factura debe terminar en `tesoreria`.
2. Verificar `invoice_histories`: la fila debe mostrar `from='pagada'` (estado intermedio real) y `to='tesoreria'`. Antes mostraba lo mismo por casualidad; el cambio garantiza correctitud futura si `getNextStatus` cambia por document_type policy.

---

## Tarea 4 (MJ-004): Subquery `unread_observations` con bindings

**Files:**
- Edit: `src/Controller/InvoicesController.php`

**Problema:** Líneas 486-498 — la subquery interpola `$userId` directamente en string. Hoy es seguro por casts upstream, pero la firma `?int $userId` no garantiza la convención si en el futuro se rompe.

**Step 1: Cambiar firma de `_buildInvoiceQuery`**

Cambiar la firma de:

```php
private function _buildInvoiceQuery(array $conditions = [], ?int $userId = null): SelectQuery
```

a:

```php
private function _buildInvoiceQuery(array $conditions = [], int $userId = 0): SelectQuery
```

Y adaptar la condición que controla la subquery:

```php
if ($userId > 0) {
    $query->selectAlso([...]);
}
```

**Step 2: Parametrizar la subquery con `bind`**

Reemplazar:

```php
$query->selectAlso([
    'unread_observations' => "(
        SELECT COUNT(*)
        FROM invoice_observations io
        LEFT JOIN invoice_reads ir
            ON ir.invoice_id = io.invoice_id AND ir.user_id = $userId
        WHERE io.invoice_id = Invoices.id
          AND io.user_id != $userId
          AND (ir.last_visited_at IS NULL OR io.created > ir.last_visited_at)
    )",
]);
```

por:

```php
$subquery = "(
    SELECT COUNT(*)
    FROM invoice_observations io
    LEFT JOIN invoice_reads ir
        ON ir.invoice_id = io.invoice_id AND ir.user_id = :uidJoin
    WHERE io.invoice_id = Invoices.id
      AND io.user_id != :uidWhere
      AND (ir.last_visited_at IS NULL OR io.created > ir.last_visited_at)
)";

$query
    ->selectAlso(['unread_observations' => $subquery])
    ->bind(':uidJoin', $userId, 'integer')
    ->bind(':uidWhere', $userId, 'integer');
```

**Step 3: Verificar callers**

```bash
grep -n "_buildInvoiceQuery" src/Controller/InvoicesController.php
```

Confirmar que todos los callers ya pasan `(int)$userId` (ya es el caso según la auditoría: líneas 81, 94, 109, 126, 222 aprox.). Si alguno no lo casteaba, agregar el cast.

**Step 4: Lint y style**

```bash
php -l src/Controller/InvoicesController.php
composer cs-check -- src/Controller/InvoicesController.php
```

**Validación manual:**

1. Loguearse y abrir `/invoices`. La columna/badge de "observaciones no leídas" debe seguir mostrando el conteo correcto (mismo comportamiento que antes).
2. Loguearse como otro usuario y dejar una observación; el primer usuario debe ver el badge incrementarse al recargar.
3. Marcar la factura como leída (entrar a view) — el badge debe desaparecer.

---

## Tarea 5 (MJ-006): Registrar `area_approval` change en historial al aprobar/rechazar

**Files:**
- Edit: `src/Service/InvoiceApprovalService.php`

**Problema:** `processResponse` (líneas 191-265) cambia `area_approval` a APPROVED/REJECTED pero no registra el cambio en `invoice_histories`. Solo queda evidencia en `invoice_approvals`.

**Step 1: Inyectar `HistoryServiceInterface` si no está presente**

```bash
grep -n "HistoryServiceInterface\|InvoiceHistoryService\|historyService" src/Service/InvoiceApprovalService.php
```

Si no está, agregarlo al constructor con el patrón de DI existente del proyecto (constructor con nullable + `?? new InvoiceHistoryService()`). Si ya está, saltar este step.

**Step 2: Registrar el cambio en la rama de rechazo**

Dentro del bloque `if ($action === 'reject') { ... }`, después del `$invoicesTable->save($invoice);` (línea ~240) y antes del `return ServiceResult::ok([...])`, agregar:

```php
$this->historyService->recordFieldChange(
    $invoiceId,
    'area_approval',
    InvoiceConstants::APPROVAL_PENDING,
    InvoiceConstants::APPROVAL_REJECTED,
    (int)$approval->user_id,
);
```

> Si `recordFieldChange` requiere old_value real (no PENDING fijo), leer `$invoice->area_approval` ANTES del set y guardarlo en `$oldApproval` para pasarlo. La firma exacta debe consultarse en `InvoiceHistoryService`.

**Step 3: Registrar el cambio cuando `allApproved` activa APPROVED**

Dentro del `if ($allApproved) { ... }` (línea ~251), después del `$invoicesTable->save($invoice);` y antes del `return ServiceResult::ok([...])`, agregar:

```php
$this->historyService->recordFieldChange(
    $invoiceId,
    'area_approval',
    InvoiceConstants::APPROVAL_PENDING,
    InvoiceConstants::APPROVAL_APPROVED,
    (int)$approval->user_id,
);
```

**Step 4: Lint y style**

```bash
php -l src/Service/InvoiceApprovalService.php
composer cs-check -- src/Service/InvoiceApprovalService.php
```

**Validación manual:**

1. Crear factura nueva, asignar 2 aprobadores, abrir el link de aprobación del primero y aprobar. La factura debe seguir en `aprobacion`.
2. Abrir el link del segundo y aprobar. `area_approval` debe pasar a `Aprobada`.
3. Abrir la factura en `view` y revisar el bloque "Historial": debe haber una entrada nueva con field `area_approval` y old/new = `Pendiente → Aprobada`.
4. Repetir el flujo con rechazo en lugar de approve. Verificar que el historial muestra `Pendiente → Rechazada`.

---

## Tarea 6 (MJ-007): `_persistApprovers` y `_sendApprovalEmails` a `private`

**Files:**
- Edit: `src/Service/InvoiceApprovalService.php`

**Problema:** Líneas 69 y 115 — métodos con prefijo `_` declarados `public`. Convención del proyecto (CLAUDE.md): `_` = privado. Solo se usan internamente en el mismo service.

**Step 1: Confirmar que no hay callers externos**

```bash
grep -rn "_persistApprovers\|_sendApprovalEmails" src/ templates/
```

Esperado: solo referencias dentro de `InvoiceApprovalService.php`.

**Step 2: Cambiar visibilidad**

En `src/Service/InvoiceApprovalService.php`:

- Línea 69: `public function _persistApprovers(...)` → `private function _persistApprovers(...)`
- Línea 115: `public function _sendApprovalEmails(...)` → `private function _sendApprovalEmails(...)`

**Step 3: Lint y style**

```bash
php -l src/Service/InvoiceApprovalService.php
composer cs-check -- src/Service/InvoiceApprovalService.php
```

**Validación manual:**

1. Asignar aprobadores a una factura nueva (`POST /invoices/send-approval-links/{id}`). Debe persistir filas en `invoice_approvals` y enviar correos.
2. Modificar aprobadores con motivo (`POST /invoices/modify-approvers/{id}`). Debe invalidar tokens previos y emitir nuevos.
3. Si todo funciona idéntico al estado anterior, el cambio de visibilidad fue transparente.

---

## Tarea 7 (MJ-008): Eliminar N+1 en `_getApprovalSummaries`

**Files:**
- Edit: `src/Controller/InvoicesController.php`
- Edit (opcional): `src/Service/InvoiceApprovalService.php` (agregar método batch)

**Problema:** Líneas 507-517 — para cada factura en `aprobacion`, se invoca `getApprovalSummary` que internamente hace `getCurrentApprovals` con `contain(['Users'])`. 15 facturas → hasta 30 queries.

**Step 1: Agregar `getApprovalSummariesBatch` a `InvoiceApprovalService`**

En `src/Service/InvoiceApprovalService.php`, agregar:

```php
/**
 * Devuelve los summaries de aprobación para múltiples facturas en una sola query.
 *
 * @param array<int> $invoiceIds
 * @return array<int, array> indexado por invoice_id
 */
public function getApprovalSummariesBatch(array $invoiceIds): array
{
    if (empty($invoiceIds)) {
        return [];
    }

    $rows = $this->invoiceApprovalsTable->find()
        ->where([
            'InvoiceApprovals.invoice_id IN' => $invoiceIds,
            'InvoiceApprovals.status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
        ])
        ->contain(['Users'])
        ->orderBy(['InvoiceApprovals.created' => 'ASC'])
        ->all()
        ->toArray();

    $byInvoice = [];
    foreach ($rows as $row) {
        $byInvoice[$row->invoice_id][] = $row;
    }

    $summaries = [];
    foreach ($invoiceIds as $id) {
        $summaries[$id] = $this->_summaryFromApprovals($byInvoice[$id] ?? []);
    }

    return $summaries;
}
```

> **Importante:** `_summaryFromApprovals` debe replicar exactamente la forma del retorno actual de `getApprovalSummary`. Si `getApprovalSummary` ya tiene un helper privado para construir el summary desde un array de approvals, reutilizarlo. Si no, extraer el cuerpo de `getApprovalSummary` a un método privado `_summaryFromApprovals(array $approvals): array` y dejar a `getApprovalSummary` como wrapper que llama `_summaryFromApprovals($this->getCurrentApprovals($id))`.

**Step 2: Reemplazar `_getApprovalSummaries` en el controller**

En `src/Controller/InvoicesController.php`, cambiar:

```php
private function _getApprovalSummaries($invoices): array
{
    $summaries = [];
    foreach ($invoices as $inv) {
        if ($inv->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
            $summaries[$inv->id] = $this->approvalService->getApprovalSummary($inv->id);
        }
    }

    return $summaries;
}
```

por:

```php
private function _getApprovalSummaries($invoices): array
{
    $ids = [];
    foreach ($invoices as $inv) {
        if ($inv->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
            $ids[] = (int)$inv->id;
        }
    }

    return $this->approvalService->getApprovalSummariesBatch($ids);
}
```

**Step 3: Lint y style**

```bash
php -l src/Service/InvoiceApprovalService.php src/Controller/InvoicesController.php
composer cs-check -- src/Service/InvoiceApprovalService.php src/Controller/InvoicesController.php
```

**Validación manual:**

1. Habilitar el debug toolbar (CakePHP DebugKit) o el log SQL: `Configure::write('debug', true)` y revisar `logs/queries.log`.
2. Abrir `/invoices` con al menos 5 facturas en `aprobacion` con aprobadores asignados. Antes: ~10 queries de approvals. Después: 1 query.
3. La columna/badge "estado de aprobación" en el listado debe mostrar lo mismo que antes (mismos aprobadores, mismos contadores).
4. Abrir una factura en `aprobacion` y confirmar que el summary individual sigue funcionando (`view` y `edit` invocan `getApprovalSummary`/`getCurrentApprovals` por separado y deben permanecer correctos).

---

## Tarea 8: Limpieza y commit

**Step 1: Diff total**

```bash
git diff --stat
```

Esperado: cambios solo en `src/Service/InvoicePaymentService.php`, `src/Service/InvoicePipelineService.php`, `src/Service/InvoiceApprovalService.php`, `src/Controller/InvoicesController.php`.

**Step 2: Lint global**

```bash
php -l src/Service/InvoicePaymentService.php
php -l src/Service/InvoicePipelineService.php
php -l src/Service/InvoiceApprovalService.php
php -l src/Controller/InvoicesController.php
composer cs-check -- src/Service/InvoicePaymentService.php src/Service/InvoicePipelineService.php src/Service/InvoiceApprovalService.php src/Controller/InvoicesController.php
```

**Step 3: Verificar que los Critical previos siguen funcionando**

Ejecutar la validación manual de la sección 8 de `docs/audits/invoice-flow-audit-2026-05-04.md` (CR-001/CR-002/CR-003) — debe seguir funcionando todo.

**Step 4: Actualizar la auditoría**

En `docs/audits/invoice-flow-audit-2026-05-04.md`, mover los 7 Major a la subsección "Resueltos" y actualizar:

- Tabla de "Resumen ejecutivo": Major 0 pendientes.
- Category Summary.
- Task Match Analysis: MJ-002, MJ-003, MJ-006 ya no degradan el % → llevar a 100 %.
- Verdict: pasar a ✅ APPROVE.

**Step 5: Commit**

Sugerencia de commit message (un solo commit por toda la tanda Major, o uno por tarea si se prefiere granularidad):

```
fix(invoices): cierre de Major auditados en flujo de control

- MJ-001: verificar retornos de save y recalculatePaymentStatus en authorizePayment
- MJ-002: envolver rama no-refund de rejectPayment en transactional
- MJ-003: registrar status real (no literal STATUS_PAGADA) tras pago parcial
- MJ-004: parametrizar subquery unread_observations con bindings
- MJ-006: registrar cambio de area_approval en invoice_histories
- MJ-007: _persistApprovers y _sendApprovalEmails a private
- MJ-008: query batch en _getApprovalSummaries (elimina N+1)

Auditoría: docs/audits/invoice-flow-audit-2026-05-04.md
Plan: docs/plans/2026-05-04-invoice-flow-major-fixes.md
```

---

## Resumen de impacto esperado

| Tarea | Archivos | Riesgo | Impacto |
|---|---|---|---|
| MJ-001 | InvoicePaymentService | Bajo | Robustez TX en autorización de pagos |
| MJ-002 | InvoicePaymentService | Bajo | Robustez TX en rechazo no-refund |
| MJ-003 | InvoicePipelineService | Trivial | Correctitud futura del historial |
| MJ-004 | InvoicesController | Bajo | Endurece subquery, evita SQLi futura |
| MJ-006 | InvoiceApprovalService | Medio | Auditoría completa de aprobaciones (cumple promesa de FIELD_LABELS) |
| MJ-007 | InvoiceApprovalService | Trivial | Convención de visibilidad |
| MJ-008 | Controller + ApprovalService | Bajo | Performance del listado (de O(N) queries a O(1)) |

**Tiempo estimado:** ~2 horas para la tanda completa con validación manual.

**Después de este plan:** quedan 10 Minor + 6 Suggestion. Se atienden en un plan separado o como mejora oportunista.
