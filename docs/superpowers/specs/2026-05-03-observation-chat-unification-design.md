# Unificación AJAX del chat de observaciones

**Fecha:** 2026-05-03
**Estado:** Diseño aprobado — pendiente de plan de implementación
**Antecedente:** `2026-05-03-ajax-document-uploader-design.md` (mismo patrón aplicado a la subida de documentos).

## Contexto

El SGI tiene chats de observaciones en seis módulos. Solo dos (Invoices y PaymentSchedulings) funcionan vía AJAX hoy, y aun así con JS inline duplicado y casi idéntico entre ambas vistas. Los otros cuatro (EmployeeNovelties, Employees, PettyCashRecords, Refunds) hacen POST clásico con redirect.

Recientemente se unificó la subida de documentos creando `webroot/js/sgi-document-uploader.js` + un partial compartido + una rama JSON normalizada en los controllers. El resultado es consistente, fácil de mantener y reduce divergencia visual. Este spec aplica el mismo patrón al chat de observaciones.

### Estado actual

| Módulo | Controller action | UX hoy |
|---|---|---|
| Invoices | `InvoicesController::addObservation` | AJAX inline en `edit.php` |
| PaymentSchedulings | `PaymentSchedulingsController::addObservation` | AJAX inline en `edit.php` (casi idéntico al de Invoices) |
| EmployeeNovelties | `EmployeeNoveltiesController::addObservation` | POST + redirect |
| Employees | `EmployeesController::addObservation` | POST + redirect (vive en `view.php`) |
| PettyCashRecords | `PettyCashRecordsController::addObservation` | POST + redirect |
| Refunds | `RefundsController::addObservation` | POST + redirect |

Las seis tablas (`InvoiceObservations`, `PaymentSchedulingObservations`, `NoveltyObservations`, `EmployeeObservations`, `PettyCashObservations`, `RefundObservations`) comparten estructura: FK al registro padre, `user_id`, `message`, `created`.

## Objetivo

Tener un único helper JS y un único partial server-side que reemplacen el JS inline + HTML repetido de los seis módulos, con un contrato JSON común en todas las acciones `addObservation`. El alcance incluye los 6 módulos.

## No objetivos

- No se crea un `ObservationService` ni se extrae lógica común a un trait. Mantenemos simetría con la unificación de documentos, que dejó la lógica en cada controller.
- No se modifican tablas, entidades, migraciones ni reglas de permisos.
- No se introduce edición ni borrado de observaciones (pueden añadirse después; el contrato JSON ya devuelve `id` para habilitarlo).
- No se cambia el orden cronológico (las observaciones se siguen mostrando de más antigua a más reciente).

## Decisiones tomadas durante el brainstorm

- **Alcance:** los 6 módulos en una sola tanda (opción A).
- **Employees recibe el mismo trato que los módulos de flujo** (opción A en la pregunta de diferenciación). Las reglas de quién puede comentar siguen viviendo en el controller; el helper y el partial son agnósticos al pipeline.
- **Enfoque 1 elegido**: espejo exacto del patrón de documentos. Sin extracción a servicio.

## Diseño

### Archivos nuevos

| Archivo | Propósito |
|---|---|
| `webroot/js/sgi-observation-chat.js` | Helper único `SgiObservationChat.init({...})` |
| `templates/element/observation_item.php` | Render server-side de una observación |
| `templates/element/observation_item_template.php` | `<template>` con los mismos `data-slot`, usado por el helper para clonar al insertar vía AJAX |

Las clases `.sgi-observation-item`, `.sgi-observation-header`, `.sgi-observation-author`, `.sgi-observation-date`, `.sgi-observation-body` se agregan a `webroot/css/styles.css` siguiendo el lenguaje visual del SGI (sin sombras; borde inferior 1px neutro entre items; fecha en micro-caps `.61rem`).

### Archivos modificados

**Controllers** (rama JSON normalizada en `addObservation`):

- `src/Controller/InvoicesController.php` (ya tiene rama JSON; solo normalizar)
- `src/Controller/PaymentSchedulingsController.php` (ya tiene rama JSON; solo normalizar)
- `src/Controller/EmployeeNoveltiesController.php`
- `src/Controller/EmployeesController.php`
- `src/Controller/PettyCashRecordsController.php`
- `src/Controller/RefundsController.php`

**Templates** (reemplazan JS inline + HTML del item por el partial + `init` del helper):

