# Gestión documental de empleados — navegación maestro-detalle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir la sección de documentos de un empleado en un navegador maestro-detalle: el árbol izquierdo filtra los documentos del panel derecho, que muestra solo documentos (con columna "Carpeta"), con el nodo raíz seleccionado por defecto.

**Architecture:** 100% frontend. La data (`$folders` con `employee_documents` y `child_folders`) ya está en el DOM. Se aplanan los documentos en PHP a una lista plana de filas con `data-folder-id`; un script vanilla JS filtra esas filas según el ítem de carpeta seleccionado en el árbol izquierdo. Sin cambios de controlador, servicio ni BD.

**Tech Stack:** CakePHP 5 (plantilla PHP `templates/Employees/view.php`), Bootstrap 5, JS vanilla, helper `DocumentIcon`, tokens CSS `--sgi-*`/`--primary-*`.

**Ámbito de archivos:**
- Modify: `templates/Employees/view.php` — preámbulo (`$allDocs`), árbol izquierdo (ítems clicables + subcarpetas anidadas), panel derecho (lista plana + columna "Carpeta" + empty-state), script JS inline.

No hay suite de tests para vistas en este proyecto (CLAUDE.md no la define). La verificación es manual en navegador. Cada tarea termina con verificación y commit.

---

### Task 1: Aplanar documentos en el preámbulo PHP

**Files:**
- Modify: `templates/Employees/view.php:30-37`

- [ ] **Step 1: Añadir `$allDocs` junto al cálculo de `$totalDocs`**

Reemplazar el bloque actual (líneas 30-37):

```php
// ─── Conteo de documentos (carpetas raíz + subcarpetas) ─────────────
$totalDocs = 0;
foreach ($folders as $folder) {
    $totalDocs += count($folder->employee_documents);
    foreach ($folder->child_folders as $sf) {
        $totalDocs += count($sf->employee_documents);
    }
}
```

por:

```php
// ─── Conteo de documentos + lista plana para el navegador docs ──────
// $allDocs aplana carpetas y subcarpetas en filas {doc, folderId, folderName}
// para renderizar una única lista filtrable del lado del cliente.
$totalDocs = 0;
$allDocs = [];
foreach ($folders as $folder) {
    foreach ($folder->employee_documents as $doc) {
        $allDocs[] = ['doc' => $doc, 'folderId' => $folder->id, 'folderName' => $folder->name];
    }
    $totalDocs += count($folder->employee_documents);
    foreach ($folder->child_folders as $sf) {
        foreach ($sf->employee_documents as $doc) {
            $allDocs[] = ['doc' => $doc, 'folderId' => $sf->id, 'folderName' => $sf->name];
        }
        $totalDocs += count($sf->employee_documents);
    }
}
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run: `php -l templates/Employees/view.php`
Expected: `No syntax errors detected in templates/Employees/view.php`

- [ ] **Step 3: Commit**

```bash
git add templates/Employees/view.php
git commit -m "feat(docs-empleado): aplanar documentos en \$allDocs para lista filtrable"
```

---

### Task 2: Convertir el árbol izquierdo en ítems clicables con subcarpetas anidadas

**Files:**
- Modify: `templates/Employees/view.php:496-533`

- [ ] **Step 1: Reemplazar el panel izquierdo del árbol**

Reemplazar el bloque del árbol de carpetas (desde el `<div>` del nodo raíz hasta el cierre del `foreach` de carpetas, ≈ líneas 496-533) por:

```php
                        <!-- ── Árbol de carpetas (izquierda) ── -->
                        <div id="docTree" style="background:var(--bg-subtle);padding:12px 0;
                                    border-right:1px solid var(--rule);font-size:12px;">
                            <!-- Nodo raíz -->
                            <div class="doc-tree-item is-active" data-folder-id="all"
                                 style="display:flex;align-items:center;gap:8px;padding:7px 14px;
                                        cursor:pointer;font-weight:700;">
                                <i class="bi bi-folder2-open" style="font-size:13px;" aria-hidden="true"></i>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($employee->first_name . ' ' . ($employee->last_name1 ?? '')) ?>
                                </span>
                                <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;">
                                    <?= $totalDocs ?>
                                </span>
                            </div>

                            <!-- Carpetas + subcarpetas anidadas -->
                            <?php foreach ($folders as $folder):
                                $folderDocCount = count($folder->employee_documents);
                            ?>
                            <div class="doc-tree-item" data-folder-id="<?= $folder->id ?>"
                                 style="display:flex;align-items:center;gap:8px;padding:7px 14px 7px 24px;
                                        cursor:pointer;color:var(--text-default);font-weight:500;">
                                <i class="bi bi-folder" style="font-size:13px;color:var(--secondary-color);flex-shrink:0;" aria-hidden="true"></i>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($folder->name) ?>
                                </span>
                                <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;flex-shrink:0;">
                                    <?= $folderDocCount ?>
                                </span>
                            </div>
                            <?php foreach ($folder->child_folders as $subfolder): ?>
                            <div class="doc-tree-item" data-folder-id="<?= $subfolder->id ?>"
                                 style="display:flex;align-items:center;gap:8px;padding:6px 14px 6px 38px;
                                        cursor:pointer;color:var(--text-muted);font-weight:500;">
                                <i class="bi bi-folder" style="font-size:12px;color:var(--secondary-color);flex-shrink:0;" aria-hidden="true"></i>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($subfolder->name) ?>
                                </span>
                                <span class="mono" style="font-size:9.5px;color:var(--text-faint);font-weight:600;flex-shrink:0;">
                                    <?= count($subfolder->employee_documents) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
