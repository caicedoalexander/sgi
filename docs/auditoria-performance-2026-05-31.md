# Auditoría de performance / N+1 — SGI

## Resumen ejecutivo

Esta auditoría revisó las rutas calientes del sistema (overhead por-request del `AppController::beforeFilter`, dashboard, listados paginados y servicios de pipeline) en busca de N+1, queries-en-loop, índices faltantes, brechas de caché y agregaciones pesadas. Tras verificación adversarial contra el código real, las migraciones y la configuración de caché, **ningún hallazgo califica como Crítico ni Alto**: las severidades iniciales (varias marcadas critical/high) se ajustaron a la baja porque el cache `sidebar` de `+30 s` por rol, la paginación fija de 15 ítems, el gating por permisos del dashboard y el bajo volumen típico de un sistema de gestión interna mitigan fuertemente el impacto real.

**Conteo por severidad (post-verificación):**

| Severidad | Hallazgos confirmados |
|-----------|----------------------|
| Crítico | 0 |
| Alto | 0 |
| Medio | 4 |
| Bajo | 14 |
| Descartados (falsos positivos) | 30 |

**Hotspots (lo que realmente conviene atacar, por impacto transversal):**

1. **Falta de índices en `invoices` (`pipeline_status`, `area_approval`, `created`, `due_date`).** Es la causa raíz transversal: afecta por igual al dashboard, los listados `/invoices` (index/rejected/overdue) y los COUNT del sidebar. Cada filtro es un full scan. **Mayor ROI de toda la auditoría: una sola migración con índices baratos.**
2. **`PaymentRegistryService::getAll()` carga TODA la tabla de pagos en memoria** (`->all()->toArray()`) y pagina con `array_slice`: O(N) memoria + O(N log N) CPU por request, crece sin cota con el histórico.
3. **Dashboard sin caché** (`DashboardStatisticsService`): ~14 queries agregadas frescas por carga, incluyendo `getChartData()` que itera 6 estados con un `SUM` por estado (consolidable a 1 `GROUP BY`).
4. **Overhead por-request del sidebar:** ~16 COUNT por cache-miss. Bien mitigado por el cache de 30 s por rol (no por usuario), pero los COUNT pegan a `invoices` sin índice. Subir el TTL + indexar es quick-win.

Énfasis sobre lo que afecta TODA la app: el único overhead verdaderamente por-request (sidebar/permisos) ya está cacheado y memoizado correctamente; el costo residual se concentra en la **ausencia de índices en `invoices`**, no en la cantidad de queries.

---

## Hallazgos confirmados

### Crítico
Ninguno. (Los hallazgos originalmente marcados como críticos —índices faltantes en `invoices`— se reclasificaron a Medio/Bajo tras confirmar que el path más frecuente que los toca está cacheado 30 s por rol y el volumen es moderado.)

### Alto
Ninguno. (Todos los hallazgos originalmente "high" se ajustaron a Medio o Bajo en verificación; ver razonamiento por hallazgo.)

### Medio

#### M1. `getChartData()` itera `PIPELINE_STATUSES` con un `SUM` por estado (7 queries donde basta 1)
- **Categoría:** N+1 / query-in-loop (cardinalidad fija).
- **Ubicación:** `src/Service/Dashboard/InvoiceStatisticsService.php:159-174`
- **Descripción / amplificación:** El `foreach (InvoiceConstants::PIPELINE_STATUSES …)` (6 estados) dispara 6 `find()->select(sum('amount'))->first()` + 1 query extra para `'rechazada'` (línea 167) = **7 queries de agregación donde 1 bastaría**. No es un N+1 amplificado por filas (N fijo = 6, una vez por carga del dashboard), pero cada `SUM ... WHERE pipeline_status=? AND created BETWEEN …` es un full scan por falta de índice.
- **Evidencia:**
  ```php
  foreach (InvoiceConstants::PIPELINE_STATUSES as $status) {
      $result = $table->find()
          ->where(array_merge(['pipeline_status' => $status], $dateConditions))
          ->select(['total' => $table->find()->func()->sum('amount')])
          ->first();
      $statusAmounts[$status] = (float)($result->total ?? 0);
  }
  ```
- **Recomendación:** Consolidar en un único `GROUP BY pipeline_status` o `SUM(CASE WHEN pipeline_status='X' THEN amount ELSE 0 END)`. El bloque mensual de las líneas 176-185 ya usa esa técnica con SQL raw — replicarla aquí. Aplicar el mismo patrón a `getStats()` (líneas 28-40: 6 `_safeCount` consolidables en 1 `GROUP BY`).
- **Confianza:** Alta.

