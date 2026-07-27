# Aprobación de área en lote para Reintegros y Legalización de Anticipos

**Fecha:** 2026-07-02
**Estado:** Diseño (revisado con `spi-design-reviewer`; pendiente de plan de implementación)
**Módulos:** Reintegros (`refunds`) · Legalización de Anticipos (`legalizations` / `advance_legalizations`)

---

## 1. Problema

Hoy, para vincular facturas a un **reintegro** o a la **legalización de un anticipo**, cada factura debe estar en estado `contabilidad`, lo que obliga a **aprobarlas una por una** (flujo de aprobadores individuales por factura hasta `contabilidad`) antes de poder agruparlas.

Estado actual verificado en código:

- **Reintegros** — `GroupedInvoiceService` lista/valida facturas tipo `Reintegro` en `pipeline_status = contabilidad`, sin vincular. El estado está **hardcodeado en dos métodos**: `getAvailableInvoices()` (`src/Service/GroupedInvoiceService.php:191`) y `validateGrouping()` (`:77`). El servicio es **compartido con Caja Menor**.
- **Anticipos** — los candidatos se filtran en `AdvancesController` (`:417-426`): `Legalización` (cualquier estado) **o** `Recibo de Caja` en `contabilidad`. El **enforcement real del vínculo** está en `AdvanceLegalizationService::linkInvoices` (`:138-147`), espejo exacto de esa condición. Para avanzar, `ValidacionState::validateAdvance` (`src/Service/Pipeline/Advance/State/ValidacionState.php:48-57`, regla MA-006) exige que **todas** las vinculadas estén en `contabilidad`.
- **Aprobación de una factura** (estado `aprobacion`) es **multi-aprobador**: `InvoiceApprovalService` asigna aprobadores, envía un link por correo a cada uno (`invoice_approvals` + tokens hasheados), y cuando **todos** aprueban marca `area_approval = Aprobada`. Con eso + `dian_validation = Aprobada` (gate en `Invoice\State\AprobacionState::validateAdvance:30-33`), la factura avanza a `contabilidad`.

## 2. Objetivo

Permitir **vincular facturas aún no aprobadas** (estado `aprobacion`) a un reintegro/anticipo, y **aprobar todo el grupo en un solo acto** de los aprobadores, avanzándolas juntas a `contabilidad`.

## 3. Decisiones tomadas (brainstorming)

| # | Decisión | Elección |
|---|----------|----------|
| 1 | Semántica de "aprobación masiva" | **Aprobación de área en lote**: un link cubre todas las facturas vinculadas; no factura por factura. |
| 2 | Quiénes aprueban | **Aprobadores a nivel de grupo**: se eligen una vez; cada aprobador recibe un link con todas las facturas. |
| 3 | Granularidad de la decisión | **Todo o nada**: el aprobador aprueba o rechaza el grupo completo. |
| 4 | Alcance documental | **Reintegros (todas) + Anticipos (ambos tipos: Recibo de Caja y Legalización)**. |
| 5 | Manejo del rechazo | **Regresa a `agrupacion`/`validacion`** para editar libremente y reenviar. |
| 6 | Composición del grupo | **Homogéneo**: solo facturas en `aprobacion`. |
| 7 | Arquitectura de aprobación | **Enfoque A — per-dominio**: tablas y servicios por módulo con una base compartida; no fusiona `invoice_approvals` ni `approval_tokens`. |

## 4. Diseño

### 4.1 Pipelines — nuevo estado `aprobacion` (estructura canónica incluida)

**Reintegro** (`App\Constants\Domain\Refund\PipelineStatus`):

```
agrupacion → aprobacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada
```

- Añadir `case APROBACION = 'aprobacion'`; `next()`: `AGRUPACION → APROBACION → CONTABILIDAD`; `previous()`: `CONTABILIDAD → APROBACION → AGRUPACION`; `label()`: `'Aprobación'`.
- **Crear `src/Service/Pipeline/Refund/State/AprobacionState.php`** y **registrarlo** en `RefundPipelineStateRegistry` (si falta, `get(APROBACION)` lanza índice indefinido). Es un State puro in-memory; su `validateAdvance` incluye el gate de aprobación del grupo (§4.3).

