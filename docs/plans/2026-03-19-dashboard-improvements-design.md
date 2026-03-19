# Dashboard Improvements Design

## Overview

Mejorar el dashboard de inicio del SGI con estadísticas avanzadas, gráficos simples (Chart.js) y un selector de período. Cada rol ve estadísticas relevantes a su función.

## Selector de Período

- Ubicación: debajo del saludo de bienvenida, antes de las secciones
- Opciones predefinidas (botones inline): **Mes actual**, **Trimestre actual**, **Año actual**, **Todo**
- Rango personalizado: dos inputs Flatpickr (desde/hasta)
- Default: Mes actual
- Recarga con query params: `?period=month&from=2026-03-01&to=2026-03-31`
- Server-side: `DashboardController` filtra queries según rango

## Facturación (estadísticas nuevas)

### Stat Cards (filtradas por período)

| Card | Cálculo | Formato | Visibilidad |
|------|---------|---------|-------------|
| Monto total pagado | SUM(amount) WHERE pipeline_status='pagada' | COP | Admin, Contabilidad, Tesorería, Registro/Revisión |
| Monto en proceso | SUM(amount) WHERE status NOT IN (pagada, rechazada) | COP | Admin, Contabilidad, Registro/Revisión |
| Promedio por factura | AVG(amount) | COP | Admin, Contabilidad, Registro/Revisión |
| Facturas vencidas | COUNT WHERE due_date < hoy AND status != 'pagada' | Número (rojo) | Admin, Tesorería, Registro/Revisión |

### Gráficos

1. **Dona — Distribución de montos por estado del pipeline**
   - Segmentos: aprobación, contabilidad, tesorería, pagada, rechazada
   - Muestra cuánto dinero hay en cada etapa
   - Visible: Admin, Contabilidad, Tesorería, Registro/Revisión

2. **Barras — Facturas por mes**
   - Dos series: cantidad de facturas y monto total
   - Eje X: meses (últimos 6 o según período)
   - Visible: Admin, Contabilidad, Registro/Revisión

## RRHH / Empleados (estadísticas nuevas)

### Stat Cards

| Card | Cálculo | Depende del período | Visibilidad |
|------|---------|---------------------|-------------|
| Edad media | AVG(age) de empleados activos (desde birth_date) | No | Roles con permiso Empleados + Admin |
| Antigüedad media | AVG(años desde hire_date) de empleados activos | No | Roles con permiso Empleados + Admin |
| Nuevos ingresos | COUNT(hire_date dentro del período) | Sí | Roles con permiso Empleados + Admin |
| Retiros | COUNT(termination_date dentro del período) | Sí | Roles con permiso Empleados + Admin |

### Gráficos

1. **Dona — Distribución por tipo de contrato**
   - Segmentos por `contract_type` de empleados activos
   - No depende del período (snapshot actual)
   - Visible: Roles con permiso Empleados + Admin

2. **Barras — Novedades por mes**
   - Una barra por mes, cantidad de novedades registradas
   - Filtrado por período
   - Visible: Roles con permiso Empleados + Admin

## Catálogos y Administración

Sin cambios. Se mantienen las stat cards actuales (proveedores, centros de operación, tipos de gasto, centros de costos, usuarios activos, roles configurados).

## Implementación Técnica

### Chart.js
- CDN en `templates/layout/default.php`, después de los JS actuales
- Archivo dedicado: `webroot/js/dashboard-charts.js`
- Datos del controller pasados como JSON en atributos `data-*` o variables JS inline

### DashboardController
- `_getPeriodDates()`: lee query params, retorna [$from, $to]
- `_getInvoiceStats($from, $to)`: stat cards + datos para gráficos de facturación
- `_getEmployeeStats($from, $to)`: stat cards + datos para gráficos de RRHH
- Métodos existentes de contadores se mantienen sin cambios

### Template
- Nuevo element: `templates/element/period_selector.php`
- Gráficos en `<canvas>` con ids específicos
- Layout responsive: gráficos en `col-md-6` (2 por fila desktop, 1 móvil)
- Selector de período es element reutilizable

### CSS Load Order (sin cambios)
1. Bootstrap CSS
2. Bootstrap Icons CSS
3. Flatpickr CSS
4. `styles.css`

### JS Load Order (actualizado)
1. Bootstrap JS
2. Flatpickr JS + locale es
3. AutoNumeric JS
4. Select2 JS
5. **Chart.js (nuevo)**
6. `sgi-common.js`
7. **`dashboard-charts.js` (nuevo, solo en dashboard)**

## Visibilidad por Rol (resumen)

| Sección | Admin | Registro/Revisión | Contabilidad | Tesorería |
|---------|-------|-------------------|--------------|-----------|
| Selector de período | Sí | Sí | Sí | Sí |
| Stats facturación (todas) | Sí | Sí | Parcial | Parcial |
| Gráfico dona facturación | Sí | Sí | Sí | Sí |
| Gráfico barras facturación | Sí | Sí | Sí | No |
| Stats RRHH | Sí | Si tiene permiso | Si tiene permiso | Si tiene permiso |
| Gráficos RRHH | Sí | Si tiene permiso | Si tiene permiso | Si tiene permiso |
| Catálogos | Sí | Si tiene permiso | Si tiene permiso | Si tiene permiso |
| Administración | Sí | No | No | No |
