# Diseño: Pipeline completo de Novedades

**Fecha:** 2026-03-13
**Estado:** Validado, listo para implementar

---

## Resumen

Reemplazar el sistema simple `pendiente/aprobado/rechazado` de novedades por un pipeline de 6 etapas análogo al de facturas, con agrupación de novedades bajo un Documento de Liquidación a partir de Contabilidad.

---

## Flujo del pipeline

```
registro → rrhh → contabilidad → firmas_aprobacion → gdp → tesoreria → pagada
                                                                      → rechazada (cualquier etapa)
```

Cada tipo de novedad tiene flags que determinan qué etapas se saltan automáticamente. El `NoveltyPipelineService` calcula el siguiente estado consultando esos flags.

---

## Arquitectura general

El pipeline vive en `employee_novelties.pipeline_status`. A partir de Contabilidad, las novedades con el mismo número de liquidación se agrupan bajo un `novelty_liquidation_docs`, que almacena los datos compartidos de las etapas 3–6.

**Regla clave:** Una novedad con `liquidation_doc_id` no puede avanzar individualmente. Solo avanza como parte del grupo desde `NoveltyLiquidationDocs`.

**Avance grupal:** `NoveltyPipelineService::advanceGroup()` actualiza el `pipeline_status` de todas las novedades miembro y el documento de liquidación en una sola transacción.

---

## Cambios en base de datos

### `novelty_types` — nuevas columnas de configuración

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `requires_rrhh` | boolean | true | La etapa RRHH aplica |
| `requires_firmas` | boolean | true | La etapa Firmas y Aprobación aplica |
| `requires_gdp` | boolean | true | La etapa GDP aplica |
| `requires_tesoreria` | boolean | true | La etapa Tesorería aplica |
| `show_start_date` | boolean | true | Mostrar fecha de inicio |
| `show_end_date` | boolean | true | Mostrar fecha de fin |
| `show_permission_date` | boolean | true | Mostrar fecha de permiso |
| `show_schedule_type` | boolean | true | Mostrar tipo de horario (días/horas) |
| `uses_custom_name` | boolean | false | Campo de texto libre en vez de select de empleado |
| `is_massive` | boolean | false | Multi-selección de empleados (ej: Horas Extras Masivo) |

### `employee_novelties` — modificaciones

| Columna | Cambio |
|---|---|
| `status` | Renombrar a `pipeline_status` string(30). Valores: registro, rrhh, contabilidad, firmas_aprobacion, gdp, tesoreria, pagada, rechazada |
| `employee_id` | Pasa a nullable (null cuando `is_massive = true`) |
| `passes_payroll` | Nueva — boolean — RRHH: "Pasa a Nómina" |
| `rrhh_by` | Nueva — int FK→users — RRHH: "Registrado Por" |
| `liquidation_doc_id` | Nueva — int nullable FK→novelty_liquidation_docs |
| `custom_name` | Nueva — string(255) nullable — nombre libre cuando `uses_custom_name = true` |

Campos existentes que se conservan: `filing_date`, `permission_date`, `schedule_type`, `start_date`, `end_date`, `start_time`, `end_time`, `is_paid`, `reason`, `registered_by`, `employee_signature`, `coordinator_signature`, `observations`.

### Nueva tabla `novelty_massive_employees`

Junction para novedades de tipo masivo.

| Columna | Tipo |
|---|---|
| `id` | int PK |
| `novelty_id` | int FK→employee_novelties |
| `employee_id` | int FK→employees |

### Nueva tabla `novelty_liquidation_docs`

Registro grupal que almacena los datos compartidos de Contabilidad → Tesorería.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | int PK | |
| `liquidation_number` | string(50) UNIQUE | Código/radicado del documento |
| `period` | string(30) | primera_quincena, segunda_quincena, cierre_nomina |
| `document_date` | date | |
| `performed_by` | int FK→users | "Realizado Por" (rol Contabilidad) |
| `passes_for_payment` | boolean nullable | GDP: "Pasa para pago" |
| `payment_status` | string(20) nullable | Tesorería: pagado, pendiente, na |
| `payment_date` | date nullable | |
| `created_by` | int FK→users | |
| `created` | datetime | |
| `modified` | datetime | |

### Nueva tabla `novelty_liquidation_signatures`

Firmas por documento de liquidación (se recogen una sola vez para todo el grupo).

| Columna | Tipo |
|---|---|
| `id` | int PK |
| `liquidation_doc_id` | int FK→novelty_liquidation_docs |
| `signer_type` | string(30) — contador, coordinador_admin, jefe_inmediato, trabajador |
| `signature_path` | string(255) nullable |
| `signed_by` | int nullable FK→users |
| `approved_at` | datetime nullable |

### Nueva tabla `novelty_observations`

Chat de observaciones, análogo al de facturas.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | int PK | |
| `novelty_id` | int nullable FK→employee_novelties | Etapas registro/rrhh |
| `liquidation_doc_id` | int nullable FK→novelty_liquidation_docs | Etapas contabilidad→tesoreria |
| `user_id` | int FK→users | |
| `message` | text | |
| `is_read` | boolean default false | |
| `created` | datetime | |

### Nueva tabla `novelty_documents`

Soportes por estado, mismo patrón que `invoice_documents`.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | int PK | |
| `novelty_id` | int nullable FK→employee_novelties | Soportes etapas registro/rrhh |
| `liquidation_doc_id` | int nullable FK→novelty_liquidation_docs | Soportes etapas contabilidad→tesoreria |
| `pipeline_status` | string(30) | En qué etapa fue subido |
| `file_path` | string(255) | |
| `file_name` | string(255) | |
| `file_size` | int | |
| `mime_type` | string(100) | |
| `uploaded_by` | int nullable FK→users | |
| `created` | datetime | |

