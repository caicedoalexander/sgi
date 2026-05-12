# PA-001 — `default => throw` en `_actionToPermission` (Plan de implementación)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir el fallback silencioso `default => 'view'` de `AppController::_actionToPermission()` en un `LogicException` accionable, mapeando explícitamente las 7 acciones huérfanas detectadas en el inventario del spec.

**Architecture:** Cambio quirúrgico en un solo archivo (`src/Controller/AppController.php`). Se añaden 7 entradas explícitas al `match`, se reemplaza el `default => 'view'` por `default => throw new \LogicException(...)`. No se introducen atributos, ni se mueve lógica fuera del `AppController` (eso vive en PA-002/PA-010). Validación manual sustituye tests automatizados según `CLAUDE.md` §Testing Policy.

**Tech Stack:** PHP 8.4, CakePHP 5.3, MySQL/MariaDB. Sin nuevas dependencias.

**Spec:** `docs/superpowers/specs/2026-05-12-pa-001-default-throw-design.md`

---

## File Structure

Un único archivo modificado.

- **Modificar:** `src/Controller/AppController.php` líneas 112–121 (método `_actionToPermission`)
- **NO crear** archivos nuevos
- **NO modificar** otros controllers, services, migraciones, ni templates
- **NO crear** archivos en `tests/` (política del proyecto)

---

## Task 1: Pre-check — verificar permisos CRUD en BD para las acciones con cambio de comportamiento

**Files:**
- Lectura: tabla `permissions` (vía consulta SQL ad-hoc)

Tres acciones cambian comportamiento real (el resto solo formaliza lo que ya hacía el `default`):
- `EmployeeNoveltiesController::resendApproval` → ahora requiere `employee_novelties.can_edit`
- `NoveltyLiquidationDocsController::uploadLiquidationDocument` → ahora requiere `novelty_liquidation_docs.can_add`
- `NoveltyLiquidationDocsController::updateLiquidationDocument` → ahora requiere `novelty_liquidation_docs.can_edit`

Si en producción algún rol que dispara estas acciones tiene solo `can_view` del módulo, la PR rompe ese flujo. Hay que confirmarlo antes de tocar código.

- [ ] **Step 1: Lanzar el servidor y conectar a la BD**

Run: `php bin/cake server`

Abrir cliente MySQL apuntando a la misma BD que `DATABASE_URL` en `.env`. Si se prefiere CLI:
```bash
mysql -h <host> -u <user> -p <db>
```

Esperado: prompt MySQL listo.

- [ ] **Step 2: Listar los roles que tienen permiso de mutación en `employee_novelties`**

Ejecutar:
```sql
SELECT r.id, r.name, p.can_view, p.can_create, p.can_edit, p.can_delete
FROM permissions p
JOIN roles r ON r.id = p.role_id
WHERE p.module = 'employee_novelties'
ORDER BY r.id;
```

Esperado: listado con al menos los roles que usan novedades hoy (Asistente de Personal, Auxiliar de Personal, Coordinador Administrativo y Financiero, Administrador). Anotar quién tiene `can_view=1` pero `can_edit=0` — esos son los roles que perderán acceso a `resendApproval` con el cambio.

- [ ] **Step 3: Listar los roles con permisos en `novelty_liquidation_docs`**

Ejecutar:
```sql
SELECT r.id, r.name, p.can_view, p.can_create, p.can_edit, p.can_delete
FROM permissions p
JOIN roles r ON r.id = p.role_id
WHERE p.module = 'novelty_liquidation_docs'
ORDER BY r.id;
```

Esperado: listado. Anotar quién tiene `can_view=1` pero `can_create=0` (perderá `uploadLiquidationDocument`) o `can_edit=0` (perderá `updateLiquidationDocument`).

- [ ] **Step 4: Documentar el resultado en el cuerpo del eventual commit**

Crear un archivo temporal `pa-001-precheck.md` (no se commitea, solo notas locales) con la salida de los dos queries. Si todos los roles que disparan estas acciones en producción tienen el permiso de mutación correspondiente → seguir. Si falta alguno → decidir uno de:

  a) sembrar el permiso faltante en una data-migration dentro de la misma PR;
  b) cambiar el mapping de la acción afectada (p. ej. `resendApproval` → `'view'` en lugar de `'edit'`);
  c) parar y consultar antes de continuar.

Esperado: decisión documentada para cada uno de los 3 casos. Si la decisión cambia el mapping, ajustar Task 2 antes de continuar.

- [ ] **Step 5: Commit del precheck si genera migración**