- `templates/Invoices/edit.php`
- `templates/PaymentSchedulings/edit.php`
- `templates/EmployeeNovelties/edit.php`
- `templates/Employees/view.php`
- `templates/PettyCashRecords/edit.php`
- `templates/Refunds/edit.php`

### Helper JS — `SgiObservationChat`

Ubicación: `webroot/js/sgi-observation-chat.js`. Se carga bajo demanda en cada vista que tenga chat (igual que `sgi-document-uploader.js`).

**API:**

```js
SgiObservationChat.init({
    formSelector:         '#observation-form',
    listSelector:         '#observations-list',
    emptySelector:        '#observations-empty-state',
    itemTemplateSelector: '#observation-item-template',
    csrfToken:            '<?= $this->request->getAttribute('csrfToken') ?>'
});
```

El contador (badge) se resuelve automáticamente desde el `.card` que contiene `listSelector`, buscando un `.sgi-folder-count` (mismo convenio que el uploader).

**Comportamiento:**

1. Intercepta `submit` del form.
2. Valida que `textarea[name=message]` no esté vacío (trim); si lo está, no envía.
3. Hace `fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken }, body: new FormData(form) })`.
4. Con `success: true`:
   - Clona `itemTemplateSelector`, llena los `data-slot` (`user_name`, `message`, `created`), inserta al final de `listSelector` (cronológico).
   - Si existe `emptySelector` y está visible, lo oculta.
   - Incrementa el contador.
   - Limpia el textarea y le devuelve foco.
   - Hace `scrollIntoView({ block: 'end' })` sobre el item recién agregado.
5. Con `success: false` o error de red: muestra `data.error` (o mensaje genérico) en un `<div class="sgi-chat-error">` dentro del form. Sin `alert()` nativo (mejora respecto al JS actual de Invoices/PaymentSchedulings).

**Escape de HTML:** los slots se pueblan con `textContent` (no `innerHTML`). Para el `message`, los `\n` se reemplazan insertando nodos `<br>` reales en el DOM tras el `textContent`. Equivalente seguro a lo que hoy hacen los inline.

**Sin estado global:** cada `init` opera sobre los selectores recibidos; permite múltiples chats por página si en el futuro hace falta.

### Partial PHP — `observation_item.php`

```php
<?php
/** @var \Cake\Datasource\EntityInterface $observation */
?>
<div class="sgi-observation-item" data-observation-id="<?= h($observation->id) ?>">
    <div class="sgi-observation-header">
        <span class="sgi-observation-author" data-slot="user_name">
            <?= h($observation->user->full_name ?? 'Usuario') ?>
        </span>
        <span class="sgi-observation-date" data-slot="created">
            <?= h($observation->created->format('d/m/Y H:i')) ?>
        </span>
    </div>
    <div class="sgi-observation-body" data-slot="message">
        <?= nl2br(h($observation->message)) ?>
    </div>
</div>
```

Uso desde cada template:

```php
<?php foreach ($record->xxx_observations as $obs): ?>
    <?= $this->element('observation_item', ['observation' => $obs]) ?>
<?php endforeach; ?>
```

### Template `<template>` — `observation_item_template.php`

```html
<template id="observation-item-template">
    <div class="sgi-observation-item">
        <div class="sgi-observation-header">
            <span class="sgi-observation-author" data-slot="user_name"></span>
            <span class="sgi-observation-date" data-slot="created"></span>
        </div>
        <div class="sgi-observation-body" data-slot="message"></div>
    </div>
</template>
```

**Garantía de paridad visual:** el render server-side y el render JS producen el mismo DOM. Esto evita los bugs de "se ve distinto al recargar".

### Contrato JSON

**Request:** `POST /<modulo>/add-observation/<id>` con `Accept: application/json`, header `X-CSRF-Token`, body `message=<texto>`.

**Response OK:**

```json
{
    "success": true,
    "observation": {
        "id": 123,
        "message": "Texto tal cual lo escribió el usuario",
        "user_name": "Juan Pérez",
        "created": "03/05/2026 14:32"
    }
}
```

**Response error** (HTTP 200 con `success:false`, igual que el uploader):

```json
{ "success": false, "error": "No se pudo agregar la observación." }
```

### Patrón en cada controller

