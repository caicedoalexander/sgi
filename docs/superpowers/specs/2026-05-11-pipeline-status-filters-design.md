# Migración de filtros de visibilidad por estado a `pipeline_permissions`

**Fecha**: 2026-05-11
**Autor**: Alexander
**Estado**: Propuesto

---

## Contexto

Los listados de los 6 módulos con pipeline (Facturas, Reintegros, Caja Menor, Programación de Pagos, Anticipos, Documentos de Liquidación, Novedades) filtran hoy los registros visibles para cada usuario en función del **nombre del rol** hardcodeado en el código:

- `InvoicePipelineService::getVisibleStatuses(string $roleName)` recorre los `InvoicePipelineState` y consulta `$state->getRoleVisibility()` — un array de `RoleConstants::*` declarado en cada `State/*State.php`.
- `InvoicePipelineService::getVisibleAdvanceStatuses(string $roleName)` hace lo mismo via `$state->getAdvanceRoleVisibility()`.
- `NoveltyService`, `PaymentSchedulingService`, `PettyCashService`, `RefundService` cada uno con una constante privada `ROLE_VISIBLE_STATUSES = [RoleConstants::X => [STATUS_Y, ...], ...]`.

En el cleanup del 2026-05-02 se creó la tabla `pipeline_permissions(role_id, pipeline, step, can_operate)` y el servicio `PipelineAuthorizationService`, que ya resuelve "¿este rol puede operar este step del pipeline?". Los permisos de operación (avanzar/regresar) ya consultan la tabla, pero los **filtros de listados** siguen dependiendo del nombre de rol hardcodeado. Eso significa que un administrador no puede ajustar quién ve qué desde la UI; necesita un PR.

## Objetivo

Hacer que la tabla `pipeline_permissions` sea la **única fuente de verdad** para "qué estados ve cada rol" en los listados ("Mis Registros") de los 6 módulos con pipeline. Eliminar las matrices hardcodeadas y la dependencia de `RoleConstants` en los servicios de filtrado.

## No-objetivos

- Cambiar la semántica de "puede operar" (eso ya vive en `pipeline_permissions`).
- Modificar el comportamiento del Admin bypass de `AuthorizationService` (sigue acotado a `users`/`roles`).
- Tocar las matrices de `InvoiceFieldAccessPolicy` / `InvoiceLockPolicy` (esas son políticas de edición de campos, no de visibilidad de registros).

## Decisiones tomadas

1. **Semántica unificada**: visibilidad de registros == puede operar. Un solo flag `can_operate` en `pipeline_permissions`; no se introduce `can_view`.
2. **Admin pasa por la tabla**: la migration de seed inserta filas con `can_operate=true` para Admin en todos los pares `(pipeline, step)` declarados en `STEPS_BY_PIPELINE`. Ningún bypass hardcodeado.
3. **Anticipos y Liquidaciones**:
   - **Anticipos**: consulta el pipeline `invoices` (mismo que facturas, porque viven sobre la misma tabla `invoices`). Para distinguir el listado /invoices del listado /advances, se modifica `InvoicesController` para **excluir `document_type='Anticipo'`** del filtro de "Mis Facturas". Sin esta exclusión, sembrar la unión de las dos matrices (visibilidad de factura + visibilidad de anticipo) introduciría que roles como Auxiliar de Personal vean facturas normales en /invoices — cambio de comportamiento no deseado. Con la exclusión, /invoices y /advances se distinguen por `document_type` (no por matriz de visibilidad por rol), y un solo pipeline `invoices` en `pipeline_permissions` puede expresar la unión de ambas visibilidades sin ambigüedad.
   - Los terminales `pagada` / `legalizada` están fuera de `STEPS_BY_PIPELINE['invoices']`, así que la lógica de "excluir terminales" desaparece — emerge del catálogo.
   - **Documentos de Liquidación de novedades**: necesitan un pipeline propio (`liquidation_docs`) — hoy `getVisibleLiquidationStatuses` lo asume implícitamente pero no está modelado en `PipelineStepConstants`. Se agrega con los steps: `contabilidad`, `revision_firmas`, `gdp`, `tesoreria`, `autorizacion_pago`, `verificacion_pago` (los `LIQUIDATION_ACTIVE_STATUSES` actuales de `NoveltyService`, que son un subset del pipeline de novedades excluyendo `aprobacion` y `rrhh` que no aplican a documentos de liquidación).