```

- [ ] **Step 2: Añadir estilos de estado activo/hover del árbol**

Justo antes del `<div id="docTree" ...>`, insertar el bloque `<style>`:

```php
                        <style>
                            .doc-tree-item { transition: background var(--t-fast) ease; }
                            .doc-tree-item:hover { background: var(--bg-muted); }
                            .doc-tree-item.is-active { background: var(--primary-soft); color: var(--primary-color); font-weight: 700; }
                        </style>
```

- [ ] **Step 3: Verificar sintaxis PHP**

Run: `php -l templates/Employees/view.php`
Expected: `No syntax errors detected in templates/Employees/view.php`

- [ ] **Step 4: Commit**

```bash
git add templates/Employees/view.php
git commit -m "feat(docs-empleado): arbol izquierdo clicable con subcarpetas anidadas"
```

---

### Task 3: Reemplazar el panel derecho por una lista plana de documentos

**Files:**
- Modify: `templates/Employees/view.php` (panel derecho, ≈ líneas 535-613)

- [ ] **Step 1: Reemplazar el header de columnas y el cuerpo del panel derecho**

Reemplazar desde el `<!-- Header de columnas -->` hasta el cierre del cuerpo scroll (el `<div style="flex:1;overflow:auto;">...</div>` con su contenido), dejando intacto el footer de estado posterior, por:

```php
                            <!-- Header de columnas -->
                            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 96px;padding:10px 18px;
                                        background:var(--bg-muted);font-size:9.5px;font-weight:700;
                                        color:var(--text-faint);letter-spacing:0.7px;text-transform:uppercase;
                                        gap:12px;align-items:center;border-bottom:1px solid var(--rule);">
                                <span>Documento</span>
                                <span>Carpeta</span>
                                <span>Cargado</span>
                                <span style="text-align:right;">Acciones</span>
                            </div>

                            <div id="docList" style="flex:1;overflow:auto;">
                                <?php foreach ($allDocs as $row):
                                    $doc = $row['doc'];
                                    $type = $this->DocumentIcon->typeLabel($doc->mime_type);
                                ?>
                                <div class="doc-row" data-folder-id="<?= $row['folderId'] ?>"
                                     style="display:grid;grid-template-columns:2fr 1fr 1fr 96px;gap:12px;
                                            align-items:center;padding:10px 18px;border-bottom:1px solid var(--rule);
                                            font-size:12px;">
                                    <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> me-1"
                                           style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1rem;vertical-align:middle"></i>
                                        <?= $this->Html->link(
                                            h($doc->name),
                                            ['action' => 'downloadDocument', $employee->id, $doc->id],
                                            ['target' => '_blank', 'class' => 'text-decoration-none']
                                        ) ?>
                                        <span class="pill <?= h($this->DocumentIcon->badgeClass($type)) ?> ms-1"><?= h($type) ?></span>
                                    </span>
                                    <span style="color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        <i class="bi bi-folder me-1" style="color:var(--secondary-color);" aria-hidden="true"></i><?= h($row['folderName']) ?>
                                    </span>
                                    <span style="color:var(--text-faint);font-size:.8rem;">
                                        <?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?>
                                        <span style="color:var(--text-disabled);display:block;"><?= $doc->created?->format('d/m/Y H:i') ?></span>
                                    </span>
                                    <span class="text-end">
                                        <span class="d-flex gap-1 justify-content-end">
                                            <?= $this->Html->link(
                                                '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>',
                                                ['action' => 'downloadDocument', $employee->id, $doc->id],
                                                ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                                            ) ?>
                                            <?php if (!empty($userPermissions['employees']['can_delete'])): ?>
                                            <?= $this->Form->postLink(
                                                '<i class="bi bi-trash" aria-hidden="true"></i>',
                                                ['action' => 'deleteDocument', $employee->id, $doc->id],
                                                ['confirm' => '¿Eliminar este documento?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']
                                            ) ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </div>
                                <?php endforeach; ?>

                                <!-- Empty-state (lo muestra el JS cuando la carpeta no tiene docs) -->
                                <div id="docEmpty" style="display:none;padding:28px 18px;text-align:center;
                                            font-size:12px;color:var(--text-faint);font-style:italic;">
                                    <i class="bi bi-folder2-open d-block" style="font-size:22px;margin-bottom:6px;" aria-hidden="true"></i>
                                    Esta carpeta no tiene documentos.
                                </div>
                            </div>
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run: `php -l templates/Employees/view.php`
Expected: `No syntax errors detected in templates/Employees/view.php`