```php
public function addObservation($id = null)
{
    $this->request->allowMethod(['post']);
    $user = $this->_getCurrentUser(); // o equivalente local

    $message = trim((string)$this->request->getData('message'));

    $observationsTable = $this->fetchTable('XxxObservations');
    $observation = $observationsTable->newEntity([
        '<xxx>_id' => $id,
        'user_id'  => $user->id,
        'message'  => $message,
    ]);

    $saved = $message !== '' && $observationsTable->save($observation);

    if ($this->_isJsonRequest()) {
        if (!$saved) {
            return $this->_jsonResponse([
                'success' => false,
                'error'   => $message === ''
                    ? 'El mensaje no puede estar vacío.'
                    : 'No se pudo agregar la observación.',
            ]);
        }
        $observation->user = $user;
        return $this->_jsonResponse([
            'success'     => true,
            'observation' => [
                'id'        => $observation->id,
                'message'   => $observation->message,
                'user_name' => $user->full_name,
                'created'   => $observation->created->format('d/m/Y H:i'),
            ],
        ]);
    }

    if ($saved) {
        $this->Flash->success('Observación agregada.');
    } else {
        $this->Flash->error(
            $message === ''
                ? 'El mensaje no puede estar vacío.'
                : 'No se pudo agregar la observación.'
        );
    }

    return $this->redirect($this->referer(['action' => 'edit', $id]));
}
```

**Diferencias por controller:**

- **Employees:** redirect de fallback a `view`, no a `edit`.
- **Invoices:** mantiene `_redirectForInvoice` para el fallback no-AJAX.
- **PaymentSchedulings, PettyCashRecords, Refunds, EmployeeNovelties:** redirect estándar al `edit`.

**Permisos:** intactos. Cada controller hereda los chequeos de `AppController::beforeFilter()` vía `_enforcePermission()`. No tocamos eso.

**Sin extracción a servicio:** la duplicación de ~10 líneas por controller es aceptada como contrapartida de mantener simetría con el patrón de documentos.

### Riesgos identificados

- **CSRF en AJAX**: cakephp/authentication requiere el token. El `init` del helper recibe `csrfToken` del request. El helper de documentos ya resolvió esto; replicamos el patrón.
- **Disponibilidad de `$user->full_name`**: cada controller obtiene el usuario actual de manera distinta (`_getCurrentUser`, `Authentication->getIdentity()`). Hay que verificar que `full_name` esté disponible en los seis casos; si alguno solo tiene `id`, recargar desde `Users` antes de devolver el JSON.
- **Diferencias de FK**: las seis tablas usan FK distintas (`invoice_id`, `payment_scheduling_id`, etc.). El contrato JSON no cambia; el `newEntity` sí varía por módulo (esperado).

## Plan de migración

Un commit por paso, ordenados para minimizar riesgo y permitir revisión incremental:

1. **Crear infra compartida**: helper JS, partial, template, clases CSS. Sin tocar ningún módulo.
2. **Migrar Invoices**: primer cliente, ya tiene AJAX; valida que el helper se comporta igual o mejor que el JS inline actual.
3. **Migrar PaymentSchedulings**: segundo cliente AJAX.
4. **Migrar EmployeeNovelties**: primer cliente nuevo a AJAX (era POST+redirect).
5. **Migrar Employees**: vista en `view.php`, valida que el helper funciona fuera de un `edit`.
6. **Migrar PettyCashRecords**.
7. **Migrar Refunds**.
8. **Code-review final** + `composer cs-fix` si hace falta.

Cada paso del 2 al 7 es independiente: si algo se rompe en uno, no afecta a los demás (la rama no-JSON sigue funcionando como fallback hasta que se migra el template del módulo correspondiente).

## Validación manual

Por proyecto policy, no hay tests automatizados. Tras cada migración de módulo, ejecutar `php bin/cake server` y validar en el navegador:

1. **Caso feliz**: abrir un registro con permiso para comentar, escribir un mensaje, enviar → aparece la observación al final de la lista sin recargar, contador del badge sube en 1, textarea se limpia y queda enfocado.
2. **Estado vacío**: si no había observaciones, "Sin observaciones aún" desaparece tras la primera.
3. **Mensaje vacío**: enviar con textarea vacío o solo espacios → no envía o muestra error inline; nada se inserta y el contador no cambia.
4. **Render inicial vs incremental**: tras enviar una observación AJAX, recargar la página y comprobar que la observación recién creada se ve idéntica (mismo HTML, mismas clases, misma alineación).
5. **Fallback no-JS**: deshabilitar JS, enviar una observación → debe funcionar vía POST clásico con redirect y Flash.
6. **Caracteres especiales**: enviar `<script>alert(1)</script>` → se renderiza como texto, no se ejecuta.
7. **Saltos de línea**: enviar mensaje multilínea → se preservan los `<br>`.
8. **Permisos**: usuario sin permiso de edición sobre el registro no ve el form (sin regresión vs. hoy).