4. **Seed via migration idempotente** (no comando manual): `INSERT ... ON DUPLICATE KEY UPDATE` (o lookup + insert/update) por cada par `(role_id, pipeline, step)`. `down()` no revierte (no-op documentado) para evitar borrar configuración del admin.
5. **Eliminar matrices viejas en el mismo PR**: `getRoleVisibility()`, `getAdvanceRoleVisibility()` (interfaz + 7 implementaciones), constantes `ROLE_VISIBLE_STATUSES`, imports de `RoleConstants` huérfanos.
6. **API pública**: la firma `getVisibleStatuses(string $roleName)` cambia a `getVisibleStatuses(int $roleId)` en los 5 services. Se actualizan los 7 controllers y los 2 servicios consumidores (`SidebarCounterService`, `PendingNotificationsService`).

## Arquitectura

```
Controller --(roleId)--> *Service::getVisibleStatuses(roleId)
                              |
                              v
                  PipelineAuthorizationService::getOperableSteps(roleId, pipeline)
                              |
                              v
                       pipeline_permissions (role_id, pipeline, step, can_operate)
```

Cada `*Service` que hoy expone `getVisibleStatuses` se convierte en un **adaptador delgado** sobre `PipelineAuthorizationService::getOperableSteps`, pasando su pipeline correspondiente.

| Service | Método | Pipeline argumento |
|---|---|---|
| `InvoicePipelineService::getVisibleStatuses(int $roleId)` | delega | `PipelineStepConstants::PIPELINE_INVOICES` |
| `InvoicePipelineService::getVisibleAdvanceStatuses(int $roleId)` | delega | `PIPELINE_INVOICES` |
| `NoveltyService::getVisibleStatuses(int $roleId)` | delega | `PIPELINE_NOVELTIES` |
| `NoveltyService::getVisibleLiquidationStatuses(int $roleId)` | delega | `PIPELINE_LIQUIDATION_DOCS` (nuevo) |
| `PaymentSchedulingService::getVisibleStatuses(int $roleId)` | delega | `PIPELINE_PAYMENT_SCHEDULINGS` |
| `PettyCashService::getVisibleStatuses(int $roleId)` | delega | `PIPELINE_PETTY_CASH` |
| `RefundService::getVisibleStatuses(int $roleId)` | delega | `PIPELINE_REFUNDS` |

## Componentes a crear / modificar

### A. Migration de seed (nueva)

`config/Migrations/YYYYMMDDhhmmss_SeedPipelinePermissionsFromRoleMatrices.php`

- Clase base `Migrations\BaseMigration`.
- En `up()`: para cada `(role_name, pipeline, step, can_operate=true)` del seed inline, resuelve `role_id` via `SELECT id FROM roles WHERE name = ?`. Si el rol no existe (renombrado/borrado), loggea warning y continúa.
- Upsert por `(role_id, pipeline, step)`: si la fila ya existe, no la pisa (respeta cambios manuales hechos en la UI de Roles después del primer deploy); si no existe, la inserta con `can_operate=true`.
- Admin (`RoleConstants::ADMIN = 'Administrador'`) recibe `can_operate=true` en todos los `(pipeline, step)` de `STEPS_BY_PIPELINE` incluyendo el nuevo `liquidation_docs`.
- `down()`: no-op. Documentado en el docblock con razón: revertir borraría configuración manual del admin.

El seed inline replica exactamente:
- Las matrices `ROLE_VISIBLE_STATUSES` de `NoveltyService`, `PaymentSchedulingService`, `PettyCashService`, `RefundService` (pipelines `novelties`, `payment_schedulings`, `petty_cash`, `refunds`).
- Los `getRoleVisibility()` de los 7 `InvoicePipelineState` y los `getAdvanceRoleVisibility()` de los mismos — **fusionados** en el pipeline `invoices`: si un rol aparece en cualquiera de los dos para un estado, recibe `can_operate=true` para ese step. La fusión es segura porque `InvoicesController` se modifica para excluir `document_type='Anticipo'` (ver componente E), así que la unión solo afecta lo que aparece en /advances.
- La matriz `LIQUIDATION_VISIBLE_STATUSES` de `NoveltyService` para el nuevo pipeline `liquidation_docs`.

