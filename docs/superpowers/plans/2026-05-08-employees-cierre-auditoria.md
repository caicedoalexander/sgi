# Cierre de auditoría Employees — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar las 5 sugerencias 🟢 pendientes del audit Employees del 2026-05-07 (CR-024, CR-025, CR-026, CR-028, CR-030) ejecutando 3 lotes pequeños y actualizando el documento de auditoría al estado final.

**Architecture:** Tres lotes independientes que se mergen en orden creciente de riesgo: VO `Identification` (CR-025) → `BaseFilterService` (CR-026) → hardening uploads (CR-028). CR-024 y CR-030 se descartan documentadamente (No Aplica).

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB, `cakephp/authentication ^3.0`. **NO hay tests automatizados** (política del proyecto, ver `CLAUDE.md` § "Testing Policy"). Cada task termina con validación manual + commit.

**Spec referenciado:** `docs/superpowers/specs/2026-05-08-employees-cierre-auditoria-design.md`

---

## Convenciones del plan

- **Sin TDD:** el proyecto no tiene tests. Donde un plan típico pondría "escribe el test → corre y falla → implementa → corre y pasa", aquí ponemos "escribe el código → valida manualmente → commit".
- **Validación manual:** cada Lote tiene su sección de validación con el servidor `php bin/cake server` levantado en `localhost:8765`.
- **Commits:** uno por task, mensaje en formato `tipo(scope): descripcion (CR-XXX)`.
- **Code style:** después de cada cambio de PHP, correr `composer cs-check`. Si falla, `composer cs-fix` y re-commit (o amend si aún no se pushó).

---

## Decisión de implementación que ajusta el spec

**Spec § 9.1 (rate limit):** el spec dejó abierto si usar guard inline o middleware declarativo. Decisión final: **usar el `RateLimitMiddleware` existente** (`src/Middleware/RateLimitMiddleware.php`) registrando una nueva instancia con `(30, 3600)` y aplicándola a la ruta `/employees/upload-document/*` vía `applyMiddleware` en `config/routes.php`.

- **Pro:** reusa código existente, persistencia consistente vía `rate_limit_buckets`, cero código nuevo.
- **Trade-off:** el middleware limita por **IP + path**, no por usuario. En oficinas con NAT compartida, los usuarios comparten cuota. Mitigación: `30 req/hora` es generoso para uso normal; si una oficina con muchas personas reporta falsos positivos, subir el límite. Documentar el trade-off en el comentario del scope.

---

# Lote 8 — CR-025: VO `Identification`

## Task 1: Crear VO `Identification`

**Files:**
- Create: `src/Model/ValueObject/Identification.php`

- [ ] **Step 1: Crear el archivo del VO**

Verificar primero que el directorio `src/Model/ValueObject/` no existe (es el primer VO del proyecto). Crearlo si hace falta.

Contenido completo de `src/Model/ValueObject/Identification.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\ValueObject;

use InvalidArgumentException;

/**
 * Value Object inmutable que representa la identificación de un empleado
 * (tipo de documento + número). Construido desde campos planos del entity
 * vía el getter virtual Employee::_getIdentification (CR-025).
 */
final readonly class Identification
{
    public function __construct(
        public string $type,
        public string $number,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('document_type no puede estar vacío.');
        }
        if ($number === '') {
            throw new InvalidArgumentException('document_number no puede estar vacío.');
        }
    }

    /**
     * Formato canónico para mostrar en UI: "{TIPO} · {NUMERO}".
     * Mantiene el separador histórico usado en templates/Employees/view.php.
     */
    public function formatted(): string
    {
        return $this->type . ' · ' . $this->number;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->number === $other->number;
    }

    public function __toString(): string
    {
        return $this->formatted();
    }
}
```

- [ ] **Step 2: Verificar code style**

```bash
composer cs-check
```

Expected: PASS sin warnings sobre el archivo nuevo. Si falla, `composer cs-fix`.

- [ ] **Step 3: Commit**

```bash
git add src/Model/ValueObject/Identification.php
git commit -m "feat(employees): VO Identification para tipo+numero documento (CR-025)"
```

