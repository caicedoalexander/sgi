# Mis Pendientes — bandeja unificada cross-módulo

**Fecha:** 2026-07-21
**Estado:** Diseño aprobado (revisado por `spi-design-reviewer`)
**Autor:** Brainstorming (Claude + Alexander)

## Problema

Un usuario que opera varios módulos de flujo hoy debe entrar módulo por módulo
para ver qué tiene pendiente en cada uno. No existe una vista única que responda
"¿qué requiere mi acción ahora mismo, en todos los módulos?". Los badges del
sidebar dan el conteo por módulo, pero no una lista accionable unificada.

## Objetivo

Una vista **"Mis Pendientes"** que agregue, en una sola tabla, los ítems de los
8 módulos de flujo cuyo **estado actual el rol del usuario puede operar**
(`getVisibleStatuses($roleId)`), con el look canónico de las listas de módulo
(fila `.row-fact`, `pipeline-mini`, pills soft), accesible desde un enlace en el
**tope del sidebar** con un badge de total.

## Decisiones (cerradas en brainstorming)

1. **Layout:** tabla única plana. Una fila por pendiente, con pill de módulo para
   distinguir origen. (No secciones por módulo, no columnas por-módulo.)
2. **Alcance:** los **8 módulos de flujo** — Facturas, Anticipos, Legalizaciones
   de anticipos, Caja Menor, Reintegros, Novedades, Liquidación de Novedades,
   Programación de Pagos.
3. **Regla de inclusión:** **espejo del sidebar**. La lista usa exactamente las
   mismas reglas de "lo mío" que `SidebarCounterService` ya calcula por módulo,
   para que `total del badge == filas de la lista`. Hereda las exclusiones
   existentes (p.ej. Novedades excluye `RECHAZADA`).
4. **Sidebar:** enlace "Mis Pendientes" en el tope (tras "Inicio") con badge de
   total. Los badges por-módulo actuales se mantienen intactos.
5. **Garantía de espejo:** **R2** — `MyPendingService` replica los `WHERE` de cada
   badge y un test verifica `count(lista por módulo) == badge`. Es el patrón que el
   repo ya usa ("Espejo exacto de `…Controller::…()`"); cambios quirúrgicos.
6. **`pipeline-mini` por fila = fiel a la lista nativa de cada módulo.** Solo los
   **6 módulos cuyo índice ya renderiza `pipeline-mini`** lo llevan en la fila
   unificada (Facturas, Anticipos, Legalizaciones, Caja, Reintegros, Prog. Pagos).
   **Novedades y Liquidaciones** muestran **solo el pill de estado** — porque sus
   listas nativas no usan `pipeline-mini` (`templates/EmployeeNovelties/index.php`
   y `templates/NoveltyLiquidationDocs/index.php` renderizan solo un pill). Tabla
   mixta, pero cada fila calca el estilo real de su módulo.
7. **Total unificado a 8:** se **reconcilia** `PendingNotificationsService` para
   que también incluya Legalizaciones (hoy arma 7 módulos, sin legalizaciones), de
   modo que badge del sidebar, lista y digest de correo n8n coincidan en los 8.

## Precedente reusado

`Approvals/*` ("Mis Aprobaciones") ya es una bandeja unificada cross-módulo con la
arquitectura exacta que necesitamos:

- DTO normalizado: `Service/Dto/ApprovalInboxItem`.
- Servicio agregador con lógica pura testeable + fetch lazy: `Service/ApprovalInboxService`.
- Presentation anti-drift + RowView: `View/Presentation/ApprovalInboxPresentation` / `ApprovalInboxRowView`.
- Template con filtros + paginación **manual** (no ORM Paginator sobre fuentes en memoria): `templates/Approvals/index.php`.
- Controller con `#[NoAuthGate]` (gate personal, no RBAC de módulo): `ApprovalsController::index`.
- Ruta custom antes de `fallbacks()`.

"Mis Pendientes" clona ese patrón cambiando la fuente: en vez de aprobaciones
asignadas, agrega ítems en estado operable por el rol.

## Modelo de datos

**Sin cambios de esquema. Sin migración.** La feature es 100% agregación read-only
sobre tablas existentes (`Invoices`, `AdvanceLegalization`, `PettyCashRecords`,
`Refunds`, `EmployeeNovelties`, `NoveltyLiquidationDocs`, `PaymentSchedulings`) vía
los `WHERE` que `SidebarCounterService` ya ejecuta.