### B. `PipelineStepConstants` — nuevo pipeline

Agregar:
```php
public const PIPELINE_LIQUIDATION_DOCS = 'liquidation_docs';
```

Y agregar entradas en `PIPELINE_LABELS`, `STEPS_BY_PIPELINE`, `STEP_LABELS` para `liquidation_docs`. Steps (extraídos de `NoveltyService::LIQUIDATION_ACTIVE_STATUSES`):

- `NoveltyConstants::STATUS_CONTABILIDAD` → "Contabilidad"
- `NoveltyConstants::STATUS_REVISION_FIRMAS` → "Revisión y Firmas"
- `NoveltyConstants::STATUS_GDP` → "GDP"
- `NoveltyConstants::STATUS_TESORERIA` → "Tesorería"
- `NoveltyConstants::STATUS_AUTORIZACION_PAGO` → "Autorización de pago"
- `NoveltyConstants::STATUS_VERIFICACION_PAGO` → "Verificación de pago"

`PIPELINE_LABELS[PIPELINE_LIQUIDATION_DOCS] = 'Documentos de liquidación'`.

### C. Services — refactor

**`InvoicePipelineService`** (ya tiene `PipelineAuthorizationService` inyectado):

```php
public function getVisibleStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps($roleId, '', PipelineStepConstants::PIPELINE_INVOICES);
}

public function getVisibleAdvanceStatuses(int $roleId): array
{
    return $this->pipelineAuth->getOperableSteps($roleId, '', PipelineStepConstants::PIPELINE_INVOICES);
}
```

(El segundo argumento `$roleName` de `getOperableSteps` está conservado por compat pero no se consulta tras el cleanup 2026-05-02 — pasamos string vacío.)

**`NoveltyService`, `PaymentSchedulingService`, `PettyCashService`, `RefundService`**:
- Inyectar `PipelineAuthorizationService` via constructor (`?PipelineAuthorizationService $pipelineAuth = null` con `?? new PipelineAuthorizationService()` fallback, siguiendo la convención del proyecto).
- Reemplazar el cuerpo de `getVisibleStatuses` (y `getVisibleLiquidationStatuses` en `NoveltyService`) por una llamada a `getOperableSteps`.
- Borrar la constante `ROLE_VISIBLE_STATUSES`.
- Borrar import `use App\Constants\RoleConstants` si queda huérfano.

### D. Pipeline States — eliminar API de visibilidad

En `App\Service\Pipeline\Invoice\InvoicePipelineState` (interfaz):
- Borrar `public function getRoleVisibility(): array;`
- Borrar `public function getAdvanceRoleVisibility(): array;`

En cada implementación (`AprobacionState`, `ContabilidadState`, `TesoreriaState`, `AutorizacionPagoState`, `VerificacionPagoState`, `PagadaState`, `LegalizadaState`):
- Borrar ambos métodos.
- Borrar import `use App\Constants\RoleConstants` si queda huérfano.

### E. Controllers — actualizar callers

7 archivos:
- `InvoicesController` (`index`, `all`, `rejected`, `overdue`) — **además, excluir `document_type='Anticipo'`** del query base de los 4 endpoints. Hoy solo se excluye `caja_menor`; se agrega la exclusión de anticipos para que /invoices no muestre anticipos (que tienen su propio listado /advances). Sin este cambio, sembrar la unión de visibilidades en pipeline `invoices` haría que roles que hoy solo ven anticipos (Auxiliar/Asistente/Coordinador) empezaran a ver facturas normales en /invoices.
- `RefundsController` (`index`)
- `PettyCashRecordsController` (`index`)
- `PaymentSchedulingsController` (`index`)
- `NoveltyLiquidationDocsController` (`index`)
- `EmployeeNoveltiesController` (`index`)
- `AdvancesController` (`index`)