#### M2. `AppController::beforeFilter()` ejecuta ~16-21 COUNT en cache-miss del sidebar
- **Categoría:** Overhead por-request.
- **Ubicación:** `src/Controller/AppController.php:106-121` → `src/Service/SidebarCounterService.php:60-103`
- **Descripción / amplificación:** En cache-miss, `_buildCounters()` ejecuta ~16-21 COUNT (1 por estado visible en `getInvoiceStatusCounters` + ~13 counts cross-módulo). **Corrección importante al framing original:** el cache está keyed por `roleId` (`sidebar_counters_{$roleId}`), **no por usuario**, con TTL `+30 s` (FileEngine, `config/app.php:139`). El peor caso global es ~1 recálculo por rol cada 30 s (≈8 roles), **no** "3-4 recálculos/segundo con 100 usuarios". El costo residual es que los COUNT sobre `invoices` carecen de índice.
- **Evidencia:** `AppController.php:117` → `_setSidebarCounters()`; `SidebarCounterService.php:42-57` `Cache::remember("sidebar_counters_{$roleId}", …, 'sidebar')`; `config/app.php:139` `'duration' => '+30 seconds'`.
- **Recomendación:** (1) Subir TTL del cache `sidebar` a `+5 minutes`. (2) Indexar `invoices.pipeline_status` / `document_type` (cubre también M1, M4 y el listado). (3) Opcional: consolidar los COUNT por estado en un `GROUP BY`.
- **Confianza:** Alta.

#### M3. `PaymentRegistryService::getAll()` carga todos los pagos en memoria antes de paginar
- **Categoría:** Paginación / queries pesadas.
- **Ubicación:** `src/Service/PaymentRegistryService.php:32-42` (`_queryInvoicePayments` línea 118, `_queryLiquidationDocPayments` línea 213); `src/Controller/PaymentRegistryController.php:37-44`
- **Descripción / amplificación:** Ambos sub-queries terminan en `->all()->toArray()` **sin `limit/offset`**, traen la tabla completa de pagos, hacen `array_merge` + `usort()` por `created` en PHP, y el controller pagina con `array_slice` y `count()` sobre el array completo. **O(N) memoria + O(N log N) CPU** por request cuando la página efectiva es de 15 ítems. Crece sin cota con el histórico de pagos. (No hay N+1: ambos queries traen `contain()` completo, así que el `array_map` posterior opera sobre asociaciones eager-cargadas.)
- **Evidencia:** `PaymentRegistryService::_queryInvoicePayments()` línea 118 `$query->all()->toArray()`; `PaymentRegistryController` línea 37 `getAll($filters)` sin límite; línea 42 `array_slice($allPayments, ($page-1)*$limit, $limit)`.
- **Recomendación:** Paginar en SQL. Como son DOS tablas distintas fusionadas, la corrección no es trivial: requiere `UNION ALL` (con columnas normalizadas) + `LIMIT/OFFSET` sobre el conjunto unido, o una estrategia de merge con `COUNT(*)` SQL por tabla para el total. Evitar materializar el array completo.
- **Confianza:** Alta.

#### M4. Ausencia de índices en `invoices.pipeline_status` (y `area_approval`, `created`, `due_date`)
- **Categoría:** Índice faltante.
- **Ubicación:** `config/Migrations/20260219000007_CreateInvoices.php:101-105` (y siguientes; ninguna migración posterior los agrega)
- **Descripción / amplificación:** `pipeline_status` se filtra en el dashboard (`InvoiceStatisticsService`: `getStats`, `getFinancialStats`, `getChartData`), en los listados `/invoices` (`InvoicesController.php:90,97,156` vía `_visibleStatusConditions`) y en `SidebarCounterService` (líneas 153, 219, 262). Sin índice → full scan en cada filtro. **Es la causa raíz transversal**, no un problema aislado de una pantalla. El impacto es lineal con el volumen de `invoices` (no "exponencial"); a volumen moderado es tolerable pero crece.
- **Recomendación:** Migración nueva (`BaseMigration` + `addIndex`): índice simple en `pipeline_status`, o compuesto `(pipeline_status, created)` para los rangos de fecha del dashboard, e índice en `area_approval`. `due_date` para la query de vencidas. Nota: `document_type !=` y `area_approval` son de baja cardinalidad/no-sargables como líder — priorizar `pipeline_status` como columna líder.
- **Confianza:** Alta.

### Bajo

Los siguientes son verdaderos positivos de impacto marginal — optimizaciones legítimas pero NO cuellos de botella, casi todos mitigados por cache, baja frecuencia o volumen acotado.

