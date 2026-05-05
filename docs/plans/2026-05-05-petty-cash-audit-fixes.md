# Plan: Correcciones de Auditoría — Caja Menor
**Fecha:** 2026-05-05  
**Rama sugerida:** `fix/petty-cash-audit`

## Contexto

Puntos pendientes de la auditoría del flujo de caja menor. Todos son cambios menores o sugerencias triviales; ninguno requiere migración ni afecta la lógica de negocio.

---

## Puntos a resolver (en orden de ejecución)

### MI-001 — Badge `aut_pago` faltante en templates (2 archivos)

**Problema:** `index.php` y `view.php` de `PettyCashRecords` definen `$statusBadge` localmente sin incluir la clave `'aut_pago'`. Cuando un registro está en ese estado se usa el fallback `'bg-dark'` en lugar del color correcto.

**Solución:** Agregar `'aut_pago' => 'bg-info'` al array `$statusBadge` en ambos templates.

**Archivos:**
- `templates/PettyCashRecords/index.php` línea 8–13
- `templates/PettyCashRecords/view.php` línea 10–15

**Cambio exacto** (mismo en ambos):
```php
// Antes
$statusBadge = [
    'agrupacion'    => 'bg-info text-dark',
    'contabilidad'  => 'bg-primary',
    'tesoreria'     => 'bg-warning text-dark',
    'pagado'        => 'bg-success',
];

// Después
$statusBadge = [
    'agrupacion'    => 'bg-info text-dark',
    'contabilidad'  => 'bg-primary',
    'tesoreria'     => 'bg-warning text-dark',
    'aut_pago'      => 'bg-info',
    'pagado'        => 'bg-success',
];
```

---

### MI-003 — Añadir `isAutPago()` a la entidad `PettyCashRecord`

**Problema:** La entidad tiene `isAgrupacion()`, `isContabilidad()`, `isTesoreria()`, `isPagado()` pero le falta `isAutPago()`. El estado `aut_pago` se verifica por comparación directa en varios lugares del código, rompiendo la consistencia del patrón.

**Solución:** Agregar el método a `src/Model/Entity/PettyCashRecord.php` después de `isTesoreria()`.

**Archivo:** `src/Model/Entity/PettyCashRecord.php`

```php
public function isAutPago(): bool
{
    return ($this->status ?? '') === PettyCashConstants::STATUS_AUT_PAGO;
}
```

---

### MI-004 — Mensaje genérico en `removeInvoice`

**Problema:** En `PettyCashRecordsController::removeInvoice()` (línea ~551), el mensaje de error es:
```
'No se puede remover facturas de un registro que no esté en Agrupación.'
```
Este mensaje no es genérico — es descriptivo y correcto. Sin embargo, el punto de auditoría señala que el mensaje de éxito es genérico: `'Factura removida del registro.'`. Se debe mejorar para incluir contexto.

**Solución:** Cambiar el mensaje de éxito para incluir el número de factura.

**Archivo:** `src/Controller/PettyCashRecordsController.php` ~línea 549

```php
// Antes
$this->Flash->success('Factura removida del registro.');

// Después
$this->Flash->success('Factura removida del registro de caja menor.');
```

> Nota: Si "mensaje genérico" se refería al mensaje de error, éste ya es específico. Se asume que el cambio es en el mensaje de éxito para consistencia con el estilo del resto del sistema.

---

### MI-005 — Tipar `_getCurrentUser(): User` en `PettyCashRecordsController`

**Problema:** El método `_getCurrentUser()` retorna `object` en `PettyCashRecordsController` (y en otros controllers). La auditoría pide tipar correctamente como `User` para consistencia con el resto del proyecto.

**Decisión:** Verificar si otros controllers también tienen la misma firma (`object`) — sí la tienen (`AdvancesController`, etc.). El cambio se aplica **solo en `PettyCashRecordsController`** para no crear inconsistencias; los demás se ajustan en refactor posterior.

**Archivo:** `src/Controller/PettyCashRecordsController.php` línea 47

```php
// Antes
private function _getCurrentUser(): object

// Después
private function _getCurrentUser(): \App\Model\Entity\User
```