---

## Task 2: Virtual getter `_getIdentification` en `Employee`

**Files:**
- Modify: `src/Model/Entity/Employee.php`

- [ ] **Step 1: Agregar import del VO**

Edit `src/Model/Entity/Employee.php`. Después de la línea actual `use App\Constants\NoveltyConstants;` (línea ~8), agregar:

```php
use App\Model\ValueObject\Identification;
```

- [ ] **Step 2: Agregar el virtual getter**

Edit `src/Model/Entity/Employee.php`. Después del cierre del método `_getFullName` (alrededor de la línea 50), antes de `public function isActive`, insertar:

```php
    /**
     * Virtual property: $employee->identification.
     * Retorna VO inmutable o null si falta tipo o número de documento (CR-025).
     */
    protected function _getIdentification(): ?Identification
    {
        if (empty($this->document_type) || empty($this->document_number)) {
            return null;
        }

        return new Identification($this->document_type, $this->document_number);
    }
```

- [ ] **Step 3: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 4: Validación manual**

Levantar el servidor:

```bash
php bin/cake server
```

En el navegador, abrir `http://localhost:8765/employees/view/{id}` con un id de empleado existente. Verificar que la página renderiza sin errores 500. Aún no debe haber cambio visual (el template aún usa los campos planos).

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/Employee.php
git commit -m "feat(employees): virtual getter Identification en Employee entity (CR-025)"
```

---

## Task 3: Usar el VO en `view.php`

**Files:**
- Modify: `templates/Employees/view.php:57`

- [ ] **Step 1: Reemplazar el bloque actual**

Localizar la línea 57 (subtítulo del avatar). El código actual es:

```php
<div class="sgi-profile-doc"><?= h($employee->document_type) ?> · <?= h($employee->document_number) ?></div>
```

Reemplazar por:

```php
<div class="sgi-profile-doc"><?= h($employee->identification?->formatted() ?? '') ?></div>
```

> Si `$employee->identification` es null (empleado sin tipo o número), el null safety + coalescing produce string vacío, comportamiento equivalente al `h()` sobre `null` previo.

- [ ] **Step 2: Validación manual — empleado completo**

Servidor levantado. Abrir `http://localhost:8765/employees/view/{id}` de un empleado con `document_type` y `document_number` poblados. Verificar:

- Subtítulo muestra exactamente el mismo string que antes, ej: `CC · 1234567890`.
- Mismo tamaño, fuente y color (no debe haber cambio visual).

- [ ] **Step 3: Validación manual — empleado incompleto (si aplica)**

Si en BD existe algún empleado con `document_type` o `document_number` NULL/vacío, abrir su `view`. El subtítulo debe quedar vacío (no romper, no mostrar "null"). Si no hay ningún caso así, omitir y documentar en commit.

- [ ] **Step 4: Validación manual — edit/save**

Editar el mismo empleado en `/employees/edit/{id}`, cambiar un campo cualquiera (ej: teléfono), guardar. Volver a `view`. El subtítulo `tipo · número` debe seguir igual (la persistencia de los campos planos no cambió).

- [ ] **Step 5: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add templates/Employees/view.php
git commit -m "refactor(employees): usar VO Identification en view.php (CR-025)"
```

---

# Lote 10 — CR-026: `BaseFilterService`

> **Orden:** este lote va antes que el Lote 9 porque tiene menor riesgo y prepara mejor la cadena de PRs.

## Task 4: Crear `BaseFilterService`

**Files:**
- Create: `src/Service/Filter/BaseFilterService.php`

- [ ] **Step 1: Crear el directorio y archivo**

Crear `src/Service/Filter/` (subnamespace nuevo) y dentro el archivo:

```php
<?php
declare(strict_types=1);

namespace App\Service\Filter;

use Cake\ORM\Query\SelectQuery;

/**
 * Helpers compartidos para filter services. Extrae la duplicación entre
 * EmployeeFilterService e InvoiceFilterService (CR-026).
 */
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

