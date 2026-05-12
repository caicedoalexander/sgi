# Cierre de la auditoría de permisos — diseño

**Fecha:** 2026-05-12
**Auditoría origen:** `docs/audits/permissions-audit-2026-05-11.md`
**Alcance:** los 7 hallazgos pendientes (PA-007, PA-008, PA-009, PA-011, PA-012, PA-013, PA-014).
**Objetivo:** llevar el documento de auditoría de `⚠️ NEEDS REWORK` a `✅ Resuelto`, sin reabrir hallazgos ya cerrados.

---

## Contexto

El bloque crítico (🔴) y mayor (🟠) de la auditoría está cerrado: PA-001..PA-006 y PA-010 fueron resueltos entre los commits `0d84bd7` y `3949528` (2026-05-12). Quedan 7 hallazgos:

| ID | Severidad | Resumen |
|----|-----------|---------|
| PA-007 | 🟡 Minor | Admin bypass duplicado entre `AuthorizationService::ADMIN_BYPASS_MODULES` y `AppController::_setUserPermissions`. |
| PA-008 | 🟡 Minor | Shape divergente entre `InvoiceFieldAccessPolicy::SECTION_BY_STEP` (string) y `NoveltyService::SECTIONS_BY_STEP` (array). |
| PA-009 | 🟡 Minor | `RolesController::add` llama `getPermissionsMatrix(0)` con `role_id` inexistente; "funciona" por accidente. |
| PA-011 | 🟡 Minor | Dos modelos de Policy conviven (Modelo A en Advance, Modelo B inline en el resto). |
| PA-012 | 🟢 Sugerencia | Fallback `?? new PipelineAuthorizationService()` en 6 services tras PA-004. |
| PA-013 | 🟢 Sugerencia | Caché per-request implícita sin docblock. |
| PA-014 | 🟢 Sugerencia | `STEP_LABELS` duplica strings de `STATUS_LABELS` de cada `*Constants`. |

Decisiones tomadas durante el brainstorming:

- **Alcance:** cerrar los 7 pendientes (no se acepta parar antes).
- **Dirección de PA-011:** migrar todo a Modelo A (crear 5 `*ActionPolicy` siguiendo `AdvanceLegalizationActionPolicy`).

---

## Plan general

4 PRs en orden de riesgo creciente. Cada PR es independiente y mergeable por sí solo.

| PR | Hallazgos | Tipo | LoC neto aprox | Riesgo |
|----|-----------|------|---------------:|--------|
| **PR1 — Cleanup batch** | PA-009, PA-012, PA-013, PA-014 | Quick wins sin cambio de comportamiento | -20 | Bajo |
| **PR2 — Admin bypass** | PA-007 | Migration + simplificación de `AuthorizationService`/`AppController::_setUserPermissions` | -15 | Bajo-medio |
| **PR3 — Field policy base** | PA-008 | Extraer `PipelineFieldPolicy` abstracta, unificar shape Invoice↔Novelty a `array` | -40 | Medio |
| **PR4 — Policy objects** | PA-011 | 5 `*ActionPolicy` nuevas + migrar call-sites | +400 | Medio |

Razones del orden:

- **PR1 primero**: aislado, sin cambio de comportamiento, valida el ciclo PR → merge → testing manual.
- **PR2 antes que PR3/PR4**: toca `AuthorizationService`/`AppController`; aislarlo evita mezclas con refactors de policy.
- **PR3 antes que PR4**: la base `PipelineFieldPolicy` aclara el shape de "qué puede ver/editar este rol en este step", consumido por algunas `*ActionPolicy`.
- **PR4 al final**: mayor superficie, necesita los pasos previos estables.

---

## PR1 — Cleanup batch (PA-009 + PA-012 + PA-013 + PA-014)

### PA-009 — Eliminar `getPermissionsMatrix(0)` accidental

**Cambios:**

1. En `PipelineAuthorizationService`, añadir método público:
   ```php
   public function getEmptyMatrix(): array
   ```
   Recorre `PipelineStepConstants::STEPS_BY_PIPELINE` y devuelve `[pipeline => [step => false]]` sin tocar BD.