| # | Hallazgo | Ubicación | Por qué es Bajo |
|---|----------|-----------|-----------------|
| B1 | `getInvoiceStatusCounters()` hace 1 COUNT por estado en vez de `GROUP BY` | `SidebarCounterService.php:126-145` | Cacheado 30 s por rol; ~6 COUNT cada 30 s, no por request. Consolidar a `GROUP BY` es nice-to-have. |
| B2 | `_applyPayments()` hace `get()` por factura en loop (+ `recalculatePaymentStatus`) | `PaymentSchedulingPipelineService.php:444-455` | Acción puntual de tesorería, N = facturas de una programación, dentro de 1 transacción. El `get()` de la línea 447 es redundante; el resto no. |
| B3 | `confirmPayment()` hace `get()`+`save()` por factura hija en loop | `PaymentSchedulingPipelineService.php:521-543` | Escritura manual poco frecuente, N acotado, transaccional. El `get()` tras `recalculatePaymentStatus` es semi-necesario para revalidar estado; el bulk-load propuesto sería inseguro (entidades stale). |
| B4 | `RefundPaymentService::authorizePayment()` hace 2×N saves (pago + factura) en loop | `RefundPaymentService.php:282-323` | Autorización manual de baja frecuencia, N = hijas de un reintegro, dentro de transacción con `FOR UPDATE`. `updateAll()` saltaría callbacks/eventos del ORM — no trivialmente seguro. |
| B5 | Dashboard recalcula todos los stats sin caché persistente | `DashboardController.php:30-81` / `DashboardStatisticsService.php` | ~14 queries (no 25+), gateadas por permiso, una carga ocasional por visita. Agregados COUNT/SUM, no N+1. |
| B6 | `getStats()` ejecuta 6 COUNT separados sin caché | `InvoiceStatisticsService.php:28-41` | Solo en dashboard, gateado por `can_view('invoices')`. Agregados simples; el escenario "12 queries/min" es especulativo. |
| B7 | `getFinancialStats()` hace 4 queries no consolidadas | `InvoiceStatisticsService.php:77-130` | Consolidable a 1 `SUM(CASE…)`; impacto marginal al volumen esperado, frecuencia baja. |
| B8 | `getChartData()` query mensual raw con `DATE_FORMAT(created)` sin índice | `InvoiceStatisticsService.php:176-185` | Full scan + filesort, pero 1 sola query, baja frecuencia, volumen modesto. El `GROUP BY DATE_FORMAT()` requiere filesort aun con índice. |
| B9 | `EmployeeStatisticsService::getChartData()` `GROUP BY` sin índices | `EmployeeStatisticsService.php:172-188` | Faltan índices en `employees.status`/`contract_type` y `employee_novelties.created`, pero tablas de RRHH pequeñas, solo en dashboard. |
| B10 | Ausencia total de caché en dashboard (sin invalidación por evento) | `DashboardStatisticsService.php` | Brecha real, pero el "250 queries/s con 10 usuarios" es fabricado: el dashboard es una página cargada por visita, no polled. Índices antes que caché por-evento. |
| B11 | Sidebar: ~14-16 COUNT independientes por cache-miss (sin UNION/batch) | `SidebarCounterService.php:40-102` | Mitigado por cache de 30 s por rol; COUNT agregados, no N+1; volumen bajo. UNION/CTE = micro-optimización; Redis es especulativo (usa FileEngine). |
| B12 | Catálogos de dropdown recargados sin caché persistente | `InvoicesController.php:567-595` y otros | Hit rate 0%, pero son finds únicos (no por-fila), 1 acción = 1 request (no 6-15 acumuladas), tablas de catálogo pequeñas. Cache `+7 días` es mejora barata. |
| B13 | Missing index `invoices.pipeline_status` (duplicado, framing "crítico") | `20260219000007_CreateInvoices.php:101` | Mismo problema que M4; el framing "O(N) por request / crítico" es falso (cacheado 30 s, sub-ms a ~1000 filas). |
| B14 | Missing índice compuesto `invoices(document_type, pipeline_status)` | `20260219000007_CreateInvoices.php:101` | Cubierto por M4. El orden propuesto está mal: `document_type` con `!=` no sirve como líder; el fix correcto es índice simple sobre `pipeline_status`. |

---

## Falsos positivos / descartados