- [ ] **Step 2: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Service/Filter/BaseFilterService.php
git commit -m "feat(filter): BaseFilterService con helpers comunes (CR-026)"
```

---

## Task 5: Refactor `EmployeeFilterService` para extender la base

**Files:**
- Modify: `src/Service/EmployeeFilterService.php`

- [ ] **Step 1: Reemplazar el archivo completo**

Sobreescribir `src/Service/EmployeeFilterService.php` con:

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

    /**
     * Apply search and filter parameters to an employees query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Base query (already contains associations).
     * @param array<string,mixed> $params Query-string parameters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function apply(SelectQuery $query, array $params): SelectQuery
    {
        $this->applySearch($query, $params['search'] ?? null, self::SEARCH_FIELDS);
        $this->applyExact($query, 'Employees.position_id', $params['position_id'] ?? null);
        $this->applyExact($query, 'Employees.operation_center_id', $params['operation_center_id'] ?? null);
        $this->applyEmployeeStatus($query, $params['status'] ?? null);

        return $query;
    }

    /**
     * Aplica filtro de status con default 'activo' (CR-007).
     *
     * - Sin parametro o vacio  -> filtra por 'activo'
     * - 'all'                  -> sin filtro (bypass explicito)
     * - cualquier otro         -> filtra literal (ej: 'retirado')
     */
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

> El método público `apply()` mantiene la firma idéntica. Los métodos `applySearch`, `applyExact` heredados de la base son `protected`, accesibles desde la subclase.

- [ ] **Step 2: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 3: Validación manual de Employees index**

Levantar servidor (`php bin/cake server`). Abrir `http://localhost:8765/employees`.

Probar cada filtro y verificar comportamiento idéntico al previo:

1. **Default:** sin parámetros → solo activos, ordenados por apellido.
2. **Search:** `/employees?search=Juan` → coincide en first_name, last_name1, last_name2, document_number, email.
3. **Position filter:** `/employees?position_id={X}` → solo de esa posición.
4. **Operation center filter:** `/employees?operation_center_id={X}` → solo de ese centro.
5. **Status retirado:** `/employees?status=retirado` → solo retirados.
6. **Status all:** `/employees?status=all` → todos los empleados.
7. **Combinado:** `/employees?search=Pe&status=all&position_id={X}` → resultados combinados.

- [ ] **Step 4: Commit**

```bash
git add src/Service/EmployeeFilterService.php
git commit -m "refactor(employees): EmployeeFilterService extiende BaseFilterService (CR-026)"
```

---

## Task 6: Refactor `InvoiceFilterService` para extender la base

**Files:**
- Modify: `src/Service/InvoiceFilterService.php`

- [ ] **Step 1: Reemplazar el archivo completo**

Sobreescribir `src/Service/InvoiceFilterService.php` con:

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

    /**
     * Apply search and filter parameters to an invoices query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Base query (already contains associations).
     * @param array<string,mixed> $params Query-string parameters.
     * @return \Cake\ORM\Query\SelectQuery
     */
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

- [ ] **Step 2: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 3: Validación manual de Invoices index**

Servidor levantado. Abrir `http://localhost:8765/invoices`.

Probar cada filtro:

1. **Default:** lista paginada de facturas, sin filtros.
2. **Search:** `/invoices?search={parte_de_numero}` → coincide en invoice_number, purchase_order, detail, Providers.name.
3. **Provider filter:** `/invoices?provider_id={X}`.
4. **Operation center filter:** `/invoices?operation_center_id={X}`.
5. **Expense type filter:** `/invoices?expense_type_id={X}`.
6. **Pipeline status filter:** `/invoices?pipeline_status=tesoreria` → solo en ese estado.
7. **Date range:** `/invoices?date_from=2026-01-01&date_to=2026-04-30`.
8. **Combinado:** dos o más filtros.

Comportamiento debe ser idéntico al previo.

- [ ] **Step 4: Commit**

```bash
git add src/Service/InvoiceFilterService.php
git commit -m "refactor(invoices): InvoiceFilterService extiende BaseFilterService (CR-026)"
```

---

# Lote 9 — CR-028: Hardening de uploads

