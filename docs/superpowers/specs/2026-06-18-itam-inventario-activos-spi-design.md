# ITAM — Módulo de Inventario de Activos de TI en SPI (diseño)

**Fecha:** 2026-06-18
**Estado:** Diseño aprobado — pendiente plan de implementación
**Autor:** Alexander + brainstorming asistido

---

## 1. Resumen

ITAM (IT Asset Management) es la gestión de inventario y activos tecnológicos del área TIC.
El sistema se compone de **dos piezas que se diseñan e implementan por separado**:

1. **Módulo de inventario en SPI** (este documento) — la **fuente de verdad**: modelo de datos,
   toda la lógica de negocio, la UI web de administración y una **API REST** para integración.
2. **Agente conversacional en n8n** (sub-proyecto posterior) — el **canal**: WhatsApp + IA (LLM con
   tool-calling) + OCR (futuro). Consume la API REST de SPI; nunca toca la base de datos directo.

Este spec cubre **(1)** completo y el **contrato de integración** que **(2)** consumirá, más una
sección de contexto sobre cómo encaja la IA. El workflow interno de n8n queda fuera de alcance.

### Principio rector

El documento original de ITAM lo exige de forma explícita:

> *"ITAM no debe ser la fuente única de verdad."*
> *"ITAM no debe modificar información crítica sin validación."*

Por eso: **la inteligencia (n8n + LLM) propone, y SPI dispone.** Las reglas de negocio
(RN-01…RN-10) viven en un solo lugar — SPI — de modo que ninguna alucinación del modelo ni acceso
externo pueda corromper el inventario.

---

## 2. Alcance

### Incluido en este MVP
- **Activos serializados**: catálogo, ciclo de vida por estado, responsable y ubicación.
- **Movimientos** (entrega, devolución, traslado, préstamo, baja, ingreso, ajuste) como **log inmutable**.
- **Consumibles** (tóners y similares) con **control de stock** (actual / mínimo / máximo) y movimientos de stock.
- **Actas y soportes** documentales (reusando la gestión documental existente de SPI).
- **Alertas** calculadas y persistidas en SPI (stock bajo, actas pendientes, etc.).
- **API REST** `/api/itam/*` con autenticación por API key para n8n.
- **Push** de alertas SPI→n8n vía el `N8nService`/`WebhookService` existentes.
- **Permisos** integrados al RBAC estándar de SPI.
- **UI de administración** (Activos, Consumibles, Categorías, Alertas) con los patrones de diseño de SPI.

### Fuera de alcance (sub-proyectos futuros, cada uno con su propio spec)
- Workflow interno de n8n (intents, system prompt, catálogo de tools, confirmaciones del agente).
- OCR / visión artificial para extraer seriales, modelos y referencias de fotos (Fase 2 del doc original).
- Predicción de consumo / recomendaciones de compra / analítica avanzada (Fase 3).
- Dashboard web avanzado de ITAM más allá del widget de alertas.

---

## 3. Arquitectura general

```
   WhatsApp ──► Agente ITAM (n8n)  ──HTTP + API key──►  SPI  ──► MySQL
                  · LLM (cerebro)        (REST /api/itam/*)      (fuente de verdad)
                  · OCR (futuro)    ◄──webhook push (alertas)───┘
                  · notifica
                                          UI web de administración (SPI)
                                          · operadores TIC (RBAC)
```

| Pieza | Rol | Dónde |
|---|---|---|
| LLM (cerebro) | Entiende lenguaje natural, decide qué hacer, desambigua, redacta | n8n |
| API REST de SPI (manos + memoria) | Ejecuta acciones validadas, guarda la verdad | SPI |
| Reglas de negocio (instinto) | Qué se permite y qué no (RN-01…RN-10) | SPI |

**Dos direcciones de integración:**
- **n8n → SPI**: API REST (lecturas y escrituras). Toda escritura pasa por los mismos servicios que la UI.
- **SPI → n8n**: webhook push cuando se genera una alerta nueva (reusa `N8nService::sendData()`).

---

## 4. Modelo de datos

Todas las tablas llevan `created`/`modified` salvo los **logs**, que son **inmutables** (RN-09) y solo
tienen `created`.

### Tablas nuevas