**Legalización de anticipo** (`App\Constants\Domain\Advance\PipelineStatus`):

```
validacion → aprobacion → revision_firmas → contabilidad → … → legalizada
```

- Añadir `case APROBACION = 'aprobacion'` entre `VALIDACION` y `REVISION_FIRMAS`; ajustar `next()/previous()/label()`.
- **`legalizations` es el coordinador OUTLIER documentado (excepción B):** `AdvanceLegalizationService` **no** usa `advance()/regress()` ni `enum::next()/previous()`, sino **verbos por outcome** (`moveToRevisionFirmas`, `markSigned`, …). Por tanto insertar el estado exige:
  1. **Crear `src/Service/Pipeline/Advance/State/AprobacionState.php`** y registrarlo en `AdvanceLegalizationPipelineStateRegistry` (`:26-48`).
  2. **Verbos nuevos de servicio** para `validacion → aprobacion` (armar/enviar aprobación) y `aprobacion → revision_firmas` (consolidar), integrados con `GroupApprovalService`.
  3. **Mover la regla MA-006** ("todas las vinculadas en `contabilidad`"): hoy vive en `ValidacionState::validateAdvance` y gatea la salida de `validacion`. Con `aprobacion` intercalado debe **reubicarse** a la validación de `aprobacion → revision_firmas` (el nuevo `AprobacionState::validateAdvance` de anticipos). ⚠️ La versión previa del spec afirmaba "sin cambiarla" — **es incorrecto**: es un refactor real de la precondición.

**Constantes y catálogo de steps:**

- `RefundConstants::STATUS_APROBACION` y `AdvanceConstants::STATUS_APROBACION` delegan al enum (no duplican valor).
- Actualizar los arrays de estado (§4.6).
- Añadir el step a `PipelineStepConstants::STEPS_BY_PIPELINE[refunds]` y `[legalizations]`, más `STEP_LABELS` (`src/Constants/PipelineStepConstants.php:87-108`). `MODULE_BY_PIPELINE` **no** cambia (`refunds→refunds`, `legalizations→advances` ya existen).
- Slug `aprobacion` (español sin acento), consistente con la convención de slugs de pipeline.

### 4.2 Modelo de datos (Enfoque A — per-dominio)

Dos tablas nuevas, espejo estructural de `invoice_approvals`:

**`refund_approvals`** — `id`, `refund_id` (FK → `refunds.id`), `user_id` (FK → `users.id`), `status`, `token_hash`, `token_expires_at`, `responded_at`, `observations`, `ip_address`, `user_agent`, `created`, `modified`.

**`advance_legalization_approvals`** — `id`, `advance_legalization_id` (FK → `advance_legalizations.id`), `user_id` (FK → `users.id`), + resto idéntico.

- **`status` en ESPAÑOL capitalizado**, replicando **exactamente** `InvoiceConstants::APPROVER_STATUS_*` (`Pendiente | Aprobada | Rechazada | Reemplazada`, `InvoiceConstants.php:53-56`). Es un **label visible** (se muestra el estado por aprobador en la UI) y la convención de slugs exige español capitalizado para estados de aprobación. ⚠️ La versión previa del spec proponía inglés "por consistencia con invoice_approvals" — era factualmente falso.
- Migraciones con `Migrations\BaseMigration`, guarda `hasTable()`, tipos de FK exactos (signed/unsigned).
- **No** se modifican `invoice_approvals` ni `approval_tokens`.

### 4.3 Servicios (backend)

**Base compartida** `App\Service\GroupApproval\GroupApprovalService` (abstracta), mecánica multi-aprobador parametrizada por tabla + FK + entidad: `assignApprovers`, `sendApprovalLinks`, `modifyApprovers` (motivo obligatorio), `getCurrentApprovals`, `getApprovalSummary`, `validateToken`, `processResponse` (con `SELECT … FOR UPDATE`), `areAllApproved`, `hasPendingApprovals`, y el manejo de rechazo. **No** toca `InvoiceApprovalService` (implementación nueva, no refactor del flujo de facturas).