Si Step 4 detectó que hay que sembrar permisos faltantes, crear la migración con `bin/cake bake migration ...` y commitearla aparte **antes** de continuar con Task 2. Si no hay seed adicional, este step es no-op.

---

## Task 2: Mapear las 7 acciones huérfanas en el `match`

**Files:**
- Modificar: `src/Controller/AppController.php:115-118`

Hoy el `match` está en `_actionToPermission()`:

```php
return match ($action) {
    'index', 'view', 'export', 'exportConfig', 'all', 'rejected', 'exportPdf', 'preview', 'active', 'activeEvents', 'allEvents', 'legalization', 'downloadDocument' => 'view',
    'add', 'addFolder', 'uploadDocument', 'import', 'importExcel', 'importUpload', 'importProcess', 'previewImport', 'confirmImport', 'addItem', 'uploadAttachment', 'addPayment' => 'add',
    'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'regenerateApiKey', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'confirmPayment', 'confirmRefundPayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'linkCandidates', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion', 'retry', 'retryAllFailed' => 'edit',
    'delete', 'deleteDocument', 'removeItem', 'deleteAttachment' => 'delete',
    default => 'view',
};
```

- [ ] **Step 1: Añadir `pendingLegalization`, `overdue`, `pending` a la rama `'view'`**

Editar la primera línea del `match` (línea 115). Resultado:

```php
'index', 'view', 'export', 'exportConfig', 'all', 'rejected', 'exportPdf', 'preview', 'active', 'activeEvents', 'allEvents', 'legalization', 'downloadDocument', 'pendingLegalization', 'overdue', 'pending' => 'view',
```

Notar: `'pending'` es compartido por `PettyCashRecordsController::pending` y `RefundsController::pending` (mismo nombre, mismo mapping). Una sola entrada los cubre a ambos.

- [ ] **Step 2: Añadir `uploadLiquidationDocument` a la rama `'add'`**

Editar la segunda línea del `match` (línea 116). Resultado:

```php
'add', 'addFolder', 'uploadDocument', 'import', 'importExcel', 'importUpload', 'importProcess', 'previewImport', 'confirmImport', 'addItem', 'uploadAttachment', 'addPayment', 'uploadLiquidationDocument' => 'add',
```

- [ ] **Step 3: Añadir `resendApproval`, `updateLiquidationDocument` a la rama `'edit'`**

Editar la tercera línea del `match` (línea 117). Resultado:

```php
'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'regenerateApiKey', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'confirmPayment', 'confirmRefundPayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'linkCandidates', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion', 'retry', 'retryAllFailed', 'resendApproval', 'updateLiquidationDocument' => 'edit',
```

- [ ] **Step 4: Verificar el archivo compila**

Run: `php -l src/Controller/AppController.php`

Esperado: `No syntax errors detected in src/Controller/AppController.php`

- [ ] **Step 5: Smoke test inmediato — arrancar el servidor**

Run: `php bin/cake server`

Esperado: servidor arranca en `localhost:8765` sin warnings. Hacer login con `admin / Admin2024*`. Dashboard carga.

Si arranca correctamente, parar el servidor con `Ctrl+C` y seguir. Sin commit todavía — la mitad de la PR (el throw) viene en Task 3.

---

## Task 3: Reemplazar `default => 'view'` por el throw

**Files:**
- Modificar: `src/Controller/AppController.php:119`

- [ ] **Step 1: Reemplazar la línea del `default`**

Editar `src/Controller/AppController.php:119`. Cambiar:

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

- [ ] **Step 2: Verificar compilación**

Run: `php -l src/Controller/AppController.php`

Esperado: `No syntax errors detected in src/Controller/AppController.php`

- [ ] **Step 3: Smoke test — el servidor sigue arrancando**

Run: `php bin/cake server`

Esperado: arranca limpio. Login con admin → dashboard. Sin Ctrl+C todavía, el servidor queda corriendo para Task 4.

---

## Task 4: Validación manual — acciones huérfanas mapeadas a `'view'` (sin cambio de comportamiento)

**Files:** ninguno (validación en navegador)

Las acciones `pendingLegalization`, `overdue`, y las dos `pending` antes caían al `default => 'view'` y ahora están explícitamente mapeadas a `'view'`. Comportamiento idéntico esperado.

- [ ] **Step 1: Login como un rol con `can_view` de los módulos afectados**

En el navegador, login con un rol que tenga `can_view = true` en `advances`, `invoices`, `petty_cash`, `refunds`. Admin sirve para esta primera ronda.

- [ ] **Step 2: Visitar `/advances/pending-legalization`**

