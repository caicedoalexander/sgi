# Diseño — Correcciones críticas auditoría Anticipos (2026-05-05)

**Fecha:** 2026-05-05
**Auditoría origen:** `docs/audits/advances-audit-2026-05-05.md`
**Branch:** `main`
**Alcance:** 7 hallazgos (4 Critical + 3 Major obligatorios del verdict)

## Hallazgos en alcance

| ID | Severidad | Resumen |
|----|-----------|---------|
| CR-001 | Critical | Mass-assignment en `AdvancesController::add()` |
| CR-002 | Critical | Falta atomicidad en operaciones multi-statement del service |
| CR-003 | Critical | Autorización rol×estado×acción rota |
| CR-004 | Critical | Race entre `_setStatus(LEGALIZADA)` y dispatch del evento |
| MA-001 | Major | Upload sin validación MIME/tamaño en `confirmShortageReceipt` |
| MA-002 | Major | IDOR potencial en `_loadLegalization` |
| MA-003 | Major | Parsing incorrecto de montos COP |

Excluidos (otra sesión): MA-004…MA-010, todos los Minor, todos los Suggestions.

---

## Decisiones de diseño tomadas

1. **Autorización rol×acción×estado** se resuelve con un Policy class dedicado, no en el controller ni dentro del service. Alinea con el patrón de `InvoiceFieldAccessPolicy` y `Service/Pipeline/Policy/`.
2. **MA-002 (scope de visibilidad)** se cierra como "todos ven todo" — es la regla real del negocio (Contabilidad y Tesorería tramitan globalmente). Se documenta explícitamente en `_loadLegalization`. CR-003 ya elimina el vector explotable real.
3. **Matriz de autorización rol×acción** se adopta tal cual la propone la auditoría:
   - Contabilidad/Admin: `linkInvoices`, `unlinkInvoice`, `uploadRelationDocument`, `moveToRevision`, `markSigned`, `returnToValidacion`, `markExact`, `registerShortage`, `registerSurplus`.
   - Tesorería/Admin: `confirmShortage`, `registerRefund`.
4. **Patrón de transaccionalidad uniforme:** callback con variable por referencia + `return false` para rollback explícito. Las excepciones internas se capturan y convierten a `ServiceResult::fail`.
5. **Validación de uploads:** se extrae un helper `validateAndMoveUpload()` del trait existente que solo valida y mueve sin tocar BD. `confirmShortageReceipt` lo usa y guarda el path en la columna existente `shortage_receipt_path` (sin migración).
6. **Bug oculto descubierto:** `LinkedInvoiceLegalizer` y `registerRefundPayment` tienen `transactional()` que no rollbackea correctamente porque retornan objetos `ServiceResult` (truthy) o ignoran el `false`. Se corrigen en la misma pasada como parte de CR-002/CR-004.

---

## Archivos afectados

### Nuevos (1)

- `src/Service/Pipeline/Policy/AdvanceLegalizationActionPolicy.php`

### Modificados (4)

- `src/Controller/AdvancesController.php`
- `src/Service/AdvanceLegalizationService.php`
- `src/Service/Pipeline/LinkedInvoiceLegalizer.php`
- `src/Service/Trait/DocumentUploadTrait.php`

### Sin cambios

- `src/Service/Subscriber/LinkedInvoicesPromoterSubscriber.php` — su comportamiento (no captura excepción del legalizer) deja propagar correctamente una vez que el legalizer lance excepciones.

---

## Diseño detallado por hallazgo

### CR-001 — Mass-assignment en `add()`

Lista blanca explícita en el `patchEntity`:

```php
$allowedFields = [
    'provider_id', 'employee_id', 'operation_center_id',
    'expense_type_id', 'cost_center_id', 'amount', 'detail',
    'issue_date', 'due_date', 'document_type', 'registered_by',
    'pipeline_status', 'registration_date',
];
$invoice = $invoicesTable->patchEntity($invoice, $data, [
    'accessibleFields' => array_fill_keys($allowedFields, true) + [
        'approver_id' => false,
        'area_approval' => false,
        'payment_status' => false,
        'confirmed_by' => false,
        'accrued' => false,
        'advance_id' => false,
    ],
]);
```

