# SGI - Arquitectura del Sistema

Documento de referencia arquitectonica para el Sistema de Gestion Interna (SGI).

---

## 1. Stack Tecnologico

| Componente | Tecnologia | Version |
|------------|-----------|---------|
| Framework | CakePHP | 5.3.* |
| PHP | PHP | >= 8.2 |
| Base de datos | MariaDB | Remota (Easypanel) |
| Autenticacion | cakephp/authentication | ^3.0 |
| Excel | phpoffice/phpspreadsheet | ^2.0 / ^3.0 |
| PDF | TCPDF + FPDI | ^6.10 / ^2.6 |
| Frontend | Bootstrap 5 + CSS plano | CDN |
| Tipografia | Inter Variable | Local (TTF) |
| Iconos | Bootstrap Icons | CDN |
| Fechas | Flatpickr | CDN |
| Moneda | AutoNumeric | CDN |
| Selects | Select2 | CDN |

---

## 2. Estructura de Directorios

```
sgi/
|-- src/
|   |-- Application.php              # Middleware stack, autenticacion
|   |-- Controller/
|   |   |-- AppController.php         # Base: permisos, sidebar counters
|   |   |-- DashboardController.php   # Dashboard con contadores
|   |   |-- InvoicesController.php    # CRUD + pipeline de facturas
|   |   |-- EmployeesController.php   # CRUD + documentos de empleados
|   |   |-- UsersController.php       # Login/logout, CRUD usuarios
|   |   |-- Trait/
|   |   |   |-- ExcelCatalogTrait.php # Export/import reutilizable
|   |   |-- [28 controllers mas]     # Catalogos y modulos
|   |
|   |-- Model/
|   |   |-- Entity/                   # Entidades tipadas con helpers de dominio
|   |   |   |-- Invoice.php           # isRejected(), isApproved(), isPaid()
|   |   |   |-- Employee.php          # Campos de empleado + contrato
|   |   |   |-- User.php              # Hash de password automatico
|   |   |-- Table/                    # Tablas ORM con validacion via constantes
|   |   |   |-- InvoicesTable.php     # Asociaciones, validacion, finders
|   |   |   |-- EmployeesTable.php    # Validacion condicional por tipo contrato
|   |   |   |-- UsersTable.php        # findAuth() para login
|   |
|   |-- Service/                      # Servicios con inyeccion de dependencias
|   |   |-- InvoicePipelineService.php    # Pipeline de 4 estados
|   |   |-- InvoiceHistoryService.php     # Audit trail campo a campo
|   |   |-- AuthorizationService.php      # RBAC por rol/modulo/accion
|   |   |-- ApprovalTokenService.php      # Tokens SHA256, delega al pipeline
|   |   |-- NotificationService.php       # Emails SMTP dinamico
|   |   |-- EmployeeDocumentService.php   # Gestion de archivos empleado
|   |   |-- InvoiceDocumentService.php    # Gestion de archivos factura
|   |   |-- ExcelService.php              # Export/import XLSX
|   |   |-- InvoiceFilterService.php      # Filtros de busqueda facturas
|   |   |-- EmployeeFilterService.php     # Filtros de busqueda empleados
|   |   |-- SystemSettingsService.php     # Configuracion SMTP, etc.
|   |   |-- DianCrosscheckService.php     # Cruce DIAN
|   |   |-- LeaveDocumentService.php      # Documentos de permisos
|   |   |-- LeaveSignatureService.php     # Firma digital permisos
|   |   |-- N8nService.php                # Integracion n8n
|   |   |-- WebhookService.php            # Webhooks salientes
|   |   |-- ImportResult.php              # DTO resultado importacion
|   |
|   |-- Constants/
|   |   |-- RoleConstants.php             # Nombres de roles del sistema
|   |   |-- EmployeeStatusConstants.php   # IDs de estados de empleado
|   |   |-- InvoiceConstants.php          # Tipos, estados, opciones de factura
|   |
|   |-- View/
|   |   |-- AppView.php              # formatDateEs() fechas en espanol
|   |
|   |-- Middleware/
|       |-- HostHeaderMiddleware.php  # Prevencion Host Header Injection
|
|-- templates/
|   |-- layout/
|   |   |-- default.php              # Sidebar + topbar + content area
|   |   |-- login.php                # Split-panel (dark | blanco)
|   |   |-- ajax.php                 # Respuestas AJAX sin layout
|   |   |-- external.php             # Layout para aprobacion externa
|   |   |-- error.php                # Layout de errores
|   |   |-- email/                   # Templates de email HTML/texto
|   |-- element/
|   |   |-- pipeline_progress.php    # Barra visual del pipeline
|   |   |-- pagination.php           # Paginacion reutilizable
|   |   |-- catalog_excel_buttons.php # Botones export/import
|   |   |-- flash/                   # Mensajes flash por tipo
|   |-- [Carpetas por controller]    # index, add, edit, view
|
|-- config/
|   |-- app.php / app_local.php      # Configuracion de la aplicacion
|   |-- routes.php                   # Rutas custom + fallbacks
|   |-- bootstrap.php                # Carga de .env, plugins
|   |-- Migrations/                  # Migraciones con prefijo fecha
|   |-- Seeds/                       # Seed inicial
|
|-- webroot/
|   |-- css/styles.css               # Sistema de diseno completo
|   |-- js/sgi-common.js             # Inicializacion de plugins JS
|   |-- js/leave-template-editor.js  # Editor de plantillas de permisos
|   |-- fonts/Inter-Variable.ttf     # Fuente Inter (100-900)
|   |-- uploads/                     # Archivos subidos (employees, invoices)
|   |-- icons/favicon.svg
```

