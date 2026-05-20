# SGI — Sistema de Gestión Interna

Sistema interno de gestión administrativa y financiera construido sobre CakePHP 5.3 y PHP 8.4+. Coordina pipelines de facturas, anticipos, reintegros, caja menor, novedades, programaciones de pago y legalizaciones, con auditoría completa, control de permisos RBAC y notificaciones por correo.

## Características

- **Pipeline de facturas (6 estados + terminal)** — `aprobacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada`, más estado terminal `legalizada` para documentos tipo Legalización.
- **Pipelines paralelos por dominio** — Novedades, Anticipos, Reintegros, Caja Menor y Programaciones de Pago, cada uno con su `Registry` + `States` (state pattern).
- **RBAC granular** — Permisos CRUD por módulo (`permissions`) y permisos por paso de pipeline (`pipeline_permissions`). Admin bypass acotado a `users` y `roles`.
- **Aprobaciones externas con tokens SHA256** — Links de aprobación con TTL de 48h enviados por correo.
- **Auditoría field-by-field** — Servicios `*HistoryService` registran cada cambio por dominio.
- **Resiliencia** — `CircuitBreaker` y `Retryer` para integraciones externas (webhooks, SMTP).
- **Observabilidad** — `StructuredLogger` con `X-Correlation-ID`, health checks (DB, cache, email, circuit breaker).
- **Integraciones** — DIAN crosscheck, n8n workflows, webhooks salientes, importación/exportación Excel, generación PDF (TCPDF + FPDI).
- **UI consistente** — Sistema de diseño basado en bordes (sin sombras), Inter Variable, paleta verde corporativo. Ver `docs/design/` (índice en `CLAUDE.md`).

## Requisitos

- **PHP 8.4+** (declarado en `composer.json`)
- **Composer 2.0+**
- **MySQL/MariaDB**
- **Extensiones PHP**: `ext-json`, `ext-pdo_mysql`, `ext-mbstring`, `ext-intl`, `ext-gd` (para TCPDF), `ext-zip` (para PhpSpreadsheet)

### Servicios opcionales

- **SMTP** — Para notificaciones de pipeline y links de aprobación externa
- **n8n** — Para workflows de integración externa

## Instalación

1. **Clonar e instalar dependencias:**

```bash
git clone <repo-url> sgi
cd sgi
composer install
```

2. **Configurar variables de entorno** en `.env` (raíz del proyecto):

```bash
DATABASE_URL=mysql://usuario:password@host:3306/sgi_db
SECURITY_SALT=<salt-generado>
APP_DEFAULT_TIMEZONE=America/Bogota
EMAIL_TRANSPORT_DEFAULT_URL=smtp://user:pass@smtp.host:587
```

3. **Ejecutar migraciones:**

```bash
php bin/cake migrations migrate
```

4. **Seed del usuario administrador inicial:**

```bash
php bin/seed-admin.php
# username: admin
# password: Admin2024*
```

5. **Levantar servidor de desarrollo:**

```bash
php bin/cake server
# Disponible en http://localhost:8765
```

## Comandos

```bash
# Servidor de desarrollo
php bin/cake server

# Migraciones
php bin/cake migrations migrate       # Aplicar pendientes
php bin/cake migrations rollback      # Revertir última
php bin/cake migrations create Name   # Nueva migración (usa BaseMigration)

# Code style (estándar CakePHP)
composer cs-check                     # Verificar
composer cs-fix                       # Auto-corregir
```

> **Política de tests:** este proyecto **no usa tests automatizados**. La validación es manual: levantar el servidor y ejercitar endpoints en navegador/`curl`.

## Estructura del proyecto

```
src/
├── Controller/         # HTTP — extiende AppController (RBAC en beforeFilter)
├── Service/            # Lógica de negocio (retorna ServiceResult)
│   ├── Pipeline/       # State pattern por módulo (Invoice, Novelty, Advance, ...)
│   ├── HealthCheck/    # Health checks (DB, cache, email, circuit breaker)
│   ├── Resilience/     # Retryer + RetryPolicy
│   ├── Adapter/        # CakeMailer, PhpSpreadsheet
│   ├── Strategy/       # Estrategias de aprobación externa
│   ├── Dashboard/      # Estadísticas por dominio
│   └── Subscriber/     # Suscriptores de eventos del pipeline
├── Constants/          # Valores de dominio (estados, roles, tipos)
│   └── Domain/         # Enums fuente única de estados de pipeline
├── Middleware/         # CorrelationId, RateLimit, HostHeader
├── Event/              # InvoicePaidEvent, AdvanceLegalizedEvent, ...
└── Model/              # Entity + Table (ORM CakePHP)

templates/              # Vistas PHP por controlador + layouts
config/                 # app.php, routes.php, Migrations/
webroot/                # Assets públicos (css, js, fonts)
docs/design/            # Sistema de diseño visual
```

Detalle completo de servicios y convenciones en [`CLAUDE.md`](CLAUDE.md).

## Pipeline de facturas (resumen)

```
aprobacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada
                                                                                  │
                                                              (Legalización) ─────┴──> legalizada
```

- Tesorería registra pagos → la factura avanza a `autorizacion_pago`.
- Contador autoriza cada pago en `autorizacion_pago` → avanza a `verificacion_pago`.
- En `verificacion_pago` se valida la ejecución/conciliación → avanza a `pagada`.
- Pago parcial tras autorización → regresa automáticamente a `tesoreria`.
- `area_approval='Rechazada'` bloquea avance; Registro puede ejecutar `resetFlow` para reiniciar.

Detalle de pipelines paralelos (Anticipos, Reintegros, Caja Menor, Novedades, Programaciones) en [`CLAUDE.md`](CLAUDE.md).

## Roles del sistema

Definidos en `App\Constants\RoleConstants`:

- Administrador
- Contabilidad
- Tesorería
- Registro/Revisión
- Contador
- Auxiliar de Personal
- Asistente de Personal
- Coordinador Administrativo y Financiero

Permisos resueltos por `AuthorizationService` (CRUD por módulo) y `PipelineAuthorizationService` (por paso de pipeline).

## Convenciones clave

- **`ServiceResult`** — Servicios retornan `ServiceResult::ok($data)` o `::fail($errors)`. Verificar `->success` antes de usar `->data`.
- **Paginación** — 15 ítems por página en todos los controladores.
- **Tablas** — Servicios acceden vía `TableRegistry::getTableLocator()->get('TableName')`.
- **DI** — Constructor con parámetros nullable y fallback `?? new ServiceClass()`.
- **CSS** — Prefijo `.sgi-` para clases custom. Orden de carga: Bootstrap → Bootstrap Icons → Flatpickr → `styles.css`.
- **Slugs** — Estados visibles en español sin acentos (`aprobacion`, `pagada`); estados técnicos internos en inglés (`pending`, `authorized`).
- **Migraciones** — Heredan de `Migrations\BaseMigration` (NO `AbstractMigration`).

Ver [`CLAUDE.md`](CLAUDE.md) y [`docs/design/`](docs/design/) para la guía completa.

## Stack

- **Framework**: CakePHP 5.3.4
- **Auth**: cakephp/authentication 4.1
- **Migraciones**: cakephp/migrations 5.1
- **PDF**: TCPDF 6.10 + FPDI 2.6
- **Excel**: PhpSpreadsheet 5.7
- **Mobile detection**: MobileDetect 4.8
- **Frontend**: Bootstrap 5, Bootstrap Icons, Flatpickr, AutoNumeric, Select2, Inter Variable

## Licencia

Proyecto interno — uso restringido. Ver archivo [`LICENSE`](LICENSE) si está disponible.
