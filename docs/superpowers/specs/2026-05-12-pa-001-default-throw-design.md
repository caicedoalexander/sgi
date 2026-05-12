# PA-001 — `default => throw` en `_actionToPermission`

**Fecha:** 2026-05-12
**Auditoría origen:** `docs/audits/permissions-audit-2026-05-11.md` (PA-001 🔴)
**Severidad:** Critical
**Esfuerzo estimado:** XS (~5 LoC netas + 7 mappings explícitos)
**Predecesor:** PA-003 cerrado en PR #4 (`1c73514`).

---

## Contexto

`AppController::_actionToPermission()` (`src/Controller/AppController.php:112-121`) traduce el nombre de la acción de CakePHP (`index`, `edit`, `advanceStatus`, …) a la acción semántica del modelo de permisos (`view`/`add`/`edit`/`delete`) que consulta el `AuthorizationService`. Hoy el `match` termina en:

```php
default => 'view',
```

Cualquier acción no listada explícitamente cae a `'view'`. Esto crea **over-permission silencioso**: si se agrega `mergeInvoices()` a `InvoicesController` y se olvida registrarla, cualquier rol con `invoices.can_view = true` (Tesorería, Contabilidad, Contador, Registro, Auxiliar de Personal, etc.) puede invocarla. El test manual en el navegador pasa con todos los roles probados porque todos tienen `view`. El bug solo aparece cuando alguien repara en que un rol que no debía pudo ejecutarla.

## Objetivo

Convertir el agujero silencioso en un fallo loud-and-clear: cualquier acción no mapeada explícitamente debe lanzar `LogicException` al primer hit, en lugar de degradar a `'view'`.

## No-objetivos

- No introducir atributos (`#[Permission]`, `#[NoAuthGate]`) — eso es PA-002.
- No reorganizar el `match` ni distribuir el mapeo por controlador — eso es PA-010.
- No tocar `controllerModuleMap` ni `$pipelineActions` per-controller.
- No agregar tests automatizados (política del proyecto, `CLAUDE.md` §Testing Policy).
- No agregar feature flag / soft-fallback. El objetivo es fallo loud-and-clear ya.

## Inventario de acciones huérfanas (auditado 2026-05-12)

Acciones públicas en controllers listados en `controllerModuleMap` que **hoy caen al `default => 'view'`** (no aparecen en el `match`, no están en `$pipelineActions` del controlador, no están en las exceptions de `_enforcePermission`).

| # | Controller::action | Naturaleza | Mapping propuesto | Riesgo si quedara como `'view'` |
|---|---|---|---|---|
| 1 | `AdvancesController::pendingLegalization` | listado de anticipos pendientes | `'view'` | bajo — comportamiento idéntico |
| 2 | `EmployeeNoveltiesController::resendApproval` | reenvía link de aprobación (mutación + email) | `'edit'` | **alto** — hoy cualquier rol con `employee_novelties.can_view` la dispara |
| 3 | `InvoicesController::overdue` | listado de facturas vencidas | `'view'` | bajo — comportamiento idéntico |
| 4 | `NoveltyLiquidationDocsController::uploadLiquidationDocument` | sube un doc de liquidación | `'add'` (consistente con `uploadDocument` en el match) | medio — hoy cualquier rol con `novelty_liquidation_docs.can_view` puede subir |
| 5 | `NoveltyLiquidationDocsController::updateLiquidationDocument` | actualiza un doc de liquidación existente | `'edit'` | medio — mismo riesgo que (4) |
| 6 | `PettyCashRecordsController::pending` | listado de cajas menores pendientes | `'view'` | bajo |
| 7 | `RefundsController::pending` | listado de reintegros pendientes | `'view'` | bajo |

**Cambio de comportamiento real** que esta PR introduce (sobre los roles actuales en BD):

- (2) `resendApproval`: roles que tengan `employee_novelties.can_view = true` y `employee_novelties.can_edit = false` perderán acceso. **A verificar antes del merge** que los roles que la usan hoy en producción (Asistente de Personal / Auxiliar de Personal / Coordinador) tienen `can_edit` del módulo.
- (4) `uploadLiquidationDocument`: idem para `novelty_liquidation_docs.can_add`.
- (5) `updateLiquidationDocument`: idem para `novelty_liquidation_docs.can_edit`.
- (1, 3, 6, 7): sin cambio de comportamiento — el resolver sigue devolviendo `'view'`, solo que ahora de forma explícita.

## Diseño técnico

### Cambio 1 — Añadir las 7 huérfanas al `match`

Editar `src/Controller/AppController.php:115-119`:

- Añadir a la rama `'view'`: `pendingLegalization`, `overdue`, `pending`.
- Añadir a la rama `'add'`: `uploadLiquidationDocument`.
- Añadir a la rama `'edit'`: `resendApproval`, `updateLiquidationDocument`.

Mantener el orden alfabético dentro de cada rama no es necesario (el match actual ya no lo respeta).

### Cambio 2 — Reemplazar el `default`

Reemplazar:

```php
default => 'view',
```

Por:

```php
default => throw new \LogicException(sprintf(
    "Action '%s' has no permission mapping in AppController::_actionToPermission(). " .
    'Register it explicitly in the match, add it to the controller\'s $pipelineActions, ' .
    'or extend the bypass in _enforcePermission().',
    $action,
)),
```

