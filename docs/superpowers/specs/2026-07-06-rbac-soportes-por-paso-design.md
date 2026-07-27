# Gate de soportes por paso de pipeline — Diseño

- **Fecha:** 2026-07-06
- **Módulos:** Invoices (cubre Anticipos), PettyCashRecords, PaymentSchedulings, NoveltyLiquidationDocs, NoveltyDocuments
- **Estado:** Aprobado (pendiente de plan de implementación)
- **Revisión:** validado por `spi-design-reviewer` (hallazgos incorporados)

## 1. Problema

Adjuntar un soporte a un registro de un módulo de flujo exige hoy un permiso **CRUD de módulo**
(`can_create` / `can_edit` / `can_delete`), cuando conceptualmente es una operación **del paso del
pipeline**. Un rol que opera un step (Tesorería, Contabilidad, Contador…) puede necesitar añadir un
soporte durante el flujo **sin** tener permiso para *crear* el registro.

Evidencia del criterio inconsistente actual (subida de soportes):

| Controlador (subir soporte) | Atributo actual | Gate efectivo |
|---|---|---|
| `InvoicesController::uploadDocument` (`:660`) | `#[Permission(action:'add')]` | `can_create` |
| `PettyCashRecordsController::uploadDocument` (`:640`) | `#[Permission(action:'add')]` | `can_create` |
| `PaymentSchedulingsController::uploadDocument` (`:470`) | `#[Permission(action:'add')]` | `can_create` |
| `NoveltyLiquidationDocsController::uploadDocument` (`:359`) | `#[Permission(action:'add')]` | `can_create` |
| `NoveltyDocumentsController::upload` (`:25`) | `#[Permission(action:'edit')]` | `can_edit` |
| `RefundsController::uploadDocument` (`:851`) | `#[PipelineAction(refunds)]` dinámica | `canOperateStep(rol, status)` |

El mapeo `add → can_create` está en `AuthorizationService:123`. Conviven **tres** criterios distintos.

## 2. Objetivo

Unificar la autorización de **gestionar soportes** (subir / borrar / reemplazar) en los módulos de flujo
hacia el gate por paso de pipeline, replicando el patrón que **Refunds ya estableció**
(`RefundsController::_documentGate` → `RefundActionPolicy::canOperateStep`).

Resultado: la gestión de soportes se autoriza por "¿puede este rol operar el paso actual del registro?",
contra la tabla `pipeline_permissions` del **pipeline correcto de cada módulo**, no por el CRUD del módulo.

## 3. Alcance

### Dentro — 12 acciones en 5 controladores

| Controlador | Acciones | Pipeline de autorización | Atributo hoy → destino |
|---|---|---|---|
| `InvoicesController` (cubre Anticipos) | `uploadDocument` (`:660`), `deleteDocument` (`:729`) | `invoices` | `add`/`delete` → `#[PipelineAction(pipeline: PIPELINE_INVOICES)]` |
| `PettyCashRecordsController` | `uploadDocument` (`:640`), `deleteDocument` (`:711`) | `petty_cash` | `add`/`delete` → `#[PipelineAction]` |
| `PaymentSchedulingsController` | `uploadDocument` (`:470`), `deleteDocument` (`:528`) | `payment_schedulings` | `add`/`delete` → `#[PipelineAction]` |
| `NoveltyLiquidationDocsController` | `uploadDocument` (`:359`), `deleteDocument` (`:531`), `uploadLiquidationDocument` (`:420`), `updateLiquidationDocument` (`:466`) | **`liquidation_docs`** | `add`/`delete`/`add`/`edit` → `#[PipelineAction]` |
| `NoveltyDocumentsController` | `upload` (`:25`), `delete` (`:77`) | `novelties` | `edit`/`delete` → `#[PipelineAction]` |

> Los slugs/constantes exactos se toman de `PipelineStepConstants` en el plan.
> `updateLiquidationDocument` conserva su restricción de dominio por `allowedStatuses` (`:473-479`), que
> es ortogonal al gate RBAC.

### Fuera