---

## 3. Capas de la Aplicacion

### 3.1 Request Lifecycle

```
Request HTTP
    |
    v
[Middleware Stack]
    ErrorHandler -> HostHeader -> Asset -> Routing -> Authentication -> BodyParser -> CSRF
    |
    v
[AppController::beforeFilter]
    1. Obtener identidad del usuario
    2. Setear currentUser en vistas
    3. Calcular sidebar counters
    4. Calcular permisos del usuario
    5. Enforcar permiso para controller/action actual
    |
    v
[Controller Action]
    1. Validar datos de entrada
    2. Delegar a Service (logica de negocio)
    3. Interactuar con Model (persistencia)
    4. Setear variables para la vista
    |
    v
[View/Template]
    Layout (default.php) + Template especifico
    |
    v
Response HTTP
```

### 3.2 Responsabilidades por Capa

| Capa | Responsabilidad | NO debe hacer |
|------|----------------|---------------|
| **Controller** | Recibir request, validar input, delegar a services, preparar vista | Logica de negocio, queries complejas |
| **Service** | Logica de negocio, orquestacion, transacciones | Acceso directo a request/response |
| **Table (Model)** | Asociaciones, validacion de datos, custom finders | Logica de negocio compleja |
| **Entity** | Whitelist de campos, propiedades virtuales, helpers de dominio | Queries a BD |
| **View/Template** | Presentacion HTML, formateo visual | Logica de negocio, queries a BD |
| **Middleware** | Seguridad transversal, parsing | Logica de negocio especifica |
| **Constants** | Valores de dominio reutilizables (roles, estados, tipos) | Logica, acceso a BD |

---

## 4. Modulos del Sistema

### 4.1 Modulo de Facturas (core)

Implementa un **pipeline de 4 estados**:

```
aprobacion --> contabilidad --> tesoreria --> pagada
```

**Componentes:**
- `InvoicesController` — CRUD + avance de estado, usa `_buildInvoiceQuery()` para queries reutilizables
- `InvoicePipelineService` — transiciones, campos editables por rol, `saveAndAdvance()`
- `InvoiceHistoryService` — audit trail campo a campo con comparacion estricta normalizada
- `ApprovalTokenService` — tokens SHA256, delega aprobacion al pipeline via `saveAndAdvance()`
- `NotificationService` — emails de cambio de estado
- `InvoiceDocumentService` — archivos adjuntos
- `InvoiceFilterService` — filtros de busqueda

**Reglas de negocio clave:**
- Cada rol solo ve estados asignados (`ROLE_VISIBLE_STATUSES`)
- Cada rol solo edita campos de su estado (`EDITABLE_FIELDS`)
- Admin puede ver y editar todo
- `Invoice::isRejected()` verifica si fue rechazada en area
- Aprobacion externa via token bypasea login

### 4.2 Modulo de Empleados (HR)

**Componentes:**
- `EmployeesController` — CRUD + gestion de documentos
- `EmployeeDocumentService` — carpetas y archivos por empleado
- `EmployeeFilterService` — filtros de busqueda
- `EmployeeNovedadesController` — novedades/cambios de empleado
- `EmployeeLeavesController` — permisos/ausencias
- `LeaveDocumentService` — generacion de documentos de permiso
- `LeaveSignatureService` — firma digital

### 4.3 Modulo de Catalogos

Controllers simples CRUD para tablas de referencia. Todos usan `ExcelCatalogTrait` para export/import:

- Proveedores, Centros de operacion, Tipos de gasto
- Centros de costos, Roles, Usuarios, Aprobadores
- Estados de empleado, Estados civiles, Niveles educativos, Cargos

