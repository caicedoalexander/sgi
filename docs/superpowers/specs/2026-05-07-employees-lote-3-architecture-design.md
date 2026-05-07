# Spec — Employees Lote 3 (Architecture)

**Fecha:** 2026-05-07
**Audit referenciado:** `docs/audits/employees-module-audit-2026-05-07.md`
**Hallazgos cubiertos:** CR-011 (resolver), CR-018 (aceptar y documentar)

---

## Contexto

Lotes 1 y 2 del audit ya están resueltos (seguridad + bugs). Lote 3 atiende dos hallazgos de arquitectura:

- **CR-011 — Major:** `EmployeesTable::onExcelImportCreated/Updated` instancian `new EmployeeDocumentService()` y `new EmployeeHistoryService()` directamente, violando el patrón DI del proyecto (services con fallback `?? new ServiceClass()` en constructor del controller).
- **CR-018 — Minor:** `EmployeeDocumentService` agrupa upload de documentos, profile image, default folders y ownership asserts. El audit explícitamente permite posponer si el costo es alto.

---

## CR-011 — DI en hooks de import (Resolver)

### Enfoque

Setters opcionales en `EmployeesTable` con fallback a `new`. No cambia la firma de `ExcelImportService::onExcelImportCreated/Updated` (no afecta a los 7 controllers restantes que implementan `ExcelExportableInterface`).

### Cambios en `src/Model/Table/EmployeesTable.php`

1. Agregar imports si no están: `use App\Service\EmployeeDocumentService; use App\Service\EmployeeHistoryService;` (verificar — probablemente ya existen).
2. Agregar dos propiedades privadas nullable:
   ```php
   private ?EmployeeDocumentService $documentService = null;
   private ?EmployeeHistoryService $historyService = null;
   ```
3. Agregar setters:
   ```php
   public function setDocumentService(EmployeeDocumentService $service): void
   {
       $this->documentService = $service;
   }

   public function setHistoryService(EmployeeHistoryService $service): void
   {
       $this->historyService = $service;
   }
   ```
4. Reescribir hooks:
   ```php
   public function onExcelImportCreated(EntityInterface $entity, int $userId): void
   {
       ($this->documentService ?? new EmployeeDocumentService())
           ->createDefaultFolders((int)$entity->id);
   }

   public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void
   {
       ($this->historyService ?? new EmployeeHistoryService())
           ->recordChanges($original, $entity, $userId);
   }
   ```

### Cambios en `src/Controller/EmployeesController.php`

1. Verificar que el controller ya tiene `EmployeeDocumentService` y `EmployeeHistoryService` inyectados (usados por `add`/`edit`/`delete`/`uploadDocument` etc.). Si no, agregarlos al constructor con el patrón estándar:
   ```php
   public function __construct(
       ?EmployeeDocumentService $documentService = null,
       ?EmployeeHistoryService $historyService = null,
       // ...resto
   ) {
       $this->documentService = $documentService ?? new EmployeeDocumentService();
       $this->historyService = $historyService ?? new EmployeeHistoryService();
   }
   ```
2. En la action que dispara el import (típicamente `import()` provista por `ExcelWizardTrait`, o donde sea que el controller obtenga la table y llame a `runImport`):
   - Justo antes de invocar el import:
     ```php
     $employeesTable = $this->fetchTable('Employees');
     $employeesTable->setDocumentService($this->documentService);
     $employeesTable->setHistoryService($this->historyService);
     ```
   - Si `ExcelWizardTrait` resuelve la table internamente y no expone un punto de extensión, agregar un hook al trait `beforeImport(Table $table): void` con implementación vacía por defecto y override en `EmployeesController` que llame a los setters. (Decidir al ejecutar — preferir setters directos si el trait permite.)

### Validación manual

1. `composer cs-check` — sin warnings nuevos.
2. Levantar `php bin/cake server`.
3. **Import — empleado nuevo:** subir Excel con un empleado que no existe → verificar:
   - Empleado creado.
   - Carpetas por defecto presentes en `view()` del empleado.
4. **Import — empleado existente:** subir Excel con cambio en algún campo (p.ej. position_id) → verificar:
   - Cambio aplicado.
   - Registro nuevo en `employee_histories` para el empleado y `user_id` correcto.
5. **Smoke en CRUD normal:** crear empleado vía `add` → carpetas por defecto siguen funcionando (el fallback `new` cubre cualquier código que no haya pasado por el setter).

---

## CR-018 — Split de `EmployeeDocumentService` (Aceptar y documentar)

### Decisión

Aceptar el hallazgo como **🟢 Aceptado / pospuesto**. No modificar código.

### Justificación

- El service tiene 4 grupos de operaciones, pero todos giran alrededor de **"gestión de archivos del empleado"** (cohesión funcional alta).
- 360 LOC no califica como God class por sí solo.
- El audit explícitamente permite esta decisión: _"Si el costo del split es alto, dejar como nota/sugerencia y abordar cuando crezca el service."_
- Re-evaluar si: supera 500 LOC, aparece 5ta responsabilidad, o el equipo encuentra dificultad para testear/mantenerlo.

### Cambio en `docs/audits/employees-module-audit-2026-05-07.md`

- Tabla "Estado de remediación", fila CR-018:
  - Severidad: cambiar `🟡 Minor` a `🟢 Aceptado`.
  - Estado: `✅ Aceptado` con nota inline.
  - Resuelto en: `Lote 3 (2026-05-07) — service cohesionado en torno a gestión de archivos. Re-evaluar si supera 500 LOC.`

---

## Archivos a tocar

| Archivo | Cambio |
|---------|--------|
| `src/Model/Table/EmployeesTable.php` | Setters DI + reescritura de 2 hooks |
| `src/Controller/EmployeesController.php` | Inyección de services en table antes de import |
| `docs/audits/employees-module-audit-2026-05-07.md` | Marcar CR-011 ✅ Resuelto, CR-018 ✅ Aceptado |

---

## Criterios de éxito

- [ ] `composer cs-check` pasa sin warnings nuevos.
- [ ] `EmployeesTable` ya no contiene `new EmployeeDocumentService()` ni `new EmployeeHistoryService()` directos en hooks (solo como fallback dentro de `??`).
- [ ] Import wizard de empleados crea carpetas por defecto e historiales correctamente.
- [ ] Audit actualizado con estado de CR-011 y CR-018.
