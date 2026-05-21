# Migración de Employees/view + cierre de la migración — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Migrar el chat de observaciones de `Employees/view` al element compartido `observations/drawer` y, una vez sin consumidores, retirar el trío de elementos del chat viejo — cerrando la migración de módulos de flujo.

**Architecture:** `Employees/view.php` es una vista con pestañas; las observaciones viven hoy en una pestaña dedicada con el chat viejo (`observation_bubble` + `observation_chat_init`). Se elimina esa pestaña (botón de nav + panel) y se añade el `observations/drawer` flotante (decisión aprobada por el usuario). Tras esto, ningún template consume ya `observation_bubble*`; se borran los 3 archivos del chat viejo y se verifica que no quedan referencias.

**Tech Stack:** CakePHP 5.3 (template PHP en `templates/Employees/`, elementos en `templates/element/`), JS `sgi-observation-chat.js` (lo carga el drawer, NO se borra).

**Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md` (módulo Employees/view — 7.º y último del orden de ejecución — y sección "Cierre de la migración").

**Política del proyecto:** sin tests automatizados. Cada tarea cierra con `php -l` del archivo tocado y un commit. `composer cs-fix` / `cs-check` NO corren en este entorno — no usarlos. La validación funcional (servidor + navegador) la hace el usuario.

---

## Contexto

- `templates/Employees/view.php` es una vista master-detail con pestañas
  (Documentos, Perfil, Contrato, Novedades, Historial, **Observaciones**). La
  pestaña "Observaciones" usa el chat viejo: `element('observation_bubble', …)`
  por mensaje + `element('observation_chat_init')` al final del archivo.
- El element compartido `observations/drawer` es un **panel flotante**
  (disparador fijo al borde derecho + offcanvas Bootstrap), autocontenido: emite
  su `<template>`, carga `sgi-observation-chat.js` e inicializa
  `SgiObservationChat`. No "vive dentro de una pestaña".
- **Decisión aprobada por el usuario:** se adopta `observations/drawer` flotante
  y se **elimina la pestaña "Observaciones"** (botón de nav + panel). Las
  observaciones quedan accesibles desde cualquier pestaña vía el disparador fijo.
  Es lo que el spec pide ("→ `observations/drawer`") y deja `Employees/view`
  consistente con los otros 6 módulos ya migrados.
- **`Employees/view.php` es el último consumidor** de `observation_bubble` /
  `observation_chat_init`. Confirmado: `grep -rl "observation_bubble\|observation_chat_init"
  templates/` devuelve solo 4 archivos — `Employees/view.php` y los 3 elementos
  del chat viejo. Tras la Task 1, los 3 elementos quedan huérfanos.
- El controller no necesita cambios: `EmployeesController::view()` ya carga
  `$employee->employee_observations` (el template ya lo consume) y
  `EmployeesController::addObservation()` ya existe (`#[Permission(action: 'edit')]`).

## Estructura de archivos

| Archivo | Cambio |
|---|---|
| `templates/Employees/view.php` | Quitar la pestaña "Observaciones" (nav + panel) y el `observation_chat_init`; quitar el `$obsCount` que queda sin uso; añadir `observations/drawer`. |
| `templates/element/observation_bubble.php` | **Eliminar** (sin consumidores tras la Task 1). |
| `templates/element/observation_bubble_template.php` | **Eliminar** (sin consumidores). |
| `templates/element/observation_chat_init.php` | **Eliminar** (sin consumidores). |

`webroot/js/sgi-observation-chat.js` **NO se borra** — lo usa el `observations/drawer`.

---

## Task 1: `Employees/view.php` — observaciones al drawer

**Files:**
- Modify: `templates/Employees/view.php`

- [ ] **Step 1: Quitar el botón de pestaña "Observaciones"**

En `templates/Employees/view.php`, dentro del `<ul class="nav tabs">`, localizar y
**eliminar por completo** el `<li>` de la pestaña Observaciones:

```php
        <li class="nav-item" role="presentation">
            <button class="tab" data-bs-toggle="tab" data-bs-target="#tab-observaciones" type="button" role="tab">
                <i class="bi bi-chat-square-text" aria-hidden="true"></i> Observaciones
                <?php if ($obsCount > 0): ?>
                    <span class="tab-badge"><?= $obsCount ?></span>
                <?php endif; ?>
            </button>
        </li>
```

Las otras 5 pestañas (Documentos, Perfil, Contrato, Novedades, Historial) no se
tocan.

