# Cleanup post pipeline-permissions: bypass de Administrador y constantes huérfanas

## Contexto

Tras la migración a `pipeline_permissions` (commits `78fe0f9` … `67108cc`), quedaron dos tipos de residuos en el código:

1. **Constantes huérfanas** declaradas para el modelo previo de autorización por rol fijo (ej. `REGRESS_ROLE_BY_STATUS` en `PettyCashConstants` y `RefundConstants`, ya marcadas `@deprecated`).
2. **Bypass de `Administrador` redundante**, distribuido en ~25 puntos del código. Antes era el único atajo para que Admin operara cualquier flujo; ahora con `pipeline_permissions` y el `permissions` table, el bypass deja al rol Administrador como un caso especial implícito en código que contradice el principio "todo configurable vía RBAC".

## Objetivos

- **Eliminar constantes huérfanas** en `src/Constants/` (verificación caller-por-caller, vetos del usuario aceptados antes de borrar).
- **Reducir el bypass de Administrador** a los módulos `users` y `roles` exclusivamente. En cualquier otro módulo y en todos los pasos de pipelines, Admin pasa por las mismas tablas `permissions` y `pipeline_permissions` que cualquier otro rol.
- **No crear seeds**. La configuración de permisos para Administrador en los módulos no exentos será trabajo manual del usuario tras desplegar.
- **No tocar las listas de visibilidad por rol** (`ROLE_VISIBLE_STATUSES`, `Pipeline/State::getRoleVisibility()`, etc.). Se mantienen como están — la migración de visibilidad queda fuera de alcance.

## No-objetivos

- Migrar visibilidad de listados a `pipeline_permissions` (decisión explícita del usuario).
- Seedear permisos para el rol Administrador (decisión explícita del usuario).
- Cambiar la API pública de `AuthorizationService` o `PipelineAuthorizationService`.
- Refactorizar `Pipeline/State/*` ni `NoveltyPipelineService` más allá de eliminar el bypass de Admin.

## Diseño

### 1. Modelo de bypass de Administrador

Se introduce una constante declarativa en `AuthorizationService`:

```php
public const ADMIN_BYPASS_MODULES = ['users', 'roles'];
```

Cambios derivados:

- **`AuthorizationService::isAllowed()`**: el bypass `if ($roleName === ADMIN) return true;` se reemplaza por:
  ```php
  if ($roleName === self::ROLE_ADMIN && in_array($module, self::ADMIN_BYPASS_MODULES, true)) {
      return true;
  }
  ```
  Para cualquier otro módulo, Admin cae al lookup normal en `permissions`.

- **`AppController::_setUserPermissions()`**: deja de inyectar una matriz "todo true" para Admin. Llama siempre a `getPermissionsForRoleAsMatrix($roleId)`; luego, si el rol es Admin, hace **merge** con `[users => all true, roles => all true]` para que el sidebar siempre muestre estos dos módulos (aunque la BD no tenga las filas).

- **`AppController::_checkPermission()`**: el bypass `if ($roleName === ROLE_ADMIN) return true;` se elimina por completo. Queda apoyado en `AuthorizationService::isAllowed()` que ya tiene la regla central.

- **`PipelineAuthorizationService::canOperate()` y `getOperableSteps()`**: el bypass de Admin (`if ($roleName === RoleConstants::ADMIN) ...`) se elimina por completo. Los pipelines no son ni `users` ni `roles`. Admin sin filas en `pipeline_permissions` no podrá operar pipelines — el usuario lo configurará manualmente desde la UI de Roles ya construida en `67108cc`.

### 2. Limpieza en cascada de checks redundantes

Una vez `PipelineAuthorizationService::canOperate()` ya no tiene bypass de Admin, todo `if ($roleName !== RoleConstants::ADMIN && !$pipelineAuth->canOperate(...))` queda equivalente a `if (!$pipelineAuth->canOperate(...))` y se simplifica.

**Services afectados** (eliminar bypass redundante porque ya delegan a `pipelineAuth` o a `authService`):