Esperado: respuesta `200`, listado renderiza igual que antes de la PR.

- [ ] **Step 3: Visitar `/invoices/overdue`**

Esperado: respuesta `200`, listado renderiza igual.

- [ ] **Step 4: Visitar `/petty-cash-records/pending`**

Esperado: respuesta `200`, listado renderiza igual.

- [ ] **Step 5: Visitar `/refunds/pending`**

Esperado: respuesta `200`, listado renderiza igual.

- [ ] **Step 6: Logout y login con un rol SIN `can_view` de uno de los módulos**

Por ejemplo, un rol que no tenga `can_view` en `advances`. Visitar `/advances/pending-legalization`.

Esperado: `403 Forbidden` con mensaje "No tiene permisos para view en advances." (idéntico a antes).

---

## Task 5: Validación manual — acciones con cambio real de gate

**Files:** ninguno (validación en navegador)

Estas tres acciones ahora exigen `can_edit`/`can_add` del módulo en lugar de degradar a `can_view`. Hay que confirmar que (a) el rol que las usa legítimamente sigue funcionando, y (b) un rol con solo `can_view` ahora recibe 403.

- [ ] **Step 1: `resendApproval` — rol legítimo**

Login con un rol que tenga `employee_novelties.can_edit = true` (por defecto Asistente de Personal o el rol identificado en Task 1 Step 2). Crear o tomar una novedad en estado que permita reenviar aprobación. Disparar la acción desde la UI (botón "Reenviar aprobación").

Esperado: la acción se ejecuta. Flash de éxito. El email se dispara (visible en `EmailLogs::index` si SMTP está configurado).

- [ ] **Step 2: `resendApproval` — rol sin `can_edit`**

Logout. Login con un rol que tenga `employee_novelties.can_view = true` pero `can_edit = false` (si Task 1 Step 4 identificó alguno; si no existe, este step es no-op).

Navegar manualmente a la URL `/employee-novelties/resend-approval/<id>` con un id válido (POST). Usar las DevTools o un form helper.

Esperado: `403 Forbidden`. Antes de la PR esto retornaba `200`.

- [ ] **Step 3: `uploadLiquidationDocument` — rol legítimo**

Login con un rol que tenga `novelty_liquidation_docs.can_add = true`. Visitar un doc de liquidación en `edit` y subir un documento de liquidación.

Esperado: upload exitoso, doc aparece listado.

- [ ] **Step 4: `uploadLiquidationDocument` — rol sin `can_add`**

Logout. Login con rol que tenga `novelty_liquidation_docs.can_view = true` y `can_add = false`. Intentar el upload por la misma URL.

Esperado: `403 Forbidden`.

- [ ] **Step 5: `updateLiquidationDocument` — rol legítimo**

Login con rol que tenga `novelty_liquidation_docs.can_edit = true`. Editar un documento de liquidación existente.

Esperado: update aplicado.

- [ ] **Step 6: `updateLiquidationDocument` — rol sin `can_edit`**

Logout. Login con rol con `can_view = true` y `can_edit = false`. Intentar el update.

Esperado: `403 Forbidden`.

---

## Task 6: Validación manual — el throw dispara con una acción no mapeada

**Files:**
- Modificar temporalmente: `src/Controller/InvoicesController.php` (para añadir y quitar acción dummy)

- [ ] **Step 1: Añadir acción `dummyMissing()` temporal a `InvoicesController`**

Editar `src/Controller/InvoicesController.php`. Añadir al final de la clase, antes de la `}` de cierre:

```php
public function dummyMissing(): Response
{
    $this->autoRender = false;

    return $this->response->withStringBody('should never reach here');
}
```

- [ ] **Step 2: Arrancar el servidor y hacer login**

Run: `php bin/cake server`

Login con cualquier rol que tenga `invoices.can_view = true`.

- [ ] **Step 3: Visitar `/invoices/dummy-missing`**

En el navegador o con `curl`:
```bash
curl -i -b cookies.txt http://localhost:8765/invoices/dummy-missing
```

Esperado: respuesta `500`. En el body o en los logs (`logs/error.log`) aparece `LogicException` con mensaje:
`Action 'dummyMissing' has no permission mapping in AppController::_actionToPermission(). Register it explicitly in the match, add it to the controller's $pipelineActions, or extend the bypass in _enforcePermission().`

- [ ] **Step 4: Quitar la acción `dummyMissing` de `InvoicesController`**

Revertir el cambio del Step 1. Verificar con:
```bash
git diff src/Controller/InvoicesController.php
```

Esperado: sin diferencias (el archivo vuelve a su estado original).