## Arquitectura

### Backend

#### `src/Service/Dto/PendingItem.php`
`final readonly class`. Forma común normalizada de una fila:

| Campo | Tipo | Significado |
|---|---|---|
| `module` | `string` | Slug **interno** de la feature (`invoices`, `advances`, `legalizations`, `petty_cash`, `refunds`, `novelties`, `liquidations`, `payment_schedulings`). Ver nota de slugs abajo. |
| `entityId` | `int` | Id de la entidad. |
| `code` | `string` | Código/número legible. |
| `counterparty` | `string` | Contraparte (proveedor / empleado / beneficiario / fondo). |
| `summary` | `string` | Resumen (monto formateado o tipo). |
| `status` | `string` | Slug del **estado a mostrar** del pipeline del módulo. Ver nota crítica de Legalizaciones. |
| `date` | `\Cake\I18n\DateTime` | `created` de la entidad primaria — clave única de orden cross-módulo (desc). |

- **Nota crítica (Legalizaciones):** el `status` de una fila de Legalizaciones sale
  de **`AdvanceLegalization.status`**, NO de `Invoices.pipeline_status` (que en esa
  fuente siempre es `pagada` por el join). Si se lee `pipeline_status`, el pill se
  mostraría como `pagada` sobre el step set equivocado.
- **Nota de slugs (`module`):** son identificadores **internos nuevos** de esta
  feature, no claves persistidas. NO reusarlos como claves de `permissions`. Ojo a
  no confundirlos con las trampas persistidas (CRUD `advances` ≠ pipeline
  `legalizations`; CRUD `novelty_liquidation_docs` ≠ pipeline `liquidation_docs`).
- El destino del link **no** se guarda en el DTO; lo resuelve `PendingPresentation`.

#### `src/Service/MyPendingService.php`
Agregador de la bandeja. Espejo de `SidebarCounterService`: reusa **las mismas
dependencias** para resolver `getVisibleStatuses($roleId)` por módulo:
`InvoicePipelineService`, `NoveltyPipelineService`, `PettyCashPipelineService`,
`RefundPipelineService`, `PaymentSchedulingPipelineService`,
`AdvanceLegalizationActionPolicy`.

**`getPending(int $roleId, ?string $module, ?string $search, int $page): array`**
→ `{items: PendingItem[], total, page, perPage}`. Estrategia **two-track** (evita
cargar entidades completas de 8 fuentes a memoria — la agregación es por-**rol**, y
p.ej. Contabilidad puede tener miles de facturas en `contabilidad`):

- **Total (para el badge y el contador):** `->count()` por fuente (barato, indexado,
  = el badge). La suma es el `total`.
- **Ventana de página:** cada `_fetchX` ordena por `created` desc y aplica
  `LIMIT (page * perPage)` (over-fetch **acotado**: máx `8 * page * perPage` filas
  en memoria; página 1 = 120 filas). Se hace merge de las 8 fuentes, se ordena por
  `date` desc y se corta la ventana `[offset, offset+perPage)`.
- Filtro por `module` (una sola fuente) y por `search` (sobre `code`+`counterparty`).
- La lógica de merge/orden/corte es **pura** (sin DB) → unit-testeable con
  `PendingItem[]` en memoria, igual que `ApprovalInboxService::paginateItems`.

**8 métodos privados `_fetchX(int $roleId, int $limit): PendingItem[]`**, cada uno
con el **mismo `WHERE` que su badge** en `SidebarCounterService` (replicado, no
compartido — decisión R2), `ORDER BY created DESC LIMIT $limit`. Mapeo:

| Módulo | Fuente + WHERE espejo (badge de referencia) | code | counterparty | status | link |
|---|---|---|---|---|---|
| Facturas | `Invoices` `find('withoutParent')`, `document_type != Anticipo`, `pipeline_status IN` `invoicePipeline->getVisibleStatuses` (sin `legalizada`) — espejo `getInvoiceStatusCounters` | `invoice_number` | `InvoiceBeneficiary::label` (requiere `contain(['Providers','Employees'])`) | `pipeline_status` | `Invoices::edit` |
| Anticipos | `Invoices` `document_type = Anticipo`, `pipeline_status IN` `invoicePipeline->getVisibleStatuses` — espejo `getAdvancesMineCount` | `invoice_number` | beneficiario | `pipeline_status` | `Advances::edit` |
| Legalizaciones | `Invoices` Anticipo `pagada` ⨝ `AdvanceLegalization.status IN` `legalizationPolicy->getVisibleStatuses`, `!= legalizada` — espejo `getAdvancesPendingLegalizationCount` | código anticipo | beneficiario | **`AdvanceLegalization.status`** | `Advances::legalization` |
| Caja Menor | `PettyCashRecords.status IN` `pettyCashService->getVisibleStatuses` — espejo `getPettyCashMineCount` | código | fondo/titular | `status` | `PettyCashRecords::edit` |
| Reintegros | `Refunds.status IN` `refundService->getVisibleStatuses` — espejo `getRefundsMineCount` | código | beneficiario | `status` | `Refunds::edit` |
| Novedades | `EmployeeNovelties.pipeline_status IN` `noveltyPipeline->getVisibleStatuses`, `!= RECHAZADA`, `+` exclusión liquidación (`or(status != contabilidad, liquidation_doc_id IS null)`) — espejo `getNoveltiesCount` | `#id` | empleado | `pipeline_status` | `EmployeeNovelties::edit` |
| Liquidaciones | `NoveltyLiquidationDocs.pipeline_status IN` `noveltyPipeline->getVisibleLiquidationStatuses` — espejo `getLiquidationMineCount` | código | resumen | `pipeline_status` | `NoveltyLiquidationDocs::edit` |
| Prog. Pagos | `PaymentSchedulings.pipeline_status IN` `paymentSchedulingService->getVisibleStatuses` — espejo del conteo en `PendingNotificationsService::_getPaymentSchedulingsCount` | código | beneficiario | `pipeline_status` | `PaymentSchedulings::edit` |

### Capa de vista (Presentation → RowView)

#### `src/View/Presentation/PendingPresentation.php`
`forRow(PendingItem): PendingRowView`. Único punto de derivación de fila. Deriva:
pill + label de módulo, **pasos del pipeline** (solo para los 6 módulos con
`pipeline-mini`), `stageIdx`, `pillClass` del estado, `statusLabel`, `dateLabel`,
`href` destino.

**Distinción crítica (la marcó la revisión):** hay DOS conceptos distintos y NO se
deben confundir —
- **Visible/operable statuses** (`getVisibleStatuses`) = subconjunto que el rol
  opera → gobierna el **WHERE** (qué filas aparecen). Vive en el backend.
- **Step set** = array **ordenado completo** del pipeline → gobierna el
  `pipeline-mini` (`stageIdx = array_search(status, stepSet)`). Sale de la
  **constante ordenada** del módulo, NO de `getVisibleStatuses`.

Registry **módulo → { stepSet ordenado, mapa estado→pill, muestra pipeline-mini,
ruta destino, label + pill de módulo }** como fuente única (const en
`PendingPresentation` o clase `PendingModuleMeta`). Reusa los mapas existentes, no
los redeclara:

| Módulo | stepSet ordenado (pipeline-mini) | pill del estado | ¿pipeline-mini? |
|---|---|---|---|
| Facturas | `InvoiceConstants::PIPELINE_STATUSES` | `InvoicePresentation::STATUS_BADGES` | Sí |
| Anticipos | `InvoiceConstants::PIPELINE_STATUSES` | `InvoicePresentation::STATUS_BADGES` | Sí |
| Legalizaciones | `AdvanceConstants::PIPELINE_STATUSES` | `AdvancePresentation::STATUS_BADGES` | Sí |
| Caja Menor | `PettyCashConstants::STATUSES` | `PettyCashPresentation::STATUS_BADGES` | Sí |
| Reintegros | `RefundConstants::STATUSES` | `RefundPresentation::STATUS_BADGES` | Sí |
| Prog. Pagos | `PaymentSchedulingConstants::PIPELINE_STATUSES` | `PaymentSchedulingPresentation::STATUS_BADGES` | Sí |
| Novedades | — (pill-only) | `NoveltyPresentation::STATUS_BADGES` | **No** |
| Liquidaciones | — (pill-only) | `NoveltyPresentation` (badge de liquidación) | **No** |