| Hallazgo | Por qué se descartó |
|----------|---------------------|
| `InvoicePipelineService::regress()` guarda pagos 1-a-1 | Acción esporádica sobre 1 factura, N=1-3 pagos, transaccional; `updateAll()` perdería eventos ORM. Micro-optimización. |
| `PettyCashPaymentService::authorizePayment()` 1-a-1 | Baja frecuencia, N bajo, transaccional. La premisa (que Refund usa `saveMany`) es falsa: Refund usa el mismo patrón 1-a-1. |
| 14 COUNT en `_buildCounters()` (latencia por-request) | Cacheado 30 s por rol; el "14-20 ms por request" es un cache hit, no 14 queries. |
| `AuthorizationService::getPermissionsForRole()` sin caché cross-request | Memoizado per-request (`$this->cache[$roleId]`) = 1 query indexada/request sobre tabla diminuta (~200 filas). Sin-caché-global es diseño deliberado documentado. |
| `PipelineAuthorizationService::_loadForRole()` sin caché cross-request | Memoizado per-request; 1 query indexada de pocas filas. Diseño deliberado documentado; instancia `addShared`. |
| Sidebar cache TTL `+30 s` muy agresivo | Aritmética del hallazgo errónea (clave por rol, no por usuario); subir TTL degradaría frescura de badges (no hay invalidación por evento). |
| `getOperableSteps()` llamado 4x por request | `_loadForRole` carga todos los pipelines del rol en 1 query y memoiza; las 6 llamadas son hits en memoria. Además todo está bajo cache de 30 s. |
| `AppController::beforeFilter` recalcula sidebar EN CADA request | FileEngine persiste cross-request; cache keyed por rol con TTL 30 s. La premisa "no persiste session-to-session" es falsa. |
| `InvoicePresentation::forRow()` `array_search` sin índice | Búsqueda lineal en array constante (≤6 elementos), 15 filas/página = ~90 comparaciones de string. Sin I/O. El propio hallazgo lo llama "insignificante". |
| Subquery no indexado en `_buildInvoiceQuery()` (invoice_reads) | El índice `(invoice_id, user_id)` ya existe (`CreateInvoiceReads.php:19`). `invoice_observations` también indexada. Paginado a 15. |
| `getPermissionsForRole()` sin índice en `permissions` | Índice único `(role_id, module)` ya existe (`CreatePermissions.php:42`), con `role_id` líder. Tabla diminuta + memoización. |
| `EmailLogsController::index()` paginación ineficiente | Índices `idx_status_created`, `idx_event_type`, `idx_entity` ya creados (`AddEmailLogsTable.php:78-80`). Paginación SQL correcta. |
| Missing index `invoices.area_approval` | Baja cardinalidad (B-tree aporta poco), segunda condición `!=` no-sargable; COUNT cacheado 30 s; volumen bajo. |
| Missing index `petty_cash_records.status` | COUNT cacheado 30 s por rol; tabla de lotes internos diminuta; full scan sub-ms. |
| Missing index `refunds.status` | Ya existe `addIndex(['status'])` (`CreateRefunds.php:117`). |
| Missing index `employee_novelties.pipeline_status` | Ya cubierto por `idx_novelty_pipeline_dates(pipeline_status, start_date, end_date)` con `pipeline_status` líder (`AddIndexesToEmployeeNovelties`). |
| Missing index `novelty_liquidation_docs.pipeline_status` | COUNT cacheado 30 s; tabla de agrupación de muy baja cardinalidad (decenas de filas). |
| Missing index `advance_legalizations.status` | Ya existe `addIndex(['status'])` (`CreateAdvanceLegalizations.php:33`). |
| Missing composite `invoice_payments(invoice_id, status)` | El índice `invoice_id` ya restringe a 1-10 pagos por factura; `status` se filtra en memoria sobre conjunto diminuto. Rutas de escritura puntuales. |
| Missing index `invoice_payments.status` | Todas las queries combinan `status` con `invoice_id` (indexado). `status` aislado (enum de 3 valores) no aporta selectividad. |
| Missing indexes `approval_tokens.expires_at` | Lookup siempre por `token` (índice único); expiración se valida en PHP, no en SQL. No existe job de cleanup que escanee `expires_at`. |
| Missing index `email_logs.status` (retry) | `idx_status_created` ya existe (`AddEmailLogsTable.php:76-77`). Queries de retry batch, baja frecuencia. |
| Missing index `rate_limit_buckets.window_start` | Ya existe `addIndex(['window_start'])` (`AddRateLimitBucketsTable.php:35`). |
| `EmployeeStatisticsService::getBasicStats()` 3 queries por request | Solo en dashboard (no beforeFilter), gateado por permiso; índices de novelties ya existen. COUNT simples. |
| Matriz de auth corre per-request sin persistencia cross-request | Ambos servicios memoizan per-request (≤2 SELECT indexados/request); tablas minúsculas; diseño deliberado documentado. |
| `forRow()` accede a `provider`/`operation_center` sin eager loading | `_buildInvoiceQuery` ya hace `->contain([...])` con todas las asociaciones; accesos con `hasValue()`; paginado a 15. Sin N+1. |
| `SystemSettingsService` decrypt en cada `.get()` | Cache per-request en memoria; 1 decrypt por clave por request; claves cifradas no se usan en paths calientes. |
| `PipelineAuthorizationService` cache no compartido entre servicios | Instancia `addShared` única por request inyectada al Facade; N llamadas comparten cache. Cero `new` directos. |