| Tabla | Propósito | Campos clave |
|---|---|---|
| `asset_categories` | Catálogo de tipos (Computador, Portátil, Periférico, Impresora, Servidor, Red…) | `code` (uniq), `name`, `description`, `active` |
| `assets` | Activos serializados | `code` (uniq, autogenerado), `serial_number` (null), `asset_category_id` (FK), `brand`, `model`, `description`, `status`, `responsible_employee_id` (FK→employees, null), `operation_center_id` (FK→operation_centers), `cost_center_id` (FK, null), `acquisition_date` (null), `observations` (null) |
| `asset_movements` | **Log inmutable** de movimientos | `asset_id` (FK), `movement_type`, `from_employee_id` (FK, null), `to_employee_id` (FK, null), `from_operation_center_id` (FK, null), `to_operation_center_id` (FK, null), `reason`, `movement_date`, `acta_status`, `performed_by_user_id` (FK→users), `requested_by_phone` (null), `requested_by_employee_id` (FK→employees, null), `source` |
| `asset_documents` | Actas y soportes | `asset_id` (FK), `asset_movement_id` (FK, null), `document_type`, `name`, `file_path`, `file_size`, `mime_type`, `uploaded_by` (FK→users) |
| `consumables` | Stock por cantidad | `reference` (uniq), `description`, `current_stock`, `minimum_stock`, `maximum_stock` (null), `operation_center_id` (FK, null), `unit` (null) |
| `consumable_movements` | **Log inmutable** de stock | `consumable_id` (FK), `movement_type`, `quantity`, `balance_after`, `reason`, `related_asset_id` (FK→assets, null), `movement_date`, `performed_by_user_id` (FK→users), `requested_by_phone` (null), `source` |
| `asset_alerts` | Alertas calculadas y persistidas | `alert_type`, `priority`, `asset_id` (FK, null), `consumable_id` (FK, null), `asset_movement_id` (FK, null), `message`, `status`, `notified_at` (null), `resolved_at` (null) |

> `asset_alerts` evita referencia polimórfica usando **FKs nullable** (`asset_id` / `consumable_id` /
> `asset_movement_id`), de los cuales se llena solo el relevante según `alert_type`.

### Reuso de SPI (sin tablas nuevas)
- `employees` — responsable de activo y solicitante (resuelto por nombre/documento/teléfono).
- `operation_centers` — ubicación / sede física.
- `cost_centers` — centro de costo opcional del activo.
- `users` + `roles` + `permissions` — RBAC (incluido el "usuario de servicio ITAM").
- `system_settings` — URL del webhook de n8n y API key.

### Enums (fuente única de verdad)

Patrón de SPI: `src/Constants/Domain/{Modulo}/{Enum}.php` (con `label()` y helpers), y
`src/Constants/{Modulo}Constants.php` que **delega** al enum por retrocompatibilidad.

| Enum | Casos (slugs en español sin acentos, convención SPI) |
|---|---|
| `Asset/AssetStatus` | `disponible`, `asignado`, `prestado`, `en_reparacion`, `dado_de_baja` |
| `Asset/MovementType` | `entrega`, `devolucion`, `traslado`, `prestamo`, `baja`, `ingreso`, `ajuste` |
| `Asset/ActaStatus` | `pendiente`, `cargada`, `validada`, `rechazada` |
| `Asset/AlertType` | `stock_bajo`, `acta_pendiente`, `activo_sin_responsable`, `registro_incompleto`, `movimiento_sin_cerrar` |
| `Asset/AlertStatus` | `abierta`, `resuelta`, `vencida` |
| `Asset/AlertPriority` | `alta`, `media`, `baja` |
| `Asset/MovementSource` | `web`, `agent` |
| `Consumable/MovementType` | `ingreso`, `salida`, `ajuste` |
| `Asset/DocumentType` | `acta`, `factura_compra`, `foto`, `soporte_mantenimiento`, `otro` |

### Reglas de transición (movimiento → efecto en el activo)

Registrar un movimiento es **una transacción atómica** que (1) inserta la fila inmutable, (2) actualiza el
activo y (3) marca acta pendiente si aplica:

| `movement_type` | Efecto en `assets.status` | Responsable | Acta requerida |
|---|---|---|---|
| `ingreso` | crea/queda `disponible` | — | No |
| `entrega` | `asignado` | set `to_employee` | **Sí** (RN-05) |
| `prestamo` | `prestado` | set `to_employee` | **Sí** |
| `devolucion` | `disponible` | limpia responsable | Sí |
| `traslado` | sin cambio | sin cambio | No (cambia `operation_center`) |
| `baja` | `dado_de_baja` (terminal) | limpia responsable | **Sí** + autorización |
| `ajuste` | corrección manual | según corrección | No |

Las actas se suben luego a `asset_documents` (`document_type = acta`, ligadas vía
`asset_movement_id`) usando `DocumentUploadTrait` y almacenamiento **privado** en
`ROOT/storage/assets/{assetId}`.

---

## 5. Capa de aplicación

ITAM **no es un pipeline** (los movimientos son log, no flujo de aprobación). Sigue el patrón
**catálogo + servicios + log**, más cercano a `PositionsController` que a `InvoicesController`.

### Servicios (`src/Service/`) — retornan `ServiceResult::ok/fail`, transacciones atómicas
- `AssetInventoryService` — `registerIngress()`, `assign()`, `returnAsset()`, `transfer()`,
  `lend()`, `dispose()`. Cada operación escribe el movimiento, actualiza el activo y marca acta si aplica.
- `ConsumableStockService` — `registerIngress()`, `registerOutput()`, `adjust()`; recalcula `balance_after`.
- `AssetDocumentService` — usa `DocumentUploadTrait` (actas y soportes).
- `AssetAlertService` — calcula, persiste y empuja alertas (invocado por el comando programado).
- `CodeGeneratorService` (ya existe) — genera `assets.code` secuencial.

### Controllers (UI web, `src/Controller/`)
- `AssetsController` — CRUD (`index/view/add/edit`) + acciones `assign/return/transfer/lend/dispose` (modales desde la vista).
- `ConsumablesController` — CRUD + `stockIn/stockOut`.
- `AssetCategoriesController` — catálogo simple (`CatalogCrudTrait`).
- `AssetAlertsController` — `index` + `resolve`.

### Controllers (API REST, `src/Controller/Api/Itam/`)
Namespace separado, sin sesión, autenticados por API key — ver Sección 6.

---

## 6. Contrato API REST hacia n8n

- **Ruta base:** `/api/itam/*`. Rutas registradas **antes** de `$builder->fallbacks()` en
  `config/routes.php`, con `prefix('Api/Itam')`.
- **Autenticación:** `ApiKeyMiddleware` nuevo — valida `Authorization: Bearer <key>` (o `X-Api-Key`)
  contra la API key del **usuario de servicio ITAM**, salta la auth por sesión y resuelve la identidad
  de ese usuario para el RBAC y la auditoría. `401` si falta/inválida.
- **Formato:** envelope estándar SPI `{ success, data, error }`. Validación fallida → `422` con
  `error` **legible** (el LLM se lo relee al usuario). Siempre JSON.
- **Idempotencia:** header `Idempotency-Key` en las escrituras (`POST /movements`, stock) para que un
  reintento del agente no duplique registros.

| Método | Endpoint | Uso del agente |
|---|---|---|
| GET | `/api/itam/assets?status=&category=&q=` | "¿cuántos portátiles disponibles hay?" |
| GET | `/api/itam/assets/{code}` | "¿quién tiene el equipo HP-024?" |
| GET | `/api/itam/employees?q=` | resolver responsable por nombre/documento/teléfono |
| GET | `/api/itam/consumables?low_stock=1` | "¿qué tóners quedan?" |
| GET | `/api/itam/alerts?status=abierta` | "¿qué actas están pendientes?" |
| POST | `/api/itam/movements` | registrar entrega/devolución/traslado/préstamo/baja/ingreso |
| POST | `/api/itam/consumables/{id}/movements` | entrada/salida de stock |
| PATCH | `/api/itam/alerts/{id}/resolve` | cerrar una alerta |

Toda escritura del agente pasa por los **mismos servicios** que la UI → misma validación, misma
auditoría, mismo cambio de estado. El `POST /movements` guarda `source=agent`, `requested_by_phone`
y, si se resuelve, `requested_by_employee_id` (trazabilidad del origen WhatsApp — RN-10).