- [ ] **Step 2: Quitar el panel de la pestaña "Observaciones"**

Dentro del `<div class="tab-content">`, localizar y **eliminar por completo** el
bloque del panel Observaciones — desde el comentario hasta el `</div>` que cierra
`#tab-observaciones`:

```php
        <!-- ───── Tab: Observaciones ───── -->
        <div class="tab-pane fade" id="tab-observaciones" role="tabpanel">
            <div class="sgi-card" style="padding:0;overflow:hidden;">
                <div style="padding:14px 18px;border-bottom:1px solid var(--rule);">
                    <div style="font-size:13px;font-weight:700;color:var(--text-strong);">Observaciones</div>
                    <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">
                        <span id="obs-count-label"><?= $obsCount ?> comentario<?= $obsCount !== 1 ? 's' : '' ?></span>
                    </div>
                </div>

                <div id="obs-chat-scroll" class="sgi-obs-list">
                    <?php foreach ($employee->employee_observations ?? [] as $obs): ?>
                        <?= $this->element('observation_bubble', [
                            'observation' => $obs,
                            'isMine' => $currentUser && $obs->user_id === $currentUser->id,
                        ]) ?>
                    <?php endforeach; ?>
                </div>

                <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
                    <i class="bi bi-chat-square-text" aria-hidden="true"></i>
                    <span>Sin observaciones aún</span>
                </div>

                <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
                <div class="sgi-obs-input-bar">
                    <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $employee->id], 'id' => 'obs-form']) ?>
                    <div class="sgi-obs-compose">
                        <textarea name="message" class="auto-resize" rows="1"
                                  placeholder="Escriba una observación..."></textarea>
                        <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                            <i class="bi bi-send" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
                <?php endif; ?>
            </div>
            <span id="obs-count" hidden><?= $obsCount ?></span>
        </div>
```

(Las demás `tab-pane` no se tocan.)

- [ ] **Step 3: Quitar la variable `$obsCount` (queda sin uso)**

Tras los pasos 1 y 2, la variable `$obsCount` ya no se usa en ningún sitio.
Localizar y **eliminar** su declaración cerca del inicio del archivo:

```php
$obsCount = count($employee->employee_observations ?? []);
```

(Las líneas vecinas `$novedades` / `$noveltyCount` / `$historyCount` / `$currentUser`
**sí se conservan** — `$currentUser` lo usa el drawer en el paso 4; las otras las
usan otras pestañas.)

- [ ] **Step 4: Reemplazar `observation_chat_init` por el drawer**

Localizar, cerca del final del archivo (después de los modales / el
`excel_wizard/modals`), la línea:

```php
<?= $this->element('observation_chat_init') ?>
```

Reemplazarla por la llamada al drawer compartido:

```php
<?= $this->element('observations/drawer', [
    'observations'    => $employee->employee_observations ?? [],
    'count'           => count($employee->employee_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $employee->id],
    'currentUserName' => $currentUser?->full_name ?? ($currentUser?->username ?? 'Usuario'),
]) ?>
```

Notas:
- Esa línea está a nivel superior del template (fuera de cualquier `<form>` y
  fuera de la estructura de pestañas) — posición correcta para el drawer, que es
  flotante y autocontenido.
- `$currentUser` se define al inicio del archivo como
  `$this->getRequest()->getAttribute('identity')`; el operador `?->` lo cubre por
  si fuese null.
- `addObservation` vive en `EmployeesController` — `formUrl` usa
  `['action' => 'addObservation', $employee->id]`, idéntico al `<form>` viejo.
- El `<script>` que sigue a esa línea (auto-submit del buscador del directorio)
  **no se toca**.

- [ ] **Step 5: Verificar y commitear**

```bash
php -l templates/Employees/view.php
git add templates/Employees/view.php
git commit -m "refactor(view): Employees/view — observaciones al drawer compartido"
```

El mensaje de commit debe terminar con una línea en blanco y luego:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 6: Validación manual**

`php bin/cake server`, abrir `Empleados` y seleccionar un empleado: la barra de
pestañas ya **no** muestra "Observaciones" (quedan 5 pestañas). El disparador del
drawer de observaciones aparece fijo al borde derecho; abrirlo muestra el chat con
las observaciones del empleado y el contador correcto; con permiso de edición,
publicar una observación la añade y el contador sube. Consola del navegador sin
errores JS.

---

## Task 2: Cierre de la migración — retirar el chat viejo

**Files:**
- Delete: `templates/element/observation_bubble.php`
- Delete: `templates/element/observation_bubble_template.php`
- Delete: `templates/element/observation_chat_init.php`