- [ ] **Step 5: Verificar que no quedan cambios huérfanos**

Run: `git status --short`

Esperado: solo `M src/Controller/AppController.php` (los cambios de Task 2 y Task 3). Nada en `src/Controller/InvoicesController.php`.

---

## Task 7: Validación manual — las exceptions de `_enforcePermission` siguen funcionando

**Files:** ninguno (validación en navegador)

`_enforcePermission` salta `_actionToPermission` para tres familias: controllers fuera del `controllerModuleMap`, `Users::login`/`Users::logout`, y `EmailLogs::retry`. Confirmar que el throw no los afecta.

- [ ] **Step 1: Login y logout**

Visitar `/logout` autenticado → redirige a `/login`. Visitar `/login` sin sesión → formulario carga. Hacer login con admin.

Esperado: ambos flujos funcionan sin throw.

- [ ] **Step 2: `EmailLogs::retry` con rol no-admin**

Login con un rol que tenga `invoices.can_edit = true` pero NO sea admin. Ir a `/email-logs/index`. En un log fallido de tipo `invoice`, presionar "Reintentar".

Esperado: el retry se ejecuta (la autorización fina la hace el propio controller, no `_enforcePermission`). Si el rol no tiene `invoices.can_edit`, el controller responde con su Flash interno — pero NO con throw del `match`.

- [ ] **Step 3: Visitar `/pages/display/home` (PagesController)**

`PagesController` no está en `controllerModuleMap`, por lo que `_enforcePermission` retorna antes del `match`.

Esperado: respuesta normal de la página estática, sin throw.

- [ ] **Step 4: Disparar un error 404 (ErrorController)**

Visitar una URL inexistente como `/this-does-not-exist`.

Esperado: página de error 404 normal. ErrorController tampoco está en `controllerModuleMap` → sin throw.

---

## Task 8: Smoke test end-to-end por rol (regression)

**Files:** ninguno

Recorrido corto del pipeline de facturas con varios roles para asegurar que el throw no rompe ningún flujo normal.

- [ ] **Step 1: Rol Registro/Revisión — crear factura y avanzar a contabilidad**

Login como rol Registro. Crear una factura nueva (estado inicial `aprobacion`). Completar los campos de revisión y avanzar a `contabilidad`.

Esperado: avance exitoso, sin throw.

- [ ] **Step 2: Rol Contabilidad — avanzar a tesorería**

Logout, login como Contabilidad. Tomar la factura del Step 1, completar campos contables, avanzar a `tesoreria`.

Esperado: avance exitoso.

- [ ] **Step 3: Rol Tesorería — registrar pago**

Logout, login como Tesorería. Registrar un pago en la factura (campo `addPayment` del `InvoicePaymentsController`).

Esperado: pago registrado, factura avanza a `autorizacion_pago`.

- [ ] **Step 4: Rol Contador — autorizar pago y avanzar**

Logout, login como Contador. Autorizar el pago (`authorizePayment`). Avanzar a `verificacion_pago`.

Esperado: ambas acciones exitosas. Los nombres `authorizePayment` y `advanceStatus` están en `pipelineActions`/match, así que pasan por sus rutas habituales.

- [ ] **Step 5: Confirmar el pago para llegar a `pagada`**

Disparar `confirmPayment` desde el mismo rol Contador (o el que aplique en producción).

Esperado: factura termina en `pagada`. Sin throw en ningún paso.

- [ ] **Step 6: Rol Auxiliar de Personal — flujo de novedades incluyendo `resendApproval`**

Logout, login como Auxiliar de Personal (o el rol identificado en Task 1 con `employee_novelties.can_edit`). Crear una novedad. Ejecutar `resendApproval`.

Esperado: novedad creada, email reenviado. Sin throw. Sin `403` (el rol tiene el permiso).

---

## Task 9: Commit y limpieza

**Files:**
- `src/Controller/AppController.php` (único cambio)

- [ ] **Step 1: Revisar el diff**

Run: `git diff src/Controller/AppController.php`

Esperado: cambios limitados a las 4 líneas del `match` y la sustitución del `default`. Sin cambios en otras partes del archivo.

- [ ] **Step 2: Verificar que no hay otros archivos modificados**

Run: `git status --short`

Esperado: solo `M src/Controller/AppController.php`.

- [ ] **Step 3: Code style check**

Run: `composer cs-check`

Esperado: sin errores de PSR. Si aparecen, ejecutar `composer cs-fix` y volver a revisar el diff (Step 1).

- [ ] **Step 4: Stage y commit**