Los campos `document_type`, `registered_by`, `pipeline_status`, `registration_date` se siguen sobreescribiendo server-side antes del patch.

### MA-003 — Parsing COP

Helper privado `_parseCop()` en `AdvancesController` aplicado en `registerShortage` y `registerSurplus`:

```php
private function _parseCop(string $raw): float
{
    $normalized = str_replace('.', '', $raw);     // quita separador de miles
    $normalized = str_replace(',', '.', $normalized); // coma decimal a punto
    return (float)$normalized;
}
```

### CR-003 — `AdvanceLegalizationActionPolicy`

Clase final, sin estado, inyectable vía contenedor.

```php
namespace App\Service\Pipeline\Policy;

final class AdvanceLegalizationActionPolicy
{
    public function canLinkInvoices(AdvanceLegalization $leg, string $roleName): bool;
    public function canUnlinkInvoice(AdvanceLegalization $leg, string $roleName): bool;
    public function canUploadRelationDocument(AdvanceLegalization $leg, string $roleName): bool;
    public function canMoveToRevision(AdvanceLegalization $leg, string $roleName): bool;
    public function canMarkSigned(AdvanceLegalization $leg, string $roleName): bool;
    public function canReturnToValidacion(AdvanceLegalization $leg, string $roleName): bool;
    public function canMarkExact(AdvanceLegalization $leg, string $roleName): bool;
    public function canRegisterShortage(AdvanceLegalization $leg, string $roleName): bool;
    public function canRegisterSurplus(AdvanceLegalization $leg, string $roleName): bool;
    public function canConfirmShortage(AdvanceLegalization $leg, string $roleName): bool;
    public function canRegisterRefund(AdvanceLegalization $leg, string $roleName): bool;

    private function _isAccountingOrAdmin(string $r): bool;
    private function _isTreasuryOrAdmin(string $r): bool;
}
```

Cada método combina rol permitido + estado del leg permitido para esa acción.

**Uso desde el controller:**

```php
public function markExact(?int $id = null): Response
{
    $this->request->allowMethod(['post']);
    $leg = $this->_loadLegalization((int)$id);
    $roleName = $this->_getUserRoleName($this->_getCurrentUser());

    if (!$this->actionPolicy->canMarkExact($leg, $roleName)) {
        $this->Flash->error('No tienes permiso para esta acción en el estado actual.');
        return $this->redirect(['action' => 'view', $id]);
    }
    // ... resto igual
}
```

Inyección en `initialize()`:

```php
$this->actionPolicy = $this->getContainer()
    ->get(AdvanceLegalizationActionPolicy::class);
```

**Bonus para `legalization()`:** se pasa la policy al template (`$this->set('actionPolicy', $this->actionPolicy)`). Esto deja preparado el camino para resolver MI-006 (duplicación de "puede registrar reintegro" en el template) sin abrir nuevo alcance ahora.

### CR-002 — Transaccionalidad

**Patrón uniforme aplicado a:**
- `linkInvoices()`
- `unlinkInvoice()`
- `attachRelationDocument()`
- `markSigned()`
- `returnToValidacion()`
- `confirmShortageReceipt()`
- `registerRefundPayment()` (corrección del bug existente)
- `_setStatus()`

```php
$result = null;
$conn = $table->getConnection();
$conn->transactional(function () use (..., &$result): bool {
    if (!$table->save($entity)) {
        $result = ServiceResult::fail('...');
        return false; // rollback
    }
    // statements adicionales
    $result = ServiceResult::ok($entity);
    return true; // commit
});
return $result ?? ServiceResult::fail('La transacción falló.');
```

### CR-004 — Race condition del evento

**`AdvanceLegalizationService::_setStatus()`** envuelve el `save()` y el `events->dispatch()` en el mismo `transactional`:

```php
private function _setStatus(AdvanceLegalization $leg, string $newStatus, int $userId): ServiceResult
{
    $result = null;
    $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
    $conn = $table->getConnection();

    $conn->transactional(function () use ($leg, $newStatus, $userId, $table, &$result): bool {
        $leg->status = $newStatus;
        $leg->updated_by = $userId;
        if (!$table->save($leg)) {
            $result = ServiceResult::fail(
                'No se pudo guardar la legalización: ' . json_encode($leg->getErrors())
            );
            return false;
        }

        if ($newStatus === AdvanceConstants::STATUS_LEGALIZADA) {
            try {
                $this->events->dispatch(new Event(
                    'AdvanceLegalization.legalized',
                    null,
                    ['payload' => new AdvanceLegalizedEvent($leg, $userId)],
                ));
            } catch (\Throwable $e) {
                $result = ServiceResult::fail(
                    'No se pudo cerrar la legalización: ' . $e->getMessage()
                );
                return false;
            }
        }

        $result = ServiceResult::ok($leg);
        return true;
    });

    return $result ?? ServiceResult::fail('La transacción falló.');
}
```

**`LinkedInvoiceLegalizer::legalizeFor()`** — reemplazar `return false` (que solo rollbackea sin propagar) por `throw`:

```php
foreach ($linked as $inv) {
    $from = $inv->pipeline_status;
    $inv->pipeline_status = InvoiceConstants::STATUS_LEGALIZADA;
    if (!$invoicesTable->save($inv)) {
        throw new \RuntimeException(
            "Error promoviendo factura #{$inv->id}: " . json_encode($inv->getErrors())
        );
    }
    $this->historyService->recordStatusChange(
        $inv->id, $from, InvoiceConstants::STATUS_LEGALIZADA, $userId,
    );
    $count++;
}
return true;
```

`transactional()` rollbackea y re-lanza la excepción; el subscriber no la captura; sube a `events->dispatch()`; el `try/catch` de `_setStatus` la convierte a `ServiceResult::fail` y rollbackea el save del leg.

**Sobre transacciones anidadas:** CakePHP por defecto colapsa el `transactional` interno del legalizer dentro del externo del service como una sola unidad de trabajo. Es exactamente lo deseado: todo o nada.

### MA-001 — Upload validado en `confirmShortageReceipt`

**Refactor mínimo del trait** — extraer validación+move sin tocar BD:

```php
// DocumentUploadTrait
protected function validateAndMoveUpload(
    UploadedFile $file,
    string $subDir,
    string $prefix,
): array|string {
    if ($file->getError() !== UPLOAD_ERR_OK) {
        return 'No se recibió ningún archivo válido.';
    }
    if ($file->getSize() > self::MAX_DOC_SIZE) {
        return 'El archivo excede el tamaño máximo de 20 MB.';
    }
    $mimeType = $file->getClientMediaType();
    if (!in_array($mimeType, self::ALLOWED_DOC_MIMES)) {
        return 'Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.';
    }

    $uploadDir = WWW_ROOT . 'uploads' . DS . $subDir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $originalName = $file->getClientFilename();
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $uniqueName = uniqid($prefix) . '.' . $extension;
    $filePath = $uploadDir . DS . $uniqueName;
    $file->moveTo($filePath);

    return [
        'file_path' => 'uploads/' . $subDir . '/' . $uniqueName,
        'file_name' => $originalName,
        'file_size' => $file->getSize(),
        'mime_type' => $mimeType,
    ];
}
```

`uploadAndSave()` se refactoriza para llamar `validateAndMoveUpload()` internamente y luego hacer el save (sin cambio de API pública, no rompe callers actuales).

`confirmShortageReceipt()` queda:

```php
if (!empty($data['receipt_file']) && $data['receipt_file'] instanceof UploadedFile) {
    $info = $this->validateAndMoveUpload(
        $data['receipt_file'],
        'advances/' . $leg->id,
        'shortage_',
    );
    if (is_string($info)) {
        return ServiceResult::fail($info);
    }
    $leg->shortage_receipt_path = $info['file_path'];
}
```

