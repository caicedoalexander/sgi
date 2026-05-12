# Spec — PA-003: Eliminar parámetro muerto `$roleName` en API de pipeline

**Fecha:** 2026-05-11
**Origen:** `docs/audits/permissions-audit-2026-05-11.md` (hallazgo PA-003)
**Severidad:** 🟠 Major
**Esfuerzo estimado:** S (single PR, mecánico)
**Saldo neto LoC:** ~-70

---

## Contexto

`PipelineAuthorizationService::canOperate(int $roleId, string $roleName, string $pipeline, string $step)` y `::getOperableSteps(int $roleId, string $roleName, string $pipeline)` declaran `$roleName` pero **nunca lo consultan**. El docblock lo reconoce explícitamente:

```php
/** @param string $roleName Conservado para compat con callers; no se consulta tras cleanup 2026-05-02. */
```

El parámetro fue conservado en el cleanup de 2026-05-02 como puente entre el modelo legacy (admin bypass por `$roleName`) y el modelo actual (admin pasa por la tabla `pipeline_permissions` como cualquier otro rol). El puente nunca se desmontó.

Hoy `$roleName` se propaga por:
- 2 firmas en `PipelineAuthorizationService`
- ~9 wrappers en servicios (`InvoicePipelineService`, `NoveltyService`, `PettyCashService`, `RefundService`, `PaymentSchedulingService`, `RefundPaymentService`, `LiquidationDocPaymentService`, `InvoiceTransitionValidator`, `InvoiceFieldAccessPolicy`)
- 13 métodos en `AdvanceLegalizationActionPolicy` + su helper privado `_canOperate`
- ~55 call-sites en controllers, services y templates

Cada vez que se escribe un caller nuevo se pierden 30s confirmando que el parámetro es muerto. Invita al patrón cargo-cult (`$roleName` se propaga porque "todos lo hacen"). `RefundService::canRegress` ya tiene la firma limpia — es la única excepción y sirve de plantilla.

## Objetivo

Eliminar `$roleName` del parámetro de toda la API que lo declara muerto. Una sola PR, cambio mecánico, sin alteración de lógica ni de comportamiento observable.

## Alcance

### Se elimina

**Leaf (fuente de verdad):**
- `PipelineAuthorizationService::canOperate(int $roleId, string $pipeline, string $step): bool`
- `PipelineAuthorizationService::getOperableSteps(int $roleId, string $pipeline): array`

**Wrappers que solo reenviaban `$roleName`:**
- `InvoicePipelineService::getOperableSteps`, `canAdvance`, `canRegress`
- `InvoiceFieldAccessPolicy::getEditableFields`, `getVisibleSections`, `filterEntityData` (revisar cuáles aplican)
- `InvoiceTransitionValidator::filterErrorsForRole` (verificar uso en caller chain)
- `NoveltyService::getEditableFields`, `getVisibleSections`, `canAdvanceFromStatus`, `getOperableSteps`
- `PaymentSchedulingService::canAdvance`, `canReject`, `canRegress`, `getOperableSteps`
- `PettyCashService::canAdvance`, `canRegress`, `getOperableSteps` y demás métodos que solo reenvían
- `RefundService::canAdvance`, `getOperableSteps` (canRegress ya está limpio, sirve de referencia)
- `RefundPaymentService` (3 sitios)
- `LiquidationDocPaymentService` (sitios que reenvían a `canOperate`)

**Policy objects:**
- `AdvanceLegalizationActionPolicy::canLinkInvoices`, `canUnlinkInvoice`, `canUploadRelationDocument`, `canMoveToRevision`, `canMarkSigned`, `canReturnToValidacion`, `canMarkExact`, `canRegisterShortage`, `canRegisterSurplus`, `canConfirmShortage`, `canRegisterRefund`, `canAuthorizeRefundPayment`, `canConfirmRefundPayment`
- `AdvanceLegalizationActionPolicy::_canOperate`