> Nota: Dado que todos los controllers tienen la misma firma, es preferible cambiarlos todos simultáneamente para no crear inconsistencia. Si el alcance de esta tarea solo cubre caja menor, aplicar solo en `PettyCashRecordsController`.

---

### MI-006 — Extraer label de `<option>` de estado duplicado a element compartido

**Problema:** `index.php` y `view.php` definen `$statusBadge` localmente. Ambos los tendrán corregidos con MI-001. Además, `$statusLabels` se importa de la constante correctamente. El punto señala que el bloque de `<select>` de filtro podría reutilizarse.

**Decisión:** Este punto tiene bajo impacto ya que los labels vienen de `PettyCashConstants::STATUS_LABELS` (una sola fuente de verdad). El `<select>` de filtro solo aparece en `index.php`. **No se crea element separado** — el label ya está centralizado en la constante.

**Acción:** Documentar en código que `$statusBadge` es local por diseño intencional (no hay element compartido de badges para caja menor; usar `StatusColorConstants::PIPELINE_STATUS_BADGES` sería mezclar contextos).

---

### MI-007 — Reemplazar magic strings de `pipeline_status` por constantes en `view.php`

**Problema:** En `templates/PettyCashRecords/view.php` líneas 151–157, el `match` de `pipeline_status` de las facturas hijas usa strings literales:
```php
$pBadge = match($inv->pipeline_status) {
    'aprobacion'   => 'bg-info text-dark',
    'contabilidad' => 'bg-primary',
    'tesoreria'    => 'bg-warning text-dark',
    'pagada'       => 'bg-success',
    default        => 'bg-dark',
};
```

**Solución:** Reemplazar por `StatusColorConstants::PIPELINE_STATUS_BADGES` y agregar el `use` al inicio del archivo.

**Archivo:** `templates/PettyCashRecords/view.php`

```php
// Al inicio (después de los use existentes)
use App\Constants\StatusColorConstants;
use App\Constants\InvoiceConstants;

// En la tabla de facturas
$pBadge = StatusColorConstants::PIPELINE_STATUS_BADGES[$inv->pipeline_status] ?? 'bg-dark';
```

También reemplazar el texto crudo `h($inv->pipeline_status)` con el label de `InvoiceConstants::STATUS_LABELS`:
```php
<?= $statusLabels[$inv->pipeline_status] ?? h($inv->pipeline_status) ?>
```

**Nota:** Verificar que `InvoiceConstants::STATUS_LABELS` existe y contiene los estados correctos antes de aplicar.

---

### MI-008 — Comentario en `ROLE_VISIBLE_STATUSES`

**Problema:** `PettyCashService::ROLE_VISIBLE_STATUSES` no tiene comentario explicando la regla de negocio del por qué Tesorería ve tanto `tesoreria` como `aut_pago`.

**Solución:** Agregar comentario inline al rol Tesorería.

**Archivo:** `src/Service/PettyCashService.php` líneas 31–34

```php
// Antes
RoleConstants::TESORERIA => [
    PettyCashConstants::STATUS_TESORERIA,
    PettyCashConstants::STATUS_AUT_PAGO,
],

// Después
RoleConstants::TESORERIA => [
    // Tesorería gestiona el registro de pago (tesoreria) y
    // debe ver el resultado de la autorización (aut_pago) antes de pagado.
    PettyCashConstants::STATUS_TESORERIA,
    PettyCashConstants::STATUS_AUT_PAGO,
],
```

---

### MI-009 — Dedup validación de motivo (service vs table)

**Problema:** La validación del campo `payment_rejection_reason` existe en dos lugares:
1. `PettyCashRecordsTable` (línea ~116): `allowEmptyString` — permite vacío.
2. `PettyCashService::rejectPayment()` (líneas ~789–793): valida mínimo 10 chars, máximo 500.

Esto no es duplicación real: la table simplemente permite el campo vacío (para el flujo normal), y el service valida cuando se trata de un rechazo explícito. **No hay lógica duplicada que eliminar.**

**Decisión:** El punto de auditoría no aplica — las validaciones son complementarias, no duplicadas. Agregar comentario en el service para documentar la intención.

**Archivo:** `src/Service/PettyCashService.php` ~línea 788

```php
// Service-level validation: the table allows empty (needed for normal flow);
// rejection explicitly requires a non-trivial reason.
if (mb_strlen($reason) < 10) {
```