- `InvoicePipelineService::canAdvance()` (línea 182), `canRegress()` (línea 232).
- `InvoiceFieldAccessPolicy::getEditableFields()` (línea 76), `getVisibleSections()` (línea 101), `filterEntityData()` (línea 144).
- `InvoiceTransitionValidator::filterErrorsForRole()` (línea 76).
- `NoveltyPipelineService::getEditableFields()` (línea 592), `getVisibleSections()` (línea 615), `canAdvanceFromStatus()` (línea 638).
- `PaymentSchedulingPipelineService` (líneas 74, 92, 143).
- `PettyCashService::canRegress()` (línea 451).
- `RefundService::canRegress()` (línea 463).

**Controllers afectados** (eliminar el `$roleName !== RoleConstants::ADMIN &&` extra del condicional):

- `InvoicePaymentsController.php` — 5 ocurrencias en líneas 57, 106, 153, 193, 238 (acciones de gestión de pagos).
- `LiquidationDocPaymentsController.php` — 3 ocurrencias.
- `RefundsController.php` — 2 ocurrencias (`canRegisterPayment`, `canAuthorizePayment`).
- `PettyCashRecordsController.php` — 2 ocurrencias.
- `NoveltyLiquidationDocsController.php` — 2 ocurrencias.
- `InvoicesController.php` — 3 ocurrencias (revisar caso a caso, algunas no usan pipelineAuth y siguen otra lógica).
- `EmailLogsController.php` — 1 ocurrencia (es para módulo, ya queda cubierta por el cambio en `AuthorizationService`).

**Aclaración importante:** `getRoleVisibility()` y `getAdvanceRoleVisibility()` en `Pipeline/State/*`, así como `ROLE_VISIBLE_STATUSES` y `LIQUIDATION_VISIBLE_STATUSES` en `NoveltyPipelineService`, **siguen incluyendo `RoleConstants::ADMIN`** y se mantienen sin cambios. La visibilidad de listados está fuera del alcance de este cleanup.

### 3. Limpieza de constantes huérfanas

Trabajo en dos pasos:

**Paso 3.1 — Auditoría (genera reporte, no toca código):**

Para cada constante pública en cada archivo de `src/Constants/`:
1. `grep -rn "ConstantName::CONSTANT_NAME" src/ templates/ config/Migrations/ bin/`
2. Si zero referencias fuera del propio archivo de constantes → candidata.
3. Documentar candidatas en una tabla con: archivo, constante, valor, motivo del borrado.

Candidatas pre-confirmadas:
- `PettyCashConstants::REGRESS_ROLE_BY_STATUS`
- `RefundConstants::REGRESS_ROLE_BY_STATUS`

Candidatas a verificar (basado en lectura previa):
- `NoveltyConstants::STATUS_FIRMAS_APROBACION` — alias backward-compat de `STATUS_REVISION_FIRMAS`.
- `NoveltyConstants::STATUS_PENDING`, `STATUS_APPROVED`, `STATUS_REJECTED` — aliases backward-compat.
- `NoveltyConstants::STATUSES` — alias de `ALL_STATUSES`.
- Cualquier otra constante sin uso fuera de su archivo.

**Reglas de seguridad:**
- Migraciones legacy ejecutadas en `config/Migrations/*.php` **no cuentan como uso vivo**.
- Constantes referenciadas únicamente en su propio archivo (vía `self::X`) o solo en docblocks → huérfanas.

**Paso 3.2 — Confirmación + borrado:**

El reporte se entrega al usuario. El usuario veta candidatas que quiera conservar. Se borran las restantes en un commit aparte.

## Orden de implementación

Cada tarea es atómica y se commitea por separado.

