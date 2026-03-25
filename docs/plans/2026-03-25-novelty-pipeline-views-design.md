# Diseño: Vistas de Novedades por Rol (Pipeline como Facturas)

**Fecha:** 2026-03-25
**Estado:** Aprobado

## Objetivo

Replicar el patrón de vistas de facturas en el módulo de novedades: "Mis Novedades" (filtrada por rol), "Todas las Novedades" (lectura), "Novedades Rechazadas". Implementar campos editables y secciones visibles por rol/estado.

## Nuevos Roles en RoleConstants

```php
public const AUXILIAR_PERSONAL = 'Auxiliar de Personal';
public const ASISTENTE_PERSONAL = 'Asistente de Personal';
public const CONTADOR = 'Contador';
public const COORDINADOR_ADMIN = 'Coordinador Administrativo y Financiero';
```

## Estados Visibles por Rol ("Mis Novedades")

| Rol | Estados visibles |
|-----|-----------------|
| Auxiliar de Personal | aprobacion, rrhh, revision_firmas, gdp |
| Asistente de Personal | aprobacion, rrhh, revision_firmas, gdp |
| Contabilidad | contabilidad |
| Contador | revision_firmas |
| Coordinador Adm. y Financiero | revision_firmas |
| Tesorería | tesoreria |
| Admin | todos |

## Secciones del Formulario Edit

| Sección | Campos |
|---------|--------|
| informacion | employee_id, novelty_type_id, filing_date, custom_name |
| fechas | permission_date, schedule_type, start_date, end_date, start_time, end_time, is_paid |
| motivo | reason |
| aprobacion | approver_id, area_approval |
| rrhh | passes_payroll, rrhh_by |
| contabilidad | liquidation_doc_id |
| firmas | employee_signature |

## Campos Editables por Rol/Estado

| Rol | Estado | Campos editables |
|-----|--------|-----------------|
| Auxiliar/Asistente Personal | aprobacion | approver_id |
| Auxiliar/Asistente Personal | rrhh | passes_payroll |
| Auxiliar/Asistente Personal | revision_firmas | (solo lectura, avanzar/rechazar) |
| Auxiliar/Asistente Personal | gdp | (solo lectura, avanzar/rechazar) |
| Contabilidad | contabilidad | liquidation_doc_id |
| Contador | revision_firmas | (solo lectura, avanzar/rechazar) |
| Coordinador Adm. y Financiero | revision_firmas | (solo lectura, avanzar/rechazar) |
| Tesorería | tesoreria | (avanza desde doc liquidación) |
| Admin | todos | todos los campos |

## Secciones Visibles por Rol

| Rol | Secciones |
|-----|-----------|
| Auxiliar/Asistente Personal | informacion, fechas, motivo, aprobacion, rrhh, firmas |
| Contabilidad | informacion, fechas, contabilidad |
| Contador / Coordinador Adm. | informacion, fechas, firmas |
| Tesorería | informacion |
| Admin | todas (progresivas según estado) |

## Vistas del Controller

### index() — "Mis Novedades"
- Filtra por `pipeline_status IN (visibleStatuses)` según rol
- Elimina lógica de subordinados (`_getSubordinateEmployeeIds`)
- Filas van a `edit`

### all() — "Todas las Novedades"
- Sin filtro de estado
- `visibleStatuses = []`
- Filas van a `view` (solo lectura)
- Renderiza template `index`

### rejected() — "Novedades Rechazadas"
- Filtra por `pipeline_status = rechazada`
- Filas van a `view`
- Renderiza template `index`

### edit() — Modificación
- Obtiene `editableFields` y `visibleSections` del pipeline service
- Filtra datos POST con `filterEntityData()`
- Solo permite editar si estado está en `visibleStatuses` del rol

## Rutas Nuevas

```
/employee-novelties/all       → EmployeeNovelties::all
/employee-novelties/rejected  → EmployeeNovelties::rejected
```

## Template index.php

- Título dinámico según acción (Mis/Todas/Rechazadas)
- Botones de navegación entre las 3 vistas
- Filas clickeables: edit (Mis) vs view (Todas/Rechazadas)
- Filas rechazadas con clase `table-danger`
- Filtros existentes se mantienen

## Archivos a Modificar

1. `src/Constants/RoleConstants.php` — agregar 4 roles
2. `src/Service/NoveltyPipelineService.php` — ROLE_VISIBLE_STATUSES, EDITABLE_FIELDS, VISIBLE_SECTIONS, métodos nuevos
3. `src/Controller/EmployeeNoveltiesController.php` — refactorizar index(), agregar all(), rejected(), modificar edit()
4. `templates/EmployeeNovelties/index.php` — título dinámico, navegación, lógica edit/view
5. `config/routes.php` — rutas all y rejected