## Task 7: Rate limit en ruta de upload

**Files:**
- Modify: `config/routes.php` (zonas: imports cerca de línea 24, registro de middleware cerca de línea 56, scope de rutas de empleados cerca de línea 261)

- [ ] **Step 1: Registrar nueva instancia del middleware**

Edit `config/routes.php`. En el bloque que registra middlewares (alrededor de líneas 54-61), después de `rateLimitLogin`, agregar:

```php
        $builder->registerMiddleware(
            'rateLimitUpload',
            new RateLimitMiddleware(30, 3600),
        );
```

> 30 requests por ventana de 3600 segundos (1 hora). Limita por IP+path (comportamiento del middleware existente). Trade-off documentado: oficinas con NAT compartida comparten cuota. 30/hora es generoso para uso normal.

- [ ] **Step 2: Aplicar el middleware al scope de upload**

Las rutas actuales de upload de employees están listadas planas (sin scope), alrededor de las líneas 261-281. Refactorizarlas a un scope dedicado para poder aplicar el middleware.

Reemplazar el bloque actual (líneas 261-281, las rutas `/employees/add-folder/`, `/employees/upload-document/`, `/employees/delete-document/`, `/employees/download-document/`) por:

```php
        // Employee document management routes
        $builder->connect(
            '/employees/add-folder/{employeeId}',
            ['controller' => 'Employees', 'action' => 'addFolder'],
            ['employeeId' => '\d+', 'pass' => ['employeeId']],
        );
        $builder->scope('/employees', function (RouteBuilder $employeeUploadBuilder): void {
            // Rate limit hardening (CR-028): 30 uploads/hora por IP+path.
            // El middleware existente limita por IP, no por usuario; oficinas con
            // NAT comparten cuota — ajustar el límite si genera falsos positivos.
            $employeeUploadBuilder->applyMiddleware('rateLimitUpload');
            $employeeUploadBuilder->connect(
                '/upload-document/{employeeId}',
                ['controller' => 'Employees', 'action' => 'uploadDocument'],
                ['employeeId' => '\d+', 'pass' => ['employeeId']],
            );
        });
        $builder->connect(
            '/employees/delete-document/{employeeId}/{documentId}',
            ['controller' => 'Employees', 'action' => 'deleteDocument'],
            ['employeeId' => '\d+', 'documentId' => '\d+', 'pass' => ['employeeId', 'documentId']],
        );
        $builder->connect(
            '/employees/download-document/{employeeId}/{documentId}',
            ['controller' => 'Employees', 'action' => 'downloadDocument'],
            ['employeeId' => '\d+', 'documentId' => '\d+', 'pass' => ['employeeId', 'documentId']],
        );
```

> Solo `upload-document` queda dentro del scope con rate limit. `add-folder`, `delete-document` y `download-document` siguen sin rate limit (no son vectores de abuse de upload).

- [ ] **Step 3: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 4: Validación manual — uploads normales no se bloquean**

Levantar servidor. Abrir `/employees/view/{id}`. Subir 3-5 documentos válidos seguidos. Todos deben funcionar normal.

- [ ] **Step 5: Validación manual — rate limit dispara**

Para verificar el corte sin esperar a llegar a 30, **temporalmente** ajustar el límite a un número bajo (ej: 3) editando la línea de registro:

```php
new RateLimitMiddleware(3, 3600),
```

Reiniciar el servidor. Subir 3 documentos → OK. Subir el 4to → debe responder HTTP 429 (en navegador, se verá un error genérico de "Too many requests"; con curl, `curl -X POST -F file=@... http://localhost:8765/employees/upload-document/1` devuelve 429 con header `Retry-After`).

Después de validar, **revertir el límite a 30** y reiniciar.

- [ ] **Step 6: Validación de tabla `rate_limit_buckets`**

Conectarse a la BD y verificar que entradas para uploads se están registrando:

```sql
SELECT * FROM rate_limit_buckets ORDER BY id DESC LIMIT 10;
```

Debe haber filas recientes correspondientes al hash de IP+path+ventana.