---

## Servicios

### `NoveltyPipelineService`

Servicio principal, análogo a `InvoicePipelineService`.

```
getNextStatus(novelty): string
    Lee novelty_type flags y calcula el siguiente estado saltando etapas inactivas.

advance(novelty, user): array errors
    Avance individual. Bloqueado si novelty->liquidation_doc_id no es null.
    Llama validateTransition() antes de avanzar.

advanceGroup(liquidationDoc, user): array errors
    Avanza TODAS las novelties del grupo en una transacción DB.
    Actualiza pipeline_status de cada miembro usando getNextStatus().

validateTransition(novelty, fromStatus): array errors
    Por etapa:
      rrhh → contabilidad:      passes_payroll requerido
      contabilidad → siguiente:  liquidation_doc_id obligatorio
      firmas → gdp:             todas las firmas requeridas deben estar presentes
      gdp → tesoreria:          passes_for_payment requerido
      tesoreria → pagada:       payment_status requerido; payment_date si status='pagado'

canAdvanceIndividually(novelty): bool
    false si novelty->liquidation_doc_id tiene valor.

assignToLiquidationDoc(novelty, liquidationNumber, data, user): liquidationDoc|errors
    Busca el doc por liquidation_number, lo crea si no existe.
    Vincula la novedad y cambia pipeline_status a 'contabilidad'.

getVisibleFields(noveltyType, pipelineStatus): array
    Retorna qué campos mostrar según tipo y etapa.
```

### `NoveltyDocumentService`

Clon de `InvoiceDocumentService` con soporte para dos contextos:

```
uploadForNovelty(noveltyId, pipelineStatus, file, uploadedBy)      // etapas registro/rrhh
uploadForGroup(liquidationDocId, pipelineStatus, file, uploadedBy) // etapas contabilidad→tesoreria
deleteDocument(documentId, currentPipelineStatus): bool
getDocumentsByStatus(noveltyId): array         // agrupado por pipeline_status
getGroupDocumentsByStatus(liquidationDocId): array
```

### `NoveltyObservationService`

```
addToNovelty(noveltyId, userId, message): observation
addToGroup(liquidationDocId, userId, message): observation
markAsRead(noveltyId|liquidationDocId, userId): void
getUnreadCount(noveltyId|liquidationDocId, userId): int
```

---

## Controladores

| Controlador | Acciones |
|---|---|
| `EmployeeNoveltiesController` | index, view, add, edit (etapas registro/rrhh), advance, reject, uploadDoc, deleteDoc |
| `NoveltyLiquidationDocsController` | index, view, advanceGroup, addSignature, uploadDoc, deleteDoc |

---

## Vistas

### `EmployeeNovelties/index`
- Tabla: empleado/`custom_name`, tipo, badge de pipeline_status, fecha de radicado
- Filtros: pipeline_status, novelty_type_id
- Filas clickeables

### `EmployeeNovelties/add`
- Si `is_massive`: multi-select de empleados con Select2
- Si `uses_custom_name`: campo texto libre en vez del select de empleado
- Select de tipo agrupado; campos condicionales aparecen/desaparecen vía JS según flags del tipo
- Canvas de firma del trabajador

### `EmployeeNovelties/view`
- Barra de progreso del pipeline (6 etapas, adapta `pipeline_progress.php`)
- Campos de la etapa actual editables inline (solo si no está agrupada y rol corresponde)
- Si agrupada: mensaje + link al documento de liquidación; sin botón de avance individual
- Sección de soportes agrupados por etapa
- Chat de observaciones
- Botón Avanzar / Rechazar

### `NoveltyLiquidationDocs/index`
- Tabla: número de liquidación, período, estado, cantidad de novedades, fecha
- Filtro por pipeline_status

### `NoveltyLiquidationDocs/view`
- Encabezado con datos del grupo
- Barra de progreso desde `contabilidad`
- Lista compacta de novedades miembro con link a cada una
- Sección de firmas (visible en etapa `firmas_aprobacion`): 4 firmantes con canvas individual y badge de completado
- Sección de soportes por etapa
- Chat de observaciones del grupo
- Botón "Avanzar grupo"

### `NoveltyTypes/edit` (módulo existente)
- Nueva sección "Configuración del pipeline" con toggles para todos los flags

---

## Constantes a actualizar (`NoveltyConstants`)

```php
// Pipeline statuses
STATUS_REGISTRO          = 'registro'
STATUS_RRHH              = 'rrhh'
STATUS_CONTABILIDAD      = 'contabilidad'
STATUS_FIRMAS_APROBACION = 'firmas_aprobacion'
STATUS_GDP               = 'gdp'
STATUS_TESORERIA         = 'tesoreria'
STATUS_PAGADA            = 'pagada'
STATUS_RECHAZADA         = 'rechazada'

// Period options
PERIOD_PRIMERA_QUINCENA  = 'primera_quincena'
PERIOD_SEGUNDA_QUINCENA  = 'segunda_quincena'
PERIOD_CIERRE_NOMINA     = 'cierre_nomina'

// Payment statuses
PAYMENT_PAGADO           = 'pagado'
PAYMENT_PENDIENTE        = 'pendiente'
PAYMENT_NA               = 'na'

// Signer types
SIGNER_CONTADOR          = 'contador'
SIGNER_COORDINADOR_ADMIN = 'coordinador_admin'
SIGNER_JEFE_INMEDIATO    = 'jefe_inmediato'
SIGNER_TRABAJADOR        = 'trabajador'
```
