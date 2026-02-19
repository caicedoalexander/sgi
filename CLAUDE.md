# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Descripción del Proyecto

Sistema de Gestión Interna (SGI) para la Compañía Operadora Portuaria Cafetera S.A. Automatiza procesos de recursos humanos y contabilidad. Stack: CakePHP 5, Bootstrap 5, JS.

## Módulos Principales

### Control de Facturas
Campos: fecha registro, fecha emisión, fecha vencimiento, tipo documento (Factura/Nota Debito/Caja menor/Tarjeta de Crédito/Reintegro/Legalización/Recibo/Anticipo), orden de compra, NIT, proveedor_id, centro de operación, detalle, valor (COP), tipo de gasto, centro de costos.

**Pipeline de conciliación:** Revisión → Área Aprobada → Causada → Tesorería → Pagada

- **Revisión:** Confirmación (usuario asignado), Aprobada Área (Aprobada/Rechazada/Pendiente), fecha aprobación, Validado DIAN (Pendiente/Aprobada/Rechazado)
- **Contabilidad:** Causada (Si/No), fecha causación, Lista para Pago (Si/No/Anticipo Empleado/Anticipo Proveedor/Pago prioritario/Pago PSE/No Legalización/Reintegro)
- **Tesorería:** Estado Pago (Pago total/Pago Parcial), fecha pago

Incluye campo de observaciones por área e historial de cambios en la conciliación.

### Tablas relacionadas
- Proveedores (vinculado con NIT)
- Centros de Operación
- Tipos de Gasto
- Usuarios, Roles, Empleados

### Gestión de Personal (por definir)
Módulo de empleados y documentos — pendiente de especificación.

## Sistema de Roles
Usuarios divididos por roles que controlan acceso a módulos y permisos (ej: Aux. Personal → módulo Empleados).

## Comandos de Desarrollo

```bash
# Servidor de desarrollo
bin/cake server

# Tests
vendor/bin/phpunit                          # todos los tests
vendor/bin/phpunit tests/TestCase/Controller/PagesControllerTest.php  # test individual

# Code style
vendor/bin/phpcs --colors -p                # verificar
vendor/bin/phpcbf --colors -p               # corregir

# Migraciones
bin/cake migrations migrate                 # ejecutar migraciones
bin/cake migrations create NombreMigracion  # crear migración

# Bake (generador de código)
bin/cake bake controller NombreController
bin/cake bake model NombreModelo
bin/cake bake template NombreTemplate
```

## Arquitectura

```
src/
├── Controller/          # Manejan peticiones HTTP (delegando lógica a Services)
├── Service/             # Lógica de negocio (crear este directorio)
├── Model/
│   ├── Entity/          # Entidades ORM
│   ├── Table/           # Clases Table (queries, validaciones, asociaciones)
│   └── Behavior/        # Comportamientos reutilizables
├── View/                # Helpers y vistas
├── Middleware/           # Middleware HTTP
└── Application.php      # Bootstrap, middleware stack, container DI

config/
├── app.php              # Configuración principal
├── app_local.php        # Overrides locales (DB, debug, etc.)
├── routes.php           # Definición de rutas (usa DashedRoute)
└── bootstrap.php        # Bootstrap del framework

templates/               # Vistas PHP (layouts, elements, páginas)
webroot/                 # Assets públicos (CSS, JS, imágenes)
```

**Patrón obligatorio:** Controladores solo manejan peticiones; la lógica de negocio va en `src/Service/`.

## Configuración

- Base de datos configurada vía `DATABASE_URL` en `.env` (MySQL/MariaDB)
- La conexión se resuelve en `config/app_local.php` usando `env('DATABASE_URL')`
- CSRF habilitado en middleware stack
- PHP >= 8.2 requerido
