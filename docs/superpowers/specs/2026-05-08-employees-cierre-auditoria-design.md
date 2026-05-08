# Spec — Cierre de auditoría Employees (Lotes 8, 9, 10)

**Fecha:** 2026-05-08
**Audit referenciado:** `docs/audits/employees-module-audit-2026-05-07.md`
**Hallazgos cubiertos:** CR-024 (no aplica), CR-025 (resolver), CR-026 (resolver), CR-028 (parcial), CR-030 (no aplica)
**Verdicto al cierre esperado:** auditoría 100% cerrada (todos los hallazgos en estado terminal: ✅ Resuelto, ✅ Aceptado, ❌ No aplica).

---

## Contexto

Los Lotes 1–7 ya cerraron 22 hallazgos resueltos (CR-001 a CR-017, CR-019 a CR-023, CR-027, CR-029) y 2 aceptados sin acción (CR-018, CR-022). Quedan 5 sugerencias 🟢 abiertas. Este spec cierra esas 5 con el alcance correcto:

- **CR-024 (VO `SocialSecurityInfo`):** se descarta (No Aplica). Los campos `eps`, `pension_fund`, `arl`, `severance_fund` existen en BD, en `_accessible`, en config de import Excel y en `EmployeeHistoryService::FIELD_LABELS`, pero **no se exhiben en ningún template ni se usan como grupo en ningún service**. Crear el VO sin callers sería academicismo. Si en el futuro aparece UI/Excel/PDF que los muestre como bloque, se reabre.
- **CR-025 (VO `Identification`):** se resuelve. Hay caller real en `templates/Employees/view.php:57` (`document_type · document_number`). Aporta encapsulamiento útil aunque sea un VO simple.
- **CR-026 (`BaseFilterService` reusable):** se resuelve. Hay 2 servicios con código duplicado idéntico (`EmployeeFilterService`, `InvoiceFilterService`). El refactor elimina duplicación real.
- **CR-028 (defense-in-depth uploads):** se resuelve parcialmente. Se implementan rate limit + rename por MIME real. AV scanning (ClamAV) se documenta como No Aplica por falta de infra.
- **CR-030 (conteo documentos vía `hasManyCount`):** se descarta (No Aplica). El código actual NO genera N+1 — el `view()` carga `EmployeeFolders → EmployeeDocuments` en un solo `contain` y la suma se hace en memoria sobre arrays ya cargados. Cambiar a `hasManyCount` complicaría el template porque también necesita iterar los documentos, sin ahorrar queries.

---

## Lote 8 — CR-025: VO `Identification`

### Enfoque

Virtual getter sobre los campos planos existentes. **No** se modifica storage, **no** se modifica `patchEntity`, **no** se modifica el form. Solo se introduce el VO como capa de presentación/dominio sobre datos ya guardados.

### Archivos nuevos

**`src/Model/ValueObject/Identification.php`**

- `final readonly class Identification`
- Constructor `__construct(public string $type, public string $number)`:
  - Validar `$type !== ''` y `$number !== ''`; lanzar `\InvalidArgumentException` si vacío.
- `formatted(): string` → `"{$this->type} · {$this->number}"` (mismo separador que el template actual).
- `equals(self $other): bool` → comparación estricta de ambos campos.
- `__toString(): string` → alias de `formatted()` para concatenación directa en templates.

> Nota: se elige `\App\Model\ValueObject\` como namespace nuevo. El proyecto no tenía VOs aún; este es el primer caso. Si surgen más VOs (eventual `SocialSecurityInfo` si se decide reabrir), comparten ubicación.

### Cambios en `src/Model/Entity/Employee.php`

Agregar virtual getter:

```php
use App\Model\ValueObject\Identification;