---

## Recomendaciones priorizadas

Ordenadas por impacto/esfuerzo (quick-wins de alto impacto primero):

1. **[Quick-win, alto impacto transversal] Añadir índices a `invoices`.** Una migración `BaseMigration`: `addIndex(['pipeline_status', 'created'])`, `addIndex(['area_approval'])`, `addIndex(['due_date'])`. Resuelve la causa raíz de M2, M4, B1, B6, B7, B8, B13, B14 y los listados. Bajísimo esfuerzo, beneficia toda la app. (M4)
2. **[Quick-win] Subir el TTL del cache `sidebar` de `+30 s` a `+5 min`** en `config/app.php:139`. Reduce los recálculos de COUNT por rol. Ojo: no hay invalidación por evento, así que evaluar el tradeoff de frescura de badges (o añadir invalidación explícita en transiciones de pipeline). (M2)
3. **[Medio esfuerzo, alto impacto en su pantalla] Paginar `PaymentRegistryService` en SQL** con `UNION ALL` + `LIMIT/OFFSET` y `COUNT(*)` SQL, eliminando `->all()->toArray()` + `array_slice`. Es el único hallazgo que crece sin cota con el histórico. (M3)
4. **[Bajo esfuerzo] Consolidar `getChartData()`/`getStats()`/`getFinancialStats()`** en queries únicas (`GROUP BY` / `SUM(CASE…)`). El bloque mensual ya usa la técnica. (M1, B6, B7)
5. **[Bajo esfuerzo] Consolidar `getInvoiceStatusCounters()` a un `GROUP BY pipeline_status`.** Colapsa ~6 COUNT en 1 (sinergia con el índice del punto 1). (B1, B11)
6. **[Opcional, medio esfuerzo] Cachear el dashboard** con `Cache::remember(TTL 5-15 min)` en `DashboardStatisticsService`. Hacerlo DESPUÉS de los índices (menor ROI; los índices solos ya hacen tolerables los agregados). (B5, B10)
7. **[Opcional, bajo esfuerzo] Cachear catálogos casi-estáticos** (`Providers`, `OperationCenters`, etc.) con `Cache::remember(+7 días)` e invalidar en `save/delete`. (B12)
8. **[Limpieza, bajo impacto] Eliminar el `get()` redundante** de `PaymentSchedulingPipelineService.php:447` pasando la entidad ya recalculada. NO convertir los loops de pago (B3/B4) a `updateAll()` sin evaluar los side-effects de eventos/auditoría del ORM. (B2, B3, B4)

---

## Alcance y limitaciones

- **Alcance:** análisis estático del código PHP (controllers, services, pipeline, ViewModels/Presentation), las migraciones de `config/Migrations/` y la configuración de caché (`config/app.php`). Foco en: overhead por-request (`beforeFilter`), dashboard, listados paginados, servicios de pipeline y agregaciones.
- **Verificación:** cada hallazgo se contrastó contra el código y las migraciones reales; las severidades iniciales se ajustaron adversarialmente (la mayoría a la baja) cuando existía mitigación verificable (cache, memoización, índices ya presentes, paginación fija).
- **Limitaciones:**
  - **Sin datos de volumen reales ni `EXPLAIN`:** las severidades asumen volumen "moderado" típico de un sistema de gestión interna (miles, no millones de filas). Con volúmenes altos en `invoices`, M4/M2 escalarían a Alto y las recomendaciones de índices se vuelven urgentes.
  - **Sin profiling en runtime** (no se midieron latencias reales ni se ejecutó el servidor; verificación manual del servidor queda a cargo del equipo).
  - **No se auditó concurrencia/locking a nivel BD** más allá de los `FOR UPDATE` observados, ni el comportamiento de FileEngine bajo alta concurrencia (posible candidato a Redis si la concurrencia crece).
  - El conteo exacto de queries del sidebar (~16-21) depende de los estados visibles por rol; se usó el rango observado en `_buildCounters`.