### Requisitos que la IA impone a la API (refinamientos LLM-friendly)
1. **Búsqueda por texto parcial** en `GET /assets` y `/employees` (`?q=`) — el LLM trabaja con
   nombres/códigos, no con IDs internos.
2. **Mensajes de error legibles** en los `422` (p. ej. *"ese equipo ya está asignado a otra persona"*).
3. **Idempotencia** en escrituras (`Idempotency-Key`).

---

## 7. Cómo encaja la IA (el "cerebro")

> Contexto de la frontera. El diseño detallado del agente (intents, system prompt, tools, confirmaciones)
> es un **sub-proyecto aparte** con su propio spec; aquí solo se documenta la frontera para garantizar
> que el contrato de SPI la sostenga.

**El cerebro vive en n8n, no en SPI.** El patrón es *tool-calling*: el LLM no recibe acceso a la base de
datos, recibe un **catálogo de herramientas** — y ese catálogo **es la API de la Sección 6**:

```
GET  /api/itam/assets        → tool "buscar_activos"
GET  /api/itam/employees     → tool "resolver_empleado"
POST /api/itam/movements     → tool "registrar_movimiento"
GET  /api/itam/alerts        → tool "consultar_alertas"
```

El n8n AI Agent recibe el mensaje de WhatsApp, el LLM elige la herramienta y extrae parámetros, n8n hace
la llamada HTTP, SPI valida y responde, y el LLM redacta la respuesta en lenguaje natural. **No hay que
diseñar nada nuevo en SPI para habilitar la IA: el contrato REST ya la habilita.**

### Flujo de ejemplo
```
Usuario: "Entrégale el portátil HP-024 a Juan Pérez"
  1. LLM interpreta: intent=entrega, activo=HP-024, responsable="Juan Pérez"
  2. tool resolver_empleado → GET /employees?q=Juan Pérez  (2 candidatos)
  3. LLM desambigua con el usuario
  4. LLM confirma la acción y pide "sí"
  5. tool registrar_movimiento → POST /movements (source=agent)
     SPI valida: ¿activo disponible? ¿empleado activo?
  6. LLM: "Listo ✅ Recuerda subir el acta firmada."
```

### Guardrails (donde la IA *no* decide) — se hacen cumplir en SPI, no en el prompt
- **Validación de negocio** (disponibilidad, empleado activo, estado válido) → SPI rechaza con `422`.
- **Acciones irreversibles** (bajas): SPI las gatea por rol/confirmación; la IA nunca da de baja sola.
- **OCR de baja confianza** → marca para validación humana, no auto-confirma (RN-08).
- **Confirmar antes de escribir** → se configura en el workflow de n8n.

### Memoria
- **Corto plazo** (hilo de conversación) → n8n.
- **Largo plazo / la verdad** (el inventario) → el LLM **nunca lo memoriza**, lo consulta en vivo por API.
- **Identidad**: el teléfono de WhatsApp se resuelve contra `employees`.

---

## 8. Alertas

- **Comando programado** `bin/cake itam_generate_alerts` (se agenda en el cron del servidor). Recorre el
  inventario y aplica reglas:
  - `stock_bajo`: `current_stock <= minimum_stock` (RN-07).
  - `acta_pendiente`: movimiento con `acta_status = pendiente` y N días desde `movement_date` (RN-06).
  - `activo_sin_responsable`: `status = asignado` sin `responsible_employee_id` (inconsistencia).
  - `registro_incompleto`: activo sin serial o sin categoría.
  - `movimiento_sin_cerrar`: acta pendiente vencida → la alerta pasa a `vencida`.
- Inserta alertas nuevas (sin duplicar las ya `abierta`), marca vencidas y, por cada alerta nueva, hace
  **push a n8n** vía `N8nService::sendData('itam_alert', {...})` (URL en `system_settings`), registrando
  `notified_at`.
- Las alertas se ven también en la **UI de SPI** (lista filtrable + widget en el dashboard).

---

## 9. Permisos (RBAC estándar de SPI)

Se replica exactamente el flujo que ya usa cada módulo, **sin** `pipeline_permissions` (no es pipeline):

1. **`$controllerModuleMap`** (`AppController`): `Assets→assets`, `Consumables→consumables`,
   `AssetCategories→asset_categories`, `AssetAlerts→asset_alerts`. (Los movimientos se ven dentro de `assets`.)