**Callers (controllers, services, templates):** actualizar cada llamada para no propagar `$roleName` a las funciones reducidas.

### NO se toca (por diseño)

- **`AuthorizationService::isAllowed($roleId, $roleName, $module, $action)`** — `$roleName` **sí se consulta** ahí para el admin bypass (`AuthorizationService.php:57`). Permanece intacto.
- **`AppController::_getUserRoleName()`** — sigue vivo porque alimenta `AuthorizationService::isAllowed` y `_setUserPermissions` (admin bypass del sidebar).
- **Lógica de autorización** — sin cambios. Mismas filas de `pipeline_permissions`, mismo resultado runtime.
- **No se introduce `UserContext` value object** — ése es trabajo de PA-002, fuera de scope.
- **No se reorganiza `PipelineAuthorizationService`** — solo se borra el parámetro muerto de sus dos métodos públicos.
- **Otros hallazgos de la auditoría** (PA-001, PA-002, PA-004…) — fuera de scope. Specs separados.

## Diseño

### Estrategia: bottom-up, single PR

PHP `declare(strict_types=1)` + `composer cs-check` actúan como red de seguridad: al borrar el parámetro del leaf, cualquier caller desfasado falla en boot/CS check.

**Orden recomendado:**

1. **Leaf** — `PipelineAuthorizationService::canOperate` y `::getOperableSteps`. Borrar el parámetro de la firma y del docblock.
2. **Wrappers de servicios pipeline** — eliminar `$roleName` de firma y de la llamada interna a `$pipelineAuth->canOperate/getOperableSteps`. Iterar por: `InvoicePipelineService`, `NoveltyService`, `PettyCashService`, `RefundService`, `PaymentSchedulingService`, `RefundPaymentService`, `LiquidationDocPaymentService`, `InvoiceTransitionValidator`, `InvoiceFieldAccessPolicy`.
3. **Policy objects** — `AdvanceLegalizationActionPolicy`: borrar `$roleName` de los 13 `canX(...)` + del helper privado `_canOperate`.
4. **Controllers** — actualizar cada call-site. Si el controller construía `$roleName` solo para pasarlo, eliminar también la línea `$roleName = $this->_getUserRoleName($user)`. Si el controller usa `$roleName` por otra razón válida (mensaje de error, log, `AuthorizationService::isAllowed`), conservar.
5. **Templates** — `grep -rn 'canOperate\|getOperableSteps\|->canX' templates/` por si alguna vista invoca services pipeline directamente (poco común pero posible en pipeline rendering).
6. **Docblocks** — eliminar `@param string $roleName Conservado para compat...` de los archivos tocados.

### Por qué single PR

Una PR atómica. Múltiples PRs dejarían el código en un estado intermedio inconsistente — algunos callers pasarían `$roleName`, otros no, generando `TypeError` reales. El cambio es semánticamente atómico (firma muerta → eliminada) y mecánico en su mayor parte: 99% es buscar `, $roleName` y borrarlo.

### Plantilla de referencia

`RefundService::canRegress` (línea ~358) ya tiene la firma limpia (`canRegress(int $roleId, string $currentStatus)`). Usar como referencia para verificar que el patrón final es consistente.

## Criterios de validación manual

Dado que el proyecto no usa tests automatizados (ver `CLAUDE.md` → "Testing Policy"), la validación es manual.

### Pre-merge (verificación estática)

1. `composer cs-check` pasa sin nuevos warnings ni errores.
2. `php bin/cake server` arranca sin `TypeError`, `ArgumentCountError`, ni `Throwable` en `logs/`.
3. `grep -rn '\$roleName' src/Service/PipelineAuthorizationService.php` devuelve **0** resultados.
4. `grep -rn 'canOperate(.*\$roleName' src/ templates/` devuelve **0** resultados (excluyendo `AuthorizationService::isAllowed`, que es función distinta).
5. `grep -rn 'getOperableSteps(.*\$roleName' src/ templates/` devuelve **0** resultados.

