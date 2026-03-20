# Diseño: Módulo de Legalizaciones

**Fecha:** 2026-03-20
**Estado:** Aprobado

---

## Alcance

Crear el módulo de **Legalizaciones**, réplica funcional del módulo de Caja Menor, que permite agrupar facturas de tipo "Legalización" y procesarlas a través del mismo pipeline de 4 estados.

### Diferencias con Caja Menor

| Aspecto | Caja Menor | Legalizaciones |
|---------|-----------|----------------|
| Tipo de documento filtrado | `Caja menor` | `Legalización` |
| Código | Autogenerado `CM-YYYY-NNNN` → **cambiar a manual/opcional** | Campo libre, opcional, editable |
| FK en invoices | `petty_cash_record_id` | `legalization_record_id` |
| Tablas | `petty_cash_records`, `petty_cash_documents`, `petty_cash_observations` | `legalization_records`, `legalization_documents`, `legalization_observations` |
| Pipeline | agrupación → contabilidad → tesorería → pagado | Idéntico |
| Validaciones de transición | Idénticas | Idénticas |
| Permisos | Dinámicos según módulo `petty_cash` | Dinámicos según módulo `legalizations` |

### Cambio adicional en Caja Menor

Eliminar generación automática de código. El campo `code` pasa a ser opcional y editable manualmente.

---

## Estructura de Archivos

### Nuevos archivos

**Backend:**
- `src/Controller/LegalizationRecordsController.php`
- `src/Model/Table/LegalizationRecordsTable.php`
- `src/Model/Table/LegalizationDocumentsTable.php`
- `src/Model/Table/LegalizationObservationsTable.php`
- `src/Model/Entity/LegalizationRecord.php`
- `src/Model/Entity/LegalizationDocument.php`
- `src/Model/Entity/LegalizationObservation.php`
- `src/Service/LegalizationService.php`
- `src/Service/LegalizationDocumentService.php`
- `src/Constants/LegalizationConstants.php`

**Vistas:**
- `templates/LegalizationRecords/index.php`
- `templates/LegalizationRecords/add.php`
- `templates/LegalizationRecords/edit.php`
- `templates/LegalizationRecords/view.php`
- `templates/element/legalization_progress.php`

**Migraciones:**
- `CreateLegalizationRecords`
- `AddLegalizationRecordIdToInvoices`
- `CreateLegalizationDocuments`
- `CreateLegalizationObservations`
- `AddLegalizationPermissions`

### Archivos a modificar

- `src/Controller/AppController.php` — mapeo `LegalizationRecords => legalizations`
- `src/Service/AuthorizationService.php` — módulo `legalizations`
- `config/routes.php` — rutas custom
- `src/Controller/PettyCashRecordsController.php` — code opcional
- `src/Service/PettyCashService.php` — eliminar `generateCode()`, code opcional
- `src/Model/Table/PettyCashRecordsTable.php` — quitar validación required de code
- Templates Caja Menor (add/edit) — code como campo editable opcional

---

## Modelo de Datos

### Tabla `legalization_records`

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | INT, PK, AI | |
| `code` | VARCHAR(30), nullable | Opcional, manual |
| `status` | VARCHAR(20), default 'agrupacion' | |
| `total_amount` | DECIMAL(15,2), default 0 | Suma de facturas |
| `accrued` | BOOLEAN, default false | |
| `accrual_date` | DATE, nullable | |
| `ready_for_payment` | VARCHAR(50), nullable | |
| `payment_status` | VARCHAR(30), nullable | |
| `payment_date` | DATE, nullable | |
| `notes` | TEXT, nullable | |
| `created_by` | INT, FK → users.id | |
| `created` | DATETIME | |
| `modified` | DATETIME | |

### Tabla `legalization_documents`

Idéntica a `petty_cash_documents` con FK `legalization_record_id`.

### Tabla `legalization_observations`

Idéntica a `petty_cash_observations` con FK `legalization_record_id`.

### Modificación a `invoices`

- `legalization_record_id` INT, nullable, FK → `legalization_records.id` (SET_NULL on delete)

### Modificación a `petty_cash_records`

- `code`: cambiar a nullable

---

## Lógica de Negocio

### `LegalizationConstants.php`

Mismos estados, labels, iconos y transiciones que PettyCashConstants. Sin CODE_PREFIX.

### `LegalizationService.php`

| Método | Comportamiento |
|--------|---------------|
| `validateGrouping()` | Filtra `document_type = 'Legalización'` |
| `addInvoices()` | Usa `legalization_record_id` |
| `removeInvoice()` | Usa `legalization_record_id` |
| `calculateAndSaveTotal()` | Idéntico |
| `advanceStatus()` | Mismas transiciones |
| `getTransitionErrors()` | Idéntico |
| `validateLegalizationTransition()` | Mismas reglas |
| `getAvailableInvoices()` | Filtra `document_type = 'Legalización'` y `legalization_record_id IS NULL` |
| `canDelete()` | Solo en agrupacion |

### `LegalizationDocumentService.php`

- Uploads en `/uploads/legalizations/{recordId}/`
- Prefijo: `leg_[uniqid].[ext]`

### Validaciones de transición

- **agrupación → contabilidad:** Al menos 1 factura
- **contabilidad → tesorería:** `accrued = true` y `ready_for_payment` seleccionado
- **tesorería → pagado:** `payment_status` y `payment_date` completados

---

## Vistas

Réplicas de Caja Menor con labels actualizados ("Legalizaciones").

- **Index:** Filtros: código, estado, rango de fechas
- **Add:** Campo code opcional, filtros de agrupación, Select2 para facturas tipo "Legalización"
- **Edit:** Dos columnas, code editable siempre, secciones por etapa, sidebar documentos + observaciones
- **View:** Solo lectura
- **Element:** Pipeline visual 4 pasos

---

## Rutas

```
/legalization-records                              → index, add, view, edit, delete
/legalization-records/advance-status/{id}          → advanceStatus
/legalization-records/upload-document/{id}         → uploadDocument
/legalization-records/delete-document/{rId}/{dId}  → deleteDocument
/legalization-records/remove-invoice/{rId}/{iId}   → removeInvoice
/legalization-records/add-observation/{id}         → addObservation
```

---

## Permisos

- Módulo: `legalizations`
- Admin: can_view=1, can_create=1, can_edit=1, can_delete=1
- Demás roles: can_view=1, can_create=0, can_edit=0, can_delete=0
- Configurable dinámicamente desde módulo de roles