**Concretos:** `RefundApprovalService` (tabla `refund_approvals`) y `AdvanceLegalizationApprovalService` (tabla `advance_legalization_approvals`).

**a) Gate no-bypasseable (crítico).** El avance del grupo desde `aprobacion` **debe bloquearse** si el grupo no tiene quórum (`areAllApproved(groupId) === false`):
- Reintegros: nueva razón de denegación en `RefundPipelineService::denialReasonForAdvance` (`:279-307`) + su chequeo en `advance` (`:318`).
- Anticipos: el chequeo vive en el nuevo `AprobacionState::validateAdvance` + el verbo de servicio `aprobacion → revision_firmas`.
- Sin este gate, avanzar `aprobacion → contabilidad` sería bypasseable (sin votos) — rompe la invariante central.

**b) Efecto de la aprobación completa (todos aprueban).**
1. En cada factura vinculada: `area_approval = Aprobada` + `area_approval_date`.
2. Marcar el grupo como "aprobado" → habilita el avance (gate de (a) satisfecho).
3. El operador pulsa **Avanzar** (acción explícita, §5). Al avanzar:
   - **Reintegro** (`aprobacion → contabilidad`): las facturas hijas pasan a invoice-`contabilidad` vía la propagación del coordinador (`RefundPipelineService::advance` `updateData:384-398`). **Ajustar** `updateData`/`childPipelineMap` (`:601-604`) para contemplar el nuevo estado (durante `aprobacion` las hijas están en invoice-`aprobacion`; al avanzar van a invoice-`contabilidad`).
   - **Anticipo** (`aprobacion → revision_firmas`): ⚠️ **dos ejes distintos**. El *leg* avanza a `revision_firmas`, pero las **facturas** deben pasar a invoice-`contabilidad`. Hoy `moveToRevisionFirmas` **solo valida** (MA-006), no mueve. Por tanto el verbo `aprobacion → revision_firmas` debe **mover explícitamente** cada factura del grupo a invoice-`contabilidad` (propagación leg→facturas nueva) antes de validar MA-006.

**c) Gate DIAN por-hija (cableado explícito).** El requisito `dian_validation = Aprobada` (gate en `Invoice\State\AprobacionState`) se mantiene. Como el avance de grupo no pasa por la `AprobacionState` individual de cada factura, hay que **invocar la validación por-hija explícitamente** (reusar `Invoice\State\AprobacionState::validateAdvance` o `InvoiceTransitionValidator` por factura) en lugar de un `updateAll` ciego; si alguna factura falla DIAN, el avance falla con error que lista las ofensoras. (Verificado: DIAN no depende del `document_type`; estos tipos ya cumplen el requisito en el flujo actual para llegar a `contabilidad`.)

**d) Rechazo (cualquier aprobador rechaza, vía link, sin sesión/rol).** `GroupApprovalService::processResponse` al recibir `reject`:
1. Invalida los tokens pendientes del grupo.
2. Registra el **motivo del aprobador** como observación/historial (`user_id` = aprobador que rechaza).
3. **Regresa el grupo** a `agrupacion` (reintegro) / `validacion` (anticipo) por una **ruta interna dedicada** que **no** pasa por `RefundPipelineService::regress` (`:563`) — este exige `roleId` con `canOperate` y motivo ≥10 chars, que un aprobador externo no tiene. La ruta interna omite el RBAC de operador (el rechazo del aprobador ES la autoridad).
4. Las facturas quedan en invoice-`aprobacion` con `area_approval` sin cambio (nunca se aprobaron).

### 4.4 Aprobación externa (link)

- Aprobadores = **usuarios del sistema** seleccionados en un dropdown (mismo modelo que aprobadores de factura).
- Nueva página externa bajo layout `external.php` que muestra **todas las facturas del grupo** + **Aprobar / Rechazar** (todo-o-nada) + observaciones.
- Tokens hasheados (SHA256) con TTL; consumo single-use por aprobador con `FOR UPDATE`.
- Quórum: **todos deben aprobar** para consolidar.

### 4.5 Vinculación (filtros — ambos extremos por módulo)