- [ ] **Step 7: Commit**

```bash
git add config/routes.php
git commit -m "feat(employees): rate limit en upload-document (CR-028)"
```

---

## Task 8: Constantes y helper `canonicalize` en `EmployeeDocumentService`

**Files:**
- Modify: `src/Service/EmployeeDocumentService.php`

- [ ] **Step 1: Agregar constantes `MIME_TO_EXT` y `MIME_TO_EXT_PROFILE`**

Edit `src/Service/EmployeeDocumentService.php`. Después de la constante `ALLOWED_PROFILE_MIMES` (alrededor de la línea 42), antes del comentario PHPDoc de `storageRoot()`, agregar:

```php
    /**
     * Mapeo MIME real → extensión canónica para documentos.
     * Usado por canonicalize() para renombrar el archivo en disco si la
     * extensión del cliente no coincide con la canónica del MIME real (CR-028).
     */
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

- [ ] **Step 2: Agregar el helper privado `canonicalize`**

En el mismo archivo, al final de la clase (después de `purgeDir`, antes del `}` de cierre), agregar:

```php
    /**
     * Renombra el archivo en disco para que su extensión coincida con la
     * canónica del MIME real detectado por finfo (CR-028).
     *
     * Si el rename falla (permisos, etc.), se conserva el path original sin
     * lanzar excepción — la validación de MIME ya pasó, esto es defense-in-depth.
     *
     * @param array<string,string> $mimeToExt Mapeo MIME → extensión canónica.
     * @return array{0:string, 1:string} [absolutePath, relativePath] resultantes.
     */
    private function canonicalize(
        string $absolutePath,
        string $relativePath,
        string $realMime,
        array $mimeToExt,
    ): array {
        $canonicalExt = $mimeToExt[$realMime] ?? null;
        if ($canonicalExt === null) {
            return [$absolutePath, $relativePath];
        }

        $currentExt = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($currentExt === $canonicalExt) {
            return [$absolutePath, $relativePath];
        }

        $newAbsolute = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $absolutePath) ?? $absolutePath;
        $newRelative = preg_replace('/\.[^.]+$/', '.' . $canonicalExt, $relativePath) ?? $relativePath;

        if (!@rename($absolutePath, $newAbsolute)) {
            return [$absolutePath, $relativePath];
        }

        return [$newAbsolute, $newRelative];
    }
```

- [ ] **Step 3: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Service/EmployeeDocumentService.php
git commit -m "feat(employees): constantes MIME_TO_EXT y helper canonicalize (CR-028)"
```

---

## Task 9: Aplicar `canonicalize` en `uploadDocument` y `handleProfileImage`

**Files:**
- Modify: `src/Service/EmployeeDocumentService.php`

- [ ] **Step 1: Aplicar canonicalize en `uploadDocument`**

Edit `src/Service/EmployeeDocumentService.php`. En el método `uploadDocument`, después del bloque que valida el MIME real (que actualmente termina en `if (!in_array($realMime, self::ALLOWED_DOC_MIMES, true)) { @unlink($absolutePath); return ServiceResult::fail(...); }`) y **antes** de construir el `$documentsTable->newEntity([...])` (alrededor de las líneas 148-156).

Reemplazar el bloque que va desde el cálculo de `$relativeFilePath` (en realidad hoy se hace inline en el array) — específicamente:

Antes (líneas ~141-156):

```php
        // Validar MIME real luego de mover (finfo opera sobre el archivo final)
        $realMime = $this->detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_DOC_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido del archivo no coincide con su extensión.');
        }

        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        $document = $documentsTable->newEntity([
            'employee_folder_id' => $folderId,
            'name' => $originalName,
            'file_path' => $employeeId . '/' . $uniqueName,
            'file_size' => $file->getSize(),
            'mime_type' => $realMime,
            'uploaded_by' => $uploadedBy,
        ]);
```

Después:

```php
        // Validar MIME real luego de mover (finfo opera sobre el archivo final)
        $realMime = $this->detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_DOC_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido del archivo no coincide con su extensión.');
        }

        // Canonicalizar extensión a partir del MIME real (CR-028).
        [$absolutePath, $relativeFilePath] = $this->canonicalize(
            $absolutePath,
            $employeeId . '/' . $uniqueName,
            $realMime,
            self::MIME_TO_EXT,
        );

        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        $document = $documentsTable->newEntity([
            'employee_folder_id' => $folderId,
            'name' => $originalName,
            'file_path' => $relativeFilePath,
            'file_size' => $file->getSize(),
            'mime_type' => $realMime,
            'uploaded_by' => $uploadedBy,
        ]);
```

- [ ] **Step 2: Aplicar canonicalize en `handleProfileImage`**

En el mismo archivo, en el método `handleProfileImage`, después del bloque que valida MIME real de profile image. Antes del último bloque que asigna `$employee->profile_image`.

Antes (líneas ~235-245):

```php
        $realMime = $this->detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_PROFILE_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido de la imagen no coincide con su extensión.');
        }

        $employee->profile_image = 'uploads/employees/' . $employee->id . '/' . $fileName;
        $employee->setDirty('profile_image', true);

        return ServiceResult::ok(['path' => $employee->profile_image]);
```

Después:

```php
        $realMime = $this->detectRealMime($absolutePath);
        if (!in_array($realMime, self::ALLOWED_PROFILE_MIMES, true)) {
            @unlink($absolutePath);

            return ServiceResult::fail('El contenido de la imagen no coincide con su extensión.');
        }

        // Canonicalizar extensión a partir del MIME real (CR-028).
        [$absolutePath, $relativePath] = $this->canonicalize(
            $absolutePath,
            'uploads/employees/' . $employee->id . '/' . $fileName,
            $realMime,
            self::MIME_TO_EXT_PROFILE,
        );

        $employee->profile_image = $relativePath;
        $employee->setDirty('profile_image', true);

        return ServiceResult::ok(['path' => $employee->profile_image]);
```

- [ ] **Step 3: Verificar code style**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Step 4: Validación manual — upload con extensión correcta**

Levantar servidor. Subir un PDF real desde `/employees/view/{id}` → upload-document. Verificar:

- En BD: `SELECT file_path, mime_type FROM employee_documents ORDER BY id DESC LIMIT 1;` → `file_path` debe terminar en `.pdf`, `mime_type` debe ser `application/pdf`.
- En disco: archivo existe en `storage/employees/{id}/doc_xxx.pdf`.

- [ ] **Step 5: Validación manual — upload con extensión mismatch (defense-in-depth)**

Tomar un PNG real (ej: cualquier `.png` del filesystem). Renombrarlo localmente a `imagen.jpeg` (cambiar solo el nombre, no el contenido).

Subirlo desde la UI → upload-document.

Verificar:

- El upload **debe pasar** (el MIME real `image/png` está en `ALLOWED_DOC_MIMES`).
- En BD: `file_path` debe terminar en `.png` (no en `.jpeg`).
- En disco: el archivo está como `doc_xxx.png` (no `doc_xxx.jpeg`).
- `mime_type` en BD: `image/png`.

> Esto demuestra que la extensión almacenada se deriva del MIME real, no de lo que mandó el cliente.

- [ ] **Step 6: Validación manual — profile image con mismatch**

Igual ejercicio en `/employees/edit/{id}` con la imagen de perfil:

- Tomar una imagen JPG real, renombrarla a `foto.png`, subirla como profile_image.
- Después del save, verificar:
  - En BD: `SELECT profile_image FROM employees WHERE id = {X};` → debe terminar en `.jpg` (canónica del MIME real `image/jpeg`).
  - En disco: archivo está en `webroot/uploads/employees/{id}/profile.jpg`.
  - El avatar en `view` debe renderizar la imagen correctamente.

- [ ] **Step 7: Validación manual — rechazo de mismatch real**

Tomar un archivo `.txt` con contenido `<?php phpinfo(); ?>` y renombrarlo a `evil.jpg`. Intentar subirlo como documento.

Verificar:

- Upload **debe ser rechazado** con mensaje "El contenido del archivo no coincide con su extensión."
- No queda nada en disco ni en BD.

