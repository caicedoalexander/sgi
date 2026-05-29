# Plan — Sub-paso de seguridad: enforcement de `canOperate` en las transiciones de Novelty

> Estado: **✅ EJECUTADO 2026-05-29** (commit `feat(seguridad): enforcement de canOperate en las transiciones de Novelty`). Cambio puro de código (servicio + atributos), sin BD. `php -l` limpio + 192/192 + revisión de seguridad adversarial (2 revisores) + revisión manual del código. **Hueco HIGH encontrado por la revisión adversarial y CERRADO**: el pre-save del header en `advanceGroup` hacía `patchEntity(getData())` crudo (mass-assignment de `pipeline_status`/`payment_status`/…); fix = gate `canOperate` + whitelist de campos editables. **Pendiente**: verificación manual por rol vía la UI de Permisos antes de producción (requiere login).
>
> _(Plan original abajo, conservado como referencia de diseño.)_
>
> _Corrección 2026-05-29: una versión previa de este plan incluía "seed / verificación de `pipeline_permissions` / mapeo rol→paso". **Eliminado** — fue un malentendido: no hay seed por defecto, todo lo configura el admin desde la UI._

## 1. El hueco real

**Novelty es el ÚNICO módulo de flujo que NO hace enforcement de `canOperate` en sus transiciones.** Invoice / Refund / PettyCash / PaymentScheduling llaman `denialReasonForAdvance` → `canOperate(role, pipeline, step)` dentro del servicio (`RefundPipelineService:185`, `PettyCashPipelineService:233/311`, `InvoicePipelineService:233`, `PaymentSchedulingPipelineService:134`). Novelty usa `canOperate` **solo para el display** del botón (`EmployeeNoveltiesController:522`); el enforcement real de `advance`/`reject`/`advanceGroup` pasa **solo** por `#[Permission(action:'edit')]` (tabla `permissions`, CRUD por módulo).

→ Hoy **un rol con `edit` en novedades puede avanzar/rechazar cualquier paso**, sin importar si la UI de Permisos de Pipeline le dio permiso de operar ese paso. Eso contradice el modelo del proyecto ("solo si tiene permiso de operar el paso puede hacerlo").

## 2. Modelo de autorización del proyecto (confirmado en código)

- **`pipeline_permissions`** (`canOperate(role, pipeline, step)`) autoriza **operar un paso**: avanzar/regresar la pieza, editar los campos de ese paso y ver su sección. **Admin-managed** vía UI; **sin seed**; **default deny** (sin fila → `false`, `PipelineAuthorizationService:41`); **sin bypass** salvo Admin.
- **Único bypass hardcodeado legítimo**: Admin (`AppController:139`, `AuthorizationService:102` con `ADMIN_BYPASS_MODULES = users, roles`). Auditoría 2026-05-29: **ningún servicio de flujo valida por rol exacto** (los `roleName` restantes son display en ViewModels; los `'Contabilidad'/'Tesorería'` son labels de estado).
- **El canon hace el enforcement en el SERVICIO**: `saveAndAdvance`/`advance`/`regress` → `denialReasonForAdvance`/`Regress` (`canOperate`) + `filterEntityData` (campos por paso). El atributo del controller es solo el gate grueso de módulo.

## 3. Estado actual de Novelty (gap exacto)

`NoveltyPipelineService` **ya tiene** `denialReasonForAdvance(:466)` (con `canOperate`) y `filterEntityData(:488)`, pero:
- `denialReasonForAdvance` se usa **solo para display** (`EmployeeNoveltiesController:522`), no para enforcement.
- `advance(:121)` no recibe `roleId`, no llama `denialReasonForAdvance`; el controller (`:843-849`) hace `patchEntity($novelty,$data)` **crudo, sin `filterEntityData`**.
- `reject`/`advanceGroup`: sin `canOperate` ni `filterEntityData`.

## 4. El arreglo (puro servicio + atributos, cero BD)

### 4.1 `advance` (individual, save+advance) — mantiene `#[Permission(edit)]`
Equivale al `edit`/`saveAndAdvance` del canon (Invoice/PettyCash `edit` también usan `#[Permission(edit)]` + enforcement en servicio).
- Nuevo `NoveltyPipelineService::saveAndAdvance(EmployeeNovelty $novelty, array $data, int $roleId, int $userId): ServiceResult`, en UNA transacción:
  1. `filterEntityData($data, $roleId, $novelty->pipeline_status)` → patch + save + `recordChanges` (los campos quedan filtrados por `canOperate` del paso).
  2. avanza solo si `denialReasonForAdvance($novelty, $roleId) === null` y `validateTransitionRequirements` vacío.