### Smoke por módulo (un flujo end-to-end por dominio de pipeline)

| Módulo | Acción | Rol sugerido | Cubre |
|---|---|---|---|
| Facturas | `advanceStatus` `tesoreria` → registrar pago → `autorizacion_pago` → autorizar → `verificacion_pago` | Tesorería + Contador | `InvoicePipelineService::canAdvance`, `InvoicePaymentService`, `InvoiceTransitionValidator` |
| Anticipos | `linkInvoices`, `markSigned`, `markExact` en una legalización | Contabilidad + Tesorería | `AdvanceLegalizationActionPolicy::canX` (13 métodos) |
| Reintegros | Avance `agrupacion` → `contabilidad` + registrar pago | Tesorería | `RefundService`, `RefundPaymentService` |
| Caja Menor | Avance `tesoreria` → `autorizacion_pago` | Tesorería | `PettyCashService` |
| Novedades | Avance + abrir `edit` con rol distinto (secciones visibles correctas) | Auxiliar de Personal | `NoveltyService::canAdvanceFromStatus`, `getEditableFields`, `getVisibleSections` |
| Payment Schedulings | Avance `borrador` → `tesoreria` y rechazo | Coordinador | `PaymentSchedulingService::canAdvance`, `canReject` |

### Smoke transversal

- **Sidebar:** badge counters aparecen correctamente para roles distintos (verifica que ningún service consumido por `SidebarCounterService` quedó con firma rota).
- **Form edit (facturas):** abrir un `invoices/edit` con rol no-admin → secciones visibles y campos editables idénticos al pre-merge (cubre `InvoiceFieldAccessPolicy`).
- **Roles:** `/roles/edit/{id}` y `/roles/add` cargan la matriz pipeline sin errores (cubre `PipelineAuthorizationService::getPermissionsMatrix`).

### Criterio de aceptación

- Los 6 flujos de pipeline funcionan idénticos al pre-merge.
- Sidebar, edits y `roles/*` intactos.
- `logs/` sin nuevos errores tras smoke.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Caller olvidado pasando `$roleName` por posición | PHP `strict_types` + boot del server lanza `ArgumentCountError` inmediatamente. Bottom-up garantiza propagación. |
| Caller que usa `$roleName` por otra razón legítima | Lectura caso por caso: si la variable se usa después del call (log, mensaje), conservar la asignación; eliminar solo el argumento. |
| Template que llama directo a `canOperate` | Paso 5 del orden incluye `grep` en `templates/`. |
| Confusión con `AuthorizationService::isAllowed($roleId, $roleName, …)` | Función distinta, alcance separado. Verificación estática (criterio 4 pre-merge) usa nombre completo del método. |
| Regresión en admin bypass | No aplica. Admin bypass vive en `AuthorizationService::isAllowed` y `_setUserPermissions`, no en `PipelineAuthorizationService`. Sin cambios ahí. |

## Fuera de scope (specs futuros)

- **PA-001** — Cambiar `_actionToPermission` default de `'view'` a `throw`. Spec aparte.
- **PA-002** — Reemplazar `_actionToPermission` + `$pipelineActions` por atributos PHP. Spec aparte.
- **PA-004 a PA-014** — Hallazgos restantes de la auditoría. Cada uno con su propio ciclo.

## Saldo esperado

- **LoC borrados:** ~70 (parámetros + docblocks + asignaciones huérfanas de `$roleName`).
- **LoC añadidos:** 0.
- **Archivos tocados:** ~20 (1 leaf service + 9 wrappers + 1 policy + ~8 controllers + posibles templates).
- **Cambio funcional:** ninguno. Mismo comportamiento runtime.
- **Beneficio DX:** firma honesta, menos ruido cargo-cult, base limpia para PA-001 y PA-002.
