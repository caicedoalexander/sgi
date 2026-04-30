# Alineación de Sidebar — Documentos de Liquidación

**Fecha:** 2026-04-30
**Estado:** Diseño aprobado, listo para implementar

## Problema

El módulo **Documentos de Liquidación** (`NoveltyLiquidationDocs`) es el único que expone en el sidebar **filtros por `pipeline_status`** como sub-items (En Contabilidad, En Tesorería, Aut. Pago, En Revisión y Firmas, GDP). Esto rompe la convención de los otros dos módulos del pipeline (Facturas y Novedades), que siguen el patrón:

```
Padre  →  Todos los <recurso>     (action: all)
├──        Mis <recurso>           (action: index, filtra por rol)
├──        Rechazadas              (action: rejected)
└──        ... (otra vista por módulo)
```

Resultado: el usuario tiene que aprender una sintaxis distinta para liquidaciones, los badges son 5 contadores separados por estado, y el sidebar carga visualmente más de lo necesario.

## Objetivo

Que **D. de Liquidación** siga la misma convención que Facturas y Novedades, con:

- Padre `Todos los D. de Liquidación` → `all`
- Sub-item `Mis D. de Liquidación` → `index` (filtra por estados visibles del rol)
- Sub-item `Rechazadas` → `rejected`

Los filtros granulares por `pipeline_status` se mantienen, pero se mueven del sidebar a la vista (chips/dropdown sobre la tabla del index).

## Diseño

### 1. Endpoints del controller

| Acción | Comportamiento | Equivalencia |
|---|---|---|
| `index` *(modificada)* | "Mis D. de Liquidación" — filtra por `pipeline_status IN getVisibleStatuses(role)`. Conserva override por `?pipeline_status=...`. | `Invoices::index` |
| `all` *(nueva)* | Todos sin filtro de estado. Renderiza `index.php`. | `Invoices::all` |
| `rejected` *(nueva)* | Filtra `pipeline_status = 'rechazada'`. Renderiza `index.php`. | `Invoices::rejected` |

### 2. Mapeo rol → estados visibles

Se añade `getVisibleStatuses(string $roleName): array` al servicio del pipeline de liquidación:

| Rol | Estados visibles |
|---|---|
| Contabilidad | `contabilidad` |
| Tesorería | `tesoreria`, `aut_pago` |
| Contador | `aut_pago` |
| Registro/Revisión | `revision_firmas`, `gdp` |
| Auxiliar de Personal / Asistente de Personal / Coordinador Administrativo y Financiero | todos los activos (excluye `pagada` y `rechazada`) |
| Administrador | todos |

### 3. Sidebar (`templates/layout/default.php`)

Reemplaza el bloque actual de 5 sub-items por:

```
D. de Liquidación                 → action 'all'
├── Mis D. de Liquidación         → action 'index'    [badge verde con liquidationMineCount]
└── Rechazadas                    → action 'rejected' [badge rojo con liquidationRejectedCount]
```

El padre actúa como link (igual que `Invoices::all`) y como toggle del submenú vía chevron.

### 4. Counters (`SidebarCounterService`)

Antes:
```php
liquidationCounters => [
    'contabilidad' => int,
    'tesoreria' => int,
    'aut_pago' => int,
    'revision_firmas' => int,
    'gdp' => int,
]
```

Después:
```php
liquidationMineCount     => int  // suma de pipeline_status ∈ getVisibleStatuses($roleName)
liquidationRejectedCount => int  // pipeline_status = 'rechazada'
```

### 5. Vista `index` (`templates/NoveltyLiquidationDocs/index.php`)

Añadir chips de filtro por `pipeline_status` sobre la tabla, apuntando al mismo `index?pipeline_status=<estado>`. Esto preserva la capacidad de filtrar por etapa específica, pero del lado de la vista en lugar del sidebar. Es opcional pero recomendado para no perder funcionalidad.

### 6. Permisos

`all` y `rejected` mapean al mismo módulo `novelty_liquidation_docs`. Heredan `can_view`. No se agregan permisos nuevos.

## Plan de implementación

| # | Archivo | Cambio | Verificación |
|---|---|---|---|
| 1 | `src/Service/NoveltyLiquidationPipelineService.php` | Añadir `getVisibleStatuses(string $roleName): array` | `composer cs-check` |
| 2 | `src/Controller/NoveltyLiquidationDocsController.php` | Modificar `index`; añadir `all` y `rejected` | Rutas responden 200 |
| 3 | `src/Service/SidebarCounterService.php` | Reemplazar `liquidationCounters` por `liquidationMineCount` + `liquidationRejectedCount` | Badges correctos en cada rol |
| 4 | `templates/layout/default.php` | Nuevo bloque de submenu (padre + 2 sub-items) | Navegación coherente |
| 5 | `templates/NoveltyLiquidationDocs/index.php` | Chips de filtro por estado sobre la tabla *(opcional)* | Filtros granulares funcionan |

## Riesgos y consideraciones

- **Cambio de comportamiento por defecto en `index`:** antes mostraba todo, ahora filtra por rol. Usuarios con bookmarks a `/novelty-liquidation-docs` verán menos registros. Es el comportamiento esperado, consistente con Facturas y Novedades.
- **Pérdida de visibilidad de los 5 sub-items por estado:** se mitiga con los chips en la vista index. Si esos chips no se implementan, hay que entrar a "Todos" y filtrar manualmente por querystring.
- **Counter granular ya no se calcula:** si en el futuro se quiere reintroducir badges por estado en la vista, hay que recalcular esa información dentro de la vista (no desde `SidebarCounterService`).

## Fuera de alcance

- Renombrar acciones internas o rutas existentes.
- Cambiar el modelo de datos o estados del pipeline.
- Añadir vistas terminales (`Pagadas`/`Finalizadas`) — descartado en brainstorming a favor de mantener solo `Rechazadas` para máxima consistencia con los otros dos módulos.