2. En `RolesController::add`, reemplazar `$this->pipelineAuth->getPermissionsMatrix(0)` por `getEmptyMatrix()` (vía `AuthorizationFacade` si la API lo expone, o vía servicio directo si está dentro de los puntos `@internal` permitidos por PA-004).
3. Revisar si `AuthorizationService::getPermissionsForRoleAsMatrix(0)` también es llamado con `role_id` inexistente. Si lo es, repetir el mismo patrón (`getEmptyMatrix()` simétrico).

**Criterio de validación manual:**

- `/roles/add` muestra todos los checkboxes desmarcados (CRUD + pipeline).
- `/roles/edit/{id}` sigue mostrando la matriz actual de cada rol existente.

### PA-012 — Eliminar fallbacks `?? new PipelineAuthorizationService()`

**Cambios:**

1. Inventario de los 6 puntos del documento: `RefundService:45`, `NoveltyService:49`, `PaymentSchedulingService:31`, `PettyCashService:53`, `RefundPaymentService:36`, `InvoiceFieldAccessPolicy:62`. Verificar el estado actual tras PA-004 — si la migración a `AuthorizationFacade` ya reemplazó algunos, anotarlo en el PR.
2. Constructor sin fallback. La dependencia inyectada queda obligatoria.
3. Si algún punto sigue dependiendo directamente de `PipelineAuthorizationService` y debería usar `AuthorizationFacade`, migrarlo aquí (alineamiento con PA-004).

**Criterio de validación manual:**

- `php bin/cake server` arranca sin errores.
- Smoke por dominio: avanzar un paso del pipeline en facturas, reintegros, caja menor, novedades, programación de pagos. Comportamiento idéntico.

### PA-013 — Documentar caché per-request

**Cambios:**

1. Docblock de clase en `PipelineAuthorizationService` y `AuthorizationService`:
   > Caché per-request invalidada explícitamente por `invalidate(roleId)` y en `save*Permissions()`. No persiste entre requests por diseño: depende del scope per-request del container de DI.

**Criterio de validación manual:** cambio puramente documental. `composer cs-check` pasa.

### PA-014 — Deduplicar `STEP_LABELS`

**Cambios:**

1. En `PipelineStepConstants::STEP_LABELS`, sustituir cada string literal por la referencia a `{Modulo}Constants::STATUS_LABELS[STATUS_X]` donde exista.
2. Si la label de un step no tiene equivalente en el `*Constants` del dominio, dejar el string literal con comentario `// no existe equivalente en {Modulo}Constants — fuente única aquí`.

**Criterio de validación manual:**

- UI `/roles/edit/{id}` muestra las mismas etiquetas en español que antes para cada pipeline.
- UI de progreso de cada dominio muestra los labels correctos.

### Validación global de PR1

- `composer cs-check` limpio.
- Login con cada rol → matriz idéntica.
- Smoke E2E pipeline en facturas.

---

## PR2 — Admin bypass (PA-007)

### Cambios

1. **Migration** `config/Migrations/YYYYMMDDHHMMSS_SeedAdminPermissions.php` (con `BaseMigration`):
   - Insertar `(admin_role_id, 'users', 1, 1, 1, 1)` y `(admin_role_id, 'roles', 1, 1, 1, 1)` en `permissions`.
   - Idempotente: chequear existencia previa por `(role_id, module)`; si existe con valores distintos, **no sobreescribir** (loggear warning) — protege entornos donde alguien haya configurado un admin con permisos parciales intencionalmente.
   - Verificar también si hay otros módulos en `ADMIN_BYPASS_MODULES` no mencionados en el documento actual (auditoría manual antes de escribir la migration).
2. Eliminar la constante `AuthorizationService::ADMIN_BYPASS_MODULES`.
3. Simplificar `AuthorizationService::isAllowed()` a una sola rama: lookup contra `permissions`.
4. Eliminar el bloque `if ($roleName === ROLE_ADMIN) { ... }` en `AppController::_setUserPermissions`.
5. Si tras los cambios `$roleName` queda muerto en la firma de `isAllowed`, eliminarlo (cierra residuo de PA-003 que se conservó intencionalmente).