> Regresión-test del Lote 1; este flujo no debe haberse roto con el rename canónico.

- [ ] **Step 8: Commit**

```bash
git add src/Service/EmployeeDocumentService.php
git commit -m "feat(employees): rename canonico por MIME real en uploads (CR-028)"
```

---

# Cierre — Actualizar documento de auditoría

## Task 10: Actualizar `docs/audits/employees-module-audit-2026-05-07.md`

**Files:**
- Modify: `docs/audits/employees-module-audit-2026-05-07.md`

- [ ] **Step 1: Actualizar el verdicto global**

En el header del documento, cambiar la línea:

```markdown
**Verdicto global:** ❌ **REQUEST CHANGES** — funcionalmente completo y alineado con varias convenciones del SGI, pero con 3 vulnerabilidades de seguridad críticas y 8 issues mayores que bloquean aprobación.
```

Reemplazar por:

```markdown
**Verdicto global:** ✅ **APPROVED** — auditoría 100% cerrada al 2026-05-08 (22 resueltos en Lotes 1-7, 2 aceptados sin acción, 1 resuelto parcial con backlog documentado, 2 marcados No Aplica con justificación). Ningún hallazgo abierto.
```

- [ ] **Step 2: Actualizar las filas de la tabla "Estado de remediación"**

En la tabla "Estado de remediación", actualizar las filas correspondientes:

**CR-024** — antes:
```markdown
| CR-024 | 🟢 Sugerencia | VO `SocialSecurityInfo` (eps + pension_fund + arl + severance_fund) | ⏳ Pendiente | — |
```
después:
```markdown
| CR-024 | 🟢 Sugerencia | VO `SocialSecurityInfo` (eps + pension_fund + arl + severance_fund) | ❌ No Aplica | Cierre (2026-05-08) — campos `eps`, `pension_fund`, `arl`, `severance_fund` no exhibidos en UI/exports actuales. VO sin caller real. Reabrir si aparece UI/Excel/PDF que los muestre como bloque |
```

**CR-025** — antes:
```markdown
| CR-025 | 🟢 Sugerencia | VO `Identification` (document_type + document_number) | ⏳ Pendiente | — |
```
después:
```markdown
| CR-025 | 🟢 Sugerencia | VO `Identification` (document_type + document_number) | ✅ Resuelto | Lote 8 (2026-05-08) — `App\Model\ValueObject\Identification` + virtual getter `_getIdentification` en Employee + uso en `view.php:57` |
```

**CR-026** — antes:
```markdown
| CR-026 | 🟢 Sugerencia | Extraer `BaseFilterService` reusable | ⏳ Pendiente | — |
```
después:
```markdown
| CR-026 | 🟢 Sugerencia | Extraer `BaseFilterService` reusable | ✅ Resuelto | Lote 10 (2026-05-08) — `App\Service\Filter\BaseFilterService` con `applySearch`/`applyExact`/`applyDateRange`; `EmployeeFilterService` e `InvoiceFilterService` extienden |
```