- **Refunds** — ya migrado; es la referencia de patrón.
- **Advances** — sin acción propia de soportes. Confirmado por el reviewer: `AdvancesController` no tiene
  `uploadDocument`; `Advances::edit` redirige a `Invoices::edit` (`AdvancesController.php:406-410`) y los
  soportes del Anticipo se suben por `Invoices::uploadDocument` (Anticipo = Invoice, gate contra pipeline
  `invoices`). El documento de relación de la legalización ya está gateado por
  `#[PipelineAction(PIPELINE_LEGALIZATIONS)]` + `canUploadRelationDocument` (`AdvancesController.php:548-556`).
- **Employees / Assets** — módulos de catálogo/inventario, no de flujo.

## 4. Diseño del gate (método privado por controlador)

Decisión: **réplica fiel de Refunds** (método privado por controlador), no un trait compartido ni gate
en la capa de servicio. Se acepta la duplicación en 5 sitios como deuda menor conocida.

Cada controlador incorpora dos métodos privados calcados de `RefundsController`:

```
_documentGate($record, string $blockedActionLabel): ?Response
  1. si $record en estado terminal
        → 409 "No se puede {label} un soporte de un {registro} {estado terminal}."
  2. si el rol NO puede operar el paso actual (ver §5, pipeline por módulo)
        → 403 "No tiene permisos para gestionar soportes en este paso."
  3. else → null (continúa)

_documentGateError(string $msg, $recordId, int $statusCode): Response
  → JSON {success:false, error} con HTTP 4xx si es AJAX
  → redirect a 'edit' con Flash->error si es POST tradicional
```

Las acciones documentales:

1. Cambian su atributo de `#[Permission(...)]` a **`#[PipelineAction(pipeline: X)]` dinámica (sin `step`)**.
   Esto hace que `AppController::_applyAuthAttribute` **salte el gate CRUD** y delegue el enforcement al
   `_documentGate` inline (`AppController:249-254`).
2. Al inicio del cuerpo llaman a `$gate = $this->_documentGate($record, '<verbo>'); if ($gate) return $gate;`
   antes de tocar el `DocumentService`.

## 5. Autorización por paso — pipeline correcto por módulo

Punto crítico (hallazgo bloqueante del reviewer): **cada módulo autoriza contra su propio pipeline**.

- **Invoices** → pipeline `invoices`. `InvoiceActionPolicy` hoy solo tiene `_canOperate` **privado**
  (`InvoiceActionPolicy.php:111`). Se expone un método **público** `canOperateStep(int $roleId, string $step): bool`
  que lo envuelve. Terminal: `Invoice::isInFinalState()` (`Invoice.php:85`, cubre `pagada` **y** `legalizada`).
- **PettyCashRecords** → pipeline `petty_cash`. `PettyCashActionPolicy::canOperateStep` ya existe (`:31`).
  Terminal: `PettyCashRecord::isPagada()` (`:56`).
- **PaymentSchedulings** → pipeline `payment_schedulings`. `PaymentSchedulingActionPolicy::canOperateStep`
  ya existe (`:31`). Terminal: `PaymentScheduling::isPagada()` (`:19`).
- **NoveltyDocuments** (individual) → pipeline `novelties`. `NoveltyActionPolicy::canOperateStep` ya existe
  (`:35`) y usa `PIPELINE_NOVELTIES` — correcto para este controlador. Terminal:
  `EmployeeNovelty::isPaid()` / `isRejected()` (`:42`/`:37`).
- **NoveltyLiquidationDocs** (grupal) → pipeline **`liquidation_docs`**, NO `novelties`. **No** se puede
  reusar `NoveltyActionPolicy::canOperateStep` porque hardcodea `PIPELINE_NOVELTIES`
  (`NoveltyActionPolicy.php:39`), que autorizaría contra la tabla `pipeline_permissions` del pipeline
  equivocado. La bandeja/avance de este controlador ya autorizan contra `liquidation_docs`
  (`NoveltyPipelineService::getVisibleLiquidationStatuses:556-562` y `denialReasonForAdvanceGroup:639-654`).
  El gate de soportes debe autorizar igual. El **plan** elige la forma exacta:
  - (preferida) reusar `NoveltyPipelineService::denialReasonForAdvanceGroup`, que ya encapsula
    terminal→409 (vía enum) + `!canOperate(liquidation_docs)`→403; o
  - exponer un método análogo `canOperateLiquidationStep(int $roleId, string $step)` que use
    `PIPELINE_LIQUIDATION_DOCS`.
  Terminal: enum `NoveltyPipelineStatus::isTerminal()` (ya usado en `NoveltyPipelineService.php:642`;
  cubre `pagada` **y** `rechazada`). `NoveltyLiquidationDoc` no tiene helper de entidad de terminal.