1. **Auditoría de constantes** (lectura). Genera tabla con candidatas. Salida = lista para revisión del usuario. No modifica código.
2. **Borrar constantes confirmadas.** Tras OK del usuario sobre la tabla, eliminar las constantes vetadas. Verificar `composer cs-check` y `php -l` sobre archivos tocados. Commit: `chore(cleanup): eliminar constantes huérfanas tras pipeline-permissions`.
3. **Bypass de Admin: capa central.** Modificar `AuthorizationService::ADMIN_BYPASS_MODULES`, `AuthorizationService::isAllowed()`, `PipelineAuthorizationService::canOperate()` y `getOperableSteps()`. Commit: `refactor(auth): bypass de Administrador limitado a users y roles`.
4. **Bypass de Admin: AppController.** Ajustar `_setUserPermissions()` (merge selectivo) y `_checkPermission()` (eliminar bypass). Commit: `refactor(auth): AppController delega bypass a AuthorizationService`.
5. **Limpieza en cascada en services.** Eliminar bypass redundantes en los services listados en sección 2. Commit: `refactor(auth): eliminar bypass redundante de Admin en services de pipeline`.
6. **Limpieza en cascada en controllers.** Eliminar `$roleName !== RoleConstants::ADMIN &&` de los controllers listados en sección 2. Commit: `refactor(auth): eliminar bypass redundante de Admin en controllers`.

## Validación manual (sustituye tests)

El usuario levanta el server (`php bin/cake server`) y ejecuta:

| # | Como | En | Acción esperada |
|---|------|-----|-----------------|
| 1 | Admin sin filas adicionales en `permissions` ni `pipeline_permissions` | `/users` y `/roles` | Acceso permitido (sidebar visibles, index carga). |
| 2 | Admin sin filas adicionales | Cualquier otro módulo (ej. `/invoices`, `/petty-cash-records`, `/refunds`) | 403 / sidebar oculto. |
| 3 | Admin con todos los permisos puestos manualmente desde la UI de Roles | Index de cada módulo | Carga normal. |
| 4 | Admin con `pipeline_permissions` configurados desde la UI | Edit de factura, advance, regress, payment authorization | Funciona como rol normal. |
| 5 | Rol no-admin (Contabilidad) — sin cambios esperados | Sus operaciones habituales | Comportamiento idéntico al previo al refactor. |

**Verificación post-implementación de regresiones:**
- `grep -rn "RoleConstants::ADMIN\|ROLE_ADMIN" src/Controller/ src/Service/` debe devolver únicamente:
  - La definición y uso de `ADMIN_BYPASS_MODULES` en `AuthorizationService`.
  - Las visibility lists declaradas como excepción explícita: `Pipeline/State/*::getRoleVisibility()`/`getAdvanceRoleVisibility()`, `NoveltyPipelineService::ROLE_VISIBLE_STATUSES`, `LIQUIDATION_VISIBLE_STATUSES`.
  - Callsites donde `RoleConstants::ADMIN` se usa como identidad del actor en flows internos (p. ej. `InvoiceApprovalStrategy`), no como bypass de control de acceso.
- `composer cs-check` debe pasar.
- `php -l` sobre cada archivo tocado debe pasar.

## Riesgos y mitigación

| Riesgo | Mitigación |
|--------|------------|
| Tras el deploy, Admin pierde acceso a módulos/pipelines hasta configurar permisos. | Anotar en commit/changelog que el rol Administrador requiere configuración manual de permisos en cualquier módulo distinto a users/roles y en todos los pipelines. Acción consciente del usuario. |
| Una constante borrada tiene un caller que el `grep` no detectó (ej. uso dinámico vía variable). | Búsqueda en todos los directorios relevantes (`src/`, `templates/`, `config/`, `bin/`) excluyendo el propio archivo de definición. `composer cs-check` y `php -l`. Si surge regresión, revertir el commit aislado de constantes. |
| Algún check de bypass que no detectamos en el inventario inicial. | Grep final post-implementación con whitelist explícita de los lugares legítimos donde sobrevive `RoleConstants::ADMIN`. |
| `InvoiceFieldAccessPolicy` removerle el bypass deja a Admin sin acceso a campos editables si no tiene `pipeline_permissions`. | Comportamiento aceptado por la decisión "no hago seed". Documentado en validación manual #2. |

## Out of scope

- Migración de `ROLE_VISIBLE_STATUSES`, `LIQUIDATION_VISIBLE_STATUSES` y `Pipeline/State::getRoleVisibility()`/`getAdvanceRoleVisibility()` a `pipeline_permissions`.
- Cualquier cambio a la UI de Roles (ya construida en commit `67108cc`).
- Seeds de datos para el rol Administrador.
- Refactor del modelo de `permissions` o `pipeline_permissions`.
