# Diseño: Plantillas de documento por tipo de contrato en LeaveTypes

**Fecha:** 2026-03-06
**Estado:** Validado

---

## Objetivo

Permitir que cada tipo de permiso (LeaveType) tenga plantillas de documento PDF diferenciadas por tipo de contrato del empleado (FIJO, INDEFINIDO, OBRA O LABOR DETERMINADA) y, en el caso de OBRA O LABOR DETERMINADA, por organización temporal.

---

## 1. Modelo de datos

### Nueva tabla: `leave_type_contract_templates`

| Columna | Tipo | Descripción |
|---------|------|-------------|
| id | INT PK AUTO | - |
| leave_type_id | INT FK -> leave_types | Tipo de permiso |
| contract_type | VARCHAR(30) | FIJO, INDEFINIDO, OBRA O LABOR DETERMINADA |
| temporary_organization_id | INT FK nullable -> temporary_organizations | Solo cuando contract_type = OBRA O LABOR DETERMINADA |
| leave_document_template_id | INT FK -> leave_document_templates | Plantilla PDF |
| created | DATETIME | - |
| modified | DATETIME | - |

**Constraint unico:** `(leave_type_id, contract_type, temporary_organization_id)` - evita duplicados.

### Migracion sobre `leave_types`

- Eliminar columna `leave_document_template_id` de `leave_types`.

### Migracion sobre `employees`

- Renombrar valores de `contract_type`: Fijo -> FIJO, Indefinido -> INDEFINIDO, Temporal -> OBRA O LABOR DETERMINADA.

### Nuevas constantes: `src/Constants/ContractTypeConstants.php`

```php
final class ContractTypeConstants
{
    public const FIJO = 'FIJO';
    public const INDEFINIDO = 'INDEFINIDO';
    public const OBRA_LABOR = 'OBRA O LABOR DETERMINADA';

    public const ALL = [self::FIJO, self::INDEFINIDO, self::OBRA_LABOR];
}
```

---

## 2. Backend

### Nuevo modelo `LeaveTypeContractTemplate`

- **Entity:** $_accessible con leave_type_id, contract_type, temporary_organization_id, leave_document_template_id.
- **Table:** belongsTo LeaveTypes, TemporaryOrganizations, LeaveDocumentTemplates. Validacion contract_type inList de las 3 constantes. Rule: si contract_type != OBRA O LABOR DETERMINADA -> temporary_organization_id debe ser null.

### Cambios en LeaveTypesTable

- Eliminar belongsTo('LeaveDocumentTemplates').
- Agregar hasMany('LeaveTypeContractTemplates').

### Cambios en LeaveTypesController (add/edit)

- Recibir datos de la tabla inline como array asociado (leave_type_contract_templates).
- Usar patchEntity con associated para guardar en una transaccion.
- Pasar a la vista: lista de plantillas, lista de organizaciones temporales, constantes de contrato.

### Cambios en EmployeesTable

- Actualizar validacion inList de contract_type a usar ContractTypeConstants::ALL.
- Cambiar la regla: si contract_type = ContractTypeConstants::OBRA_LABOR -> exigir temporary_organization_id.

### Cambios en LeaveDocumentService

- Nuevo metodo resolveTemplate(leaveTypeId, contractType, temporaryOrgId) que busca en leave_type_contract_templates.
- generatePdf() ya no lee leave_type->leave_document_template_id, usa resolveTemplate().
- Si no encuentra plantilla -> retorna error.

---

## 3. Interfaz (formulario add/edit LeaveType)

Tabla inline dinamica debajo de los campos actuales (codigo, nombre, remunerado):

```
+----------------------------------------------------------+
| ASIGNACION DE PLANTILLAS POR TIPO DE CONTRATO            |
+---------------------+-----------------+----------+-------+
| Tipo de Contrato    | Org. Temporal   | Plantilla|       |
+---------------------+-----------------+----------+-------+
| [FIJO          v]   | (deshabilitado) | [Luto v] | [X]   |
| [OBRA O LABO.. v]   | [Agencia A  v]  | [Luto v] | [X]   |
+---------------------+-----------------+----------+-------+
| [+ Agregar asignacion]                                   |
+----------------------------------------------------------+
```

- Boton "+ Agregar asignacion" agrega fila con 3 dropdowns.
- Dropdown "Org. Temporal" se habilita solo con OBRA O LABOR DETERMINADA.
- Boton eliminar quita la fila.
- Campos se envian como array: leave_type_contract_templates[N][campo].
- Estilo: label micro-caps, bordes, form-select.

---

## 4. Resolucion de plantilla al exportar PDF

1. Obtener empleado con contract_type y temporary_organization_id.
2. Buscar en leave_type_contract_templates:
   - Match exacto: leave_type_id + contract_type + temporary_organization_id (para OBRA O LABOR DETERMINADA).
   - Match por contrato: leave_type_id + contract_type + temporary_organization_id IS NULL (para FIJO/INDEFINIDO).
3. Si encuentra -> usa esa plantilla.
4. Si no encuentra -> flash error "No hay plantilla configurada para este tipo de contrato".

---

## 5. Archivos impactados

### Nuevos
- `src/Constants/ContractTypeConstants.php`
- `src/Model/Entity/LeaveTypeContractTemplate.php`
- `src/Model/Table/LeaveTypeContractTemplatesTable.php`
- `config/Migrations/YYYYMMDD_CreateLeaveTypeContractTemplates.php`
- `config/Migrations/YYYYMMDD_RemoveTemplateFromLeaveTypes.php`
- `config/Migrations/YYYYMMDD_UpdateContractTypeValues.php`

### Modificados
- `src/Model/Table/LeaveTypesTable.php` - cambiar asociacion
- `src/Model/Entity/LeaveType.php` - quitar leave_document_template_id de accessible
- `src/Controller/LeaveTypesController.php` - tabla inline, pasar datos extra
- `templates/LeaveTypes/add.php` - tabla inline UI
- `templates/LeaveTypes/edit.php` - tabla inline UI
- `templates/LeaveTypes/index.php` - ajustar columna plantilla
- `src/Service/LeaveDocumentService.php` - resolveTemplate(), cambiar generatePdf()
- `src/Controller/EmployeeLeavesController.php` - usar resolveTemplate()
- `src/Model/Table/EmployeesTable.php` - constantes en validacion
- `templates/Employees/add.php` - label "OBRA O LABOR DETERMINADA"
- `templates/Employees/edit.php` - label "OBRA O LABOR DETERMINADA"
- `templates/Employees/view.php` - mostrar nuevo nombre