Cada controlador ya resuelve su `ActionPolicy` (o `NoveltyPipelineService`) vía `$container->get(...)` en
`initialize()` (Invoices `:69`, PaymentSchedulings `:47`, PettyCash `:54`, NoveltyLiquidationDocs `:51`).
El **único** trabajo de wiring es exponer `InvoiceActionPolicy::canOperateStep`. (El patrón `?? new` es
de services, no de controllers; no aplica aquí.)

## 6. Coherencia UI (visibilidad del botón "borrar")

Decisión: **criterio terminal-only, réplica fiel del canon Refunds** (no `canOperateStep`). El
enforcement duro es el gate por paso del §4/§5 (defensa en profundidad); el flag de UI solo oculta el
botón cuando el registro está cerrado.

En Refunds el flag es `!$record->isPagada()`, tanto en la plantilla (`templates/Refunds/edit.php:423`)
como en el payload JSON (`RefundsController.php:896`). Se replica en cada módulo:

- El cálculo del flag deja de depender del CRUD (`$userPermissions[module]['can_delete']` en plantillas /
  `_checkPermission(module,'delete')` en el JSON de `uploadDocument`, ej. `InvoicesController:700`) y pasa
  a `!<terminal>()` (`!isInFinalState()` / `!isPagada()` / `!enum::isTerminal()`), manteniendo el
  `documentService->canDeleteDocument(...)` de dominio que ya se aplica.
- El flag se **rutea por el `EditViewModel`** de cada módulo (canon de vista: VM → Presentation, el
  controller pasa `$viewModel`). Invoice ya expone `canDeleteDocuments` vía `PipelineEditFlags`
  (`InvoiceEditViewModel.php:54,109`); los demás módulos exponen un flag equivalente. No se dejan
  chequeos inline en las plantillas.

Consecuencia aceptada: un rol que ve el registro pero no opera el step verá el botón y recibirá 403 al
hacer click (igual que hoy en Refunds). El gate del §4 lo bloquea de forma dura.

## 7. Manejo de errores / estado terminal

- **Bloqueo primario uniforme:** el gate por step. En estados terminales ningún rol opera el paso → 403.
  Cubre todos los módulos aun sin helper de terminal.