### 4.4 Modulo de Configuracion

- `SystemSettingsController` — configuracion SMTP, parametros del sistema
- `DianCrosschecksController` — cruce con DIAN

---

## 5. Sistema de Permisos (RBAC)

### 5.1 Roles

Definidos en `src/Constants/RoleConstants.php`:

| Constante | Valor | Acceso |
|-----------|-------|--------|
| `ADMIN` | Administrador | Todo |
| `REGISTRO_REVISION` | Registro/Revision | Estado 'aprobacion' |
| `CONTABILIDAD` | Contabilidad | Estado 'contabilidad' |
| `TESORERIA` | Tesoreria | Estado 'tesoreria' |

### 5.2 Flujo de Verificacion

```
AppController::beforeFilter()
    |
    v
_enforcePermission(user)
    |
    v
controllerModuleMap[controllerName] --> module
_actionToPermission(action) --> permAction (view/add/edit/delete)
    |
    v
AuthorizationService::isAllowed(roleId, roleName, module, permAction)
    |-- Admin? --> true (bypass)
    |-- Otro rol? --> consulta tabla `permissions`
```

### 5.3 Tabla permissions

```sql
permissions(id, role_id, module, can_view, can_create, can_edit, can_delete)
```

Los modulos disponibles estan definidos en `AuthorizationService::MODULES`.

---

## 6. Patrones y Convenciones

### 6.1 Constantes de Dominio

Todos los valores de dominio (estados, tipos, opciones) van en `src/Constants/`:

```php
// src/Constants/InvoiceConstants.php
InvoiceConstants::APPROVAL_REJECTED   // 'Rechazada'
InvoiceConstants::PAYMENT_FULL        // 'Pago total'
InvoiceConstants::DOCUMENT_TYPES      // ['Factura', 'Nota Debito', ...]

// src/Constants/EmployeeStatusConstants.php
EmployeeStatusConstants::RETIRADO     // 2

// src/Constants/RoleConstants.php
RoleConstants::ADMIN                  // 'Administrador'
```

**Regla:** nunca usar strings o numeros literales para valores de dominio en codigo PHP (`src/`). Siempre referenciar la constante.

### 6.2 Servicios

**Inyeccion de dependencias:** Los servicios reciben sus dependencias via constructor con defaults opcionales. Se instancian en `initialize()` del controller.

```php
// Servicio con DI
class InvoicePipelineService
{
    public function __construct(
        ?InvoiceHistoryService $historyService = null,
        ?NotificationService $notificationService = null,
        ?ApprovalTokenService $tokenService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->notificationService = $notificationService ?? new NotificationService();
        $this->tokenService = $tokenService ?? new ApprovalTokenService();
    }
}

// Controller
public function initialize(): void
{
    parent::initialize();
    $this->pipeline = new InvoicePipelineService();
    $this->filterService = new InvoiceFilterService();
    $this->documentService = new InvoiceDocumentService();
}
```

**Reglas:**
- Un servicio por dominio de negocio
- Los servicios acceden a tablas via `TableRegistry::getTableLocator()->get()`
- Los servicios NO acceden al request/response
- Dependencias entre servicios se inyectan via constructor
- No duplicar logica: si un servicio ya implementa algo, delegar a el

### 6.3 Entities

Las entities pueden tener metodos helper de dominio que no acceden a BD:

```php
class Invoice extends Entity
{
    public function isRejected(): bool { ... }
    public function isApproved(): bool { ... }
    public function isPaid(): bool { ... }
}
```

### 6.4 Custom Finders

En CakePHP 5, **no sobreescribir** `findList()` (firma incompatible):

```php
public function findCodeList(SelectQuery $query, array $options): SelectQuery
{
    return $query->formatResults(fn($results) =>
        $results->combine('id', fn($row) => $row->code . ' - ' . $row->name)
    );
}

// USO
$table->find('codeList');
```

### 6.5 Queries Reutilizables en Controllers

Cuando multiples acciones comparten la misma query base, extraer a metodo privado:

```php
private function _buildInvoiceQuery(array $conditions = []): SelectQuery
{
    $query = $this->Invoices->find()
        ->contain(['Providers', 'OperationCenters', ...]);

    if (!empty($conditions)) {
        $query->where($conditions);
    }

    $this->filterService->apply($query, $this->request->getQueryParams());

    return $query;
}
```

### 6.6 Paginacion

```php
public $paginate = ['limit' => 15, 'maxLimit' => 15];
```

Siempre 15 registros por pagina. Usar elemento `templates/element/pagination.php`.

### 6.7 Formateo de Fechas