### Riesgos

- **Entornos con permisos manuales del admin**: la migration es no-destructiva pero puede dejar a un admin con menos permisos de los esperados si alguien tenía filas parciales. Mitigación: chequeo previo + dejar la migration idempotente.
- **`$roleName` en `isAllowed`**: si se elimina del firma, hay que verificar que ningún caller lo pase posicionalmente.

### Criterio de validación manual

1. Antes de migrar: anotar el `SELECT * FROM permissions WHERE role_id = (admin)` actual.
2. Migrar.
3. Login como admin → sidebar muestra "Usuarios" y "Roles" + permite entrar a `/users` y `/roles` + matriz `roles/edit/{admin}` muestra `1,1,1,1` en esos módulos.
4. Login con cualquier otro rol → `/users` y `/roles` bloqueados igual que antes.
5. `composer cs-check` limpio.

---

## PR3 — Field policy base (PA-008)

### Cambios

1. Crear `src/Service/Pipeline/PipelineFieldPolicy.php` (clase abstracta):
   ```php
   abstract class PipelineFieldPolicy
   {
       abstract protected static function pipelineKey(): string;
       abstract protected static function fieldsByStep(): array;   // step => string[]
       abstract protected static function sectionsByStep(): array; // step => string[]

       final public function getEditableFields(UserContext $u, string $step): array;
       final public function getVisibleSections(UserContext $u, string $step): array;
       final public function filterEntityData(array $data, UserContext $u, string $step): array;
   }
   ```
   La implementación final usa `AuthorizationFacade` para resolver si el rol puede operar el step y aplica el filtrado.
2. Migrar `InvoiceFieldAccessPolicy`:
   - Renombrar `SECTION_BY_STEP` (string) → `SECTIONS_BY_STEP` (array de un elemento por step).
   - Hacer que herede de `PipelineFieldPolicy`.
   - Conservar la API pública existente (`getEditableFields`, `getVisibleSections`, `filterEntityData`).
3. Extraer la lógica de Novelty (`NoveltyService::getEditableFields`/`getVisibleSections`/equivalente de filtrado) a una nueva clase `NoveltyFieldAccessPolicy` heredando de `PipelineFieldPolicy`. `NoveltyService` delega a la policy nueva.
4. Auditar callers: ningún consumer debe asumir que `SECTION_BY_STEP` es string. Migrar a la nueva forma si los hay.

### Riesgos

- **Templates Invoice**: si algún `templates/Invoices/edit.php` o partial consume `getVisibleSections()` esperando string, falla silenciosamente. Mitigación: grep exhaustivo de `getVisibleSections`/`SECTION_BY_STEP` antes del merge.
- **NoveltyService API**: extraer a policy nueva implica revisar todos los callers de `NoveltyService::getEditableFields`/etc.

### Criterio de validación manual

- `invoices/edit` con cada rol × estado → secciones visibles y campos editables idénticos.
- `employee-novelties/edit` con cada rol × estado → idem.
- Save en ambos: el filtrado de campos descartados (no-editables para el rol) sigue funcionando.

---

## PR4 — Policy objects (PA-011)

### Cambios

Crear 5 clases siguiendo el shape de `AdvanceLegalizationActionPolicy`:

| Archivo | Acciones cubiertas |
|---------|---------------------|
| `src/Service/Pipeline/Refund/Policy/RefundActionPolicy.php` | Acciones del pipeline de reintegros (advance/regress + upload/sign + pago). |
| `src/Service/Pipeline/PettyCash/Policy/PettyCashActionPolicy.php` | Acciones del pipeline de caja menor. |
| `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php` | Acciones del pipeline de facturas: `canEdit`, `canAuthorizePayment`, `canConfirmPayment`, `canRejectPayment`, `canRegisterPayment`. |
| `src/Service/Pipeline/Novelty/Policy/NoveltyActionPolicy.php` | Acciones del pipeline de novedades + firma. |
| `src/Service/Pipeline/PaymentScheduling/Policy/PaymentSchedulingActionPolicy.php` | Acciones del pipeline de programación de pagos. |