**⚠️ Trampa Anticipos/Legalizaciones (la marcó la revisión):**
`AdvancePresentation::STATUS_BADGES` está keyed por los estados de **legalización**
(`validacion…legalizada`) — sirve a **Legalizaciones**, NO a Anticipos. **Anticipos**
usan el step set y el pill del **pipeline de facturas** (`InvoiceConstants::PIPELINE_STATUSES`
+ `InvoicePresentation`), porque Anticipo = Invoice. No cablear el registry al revés.

#### `src/View/Presentation/PendingRowView.php`
DTO inmutable de fila: `module, moduleLabel, moduleBadgeClass, code, counterparty,
summary, status, statusLabel, pillClass, pipelineSteps[] (vacío ⇒ sin mini),
stageIdx, pipelineVariant, dateLabel, href`.

**Regla de oro respetada:** cero arrays estado→pill inline en el `.php`; el template
renderiza `pipeline-mini` solo cuando `pipelineSteps` no está vacío.

### Template + controller + ruta

#### `templates/Pending/index.php`
Estructura calcada de `templates/Approvals/index.php`:
- Header: título "Mis Pendientes" + meta ("N pendientes").
- Buscador (`input` + `.btn-default`) + filtro por módulo (chips `.chip[role=tab]`
  y/o `select`).
- Tabla `.spi-card[style="padding:0"]` con filas `<a class="row-fact">` en grid.
  Columnas: **Módulo** (pill) · **Código · Contraparte** · **Resumen** ·
  **Estado · Pipeline** (`pipeline-mini` + pill soft; **solo pill** para
  Novedades/Liquidaciones) · **Fecha** · chevron.
- `empty-state` (`.es-*`) cuando no hay filas.
- **Paginación inline manual** (clases `.pgn`/`.pgn-btn`), idéntica a Approvals.

#### `src/Controller/PendingController.php`
`index()` con
`#[NoAuthGate(reason: 'Vista personal derivada de permisos ya existentes; cada fila ya está filtrada por lo que el rol opera')]`.
Resuelve `roleId` del usuario autenticado, llama `MyPendingService::getPending(...)`,
mapea cada `PendingItem` con `PendingPresentation::forRow` y hace `set('rows', ...)`
(+ `total`, `page`, `perPage`, filtros activos). **Sin** entrada en
`$controllerModuleMap` (NoAuthGate corta antes del lookup, `AppController:238-240`)
y **sin** fila en la tabla `permissions`.

#### Ruta (`config/routes.php`, antes de `fallbacks()`)
`/pendientes` → `['controller' => 'Pending', 'action' => 'index']`. Slug español
(visible al usuario). Query: `?module=…&q=…&page=…`.

### Sidebar (enlace tope + badge)

- Nuevo `<li>` "Mis Pendientes" en `templates/layout/default.php` justo **tras
  "Inicio"**. **Siempre visible** (es la entrada a "tu trabajo", no gateada por
  módulo). El **badge se muestra solo cuando `total > 0`**.
- El total se añade a `SidebarCounterService::_buildCounters()` como
  `myPendingTotal`, sumando **exactamente estas 8 claves "mine"** (no todo
  `_buildCounters`, que trae `totalInvoicesCount`, `pettyCashCount`, `openAlertsCount`,
  etc. — no confundir):
  `array_sum(sidebarCounters)` (Facturas) `+ advancesMineCount + advancesPendingLegalizationCount`
  (ya presente en `_buildCounters:102`) `+ pettyCashMineCount + refundsMineCount +
  noveltiesCount + liquidationMineCount + paymentSchedulingsMineCount`.
  Solo `paymentSchedulingsMineCount` es genuinamente nuevo en el counter → requiere
  inyectar `PaymentSchedulingPipelineService` en `SidebarCounterService`. Así el
  badge reusa la caché `sidebar` de 5 min existente.
- **Reconciliación n8n:** `PendingNotificationsService::_buildModules` gana una
  entrada `legalizations` (con `advancesPendingLegalizationCount` y ruta
  `Advances::pendingLegalization`), para que el digest de correo también sume 8. Así
  badge == lista == correo.