---

### SU-003 — Centralizar `STATUS_BADGES` en `PettyCashConstants`

**Problema:** Los templates definen `$statusBadge` localmente. `StatusColorConstants::PIPELINE_STATUS_BADGES` ya tiene las claves de caja menor (`agrupacion`, `aut_pago`, `pagado`, etc.).

**Solución:** Agregar constante `STATUS_BADGES` a `PettyCashConstants` que mapea estados propios a clases Bootstrap. Los templates la usan con fallback `'bg-dark'`.

**Archivo:** `src/Constants/PettyCashConstants.php`

```php
public const STATUS_BADGES = [
    self::STATUS_AGRUPACION    => 'bg-info text-dark',
    self::STATUS_CONTABILIDAD  => 'bg-primary',
    self::STATUS_TESORERIA     => 'bg-warning text-dark',
    self::STATUS_AUT_PAGO      => 'bg-info',
    self::STATUS_PAGADO        => 'bg-success',
];
```

Luego en `index.php` y `view.php` reemplazar el array local:
```php
// Antes: array local $statusBadge = [...]
// Después:
use App\Constants\PettyCashConstants;
$statusBadge = PettyCashConstants::STATUS_BADGES;
```

> Esto resuelve también MI-001 de forma más duradera (fuente única de verdad).

---

### SU-006 — Comentar grafo de `BACKWARD_TRANSITIONS`

**Problema:** `PettyCashConstants::BACKWARD_TRANSITIONS` ya tiene un comentario sobre `pagado`, pero no explica el grafo completo.

**Archivo:** `src/Constants/PettyCashConstants.php` línea ~46

```php
// Antes
// Backward transitions for the regress operation.
// Excluido `pagado` por riesgo de inconsistencia con datos colaterales.
public const BACKWARD_TRANSITIONS = [

// Después
// Backward transitions for the regress operation.
// Grafo: agrupacion←contabilidad←tesoreria←aut_pago. pagado es terminal.
// Se excluye `pagado` porque la autorización ya materializó pagos en facturas hijas.
public const BACKWARD_TRANSITIONS = [
```

---

### SU-008 — Bloque de pago en `view.php` (copy de `edit.php`)

**Problema:** La auditoría señala que el bloque que muestra datos de pago en `view.php` es un copy/paste adaptado de `edit.php`. Hay que verificar si existe y si está desincronizado.

**Acción:** Leer `edit.php` sección de pago y comparar con `view.php`. Si `view.php` no tiene sección de pago, agregarla en modo solo lectura. Si ya existe pero está desincronizada, alinearla.

> Este punto requiere revisión del `edit.php` antes de implementar. Se trata como **última tarea** del plan.

---

## Orden de implementación

| # | ID | Archivo(s) | Complejidad |
|---|-----|-----------|-------------|
| 1 | SU-003 | `PettyCashConstants.php` | 5 líneas |
| 2 | MI-001 | `index.php`, `view.php` | usar constante del paso 1 |
| 3 | MI-003 | `PettyCashRecord.php` | 3 líneas |
| 4 | MI-007 | `view.php` | 3 líneas (reemplazar match) |
| 5 | SU-006 | `PettyCashConstants.php` | 2 líneas de comentario |
| 6 | MI-008 | `PettyCashService.php` | 2 líneas de comentario |
| 7 | MI-009 | `PettyCashService.php` | 1 línea de comentario |
| 8 | MI-004 | `PettyCashRecordsController.php` | 1 línea |
| 9 | MI-005 | `PettyCashRecordsController.php` | 1 línea (return type) |
| 10 | MI-006 | — | no aplica (ver decisión) |
| 11 | SU-008 | `view.php` | revisar + alinear con `edit.php` |

## Validación manual

1. Levantar `php bin/cake server`
2. Crear un registro de caja menor y avanzarlo hasta `aut_pago`
3. Verificar que el badge en `index.php` y `view.php` muestra `bg-info` (azul) y no negro
4. Verificar que el pipeline progress muestra `aut_pago` correctamente
5. Remover una factura en estado agrupación → confirmar mensaje de éxito mejorado
6. Intentar rechazar un pago con motivo < 10 chars → debe fallar con mensaje claro