- [ ] **Step 1: Verificar que no quedan consumidores del chat viejo**

Desde `C:/Users/sistema/Documents/sgi`, ejecutar:

```bash
git grep -n "observation_bubble\|observation_chat_init" -- templates/
```

**Resultado esperado:** solo deben aparecer los 3 propios archivos de elementos
(`observation_bubble.php`, `observation_bubble_template.php`,
`observation_chat_init.php`) — que pueden referenciarse entre sí. **No** debe
aparecer ningún otro template (en particular, `Employees/view.php` ya no debe
salir tras la Task 1).

Si aparece cualquier otro consumidor, **DETENERSE** y reportarlo con estado
BLOCKED — no borrar los elementos mientras tengan consumidores.

- [ ] **Step 2: Eliminar los 3 elementos del chat viejo**

```bash
git rm templates/element/observation_bubble.php
git rm templates/element/observation_bubble_template.php
git rm templates/element/observation_chat_init.php
```

(NO borrar `webroot/js/sgi-observation-chat.js` — lo carga el
`observations/drawer` y sigue en uso.)

- [ ] **Step 3: Verificación final de la migración**

Ejecutar las dos verificaciones del cierre (spec, sección "Cierre de la migración"):

```bash
git grep -n "observation_bubble\|observation_chat_init" -- templates/
git grep -n "sgi-row-fact\|sgi-status-tab" -- templates/
```

**Resultado esperado:** ambos comandos devuelven **0 resultados** (salida vacía).
Confirma que ningún template consume ya el chat viejo ni el dialecto de listado
muerto (`.sgi-row-fact*` / `.sgi-status-tab*`).

Si algún comando devuelve resultados, **DETENERSE** y reportarlo con estado
BLOCKED, listando los archivos encontrados.

- [ ] **Step 4: Commitear**

```bash
git add -A
git commit -m "chore: retirar el chat de observaciones viejo (observation_bubble*)"
```

El mensaje de commit debe terminar con una línea en blanco y luego:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 5: Validación manual**

Recorrer un par de vistas que usan el drawer de observaciones (p. ej. una factura
en `Invoices/edit`, un empleado en `Employees/view`): el drawer abre y funciona.
Confirma que la eliminación de los 3 elementos no rompió nada (ninguna vista los
seguía usando). Consola sin errores.

---

## Self-review (cobertura del spec)

Sección "Employees/view" del spec:
- "Solo: el chat de observaciones ad-hoc (`observation_bubble`) →
  `observations/drawer`" → Task 1. Al ser una vista con pestañas (no el layout de
  cards de los otros módulos), adoptar el drawer flotante implica eliminar la
  pestaña "Observaciones" — decisión aprobada por el usuario. ✔

Sección "Cierre de la migración" del spec:
- "Eliminar `observation_bubble.php`, `observation_bubble_template.php`,
  `observation_chat_init.php`" → Task 2, Step 2. ✔
- "Verificar que no quedan referencias a `.sgi-row-fact*` / `.sgi-status-tab*` ni
  a `observation_bubble*` en `templates/`" → Task 2, Steps 1 y 3. ✔

Consistencia de tipos / nombres:
- El drawer recibe `observations` / `count` / `formUrl` / `currentUserName` — la
  misma firma usada en los 6 módulos ya migrados (`Invoices/edit.php`,
  `EmployeeNovelties/view.php`, etc.). ✔
- IDs del drawer (`#obs-form`, `#obs-count`, `#obs-chat-scroll`,
  `#obs-empty-state`): el panel viejo que los usaba se elimina en la Task 1, Step 2,
  antes de que el drawer (que reusa esos IDs) entre — sin duplicados. ✔
- `$obsCount` se elimina en el Step 3 porque sus únicos usos (badge de pestaña +
  panel) desaparecen en los Steps 1-2. `$currentUser` se conserva (lo usa el
  drawer). ✔

Decisiones de alcance:
- El CSS `.sgi-obs-*` del chat viejo queda sin uso tras esta migración, pero
  **limpiar CSS muerto no está en el alcance** del spec (que solo pide verificar
  referencias en `templates/`). El drawer usa clases `.chat-*`, no `.sgi-obs-*`.
  Se deja para un eventual barrido de CSS aparte.
- `webroot/js/sgi-observation-chat.js` se conserva — es el motor del drawer.

Con la Task 2 cerrada, la migración de los 7 módulos de flujo queda completa.