El mensaje cita exactamente las tres salidas que el dev tiene:
1. Mapear la acción en el `match`.
2. Declararla como acción de pipeline en `$pipelineActions` del controlador.
3. Añadir un bypass específico en `_enforcePermission` (como ya existe para `Users::login/logout` y `EmailLogs::retry`).

### Cambio 3 — Nada más

El refactor a atributos (`#[Permission]`/`#[PipelineAction]`/`#[NoAuthGate]`) vive en PA-002 y no se toca aquí. La intención de PA-001 es exclusivamente cerrar el agujero del `default` con la API actual.

## Validación manual

Sustituye la sección de tests automatizados según `CLAUDE.md` §Testing Policy. Pasos a ejecutar tras la PR:

1. **Smoke test del servidor**
   - `php bin/cake server` arranca sin warnings.
   - Login con admin → dashboard carga.

2. **Acciones huérfanas mapeadas a `'view'`** (`pendingLegalization`, `overdue`, `pending` x2)
   - Visitar cada una con un rol que tiene `can_view` del módulo → 200 (igual que antes).
   - Visitar cada una con un rol que NO tiene `can_view` del módulo → 403 (igual que antes).

3. **Acciones huérfanas con cambio real** (`resendApproval`, `uploadLiquidationDocument`, `updateLiquidationDocument`)
   - Con el rol que las usa en el flujo real (Asistente/Auxiliar de Personal según el caso) → siguen funcionando.
   - Con un rol que solo tenga `can_view` del módulo y NO `can_edit`/`can_add` → ahora reciben `403 Forbidden` (cambio de comportamiento).
   - **Pre-check obligatorio antes del merge**: leer la BD `permissions` y confirmar que los roles que disparan estas tres acciones en producción tienen el permiso CRUD correspondiente. Si falta alguno, sembrar el permiso en la misma PR (data migration) o documentar el ajuste manual.

4. **Confirmar que el throw dispara**
   - Añadir temporalmente una acción `dummyMissing()` en cualquier controller listado en `controllerModuleMap` (ej. `InvoicesController`).
   - Visitar `/invoices/dummy-missing` autenticado → respuesta `500` con `LogicException` cuyo mensaje cita `dummyMissing`.
   - Quitar la acción temporal antes del commit final.

5. **Confirmar que las exceptions siguen funcionando**
   - `/login` y `/logout` sin sesión → comportamiento normal (no throw).
   - `/email-logs/retry/<id>` con un rol que tiene `invoices.can_edit` o `employee_novelties.can_edit` (según entity_type) → autoriza correctamente.
   - `Pages` y `Error` controllers → no autenticado o autenticado, sin throw (no están en `controllerModuleMap`).

6. **Flujo end-to-end por rol** (regression smoke)
   - Rol Registro: crear factura, avanzar a contabilidad.
   - Rol Contabilidad: avanzar a tesorería.
   - Rol Tesorería: registrar pago.
   - Rol Contador: autorizar pago, confirmar.
   - Rol Auxiliar de Personal: crear novedad, reenviar aprobación.
   - Sin regresiones.

## Riesgos

| Riesgo | Probabilidad | Mitigación |
|---|---|---|
| `resendApproval` rompe un flujo legítimo de un rol con solo `can_view` | media | Pre-check de la BD `permissions` antes del merge (paso 3 de validación manual). Si aparece, sembrar permiso o ajustar el mapping de la acción. |
| `uploadLiquidationDocument`/`updateLiquidationDocument` ídem | baja | Mismo pre-check. |
| Una acción huérfana no detectada en el inventario explota en producción | baja | El inventario se hizo con `Grep` exhaustivo sobre `public function` en todos los controllers del `controllerModuleMap`. Si aparece una huérfana nueva, el throw la reporta inmediatamente con mensaje accionable. |
| El throw rompe alguna ruta de error/exception render | muy baja | `ErrorController` y `PagesController` no están en `controllerModuleMap`, por lo que `_enforcePermission` retorna antes de llegar al `match` (línea 175-177 actual). |

## Plan de implementación (alto nivel)

La PR es de un solo commit y los cambios viven en un único archivo (`src/Controller/AppController.php`).

1. Pre-check BD: leer `permissions` para los roles que disparan `resendApproval`, `uploadLiquidationDocument`, `updateLiquidationDocument`. Documentar resultado en el cuerpo del commit.
2. Editar el `match` de `_actionToPermission` con las 7 entradas nuevas.
3. Sustituir el `default`.
4. Smoke test local (validación manual pasos 1-5).
5. Commit + PR siguiendo el formato de PR #4 (PA-003).

## Métricas de éxito

- Cero acciones huérfanas con CRUD enforcement degradado.
- Cualquier acción nueva en cualquier controlador del `controllerModuleMap` falla en el primer hit del dev con un mensaje accionable, en vez de ejecutarse silenciosamente con permiso `'view'`.
- Saldo neto de LoC: ~+15 (entradas del match + throw) / -1 (default actual).

## Referencias

- Auditoría: `docs/audits/permissions-audit-2026-05-11.md` §PA-001
- Precedente de cleanup: PR #4 (PA-003) — commit `1c73514`
- CLAUDE.md §Testing Policy (manual validation en lugar de tests)