**Estructura de cada policy:**

```php
final class XActionPolicy
{
    public function __construct(private readonly AuthorizationFacade $auth) {}

    public function canAdvance(Entity $e, UserContext $u): bool { ... }
    public function canRegress(Entity $e, UserContext $u): bool { ... }
    public function canX(Entity $e, UserContext $u): bool { ... }
    // donde corresponda alinear con PA-005:
    public function denialReasonForAdvance(Entity $e, UserContext $u): ?DenialReason { ... }
}
```

**Migración de callers:**

1. Controllers que llamen `$this->pipelineAuth->canOperate(...)` inline o vía wrapper privado (`_canOperateRefundStep`, equivalentes en otros controllers) → pasan a `$this->{dominio}ActionPolicy->canX(...)`.
2. ViewModels que reciben `canX` calculado inline → reciben el booleano desde el controller o la policy directamente.
3. Eliminar wrappers privados ad hoc en cada controller.

**Orden de migración dentro del PR** (commit por dominio para permitir bisect si algo se rompe):

1. Refund
2. PettyCash
3. Invoice
4. Novelty
5. PaymentScheduling

### Riesgos

- **Superficie grande de cambio.** Mitigación: avanzar dominio por dominio + smoke E2E entre dominios.
- **ViewModels acoplados a `canX` inline**: si la signature cambia, las plantillas pueden dejar de mostrar/ocultar botones. Mitigación: grep de cada uso de `canX` en `templates/` antes de eliminarlo del controller.
- **Inyección DI**: cada `*ActionPolicy` debe registrarse en el container (services.php). Verificar que el container resuelve correctamente.

### Criterio de validación manual (por dominio)

- Ejercitar las acciones de pipeline con cada rol relevante (advance/regress/upload/sign/payment donde aplique).
- Comportamiento idéntico al previo.
- Los botones en `view`/`edit` se muestran/ocultan igual que antes.

---

## Convención de validación

Por política del proyecto (`CLAUDE.md` → "Testing Policy") no se agregan tests automatizados. Cada PR se valida con:

1. `composer cs-check` sin errores.
2. `php bin/cake server` arranca limpio.
3. Pasos manuales específicos del PR (los listados arriba).
4. Smoke E2E: login con cada rol relevante + un flujo completo del pipeline tocado.

---

## Entregables por PR

Cada PR entrega:

- **Código** + migration si aplica.
- **Commit message** estilo conventional commits (`refactor(auth):`, `chore(auth):`, `feat(auth):`).
- **Actualización inline** del documento `docs/audits/permissions-audit-2026-05-11.md`:
  - Cambiar `⏳ Pendiente` → `✅ Resuelto` en la tabla "Estado de remediación".
  - Añadir bloque `> **Cierre:**` bajo el título del hallazgo con commits, qué se hizo, validación manual ejecutada.
  - Formato idéntico al usado en PA-001..PA-006/PA-010.

---

## Documento de cierre

Al terminar los 4 PRs:

- Cambiar el **veredicto global** del documento de `⚠️ NEEDS REWORK` a `✅ Resuelto` con la fecha de cierre real.
- Añadir sección final "Cierre de la auditoría — YYYY-MM-DD" con:
  - Resumen de los 14 hallazgos cerrados.
  - Lista de commits agregados durante el closeout.
  - LoC neto total.

---

## Out of scope

- No se modifica el contrato público `AuthorizationFacade` (PA-004 estable).
- No se reabren PA-001..PA-006/PA-010.
- No se añaden tests automatizados.
- No se refactoriza código adyacente que no esté implicado en un hallazgo.
- No se añaden atributos `#[Permission]`/`#[PipelineAction]` nuevos más allá de los necesarios para los cambios listados (PA-002 ya cerrado).
