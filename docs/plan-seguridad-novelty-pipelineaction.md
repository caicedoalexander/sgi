# Plan — Sub-paso de seguridad: autorización por-paso en las transiciones de Novelty

> Estado: **PLAN** (no ejecutado). Requiere decisiones (§5) + verificación de datos (§6) + prueba manual (§7) antes de tocar código. Generado 2026-05-29.
>
> Reemplaza la redacción imprecisa del ítem diferido ("migrar transiciones a `#[PipelineAction]`"). Tras verificar el canon, la migración correcta **NO** es voltear todo a `#[PipelineAction]` — ver §2.

## 1. Objetivo y riesgo

Las 3 transiciones de Novelty (`advance`, `reject`, `advanceGroup`) **no aplican autorización por-paso ni filtrado de campos** a nivel servicio; dependen solo del gate CRUD `#[Permission(action:'edit')]`. Eso significa que **cualquier rol con permiso `edit` sobre novedades puede avanzar/rechazar desde cualquier paso**, sin importar si su rol es el dueño de ese paso (p. ej. Contabilidad avanzando desde el paso de RRHH). El objetivo es cerrar ese hueco **replicando el modelo del canon**, no inventando uno nuevo.

## 2. El canon REAL (verificado en Invoice / Refund / PettyCash)

La seguridad **por-paso NO vive en el atributo del controller** — vive en el **servicio**:

| Tipo de acción | Atributo del controller | Seguridad por-paso (servicio) |
|---|---|---|
| `edit` (save **+** avance, vía `saveAndAdvance`) | `#[Permission(action:'edit')]` | `filterEntityData($data,$roleId,$status)` (campos por rol/paso) **+** `denialReasonForAdvance($e,$roleId)` → `canOperate(role,pipeline,step)` |
| Transición **pura** (`regressStatus`, Refund `advanceStatus`) | `#[PipelineAction(pipeline)]` *(dinámico, sin step)* | el servicio llama `denialReason*` internamente |
| Acción atada a un **paso fijo** (pagos: register/authorize/verify) | `#[PipelineAction(pipeline, step: X)]` | gate automático `canOperate` por `step` |

Evidencia: `InvoicesController:266 edit→#[Permission(edit)]`, `:431 regressStatus→#[PipelineAction]`; `RefundsController:311 edit→#[Permission(edit)]`, `:475 advanceStatus→#[PipelineAction]`, `:539/568/593 pagos→#[PipelineAction(step)]`; `PettyCashRecordsController:247 edit→#[Permission(edit)]`, `:387/416/441 pagos→#[PipelineAction(step)]`. `InvoicePipelineService::saveAndAdvance:221` aplica `filterEntityData`, `:233` `denialReasonForAdvance`.

**Conclusión:** el `edit`/`saveAndAdvance` se queda en `#[Permission(edit)]`; `#[PipelineAction]` es solo para transiciones puras o atadas a un paso.

## 3. El hueco real de Novelty (no es el atributo)

`NoveltyPipelineService::denialReasonForAdvance(:466)` y `filterEntityData(:488)` **ya existen**, pero:
- `denialReasonForAdvance` se usa **solo para display** (`EmployeeNoveltiesController:522`), no para enforcement.
- `EmployeeNoveltiesController::advance(:830)` hace `patchEntity($novelty, $data)` + save **crudo, sin `filterEntityData`**, y llama `advance($novelty, $userId)` **sin `roleId` ni `denialReasonForAdvance`**.
- `reject` y `advanceGroup` no tienen ningún check de servicio.

→ El arreglo es **mover la seguridad al servicio** (igual que el canon), **no** cambiar el atributo en las acciones de save+advance.

## 4. Diseño corregido (canon-alineado)

### 4.1 `advance` (individual, save+advance) — mantiene `#[Permission(edit)]`
Es el equivalente al `edit`/`saveAndAdvance` del canon.
- Nuevo `NoveltyPipelineService::saveAndAdvance(EmployeeNovelty $novelty, array $data, int $roleId, int $userId): ServiceResult` que, en UNA transacción (espejo de `InvoicePipelineService::saveAndAdvance`):
  1. `$filtered = $this->filterEntityData($data, $roleId, $novelty->pipeline_status)`.
  2. `patchEntity($filtered)` + save + `historyService->recordChanges()`.
  3. `if ($this->denialReasonForAdvance($novelty, $roleId) === null && validateTransitionRequirements(...) vacío)` → setear `getNextStatus()` + save + `recordStatusChange()`.
  4. Si no puede avanzar, guarda igual y devuelve warnings (no error) — como Invoice.
- El método `advance($novelty, $userId)` actual se **absorbe** dentro de `saveAndAdvance` (su único caller es el controller). Conservar `getNextStatus(object,$type)` (divergencia legítima por tipo).
- `EmployeeNoveltiesController::advance`: **mantiene `#[Permission(edit)]`**; elimina el `patchEntity`/save crudo (`:843-849`); llama `saveAndAdvance($novelty, $this->request->getData(), (int)$user->role_id, $user->id)`.