- Badges por-módulo actuales **intactos**.
- Deuda conocida heredada (no empeorada): la caché del sidebar no se invalida al
  cambiar `pipeline_permissions`; el badge tarda ≤5 min en reflejar cambios de
  permisos, igual que los demás.

## Criterios de aceptación

1. `/pendientes` responde 200 a cualquier usuario autenticado, sin fila en
   `permissions` ni entrada en `$controllerModuleMap`.
2. La lista muestra, por los 8 módulos, exactamente los ítems cuyo estado actual el
   rol opera — con las mismas exclusiones que los badges (Novedades sin `RECHAZADA`,
   etc.).
3. Para **cada** módulo, `count(filas del módulo en la lista) == badge`
   correspondiente de `SidebarCounterService` (test de espejo).
4. Facturas/Anticipos/Legalizaciones/Caja/Reintegros/Prog. Pagos muestran
   `pipeline-mini` con `stageIdx` correcto sobre su step set ordenado; Novedades y
   Liquidaciones muestran solo pill.
5. La fila de Legalizaciones muestra el estado de `AdvanceLegalization.status`, no
   `pagada`.
6. Cada fila enlaza al `edit`/`legalization` de su módulo; los estados terminales no
   aparecen (la lista solo trae estados operables).
7. El badge del tope del sidebar = suma de los 8 conteos "mine"; oculto si es 0.
8. El total del badge, el total de la lista y el total del digest n8n coinciden.
9. El fetch por fuente está acotado (`LIMIT page*perPage`); ninguna carga trae la
   tabla completa a memoria.
10. `cs-check` verde; sin arrays estado→pill inline en el template.

## Testing

- **Unit `MyPendingService`** (lógica pura): merge/orden por `date` desc/corte de
  ventana + filtro por módulo/búsqueda — con `PendingItem[]` sembrados en memoria.
- **Unit normalización por módulo**: cada `_fetchX` produce el `PendingItem`
  esperado (code/counterparty/summary/**status** correctos; Legalizaciones desde
  `AdvanceLegalization.status`) desde entidades sembradas.
- **Unit `PendingPresentation::forRow`**: para cada módulo, `pipelineSteps` +
  `stageIdx` + `pillClass` correctos; `pipelineSteps` **vacío** para
  Novedades/Liquidaciones; Anticipos usa step set de Invoice (no de Advance).
- **Test de espejo (garantía R2):** para un rol con permisos sembrados,
  `count(MyPendingService::_fetchX)` por módulo `==` el badge equivalente de
  `SidebarCounterService`. Red que evita que "el badge mienta sobre su lista".
- **Integración `PendingController::index`**: 200 sin permiso de módulo, filtros
  aplicados, paginación — mirando `ApprovalsControllerTest` como plantilla.

## Fuera de alcance (YAGNI)

- No acciones inline desde la bandeja (aprobar/avanzar): cada fila enlaza a la vista
  del módulo, donde ya viven las acciones con su RBAC de paso.
- No consolidar/ocultar los badges por-módulo del sidebar (decisión explícita:
  intactos).
- No refactor de `SidebarCounterService` a query-builders compartidos (R1
  descartada a favor de R2).
- No `pipeline-mini` sintético para Novedades/Liquidaciones (pill-only, fiel a sus
  listas nativas).
- No persistencia de preferencias de la bandeja (orden/filtros por usuario).

## Archivos afectados

**Nuevos:**
- `src/Service/Dto/PendingItem.php`
- `src/Service/MyPendingService.php`
- `src/View/Presentation/PendingPresentation.php` (+ registry, quizá `PendingModuleMeta`)
- `src/View/Presentation/PendingRowView.php`
- `src/Controller/PendingController.php`
- `templates/Pending/index.php`
- Tests: `tests/TestCase/Service/MyPendingServiceTest.php`,
  `tests/TestCase/View/Presentation/PendingPresentationTest.php`,
  `tests/TestCase/Controller/PendingControllerTest.php`.

**Modificados:**
- `config/routes.php` (ruta `/pendientes`).
- `templates/layout/default.php` (enlace tope + badge).
- `src/Service/SidebarCounterService.php` (nuevo `myPendingTotal` +
  `paymentSchedulingsMineCount` + dependencia `PaymentSchedulingPipelineService`).
- `src/Service/PendingNotificationsService.php` (reconciliación: +`legalizations`).