```php
$this->AppView->formatDateEs($entity->created);
// Resultado: "Lunes, 17 Febrero 2026"
```

### 6.8 Migraciones

- Clase base: `Migrations\BaseMigration` (NO `AbstractMigration`)
- Prefijo de fecha: `YYYYMMDDHHMMSS_NombreDescriptivo.php`
- FKs: tipos de columna deben coincidir exactamente (signed/unsigned)
- Si migra a mitad, usar `$this->hasTable()` para proteger

### 6.9 Rutas

Rutas custom en `config/routes.php` para acciones especiales. El fallback `$builder->fallbacks()` cubre CRUD estandar automaticamente.

```php
$builder->connect(
    '/invoices/advance-status/{id}',
    ['controller' => 'Invoices', 'action' => 'advanceStatus'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

### 6.10 Historial de Cambios

`InvoiceHistoryService::recordChanges()` usa comparacion estricta (`!==`) con normalizacion de tipos:
- `DateTimeInterface` se normaliza a string `Y-m-d`
- Booleanos se normalizan con cast `(bool)`
- Strings vacios se normalizan a `null`

---

## 7. Frontend

### 7.1 Layouts

| Layout | Uso |
|--------|-----|
| `default.php` | Todas las paginas autenticadas (sidebar + topbar) |
| `login.php` | Pagina de login (split-panel) |
| `external.php` | Aprobacion externa via token |
| `ajax.php` | Respuestas AJAX sin chrome |
| `error.php` | Paginas de error (400, 500) |

### 7.2 Assets

**Orden de carga obligatorio en layout:**
1. Bootstrap CSS
2. Bootstrap Icons CSS
3. Flatpickr CSS
4. `styles.css` (custom, sobreescribe Bootstrap)

**JavaScript (al final del body):**
1. Bootstrap JS
2. Flatpickr JS + locale es
3. AutoNumeric JS
4. Select2 JS
5. `sgi-common.js` (inicializacion)

### 7.3 Clases CSS Custom

| Clase | Uso |
|-------|-----|
| `.sgi-stat-card` | Tarjeta de contador en dashboard |
| `.sgi-quick-tile` | Acceso rapido en dashboard |
| `.sgi-btn-primary` | Boton principal verde |
| `.sgi-input-group` | Grupo de input con borde verde al focus |
| `.sgi-topbar` | Barra superior |
| `.sgi-topbar-title` | Titulo con borde izquierdo verde |
| `.sgi-sidebar-logout` | Boton logout en sidebar |
| `.flatpickr-date` | Input de fecha (Flatpickr auto-init) |
| `.currency-input` | Input de moneda COP (AutoNumeric auto-init) |
| `.clickable-row` | Fila de tabla clickeable (requiere `data-href`) |

### 7.4 Sistema de Diseno

Documentado completamente en `STYLES.md`. Principios fundamentales:
- **Bordes en lugar de sombras** (sin box-shadow excepto sidebar activo)
- **Inter Variable** como fuente local
- **Micro-caps** para etiquetas de seccion
- **border-radius: 0** o maximo 2px
- Colores via CSS custom properties en `:root`

---

## 8. Seguridad

### 8.1 Autenticacion

- Plugin `cakephp/authentication ^3.0`
- Autenticadores: `Session` + `Form`
- Identificador: `Password` con `bcrypt`
- Finder custom: `UsersTable::findAuth()` filtra `active = true` con `contain(['Roles'])`
- Redirect a `/login` si no autenticado

### 8.2 Autorizacion

- RBAC via `AuthorizationService` + tabla `permissions`
- Verificacion automatica en `AppController::beforeFilter()`
- Admin bypassa todos los permisos

### 8.3 CSRF

- `CsrfProtectionMiddleware` con `httponly: true`
- Token en meta tag para peticiones AJAX

### 8.4 Host Header Injection

- `HostHeaderMiddleware` valida header Host en produccion

### 8.5 Upload de Archivos

- Archivos en `webroot/uploads/{entity}/{id}/`
- Nombre de archivo con prefijo unico: `inv_` + `uniqid()` + extension

---

## 9. Reglas para Nuevos Modulos

Al crear un nuevo modulo, seguir este checklist:

### Controller
- [ ] Extender `AppController`
- [ ] Agregar al `$controllerModuleMap` en AppController
- [ ] Setear `public $paginate = ['limit' => 15, 'maxLimit' => 15]`
- [ ] Instanciar servicios en `initialize()`
- [ ] Extraer queries compartidas a metodo privado `_build*Query()`
- [ ] No hacer queries complejas directamente

### Model
- [ ] Crear Entity con `$_accessible` correcto y helpers de dominio si aplica
- [ ] Crear Table con asociaciones, validacion y behaviors
- [ ] Usar constantes de `src/Constants/` en validadores `inList()`
- [ ] Usar `findCodeList()` si necesita lista de "code - name"
- [ ] Agregar `TimestampBehavior`

### Service (si hay logica de negocio)
- [ ] Crear en `src/Service/`
- [ ] Inyectar dependencias via constructor con defaults opcionales
- [ ] No acceder a request/response desde el servicio
- [ ] No duplicar logica que ya existe en otro servicio

### Constants (si hay valores de dominio)
- [ ] Crear en `src/Constants/`
- [ ] Clase `final` con constantes `public const`
- [ ] Referenciar desde servicios, tablas y controllers

### Templates
- [ ] Seguir STYLES.md para componentes visuales
- [ ] Usar element `pagination.php` para tablas paginadas
- [ ] Usar clases `.flatpickr-date`, `.currency-input`, `.clickable-row`
- [ ] Usar `AppView::formatDateEs()` para fechas

### Permisos
- [ ] Agregar modulo a `AuthorizationService::MODULES`
- [ ] Agregar mapping en `AppController::$controllerModuleMap`
- [ ] Configurar permisos por rol en la tabla `permissions`

### Migraciones
- [ ] Usar `Migrations\BaseMigration` como clase base
- [ ] Prefijo de fecha: `YYYYMMDDHHMMSS_`
- [ ] FKs con tipos de columna identicos al campo referenciado
- [ ] Usar `$this->hasTable()` como proteccion

### Rutas (si necesita rutas custom)
- [ ] Agregar en `config/routes.php` antes del `$builder->fallbacks()`
- [ ] Patron: `/{controller-dashed}/{action-dashed}/{id}`
- [ ] Constraint de parametros: `['id' => '\d+', 'pass' => ['id']]`

---

## 10. Dependencias Externas

### Produccion
| Paquete | Proposito |
|---------|-----------|
| `cakephp/cakephp` | Framework base |
| `cakephp/authentication` | Login por sesion + formulario |
| `cakephp/migrations` | Migraciones de BD |
| `phpoffice/phpspreadsheet` | Export/import Excel |
| `tecnickcom/tcpdf` | Generacion de PDF |
| `setasign/fpdi` | Manipulacion de PDF existentes |
| `mobiledetect/mobiledetectlib` | Deteccion de dispositivo |

### Desarrollo
| Paquete | Proposito |
|---------|-----------|
| `cakephp/bake` | Generacion de scaffolding |
| `cakephp/debug_kit` | Debug toolbar |
| `cakephp/cakephp-codesniffer` | Code style (estandar CakePHP) |
| `phpunit/phpunit` | Testing |
| `josegonzalez/dotenv` | Carga de .env |

---

## 11. Base de Datos

### Tablas principales

```
roles                    -- Roles del sistema (4 roles)
users                    -- Usuarios (FK -> roles)
permissions              -- Permisos RBAC (role_id, module, can_*)