**CR-028** — antes:
```markdown
| CR-028 | 🟢 Sugerencia | Defense-in-depth en uploads (rate limit, AV, rename por finfo) | ⏳ Pendiente | — |
```
después:
```markdown
| CR-028 | 🟢 Sugerencia | Defense-in-depth en uploads (rate limit, AV, rename por finfo) | ⚠️ Resuelto parcial | Lote 9 (2026-05-08) — rate limit (30 req/h por IP+path) en `/employees/upload-document/*` + rename canónico por MIME real en uploadDocument y handleProfileImage. AV scanning (ClamAV) no aplica por falta de infra (easypanel sin daemon AV); queda como backlog si el ambiente cambia |
```

**CR-030** — antes:
```markdown
| CR-030 | 🟢 Sugerencia | Conteo de documentos vía `hasManyCount` finder | ⏳ Pendiente | — |
```
después:
```markdown
| CR-030 | 🟢 Sugerencia | Conteo de documentos vía `hasManyCount` finder | ❌ No Aplica | Cierre (2026-05-08) — el código actual NO genera N+1: `view()` carga `EmployeeFolders → EmployeeDocuments` en un solo `contain` y la suma se ejecuta sobre arrays ya en memoria. Cambiar a `hasManyCount` agregaría una query sin reemplazar la iteración (el template necesita los documentos completos). Falso positivo |
```

- [ ] **Step 3: Validación visual del documento**

Abrir el archivo en el editor o en GitHub preview. Verificar:

- El verdicto global muestra ✅ APPROVED.
- Las 5 filas actualizadas muestran sus nuevos estados con razón documentada.
- Las filas de CR-001 a CR-023, CR-027, CR-029 (que ya estaban resueltas) NO se modificaron.
- La tabla sigue siendo válida Markdown (probar render en GitHub).

- [ ] **Step 4: Commit**

```bash
git add docs/audits/employees-module-audit-2026-05-07.md
git commit -m "docs(audit): cerrar auditoria Employees al 100% (Lotes 8-10 + cierre)"
```

---

## Validación final del plan completo

Después de los 10 commits, verificar el estado final:

- [ ] **Estado de git limpio**

```bash
git status
```

Expected: working tree clean.

- [ ] **Code style global**

```bash
composer cs-check
```

Expected: PASS.

- [ ] **Smoke test integral**

Levantar servidor (`php bin/cake server`) y ejercitar las rutas principales en el navegador:

1. `/employees` — index con default (solo activos), search, filtros → OK.
2. `/employees/view/{id}` — subtítulo `tipo · número` correcto, lista de documentos OK.
3. `/employees/edit/{id}` — guardar, profile image con extensión canónica.
4. Subir documento desde `view` — OK con extensión canónica.
5. `/invoices` — index con search, filtros, date range → OK.

- [ ] **Audit doc render**

Abrir `docs/audits/employees-module-audit-2026-05-07.md` y confirmar:
- Verdicto: ✅ APPROVED.
- 22 resueltos + 2 aceptados + 1 parcial + 2 No Aplica = 27 (más los 3 críticos resueltos en Lote 1 = 30 total). ✅

---

## Out of scope (no implementar en este plan)

Recordatorio de cosas que **explícitamente** no van en este plan, para evitar scope creep:

- **VO `SocialSecurityInfo` (CR-024):** descartado por falta de callers.
- **`hasManyCount` finder (CR-030):** descartado por ser falso positivo.
- **AV scanning con ClamAV (sub-tarea de CR-028):** descartado por infra; backlog.
- **Caché de catálogos `_setFormDropdowns` (CR-022):** ya cerrado en Lote 5 como aceptado.
- **Split de `EmployeeDocumentService` (CR-018):** ya cerrado en Lote 3 como aceptado.
- **Tests automatizados:** política del proyecto.
- **Mover `EmployeeFilterService`/`InvoiceFilterService` al namespace `App\Service\Filter\`:** mantienen su namespace para no tocar imports en controllers.
- **Convertir el rate limit a "por usuario":** el middleware existente es por IP+path; cambiar a por-usuario expandiría blast radius. Documentado como trade-off conocido.

---

## Resumen del plan

| # | Task | Lote | CR |
|---|------|------|-----|
| 1 | Crear VO `Identification` | 8 | CR-025 |
| 2 | Virtual getter en Employee | 8 | CR-025 |
| 3 | Uso en view.php | 8 | CR-025 |
| 4 | Crear `BaseFilterService` | 10 | CR-026 |
| 5 | Refactor `EmployeeFilterService` | 10 | CR-026 |
| 6 | Refactor `InvoiceFilterService` | 10 | CR-026 |
| 7 | Rate limit en routes.php | 9 | CR-028 |
| 8 | Constantes + canonicalize helper | 9 | CR-028 |
| 9 | Aplicar canonicalize en flujos | 9 | CR-028 |
| 10 | Actualizar audit doc | Cierre | CR-024, CR-028, CR-030 |

**Total: 10 tasks, 10 commits, 1 PR (o 3 PRs si se prefiere separar por lote).**