Cambio mecánico en cada uno (excepto el cambio adicional de InvoicesController arriba):
```php
// antes
$roleName = $this->_getRoleName();
$visibleStatuses = $service->getVisibleStatuses($roleName);

// después
$roleId = (int)$this->_getCurrentUser()->role_id;
$visibleStatuses = $service->getVisibleStatuses($roleId);
```

Si el controller usa `$roleName` para otra cosa (ej. pasar a la vista), se conserva — no se borra `_getRoleName()`.

**Cambio adicional**: el patrón actual `!empty($visibleStatuses) ? ['... IN' => $visibleStatuses] : []` significa "lista vacía → ver todo", que es incorrecto en el modelo nuevo. Cambiar a un WHERE que siempre filtre, incluso si la lista está vacía:

```php
$conditions = ['Invoices.pipeline_status IN' => $visibleStatuses ?: ['__none__']];
```

(El truco `?: ['__none__']` evita el error "Empty array in IN" de la BD y garantiza 0 resultados cuando el rol no tiene permisos. Se ajusta el patrón en los 7 controllers y los 2 services consumidores.)

### F. Servicios consumidores

**`SidebarCounterService`** — 5 invocaciones a `getVisibleStatuses($roleName)`:
- Migrar la firma del método público que recibe el role para que también acepte `roleId` (o resolverlo internamente desde el user). Verificar dónde se invoca este service y propagar `roleId` desde el caller.
- Aplicar el mismo patrón de "lista vacía → 0 resultados".

**`PendingNotificationsService`** — 1 invocación a `paymentSchedulingService->getVisibleStatuses($roleName)`:
- Mismo cambio.

## Data flow esperado por request

1. Controller obtiene `$roleId` desde el user autenticado (ya disponible en `$user->role_id`).
2. Llama al service correspondiente: `$service->getVisibleStatuses($roleId)`.
3. El service delega a `PipelineAuthorizationService::getOperableSteps($roleId, '', $pipeline)`.
4. `getOperableSteps` invoca `_loadForRole($roleId)`:
   - Primera llamada del request: 1 SELECT a `pipeline_permissions` filtrando por `role_id`. Resultado cacheado en `$this->cache[$roleId]`.
   - Llamadas siguientes (mismo rol, otro pipeline): salen del cache.
5. Filtra `STEPS_BY_PIPELINE[$pipeline]` contra el cache, retorna los steps con `can_operate=true`.
6. Controller aplica `WHERE pipeline_status IN (...)` al query.

**Costo total por request**: 1 SELECT a `pipeline_permissions` por usuario, sin importar cuántos pipelines consulte.

## Error handling

| Caso | Comportamiento |
|---|---|
| Rol sin filas en `pipeline_permissions` | `getOperableSteps` retorna `[]` → listado vacío. **Lado seguro.** |
| `roleId` null/inválido | No debería pasar (AppController exige user autenticado); si pasa, `[]` → sin acceso. |
| Pipeline desconocido (typo) | `STEPS_BY_PIPELINE[$pipeline] ?? []` → `[]` → sin acceso. |
| Step renombrado en código pero presente en BD | `getOperableSteps` itera sobre el catálogo, filtra el cache → fila huérfana inerte, sin fallo. |
| Admin no sembrado tras la migration | Bug crítico. Mitigación: la migration verifica que `SELECT id FROM roles WHERE name = 'Administrador'` retorne resultado antes de sembrar; si no, falla con error claro. |
| Re-ejecutar la migration | Upsert idempotente; no duplica filas, no pisa configuración manual. |

## Validación manual

Sustituye a tests automatizados (este proyecto no usa tests — ver `CLAUDE.md` § Testing Policy).

**Setup**:
1. `php bin/cake migrations migrate`
2. `php bin/cake server`
3. Asegurar al menos un usuario por rol: Administrador, Contabilidad, Tesorería, Registro/Revisión, Contador, Auxiliar de Personal, Coordinador Administrativo y Financiero.