protected function _getIdentification(): ?Identification
{
    if (empty($this->document_type) || empty($this->document_number)) {
        return null;
    }
    return new Identification($this->document_type, $this->document_number);
}
```

> El getter retorna `null` si falta cualquiera de los dos campos. Esto permite usar `$employee->identification?->formatted()` con null safety en el template.

### Cambios en templates

**`templates/Employees/view.php:57`**

- Antes: `<?= h($employee->document_type) ?> · <?= h($employee->document_number) ?>`
- Después: `<?= h($employee->identification?->formatted() ?? '') ?>`

> El proyecto no tiene tests automatizados (CLAUDE.md, "Testing Policy"). El VO se valida manualmente.

### Validación manual

1. `php bin/cake server` → `/employees/view/{id}` de un empleado existente.
2. Verificar que el subtítulo muestra `CC · 1234567890` igual que antes (mismo string, mismo separador).
3. Editar y guardar el empleado → debe persistir igual (los campos planos no cambiaron).
4. Crear empleado nuevo con cédula → display correcto en el redirect a `view`.

### Criterios de aceptación

- [ ] `Identification` existe en `src/Model/ValueObject/`.
- [ ] `Employee::_getIdentification()` retorna VO o null.
- [ ] `view.php:57` usa el getter virtual.
- [ ] Comportamiento visual idéntico al previo en empleados con/sin cédula completa.
- [ ] `composer cs-check` pasa.

---

## Lote 9 — CR-028: Hardening de uploads

### Enfoque

Tres sub-mejoras en `EmployeeDocumentService` y la action de upload del controller:

1. **Rate limit por usuario** sobre `uploadDocument`.
2. **Rename canónico** por MIME real (la extensión final del archivo en disco se deriva del MIME detectado por finfo, no del nombre del cliente).
3. **AV scanning:** documentado como fuera de alcance.

### 9.1 — Rate limit en `EmployeesController::uploadDocument`

#### Verificación previa

Antes de implementar, leer `src/Middleware/RateLimitMiddleware.php` para entender:
- Si soporta granularidad por ruta o solo global por IP.
- Qué backend de cache usa (CakePHP `Cache`).
- Qué configuración expone (TTL, límite, scope).

Dependiendo del resultado:

- **Si el middleware soporta reglas por ruta:** agregar regla declarativa (probable formato en `config/app.php`):
  ```php
  'RateLimits' => [
      'employees.upload' => ['limit' => 30, 'window' => 3600, 'scope' => 'user'],
  ],
  ```
  y mapearla a la ruta `/employees/upload-document/*`.

- **Si el middleware solo soporta scope global:** agregar guard inline en el controller (más simple, no toca middleware existente):
  ```php
  // En EmployeesController::uploadDocument, al inicio
  $userId = $this->Authentication->getIdentity()?->getIdentifier();
  $key = "rate.upload.employee.user.{$userId}";
  $count = (int)(\Cake\Cache\Cache::read($key) ?? 0);
  if ($count >= self::UPLOAD_RATE_LIMIT) {
      $this->Flash->error('Has alcanzado el límite de cargas por hora. Intenta más tarde.');
      return $this->redirect(['action' => 'view', $employeeId]);
  }
  \Cake\Cache\Cache::write($key, $count + 1, 'short'); // TTL del config 'short' debe ser ~3600s
  ```
  Y `private const UPLOAD_RATE_LIMIT = 30;`.

> Decisión que se toma en implementación: leer el middleware primero. Preferir la opción declarativa si el middleware ya lo soporta; si no, usar el guard inline para no expandir alcance.

> Nota: el rate limit por usuario es deliberado — usuarios legítimos pueden compartir IP (oficina, NAT). Limitar por IP causa falsos positivos.

### 9.2 — Rename por MIME real en `EmployeeDocumentService`

#### Cambio en `uploadDocument`

Hoy el flujo es:
1. Extraer extensión del nombre del cliente (`pathinfo`).
2. Validar contra whitelist de extensiones.
3. Mover archivo con esa extensión.
4. Detectar MIME real con finfo.
5. Validar MIME real contra whitelist.

Después:
1–5 igual que hoy.
6. **Nuevo:** mapear MIME real → extensión canónica vía `MIME_TO_EXT`.
7. Si la extensión canónica difiere de la del cliente, renombrar archivo en disco (`rename($absolutePath, $newPath)`) y actualizar `file_path` antes del save.

#### Constante nueva en `EmployeeDocumentService`

```php
private const MIME_TO_EXT = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt',
];

private const MIME_TO_EXT_PROFILE = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
```

#### Helper privado nuevo

```php
private function canonicalize(
    string $absolutePath,
    string $relativePath,
    string $realMime,
    array $mimeToExt,
): array {
    $canonicalExt = $mimeToExt[$realMime] ?? null;
    if ($canonicalExt === null) {
        return [$absolutePath, $relativePath]; // sin cambios; el caller ya validó MIME
    }
    $currentExt = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    if ($currentExt === $canonicalExt) {
        return [$absolutePath, $relativePath];
    }

    $newAbsolute = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $absolutePath);
    $newRelative = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $relativePath);
    if (!@rename($absolutePath, $newAbsolute)) {
        // rename falló; mantener path original y loggear (no hacer fail del upload)
        return [$absolutePath, $relativePath];
    }
    return [$newAbsolute, $newRelative];
}
```

> Decisión: si el rename falla, NO hacer fail del upload. El archivo ya está validado (MIME real está en whitelist). El rename es defense-in-depth, no validación crítica.

#### Aplicación en `uploadDocument`

Después de `detectRealMime` exitoso, antes del `save`:

```php
[$absolutePath, $relativeFilePath] = $this->canonicalize(
    $absolutePath,
    $employeeId . '/' . $uniqueName,
    $realMime,
    self::MIME_TO_EXT,
);

$document = $documentsTable->newEntity([
    // ...
    'file_path' => $relativeFilePath,
    // ...
]);
```

#### Aplicación en `handleProfileImage`

Mismo patrón con `MIME_TO_EXT_PROFILE`. Después del `detectRealMime`:

```php
[$absolutePath, $relativePath] = $this->canonicalize(
    $absolutePath,
    'uploads/employees/' . $employee->id . '/' . $fileName,
    $realMime,
    self::MIME_TO_EXT_PROFILE,
);

$employee->profile_image = $relativePath;
```

### 9.3 — AV scanning: documentado como No Aplica

No se implementa. Se documenta en el cierre del audit (sección "Cierre del documento") y queda como backlog. Razón: SGI corre en easypanel con MariaDB remota; ClamAV requiere daemon adicional + extensión PHP `php-clamav` o llamadas externas. La whitelist de MIME real + extensión canónica + rate limit cubren los vectores más comunes.

### Validación manual

1. **Rate limit:**
   - Hacer 30 uploads consecutivos en `/employees/view/{id}` → todos OK.
   - 31vo upload en la misma hora → debe rechazarse con mensaje Flash.
   - Esperar 1 hora (o limpiar cache manualmente) → contador resetea.
2. **Rename canónico (PDF):**
   - Subir un PDF con extensión `.pdf` → archivo final queda como `doc_xxx.pdf`. Sin cambios visibles.
3. **Rename canónico (mismatch real → canónico):**
   - Tomar un PNG real, renombrarlo a `imagen.jpeg` localmente (extensión cliente "jpeg"), subirlo.
   - El MIME real es `image/png` → archivo final en disco debe quedar como `doc_xxx.png` (canónico), no `doc_xxx.jpeg`.
   - Verificar que `file_path` en BD también termina en `.png`.
   - Verificar que el download funciona (el `mime_type` guardado coincide).
4. **Profile image:**
   - Mismo escenario para imagen de perfil → archivo en `webroot/uploads/employees/{id}/profile.{ext}` debe usar extensión canónica.
5. **Mismatch verdadero (rechazo):**
   - Subir `.jpg` cuyo contenido es ejecutable PHP → debe ser rechazado por la whitelist de MIME real (comportamiento existente, regresión-test).

### Criterios de aceptación

- [ ] Rate limit funcional (declarativo o inline según resultado de revisar `RateLimitMiddleware`).
- [ ] `MIME_TO_EXT` y `MIME_TO_EXT_PROFILE` definidos en `EmployeeDocumentService`.
- [ ] `canonicalize()` privado implementado.
- [ ] `uploadDocument` y `handleProfileImage` aplican rename canónico.
- [ ] Si rename falla, upload sigue (no fail).
- [ ] Validación manual de los 5 escenarios pasa.
- [ ] `composer cs-check` pasa.

---

## Lote 10 — CR-026: `BaseFilterService` reusable

### Enfoque

Extraer los métodos comunes de `EmployeeFilterService` e `InvoiceFilterService` a una clase abstracta. El método `applyEmployeeStatus` (lógica específica de Employees) queda en su servicio. Sin cambio de comportamiento.

### Archivos nuevos

**`src/Service/Filter/BaseFilterService.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Filter;

use Cake\ORM\Query\SelectQuery;

abstract class BaseFilterService
{
    /**
     * Aplica búsqueda LIKE %term% sobre múltiples campos en OR.
     *
     * @param array<int,string> $fields Lista de campos calificados (ej: ['Employees.first_name', ...]).
     */
    protected function applySearch(SelectQuery $query, mixed $term, array $fields): void
    {
        if ($term === null || $term === '' || $fields === []) {
            return;
        }

        $like = '%' . $term . '%';
        $or = [];
        foreach ($fields as $field) {
            $or[$field . ' LIKE'] = $like;
        }
        $query->where(['OR' => $or]);
    }

    protected function applyExact(SelectQuery $query, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $query->where([$field => $value]);
    }

    protected function applyDateRange(SelectQuery $query, string $field, mixed $from, mixed $to): void
    {
        if ($from !== null && $from !== '') {
            $query->where([$field . ' >=' => $from]);
        }
        if ($to !== null && $to !== '') {
            $query->where([$field . ' <=' => $to]);
        }
    }
}
```

> **Decisión:** la clase base se ubica en `App\Service\Filter\` (subnamespace nuevo) para separar de los services de dominio. Los filter services concretos (`EmployeeFilterService`, `InvoiceFilterService`) **no se mueven de namespace** — siguen en `App\Service\` y simplemente extienden de la base. Esto evita tocar imports en controllers (`EmployeesController`, `InvoicesController`) y reduce el blast radius del cambio.

### Cambios en `src/Service/EmployeeFilterService.php`

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmployeeStatusConstants;
use App\Service\Filter\BaseFilterService;
use Cake\ORM\Query\SelectQuery;

class EmployeeFilterService extends BaseFilterService
{
    private const SEARCH_FIELDS = [
        'Employees.first_name',
        'Employees.last_name1',
        'Employees.last_name2',
        'Employees.document_number',
        'Employees.email',
    ];

    public function apply(SelectQuery $query, array $params): SelectQuery
    {
        $this->applySearch($query, $params['search'] ?? null, self::SEARCH_FIELDS);
        $this->applyExact($query, 'Employees.position_id', $params['position_id'] ?? null);
        $this->applyExact($query, 'Employees.operation_center_id', $params['operation_center_id'] ?? null);
        $this->applyEmployeeStatus($query, $params['status'] ?? null);

        return $query;
    }

    private function applyEmployeeStatus(SelectQuery $query, mixed $status): void
    {
        if ($status === 'all') {
            return;
        }
        $effective = is_string($status) && $status !== ''
            ? $status
            : EmployeeStatusConstants::ACTIVO;
        $query->where(['Employees.status' => $effective]);
    }
}
```