Run:
```bash
git add src/Controller/AppController.php
git commit -m "$(cat <<'EOF'
fix(permissions): default => throw en _actionToPermission (PA-001)

Cierra el over-permission silencioso donde toda accion no mapeada caia
a 'view'. Anade mapeo explicito de las 7 acciones huerfanas detectadas
en el inventario del spec (5 a 'view', 1 a 'add', 1 a 'edit') y
reemplaza el default por LogicException con mensaje accionable.

Cambio de comportamiento real:
- EmployeeNoveltiesController::resendApproval ahora exige
  employee_novelties.can_edit (antes can_view)
- NoveltyLiquidationDocsController::uploadLiquidationDocument ahora
  exige novelty_liquidation_docs.can_add (antes can_view)
- NoveltyLiquidationDocsController::updateLiquidationDocument ahora
  exige novelty_liquidation_docs.can_edit (antes can_view)

Pre-check de la tabla permissions confirmo que los roles que disparan
estas acciones en produccion ya tienen el permiso CRUD requerido.

Spec: docs/superpowers/specs/2026-05-12-pa-001-default-throw-design.md
Auditoria: docs/audits/permissions-audit-2026-05-11.md (PA-001)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Esperado: commit creado, hook de pre-commit pasa (si existe).

- [ ] **Step 5: Verificar el commit**

Run: `git log -1 --stat`

Esperado:
```
fix(permissions): default => throw en _actionToPermission (PA-001)
 src/Controller/AppController.php | ~14 ++++++++++----
 1 file changed, ~10 insertions(+), ~4 deletions(-)
```

---

## Task 10: Cerrar PA-001 en el tablero de la auditoría

**Files:**
- Modificar: `docs/audits/permissions-audit-2026-05-11.md` (tabla de estado + sección PA-001)

Mismo patrón que el commit `371c622` que cerró PA-003.

- [ ] **Step 1: Actualizar la tabla "Estado de remediación"**

En `docs/audits/permissions-audit-2026-05-11.md`, fila de PA-001, cambiar la columna "Estado" de `⏳ Pendiente` a `✅ Resuelto` y la columna "Resuelto en" de `—` al hash de commit del Task 9 (Step 4). Formato: `commit \`<sha>\` (2026-05-12)`.

- [ ] **Step 2: Añadir bloque de cierre en la sección "PA-001"**

Modificar el encabezado de la sección PA-001 (`## PA-001 — ...`) añadiendo `✅ Resuelto (2026-05-12)` al final, y agregar un bloque `> **Cierre:** ...` justo debajo, igual que el bloque de PA-003. Texto:

```markdown
> **Cierre:** commit `<sha>` mapeó las 7 acciones huérfanas detectadas (5 a `'view'`, 1 a `'add'`, 1 a `'edit'`) y reemplazó el `default => 'view'` por `LogicException` accionable. Validación manual: smoke E2E por rol + verificación de que el throw dispara con acción dummy. Cambio de comportamiento real en `resendApproval`, `uploadLiquidationDocument`, `updateLiquidationDocument` (ahora exigen el permiso CRUD correcto del módulo en lugar de degradar a `'view'`); precheck de la tabla `permissions` confirmó que los roles legítimos ya tenían el permiso.
```

- [ ] **Step 3: Commit del cierre**

Run:
```bash
git add docs/audits/permissions-audit-2026-05-11.md
git commit -m "$(cat <<'EOF'
docs(audit): cerrar PA-001 en el tablero de la auditoria

Marca PA-001 como resuelto en la tabla de estado y agrega el bloque de
cierre en la seccion correspondiente con referencia al commit del fix.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Esperado: commit creado.

- [ ] **Step 4: Verificar log final**

Run: `git log --oneline -5`

Esperado: los dos commits nuevos (cierre del tablero + fix de PA-001) en la cima del historial.

---

## Resumen de salidas

Al terminar el plan:
- 1 archivo de código modificado: `src/Controller/AppController.php` (~10 líneas netas)
- 1 archivo de docs actualizado: `docs/audits/permissions-audit-2026-05-11.md` (cierre de PA-001)
- 2 commits nuevos en `main`
- 0 archivos nuevos
- 0 tests automatizados (por política)
- 7 acciones huérfanas mapeadas explícitamente
- 1 trampa silenciosa convertida en fallo loud-and-clear

**Próximo paso lógico tras PA-001:** PA-002 (atributos `#[Permission]`/`#[PipelineAction]`/`#[NoAuthGate]`), pero es M de esfuerzo y mucho más invasivo — vale la pena que el usuario lo apruebe explícitamente en una sesión separada de brainstorming.