- **Mensaje mejorado 409:** se antepone el chequeo terminal con HTTP 409 y mensaje claro ("registro
  cerrado"), usando el helper/enum de cada módulo (§5), igual que Refunds.
- **Formatos:** JSON `{success:false, error}` con HTTP 403/409 para AJAX; redirect a `edit` con
  `Flash->error` para POST tradicional.

## 8. Auditoría previa obligatoria (antes del rollout)

El cambio **no es puramente aditivo**: al pasar de gate CRUD a gate por paso, un rol que hoy tiene
`can_create`/`can_edit`/`can_delete` del módulo pero **no opera** el step actual **pierde** la capacidad
de gestionar soportes por esa vía. Es el comportamiento deseado, pero debe verificarse que ningún rol
operativo real dependa hoy de esa ruta.

Paso previo: auditar `permissions` vs `pipeline_permissions` (puede apoyarse en la lógica de
`bin/cake permissions_audit`) para listar, por módulo, los roles con CRUD-documental pero sin steps
operables, y confirmar con negocio que ninguno necesita adjuntar soportes. Documentar el resultado antes
de mergear.

## 9. Testing

Tests de integración por módulo (12 acciones):

1. **El fix** — rol que opera el step actual pero **sin** `can_create`/`can_edit`/`can_delete` del módulo
   → **puede** subir / reemplazar / borrar soportes.
2. Rol que **no** opera el step actual → **403**.
3. Registro en **estado terminal** → **409** (Invoice `pagada`+`legalizada`; PettyCash/PaymentScheduling
   `pagada`; liquidación `pagada`+`rechazada`; NoveltyDocuments `pagada`+`rechazada`).
4. **Regresión (encuadre correcto):** un rol que **opera el step actual** y antes podía, sigue pudiendo.
   Se documenta explícitamente que los roles con CRUD-pero-sin-step pierden la vía (intencional,
   respaldado por la auditoría del §8) — no se testea como "capacidad preservada".
5. **NoveltyLiquidationDocs autoriza contra `liquidation_docs`:** un rol con steps operables en
   `liquidation_docs` pero no en `novelties` **puede**; el inverso **no** (test anti-regresión del
   bloqueante).
6. Suite completa en verde (`vendor/bin/phpunit`, baseline ~843; ver memoria del proyecto).

## 10. Riesgos y trampas

- El cambio **no** toca `can_view`: el invariante "operar implica ver" (sidebar/bandeja) queda intacto y
  `bin/cake permissions_audit` sigue en verde.
- **Pipeline correcto por módulo** (§5): la trampa principal es autorizar liquidación contra `novelties`.
- Se preservan los slugs persistidos y constantes de pipeline sin cambios (solo cambia el atributo de la
  acción, no tablas ni slugs). `liquidation_docs` ≠ `novelties` ≠ `advances` son ejes distintos.
- Duplicación de `_documentGate`/`_documentGateError` en 5 controladores: **deuda menor aceptada**
  (extraíble a trait en el futuro; no se hace ahora para no arrastrar Refunds a un refactor).

## 11. Criterios de éxito

- Las 12 acciones documentales usan `#[PipelineAction]` dinámica + `_documentGate` (o delegación
  equivalente para liquidación).
- Un rol de flujo sin CRUD del módulo puede gestionar soportes en su paso; fuera de su paso o en estado
  terminal recibe 403/409.
- NoveltyLiquidationDocs autoriza contra `liquidation_docs`.
- Botones de borrado coherentes con el criterio terminal-only, ruteados por ViewModel.
- Auditoría del §8 documentada.
- `cs-check`, `phpunit` y `permissions_audit` en verde.

## 12. Resultado de la auditoría §8 (ejecutada 2026-07-06 contra BD de dev)

Query de §8 corrida por módulo (roles con `can_create`/`can_edit`/`can_delete` en `permissions`
pero **sin** filas `can_operate=1` en `pipeline_permissions` del pipeline correspondiente). **4 pares
(rol, módulo) afectados** — perderían la vía de gestión de soportes al pasar de gate CRUD a gate por paso:

| Rol | Módulo(s) | CRUD-doc actual | ¿Opera pasos? |
|---|---|---|---|
| **Directora Administrativa y Financiera** (role_id=2) | invoices, petty_cash, payment_schedulings | create+edit+delete | No |
| **Auxiliar Administrativa** (role_id=9) | employee_novelties (novedades) | create+edit | No |

`novelty_liquidation_docs`: 0 roles afectados. `refunds` ya estaba migrado (no auditado aquí).

**Decisión requerida (negocio) antes de mergear:** para cada par afectado, o bien (a) sembrar
`pipeline_permissions.can_operate` en los pasos donde ese rol deba adjuntar soportes (restaura la
capacidad bajo el nuevo criterio), o bien (b) confirmar que la pérdida es intencional (ese rol no debe
gestionar soportes durante el flujo). Nota: la Directora ya aparece en `permissions_audit` con `can_view`
de esos módulos pero sin pasos operables ("bandeja vacía"), lo que indica un rol de solo-lectura/dirección
en esos pipelines — coherente con que no opere pasos.

**Decisión tomada (2026-07-06):** aceptada como **intencional** (opción b). El nuevo criterio por paso es
el comportamiento deseado; esos roles no operan pasos, por lo que no deben gestionar soportes durante el
flujo (la capacidad la ejercen los roles que operan cada paso). **No** se siembran `pipeline_permissions`
adicionales. Feature apta para merge.