- [ ] **Step 3: Verificar que no quedan filas-encabezado de carpeta ni "Carpeta vacía"**

Run: `grep -n "folder-section\|Carpeta vacía\|Subcarpeta vacía" templates/Employees/view.php`
Expected: sin coincidencias (exit 1).

- [ ] **Step 4: Commit**

```bash
git add templates/Employees/view.php
git commit -m "feat(docs-empleado): panel derecho como lista plana con columna Carpeta"
```

---

### Task 4: Script JS de filtrado maestro-detalle

**Files:**
- Modify: `templates/Employees/view.php` (insertar `<script>` justo después del cierre del grid de la sección de documentos, antes del `<?php endif; ?>` ≈ línea 616)

- [ ] **Step 1: Insertar el script de filtrado**

Justo después del `</div>` que cierra `<div style="display:grid;grid-template-columns:260px 1fr;...">` (el grid de dos columnas del gestor docs) y antes de `<?php endif; ?>`, insertar:

```php
                <script>
                (function () {
                    var tree = document.getElementById('docTree');
                    if (!tree) { return; }
                    var rows = Array.prototype.slice.call(document.querySelectorAll('#docList .doc-row'));
                    var empty = document.getElementById('docEmpty');

                    function selectFolder(id) {
                        tree.querySelectorAll('.doc-tree-item').forEach(function (el) {
                            el.classList.toggle('is-active', el.getAttribute('data-folder-id') === id);
                        });
                        var visible = 0;
                        rows.forEach(function (row) {
                            var show = (id === 'all') || (row.getAttribute('data-folder-id') === id);
                            row.style.display = show ? '' : 'none';
                            if (show) { visible++; }
                        });
                        if (empty) { empty.style.display = visible === 0 ? '' : 'none'; }
                    }

                    tree.addEventListener('click', function (e) {
                        var item = e.target.closest('.doc-tree-item');
                        if (!item) { return; }
                        selectFolder(item.getAttribute('data-folder-id'));
                    });

                    selectFolder('all');
                })();
                </script>
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run: `php -l templates/Employees/view.php`
Expected: `No syntax errors detected in templates/Employees/view.php`

- [ ] **Step 3: Commit**

```bash
git add templates/Employees/view.php
git commit -m "feat(docs-empleado): JS de filtrado maestro-detalle por carpeta"
```

---

### Task 5: Verificación manual en navegador

**Files:** ninguno (verificación).

- [ ] **Step 1: Levantar el servidor (si no está corriendo)**

Run: `php bin/cake server`
Expected: servidor escuchando en `http://localhost:8765`.

- [ ] **Step 2: Abrir un empleado con documentos y verificar**

Navegar a `/employees/view/{id}` de un empleado que tenga al menos un documento (p. ej. el de la captura, "JHON FREDDY ACOSTA"). Confirmar:

1. El nodo raíz (nombre del empleado) aparece resaltado por defecto y se listan **todos** los documentos.
2. Clic en una carpeta del árbol → el panel derecho muestra **solo** los documentos de esa carpeta y el resaltado se mueve a esa carpeta.
3. El panel derecho **no** muestra nombres de carpeta como filas; cada fila es un documento con su columna "Carpeta".
4. Las subcarpetas aparecen indentadas bajo su padre y son seleccionables.
5. Seleccionar una carpeta sin documentos muestra el empty-state "Esta carpeta no tiene documentos.", sin filas residuales.
6. Los botones Abrir y Eliminar siguen funcionando.

Expected: los 6 puntos se cumplen.

- [ ] **Step 3: Verificar empleado sin carpetas**

Abrir un empleado sin carpetas (si existe) y confirmar que el empty-state previo de la sección sigue intacto y no hay errores en consola.

Expected: sin regresiones; consola del navegador sin errores JS.