### Cambios en `src/Service/InvoiceFilterService.php`

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Filter\BaseFilterService;
use Cake\ORM\Query\SelectQuery;

class InvoiceFilterService extends BaseFilterService
{
    private const SEARCH_FIELDS = [
        'Invoices.invoice_number',
        'Invoices.purchase_order',
        'Invoices.detail',
        'Providers.name',
    ];

    public function apply(SelectQuery $query, array $params): SelectQuery
    {
        $this->applySearch($query, $params['search'] ?? null, self::SEARCH_FIELDS);
        $this->applyExact($query, 'Invoices.provider_id', $params['provider_id'] ?? null);
        $this->applyExact($query, 'Invoices.operation_center_id', $params['operation_center_id'] ?? null);
        $this->applyExact($query, 'Invoices.expense_type_id', $params['expense_type_id'] ?? null);
        $this->applyExact($query, 'Invoices.pipeline_status', $params['pipeline_status'] ?? null);
        $this->applyDateRange($query, 'Invoices.issue_date', $params['date_from'] ?? null, $params['date_to'] ?? null);

        return $query;
    }
}
```

> Cambios mínimos por servicio: solo se cambia el `extends`, se introducen las constantes `SEARCH_FIELDS` y se ajustan firmas de los métodos compartidos para pasar los campos como parámetro.

### Validación manual

1. **Employees `index`:**
   - Sin filtros → solo activos (default), ordenados por apellido. Igual a antes.
   - Search por nombre, apellido, cédula y email → resultados idénticos a antes.
   - Filtro por `position_id` y `operation_center_id` → idéntico.
   - Filtro `status='retirado'` → solo retirados.
   - Filtro `status='all'` → todos.
2. **Invoices `index`:**
   - Search por número, OC, detalle y proveedor → idéntico.
   - Filtros por `provider_id`, `operation_center_id`, `expense_type_id`, `pipeline_status` → idéntico.
   - Filtros `date_from` / `date_to` → idéntico.
3. **Profiler queries:** debe haber 0 cambios en el SQL generado (el refactor es de organización, no de comportamiento).

### Criterios de aceptación

- [ ] `App\Service\Filter\BaseFilterService` existe.
- [ ] `EmployeeFilterService` extiende y solo conserva su lógica específica (status).
- [ ] `InvoiceFilterService` extiende.
- [ ] Comportamiento idéntico para los dos `index` con todas las combinaciones de filtros.
- [ ] `composer cs-check` pasa.

---

## Cierre del documento de auditoría

Tras completar Lotes 8, 9 y 10, actualizar `docs/audits/employees-module-audit-2026-05-07.md`:

### Cambios en la "Estado de remediación"

| ID | Estado nuevo | Resuelto en / Razón |
|----|--------------|----------------------|
| CR-024 | ❌ **No aplica** | Campos `eps`, `pension_fund`, `arl`, `severance_fund` no exhibidos en UI/exports actuales. VO sin caller real → academicismo. Reabrir si aparece UI/Excel/PDF que los muestre como bloque. |
| CR-025 | ✅ **Resuelto** | Lote 8 (2026-05-08) — VO `Identification` con virtual getter en Employee + uso en `view.php`. |
| CR-026 | ✅ **Resuelto** | Lote 10 (2026-05-08) — `BaseFilterService` extraído a `App\Service\Filter\`; `EmployeeFilterService` e `InvoiceFilterService` extienden. |
| CR-028 | ⚠️ **Resuelto parcial** | Lote 9 (2026-05-08) — rate limit por usuario en upload + rename a extensión canónica derivada de MIME real. AV scanning (ClamAV) **no aplica** por falta de infra (easypanel sin daemon AV); queda como backlog si el ambiente cambia. |
| CR-030 | ❌ **No aplica** | El código actual NO genera N+1: `view()` carga `EmployeeFolders → EmployeeDocuments` en un solo `contain` y la suma se ejecuta sobre arrays ya en memoria. Cambiar a `hasManyCount` agregaría una query sin reemplazar la iteración (el template sí necesita los documentos completos). El cambio sería contraproducente. |

### Verdicto global actualizado

Cambiar el banner inicial:

- Antes: `❌ REQUEST CHANGES`
- Después: `✅ APPROVED — auditoría 100% cerrada (22 resueltos, 2 aceptados sin acción, 1 parcial documentado, 2 no aplican).`

### Resumen por categoría

Sin cambios (los conteos de severidades originales se mantienen — el cierre solo cambia los estados, no la clasificación).

---

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|------------|
| Rate limit declarativo no soportado por `RateLimitMiddleware` actual | Fallback a guard inline en controller con `Cache::increment` (alcance acotado, sin tocar middleware) |
| Rename `rename()` falla por permisos en filesystem | Fallback documentado: mantener path original, no fail del upload (validación de MIME ya se hizo) |
| `BaseFilterService` introduce regresión silenciosa | Validación manual exhaustiva por servicio (Employees + Invoices), profiler de queries para verificar 0 cambios SQL |
| Empleados existentes con `document_type` o `document_number` vacíos | Virtual getter `_getIdentification` retorna `null`; template usa null safety `?->formatted() ?? ''` |
| Cambio de namespace al mover filter services rompe imports | Los filter services concretos NO se mueven de namespace en este lote (solo extienden de `App\Service\Filter\BaseFilterService`); cero cambios en controllers |

---

## Orden de ejecución

Los 3 lotes son independientes entre sí. Pueden ejecutarse en paralelo o secuencial. Orden sugerido por riesgo creciente:

1. **Lote 8** (CR-025) — VO Identification. Más pequeño y aislado.
2. **Lote 10** (CR-026) — BaseFilterService. Refactor con 2 servicios afectados.
3. **Lote 9** (CR-028) — Hardening uploads. Toca el flujo de seguridad ya endurecido en Lote 1; mayor cuidado en validación manual.
4. **Cierre** del documento de auditoría — al final, cuando los 3 lotes estén mergeados.

---

## Out of scope

- VO `SocialSecurityInfo` (CR-024) — no se implementa por falta de callers.
- `hasManyCount` para conteo de documentos (CR-030) — no se implementa por ser falso positivo.
- AV scanning (ClamAV) — sub-tarea de CR-028, descartada por falta de infra.
- Caché de catálogos en `_setFormDropdowns` (CR-022) — ya cerrado como aceptado en Lote 5.
- Split de `EmployeeDocumentService` (CR-018) — ya cerrado como aceptado en Lote 3 (re-evaluar si supera 500 LOC).
- Tests automatizados — política del proyecto: validación manual.