- **Reintegros:** parametrizar el estado requerido en `GroupedInvoiceService` (**nuevo parámetro de constructor, default `contabilidad`**) y aplicarlo en **ambos** `getAvailableInvoices` (`:191`) **y** `validateGrouping` (`:77`). `RefundPipelineService` pasa `aprobacion`. **Caja Menor** usa el default → **intacta**.
- **Anticipos:** cambiar **en conjunto** el query de candidatos en `AdvancesController` (`:417-426`) **y** el enforcement en `AdvanceLegalizationService::linkInvoices` (`:138-147`) → ambos tipos (`Legalización` + `Recibo de Caja`) en estado `aprobacion` y sin vincular. (Si solo se cambia el controller, `linkInvoices` hace no-op silencioso.)
- Grupo **homogéneo**: solo facturas en `aprobacion`.

### 4.6 UI / capa de vista

- **Constantes de arrays de estado** (para `stageIdx`, `pipeline-mini`, chips, index):
  - `RefundConstants::STATUSES` + `STATUS_LABELS` → insertar `aprobacion`/`'Aprobación'`.
  - `AdvanceConstants::PIPELINE_STATUSES` + variantes por `case_type` (`PIPELINE_STATUSES_EXACTO/FALTANTE/SOBRANTE`) + `STATUS_LABELS` → insertar `aprobacion`.
- **Presentation (anti-drift):** `RefundPresentation::STATUS_BADGES` y `AdvancePresentation::STATUS_BADGES` derivan de `PipelineColorMap::badgesFor(...)`. `PipelineColorMap` **ya** mapea `aprobacion` → `pill-warning-soft`/`is-warning` (`PipelineColorMap.php:37`, usado por facturas) → **no se toca el mapa**, y `PipelineColorConsistencyTest` seguirá verde. Prohibido literal inline en el `.php`.
- **ViewModel:** `RefundEditViewModel` y `AdvanceLegalizationViewModel` exponen los flags/badge del nuevo estado (`currentStatusBadge` derivado de Presentation; `PipelineEditFlags` si aplica).
- **Panel de aprobación** en el estado `aprobacion` del reintegro/anticipo: asignar aprobadores + "Enviar enlaces", estado por aprobador (Pendiente/Aprobada/Rechazada), "Modificar aprobadores" (motivo). Espejo del panel de facturas.

### 4.7 RBAC (seed de `pipeline_permissions`)

- Además de declarar el step en `PipelineStepConstants`, **sembrar** (migración) `pipeline_permissions` para el step `aprobacion` en `refunds` y `legalizations`, otorgándolo a los roles que hoy operan `agrupacion`/`validacion` (candidato: **Registro/Revisión** y/o **Contabilidad** — confirmar en el plan). Sin el seed, nadie tiene `canOperate(*, aprobacion)` y los registros quedan atascados; `bin/cake permissions_audit` no lo detecta (solo verifica "operar implica ver").
- Invariante "operar implica ver" se mantiene vía `PipelineViewCoercion` (marcar ≥1 step fuerza `can_view` del módulo mapeado).

## 5. Decisiones por defecto

- **Avance a `contabilidad`/`revision_firmas` = manual**: al aprobar todos, el operador pulsa "Avanzar" (consistente con el resto del pipeline).
- **Asignación/edición de aprobadores y edición del grupo:**
  - Los aprobadores se asignan y los links se envían **en el estado `aprobacion`**.
  - `addInvoices`/`removeInvoice` exigen estado inicial (`GroupedInvoiceService:136`, `isAgrupacion()`); por tanto **editar el grupo (agregar/quitar factura) requiere regresar a `agrupacion`/`validacion`**, lo que **invalida** los links enviados (hay que reenviar). Esto reconcilia la decisión #2 ("elegir aprobadores una vez") con los guards existentes y con el flujo de rechazo (§4.3d).
- **Aprobadores individuales:** una factura vinculada al grupo **no** usa el flujo individual; si tenía aprobaciones individuales activas al vincularse, se marcan `Reemplazada`, y su acción individual de "enviar enlaces" queda desactivada mientras esté vinculada.

## 6. Cambios de comportamiento (⚠️)