### 4.2 `advanceGroup` (grupo, save+advance) — mantiene `#[Permission(edit)]`
Opera sobre `PIPELINE_LIQUIDATION_DOCS` (no `PIPELINE_NOVELTIES`).
- Nuevo `denialReasonForAdvanceGroup($doc, $roleId)` con `canOperate($ctx, PIPELINE_LIQUIDATION_DOCS, $doc->pipeline_status)`.
- Nuevo `saveAndAdvanceGroup($doc, $data, $roleId, $userId)` (o añadir `filterEntityData` + denial al `advanceGroup` existente). **Verificar** que `NoveltyFieldAccessPolicy` cubre los campos editables del doc de liquidación; si el grupo no edita campos del header por paso, basta el denial + `canOperate` (documentar como en PaymentScheduling).
- `NoveltyLiquidationDocsController::advanceGroup`: **mantiene `#[Permission(edit)]`**; mueve/filtra el save; pasa `roleId`.

### 4.3 `reject` (transición pura, terminal) — sí va a `#[PipelineAction]`
No guarda campos; es el único análogo a `regressStatus` del canon.
- `EmployeeNoveltiesController::reject`: atributo → **`#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]`** (dinámico).
- Nuevo `denialReasonForReject($novelty, $roleId)`; `reject($novelty, $roleId, $userId, $obs)` lo checa al inicio (+ el guard de "ya rechazada" existente).

## 5. Decisiones requeridas antes de ejecutar
1. **RBAC de `reject`**: recomendado `canOperate(rol, PIPELINE_NOVELTIES, paso_actual)` — quien puede operar el paso actual puede rechazar desde él. (Alternativa: permiso dedicado solo para el aprobador.)
2. **`advanceGroup`**: ¿el doc de liquidación edita campos del header por paso? Si **sí** → `saveAndAdvanceGroup` con `filterEntityData`. Si **no** → solo denial + `canOperate` (excepción documentada, como PaymentScheduling).
3. **Firma de `advance`**: al absorberse en `saveAndAdvance(novelty, data, roleId, userId)`, queda la firma canónica con `roleId` (cierra el residuo de la matriz de verbos para Novelty).

## 6. 🔴 Verificación de datos OBLIGATORIA (pre-ejecución)
Al empezar a **enforcing** `denialReasonForAdvance`/`Reject`/`AdvanceGroup` (que usan `canOperate` → tabla `pipeline_permissions`), si faltan filas se **bloquea TODA transición de novedades** (lockout).
- Query: `SELECT role_id, pipeline, step, can_operate FROM pipeline_permissions WHERE pipeline IN ('novelties','liquidation_docs') ORDER BY role_id, step;`
- Confirmar que cada (rol dueño de paso) tenga `can_operate=1` en su paso, replicando lo que hoy concede `#[Permission(edit)]`.
- Si faltan → **migración/seed de datos** (no de esquema) ANTES de desplegar el cambio de código.

## 7. Pruebas (seguridad — no negociable)
Manual, por rol, en cada transición (individual + grupo + reject):
- Rol **dueño del paso** → avanza/rechaza OK; sus campos editables se guardan.
- Rol **NO dueño del paso** (pero con `edit`) → el avance/reject se **bloquea** (`denialReason*`), y `filterEntityData` **descarta** los campos que no le corresponden (no se guardan).
- Rechazo desde distintos pasos según la decisión §5.1.
- `composer test` 192/192; añadir tests de autorización para las 3 acciones (rol autorizado vs no autorizado).

## 8. Secuencia y rollback
1. Servicio: `saveAndAdvance` (+absorber `advance`), `denialReasonForReject`, `denialReasonForAdvanceGroup`/`saveAndAdvanceGroup`; firmas con `roleId`. → `php -l` + tests.
2. Controllers: `advance`/`advanceGroup` mantienen `#[Permission(edit)]` + usan los nuevos métodos (sin save crudo); `reject` → `#[PipelineAction(PIPELINE_NOVELTIES)]` + pasa `roleId`.
3. **Datos**: verificar/seed `pipeline_permissions` (§6).
4. Prueba manual por rol (§7).
5. Commit(s): servicio / controllers+atributo (+ migración de datos si aplica).
- Rollback: revertir el/los commits restaura el comportamiento previo; el seed de `pipeline_permissions` es aditivo (no rompe nada si se conserva).

## 9. Riesgo
**Medio-alto** — único cambio que toca autorización. Modos de fallo cubiertos: (a) lockout por datos faltantes → §6; (b) hueco de save sin gate → **no aplica** porque `advance`/`advanceGroup` conservan `#[Permission(edit)]` (el gate se queda) y además ganan `filterEntityData`. **No ejecutar sin §5 + §6 + §7.**

## 10. Nota sobre el ítem diferido del doc de auditoría
El ítem original decía "migrar transiciones a `#[PipelineAction]`". Es impreciso: solo `reject` (pura) va a `#[PipelineAction]`. `advance`/`advanceGroup` (save+advance) **se quedan en `#[Permission(edit)]`** y la seguridad se añade en el servicio (`filterEntityData` + `denialReason*`), tal como el canon Invoice/Refund/PettyCash.