2. **`AuthorizationService::MODULES`**: `assets→'Activos'`, `consumables→'Consumibles'`,
   `asset_categories→'Categorías de Activos'`, `asset_alerts→'Alertas de Inventario'`.
3. **Tabla `permissions`**: una migración siembra `can_view/create/edit/delete` para los roles que
   correspondan; el resto se administra desde la UI de Roles.
4. **Atributo `#[Permission(action: …)]`** en cada acción; `_checkPermission()` resuelve igual que hoy.
5. **Usuario de servicio ITAM**: un `user` con un rol que tiene sembrados los permisos del módulo. Su
   API key autentica las llamadas de n8n. La API REST respeta el mismo RBAC (el servicio no puede hacer
   lo que su rol no permita).

---

## 10. UI / templates

- **Sidebar**: nueva sección **"Inventario TI"** (`templates/element/sidebar/itam.php`) con links
  Activos, Consumibles, Categorías, Alertas — cada uno gateado por `can_view`. Badge de alertas abiertas.
- **Templates** siguiendo los esqueletos canónicos de SPI (ver `docs/design/`):
  - **Activos** — `index` con filtros (categoría, estado, responsable, sede) usando `.row-fact` + chips;
    `view` con ficha del activo + **historial de movimientos** + documentos/actas; `add/edit` formulario.
  - **Movimientos** desde la vista del activo (modales asignar/devolver/trasladar/préstamo/baja).
  - **Consumibles** — `index` con indicador de stock bajo; `view` con historial de entradas/salidas.
  - **Alertas** — `index` filtrable + acción resolver; widget en el dashboard.
- Reusa el sistema de diseño existente (`.spi-*`, átomos, `element('upload_doc_modal')` para actas).
  Sin CSS nuevo de fondo.

---

## 11. Roadmap de implementación (orden sugerido)

1. **Datos**: migraciones (tablas + FKs a `employees`/`operation_centers`), enums + constantes,
   entidades + tablas ORM (asociaciones, validaciones, finders).
2. **Servicios**: `AssetInventoryService`, `ConsumableStockService`, `AssetDocumentService` (+ pruebas).
3. **UI**: controllers web + templates (Activos, Consumibles, Categorías) + sidebar + RBAC sembrado.
4. **Alertas**: `AssetAlertService` + comando `itam_generate_alerts` + push n8n + UI de alertas.
5. **API**: `ApiKeyMiddleware` + controllers `Api/Itam/*` + rutas + usuario de servicio ITAM.

Cada fase es verificable de forma independiente (la UI funciona sin la API; la API reusa los servicios ya probados).

---

## 12. Decisiones de diseño (registro de las elecciones del brainstorming)

| Decisión | Elección | Por qué |
|---|---|---|
| Frontera del sistema | SPI = fuente de verdad + API; n8n = canal | El doc exige "ITAM no es la fuente única de verdad" |
| Movimientos | Log inmutable + estado del activo (no pipeline) | RN-09 (inmutable); "el agente registra, no aprueba" |
| Asignación | Responsable = empleado (null si disponible) + ubicación = `operation_centers` | Reusa catálogos existentes; cubre "quién" y "dónde" |
| Acceso n8n→SPI | API REST JSON con API key | Estándar máquina-a-máquina; toda escritura validada por SPI |
| Alertas | SPI calcula + persiste + push a n8n | SPI = fuente de verdad; visibles también en la UI |
| Permisos | RBAC estándar (sin pipeline_permissions) | Replica el esquema que ya usa cada módulo |
| Consumibles | Incluidos en el MVP | El control de tóners aporta valor desde el día 1 |
| IA en este spec | Sección de contexto + 3 refinamientos a la API | El workflow n8n es sub-proyecto aparte |

---

## 13. Sub-proyectos derivados (specs futuros)
1. **Agente ITAM en n8n** — intents, system prompt, catálogo de tools, confirmaciones, manejo de WhatsApp.
2. **OCR / visión artificial** — extracción de seriales/modelos/referencias desde fotos (RN-08).
3. **Predicción de consumo y analítica** — estimación de tóners, recomendaciones de compra, reportes.