invoices                 -- Facturas con pipeline
invoice_histories        -- Audit trail de facturas
invoice_documents        -- Archivos adjuntos de facturas
invoice_observations     -- Observaciones/comentarios
approval_tokens          -- Tokens de aprobacion externa

providers                -- Proveedores (NIT)
operation_centers        -- Centros de operacion
expense_types            -- Tipos de gasto
cost_centers             -- Centros de costos
approvers                -- Aprobadores por centro

employees                -- Empleados
employee_folders         -- Carpetas de documentos
employee_documents       -- Archivos de empleados
employee_statuses        -- Estados de empleado
employee_novedades       -- Novedades/cambios
employee_leaves          -- Permisos/ausencias

leave_types              -- Tipos de permiso
leave_document_templates -- Plantillas de documentos
leave_template_fields    -- Campos de plantillas

dian_crosschecks         -- Cruce DIAN

marital_statuses         -- Estados civiles
education_levels         -- Niveles educativos
positions                -- Cargos
default_folders          -- Carpetas por defecto
organizaciones_temporales -- Organizaciones temporales
system_settings          -- Configuracion del sistema
```

### Relaciones clave

```
users -----> roles (belongsTo)
invoices --> providers, operation_centers, expense_types, cost_centers (belongsTo)
invoices --> invoice_histories, invoice_documents, invoice_observations (hasMany)
employees -> employee_statuses, positions, education_levels (belongsTo)
employees -> employee_folders -> employee_documents (hasMany nested)
employees -> employee_leaves, employee_novedades (hasMany)
approvers -> users, operation_centers (belongsTo)
```