**V1 — Seed correcto en BD**:
```sql
SELECT r.name, pp.pipeline, pp.step
FROM pipeline_permissions pp
JOIN roles r ON r.id = pp.role_id
WHERE pp.can_operate = 1
ORDER BY r.name, pp.pipeline, pp.step;
```
Resultado debe coincidir con la matriz documentada en el seed inline de la migration.

**V2 — Idempotencia**:
- `php bin/cake migrations migrate` dos veces seguidas: la segunda no duplica filas ni falla.
- (Opcional) `php bin/cake migrations rollback` confirma el no-op de `down()`.

**V3 — Listados por rol** (paridad con comportamiento anterior):

| Rol | URL | Esperado |
|---|---|---|
| Contabilidad | `/invoices` | Solo facturas en `contabilidad` |
| Tesorería | `/invoices` | `tesoreria`, `autorizacion_pago`, `verificacion_pago` |
| Contador | `/invoices` | `autorizacion_pago`, `verificacion_pago` |
| Registro/Revisión | `/invoices` | `aprobacion` |
| Admin | `/invoices` | Todas las activas |
| Contabilidad | `/refunds` | Solo `contabilidad` |
| Tesorería | `/refunds` | `tesoreria`, `autorizacion_pago`, `verificacion_pago` |
| Tesorería | `/petty-cash-records` | Steps tesoreros |
| Tesorería | `/payment-schedulings` | Steps tesoreros |
| Auxiliar de Personal | `/advances` | Todos los activos (excluye `pagada`/`legalizada`) |
| Tesorería | `/novelty-liquidation-docs` | Steps tesoreros |
| RRHH | `/employee-novelties` | Solo `rrhh` |

Comparar conteos antes/después del PR. Deben coincidir.

**V4 — Sidebar counters**: badges del sidebar muestran los mismos números que antes del PR para cada rol.

**V5 — Edición de permisos cambia visibilidad en vivo**:
- Como Admin: `/roles/edit/{id_Contabilidad}` → desmarcar step `contabilidad` del pipeline `invoices`.
- Como Contabilidad: `/invoices` queda vacío.
- Volver a marcar → el listado vuelve a aparecer.

**V6 — Rol sin permisos**:
- Crear rol "Prueba" sin tocar `pipeline_permissions`.
- Asignarlo a un usuario; login.
- Los 6 listados deben quedar vacíos (no "ver todo").

**V7 — Notificaciones pendientes**: el panel de notificaciones (`PendingNotificationsService`) muestra el mismo conteo que antes del PR para cada rol.

**V8 — Anticipos terminales excluidos**: como Auxiliar de Personal, `/advances` NO muestra anticipos en `pagada` ni `legalizada`.

**V9 — code style**: `composer cs-check` sin violaciones.

## Riesgos

1. **Seed incompleto** → rol pierde acceso post-deploy. Mitigación: validar V3 antes de merge.
2. **Cambio del patrón "lista vacía → ver todo"** podría romper algún caso no contemplado (ej. acciones administrativas). Mitigación: revisar exhaustivamente cada llamada a `getVisibleStatuses` en code review.
3. **Pipeline `liquidation_docs` nuevo** agrega filas a la matriz UI de Roles → la pantalla `/roles/edit/{id}` mostrará una sección más. Verificar layout y que el POST sigue funcionando.
4. **`SidebarCounterService` toca varios pipelines en cascada** — si la migración a `roleId` se hace sin propagar bien, podríamos invocar con un valor inválido. Verificar en V4.
5. **Exclusión de anticipos en `InvoicesController`** podría afectar reportes/exportaciones que hoy asumen ver todo lo que vive en `invoices` salvo caja menor. Mitigación: grep adicional sobre el controller para detectar acciones que no sean los 4 listados (export, reportes); si existen, evaluar caso por caso si deben mantener anticipos o no.

## Fuera de alcance (potenciales follow-ups)

- Separar `can_view` de `can_operate` (rechazado en esta iteración).
- Migrar `InvoiceFieldAccessPolicy` y `InvoiceLockPolicy` a una tabla de permisos de campo (orden de magnitud mayor; otro PR).
- Eliminar `RoleConstants` completamente (sigue usándose en `AuthorizationService::ADMIN_BYPASS_MODULES` y otros lugares legítimos).