**Nota fuera de código:** la auditoría también pide validar `.htaccess`/nginx en `webroot/uploads/` para denegar ejecución PHP. Verificación de infra, no del fix de código.

### MA-002 — Documentación de scope

Sin cambio funcional. PHPDoc explícito en `_loadLegalization`:

```php
/**
 * Resolves the AdvanceLegalization for a given Anticipo invoice id.
 *
 * Scope: by design, all users with `advances.edit` see all advances regardless
 * of operation_center_id. Action-level authorization (rol×state) is enforced
 * via AdvanceLegalizationActionPolicy before any mutating operation. See audit
 * 2026-05-05 (MA-002) for the rationale.
 */
private function _loadLegalization(int $advanceInvoiceId): AdvanceLegalization
```

---

## Validación manual

Tras aplicar los fixes, levantar `php bin/cake server` y ejercitar:

1. **CR-001** — `curl -X POST` a `/advances/add` enviando campos extra (`pipeline_status=pagada`, `area_approval=Aprobada`, `approver_id=99`, `advance_id=1`); verificar que en la BD esos campos quedan con su default (no aceptan los valores enviados).
2. **MA-003** — registrar faltante con monto `"1.234,56"`; verificar `advance_legalizations.shortage_amount = 1234.56` (no `123456`). Repetir con `"500"` y `"1000,50"`.
3. **CR-003** — loguearse como Tesorería; intentar `POST /advances/mark-exact/{id}`; ver flash de rechazo y redirect a view, sin cambios en BD.
4. **CR-003** — loguearse como Contabilidad; intentar `POST /advances/register-refund/{id}` (acción de Tesorería); ver flash de rechazo.
5. **CR-003** — loguearse como Admin; ejecutar todas las acciones; deben pasar (Admin bypassa).
6. **MA-001** — subir archivo `comprobante.php` renombrado a `.pdf` en confirmación de faltante; rechazo por MIME (`application/x-php` no está en `ALLOWED_DOC_MIMES`).
7. **MA-001** — subir archivo > 20 MB; rechazo por tamaño.
8. **CR-002** — provocar fallo en save del leg (p.ej. forzar campo NOT NULL a NULL temporalmente); verificar que ningún statement asociado quedó parcialmente persistido.
9. **CR-004** — borrar `cost_center_id` (FK) de una linked invoice antes de `markExact`; ejecutar markExact; verificar que `advance_legalizations.status` quedó en `contabilidad` (rollback OK), y que el flash muestra el motivo real del fallo.

---

## Commit

Un único commit en `main`:

```
fix(advances): correcciones críticas auditoría 2026-05-05

- CR-001: accessibleFields explícitos en AdvancesController::add()
- CR-002: transactional() en operaciones multi-statement del service
- CR-003: AdvanceLegalizationActionPolicy para autorización rol×estado
- CR-004: dispatch del evento dentro del transactional + propagación
  de excepciones en LinkedInvoiceLegalizer
- MA-001: confirmShortageReceipt valida MIME/tamaño vía DocumentUploadTrait
- MA-002: documentar scope "todos ven todo" en _loadLegalization
- MA-003: parsing correcto de montos COP (str_replace en orden correcto)
```

---

## Fuera de alcance (siguientes sesiones)

- MA-004 (huérfanos en disco) — habilitado por el refactor de MA-001.
- MA-005 (doble registro de caso) — guard `case_type !== null`.
- MA-006 (`moveToRevisionFirmas` admite linked en `legalizada`).
- MA-007 (código muerto de flash en `legalization()`).
- MA-008 (paginación del modal de vinculación).
- MA-009 (`matching` + `contain` con JOIN duplicado).
- MA-010 (refactor a State pattern → SU-001).
- Todos los Minor (12 items).
- Todos los Suggestions (incluye SU-004 audit trail).