- El `advance($novelty,$userId)` actual se absorbe aquí (único caller: el controller).
- `EmployeeNoveltiesController::advance`: **mantiene `#[Permission(edit)]`**; elimina el `patchEntity` crudo; llama `saveAndAdvance($novelty, $this->request->getData(), (int)$user->role_id, $user->id)`.

### 4.2 `advanceGroup` (grupo, save+advance) — mantiene `#[Permission(edit)]`
Opera sobre `PIPELINE_LIQUIDATION_DOCS` (no `PIPELINE_NOVELTIES`); ambos pipelines existen en `STEPS_BY_PIPELINE` y los gestiona la UI de Permisos.
- Nuevo `denialReasonForAdvanceGroup($doc, $roleId)` → `canOperate(role, PIPELINE_LIQUIDATION_DOCS, $doc->pipeline_status)`.
- `saveAndAdvanceGroup($doc, $data, $roleId, $userId)` (o `advanceGroup` con denial + `filterEntityData` si el doc edita campos por paso — ver decisión §5.2).
- `NoveltyLiquidationDocsController::advanceGroup`: **mantiene `#[Permission(edit)]`**; pasa `roleId`; deja de hacer el save crudo.

### 4.3 `reject` (transición pura, terminal) — sí va a `#[PipelineAction]`
No guarda campos; análogo a `regressStatus` del canon.
- `EmployeeNoveltiesController::reject`: atributo → **`#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]`** (dinámico).
- Nuevo `denialReasonForReject($novelty, $roleId)` (`canOperate` del paso actual); `reject($novelty, $roleId, $userId, $obs)` lo checa al inicio (+ el guard de "ya rechazada").

> Con esto, las 3 transiciones de Novelty quedan gobernadas por `pipeline_permissions` (`canOperate`) igual que los otros 4 módulos. `roleId` se incorpora a las firmas (cierra también el residuo de la matriz de verbos para Novelty).

## 5. Microdecisiones del modelo (lo único que confirmar)
1. **`reject`** autoriza con `canOperate(rol, PIPELINE_NOVELTIES, paso_actual)` — quien puede operar el paso actual puede rechazar desde él. **[recomendado]**
2. **`advanceGroup`**: si el doc de liquidación edita campos del header por paso → `filterEntityData`; si no edita campos por paso → solo el denial `canOperate` (excepción documentada, como PaymentScheduling).

## 6. Pruebas (la verificación real, sin BD-seed)
- En la UI de Permisos de Pipeline, configurar **un rol no-admin** con solo ciertos pasos de Novedades/Documentos de liquidación marcados.
- Verificar: ese rol **opera solo los pasos marcados**; en los no marcados → avance/reject **bloqueado**, y los campos fuera de su paso **no se guardan** (`filterEntityData`).
- **Admin** sigue operando todo (bypass).
- `composer test` 192/192 + añadir tests de autorización para `advance`/`reject`/`advanceGroup` (rol con permiso de paso vs sin él).

## 7. Secuencia, rollback, riesgo
1. Servicio: `saveAndAdvance` (absorbe `advance`), `denialReasonForReject`, `denialReasonForAdvanceGroup`/`saveAndAdvanceGroup`; `roleId` en firmas. → `php -l` + tests.
2. Controllers: `advance`/`advanceGroup` mantienen `#[Permission(edit)]` + usan los nuevos métodos (sin save crudo) + pasan `roleId`; `reject` → `#[PipelineAction(PIPELINE_NOVELTIES)]` + `roleId`.
3. Prueba manual por rol (§6).
- **Rollback**: revertir el/los commits. **Sin migración ni cambios de datos.**
- **Riesgo: medio** (toca autorización), **acotado**: sin riesgo de lockout (la tabla la configura el admin, no este cambio) y sin hueco de save (se conserva `#[Permission(edit)]` + se gana `filterEntityData`).

## 8. Por qué NO es una excepción
Este cambio **alinea** Novelty al modelo común: enforcement de `canOperate` en el servicio + `#[Permission(edit)]` en el save+advance (igual que Invoice/Refund/PettyCash) y `#[PipelineAction]` solo en la transición pura (`reject`, como `regressStatus`). No se inventa nada nuevo ni se toca la BD.