1. **Reintegros:** el agrupamiento parte de `aprobacion` (no `contabilidad`). Reintegros ya avanzados no se ven afectados.
2. **Anticipos:** las `Legalización` dejan de vincularse "en cualquier estado" (ahora exigen `aprobacion`); `Recibo de Caja` pasa de `contabilidad` a `aprobacion`.
3. **Interacción con RC↔Legalización (F1–F3, en `dev`) — resuelto en diseño:** el freeze del Recibo de Caja vinculado ocurre en invoice-`contabilidad` (`ReciboCajaDocumentTypePolicy::blocksAdvance:33-43`) y la promoción a `legalizada` la hace `LinkedInvoiceLegalizer` sobre facturas en `contabilidad` (`:34-40`). Con el nuevo flujo, la aprobación de grupo lleva el RC de `aprobacion` a `contabilidad`, **el mismo estado terminal-de-congelado que hoy** → F1–F3 siguen coherentes **siempre que** el verbo `aprobacion → revision_firmas` (anticipos) deje las facturas exactamente en invoice-`contabilidad` (§4.3b). Verificación explícita requerida en el plan: que el RC vinculado no intente avanzar solo tras la aprobación de grupo.
4. **Registros en vuelo:** el estado `aprobacion` se inserta antes de `contabilidad`; los ya en `contabilidad`+ no cambian. Los que estén en `agrupacion`/`validacion` pasan a usar el filtro nuevo (`aprobacion`). Aceptable en `dev`; validar que no haya grupos a medio armar dependientes del filtro viejo.

## 7. Criterios de aceptación

- Se pueden vincular facturas en estado `aprobacion` a un reintegro/anticipo; las que están en `contabilidad` u otros estados **no** aparecen como candidatas.
- Un operador asigna aprobadores al grupo y envía un link a cada uno; cada link muestra **todas** las facturas del grupo.
- Con **todos** los aprobadores en `Aprobada`, el operador puede avanzar; todas las facturas quedan `area_approval = Aprobada` y en invoice-`contabilidad`.
- Si **falta** algún voto, el avance está **bloqueado** (gate no-bypasseable).
- Si un aprobador **rechaza**, el grupo regresa a `agrupacion`/`validacion`, se registra el motivo, y los links quedan invalidados.
- Editar el grupo tras enviar links exige regresar al estado inicial e invalida los links.
- Caja Menor, facturas individuales y demás módulos **no** cambian de comportamiento.
- `bin/cake permissions_audit` en verde; `composer cs-check` limpio; suite `vendor/bin/phpunit` sin regresiones (baseline 808).

## 8. Testing

- **Unit:** `next()/previous()/label()` de ambos enums; `AprobacionState` (refund y advance) `validateAdvance` (gate de grupo + MA-006 reubicada); base `GroupApprovalService` (asignar, quórum, rechazo, `Reemplazada`, ruta interna de regresión); parámetro de estado en `GroupedInvoiceService` (ambos métodos).
- **Integración:** vincular → enviar links → aprobar todos → avanzar → invoice-`contabilidad` (+ propagación); voto faltante → avance bloqueado; rechazo externo → regresa; DIAN faltante → avance bloqueado con lista; modificar grupo → invalida links; RC vinculado post-aprobación queda congelado en `contabilidad`.
- Suite `vendor/bin/phpunit` (baseline 808); credenciales de test en `config/.env`.

## 9. Faseo sugerido (se detalla en el plan)

- **Fase 0:** base compartida `GroupApprovalService` + patrón de migración/tabla.
- **Fase 1:** Reintegros end-to-end (enum, State+registry, tabla+servicio, filtro doble, gate, RBAC seed, propagación hijas, UI, tests).
- **Fase 2:** Legalización de anticipos end-to-end (State+registry, verbos de servicio, reubicación MA-006, propagación leg→facturas, filtro doble, UI) + verificación de la interacción RC↔Legalización.

## 10. Fuera de alcance

- Caja Menor, Facturas individuales, Novedades, Programación de pagos.
- Fusión/unificación de los sistemas de tokens existentes (`invoice_approvals`, `approval_tokens`).
- Aprobación granular por factura dentro del link (se eligió todo-o-nada).
