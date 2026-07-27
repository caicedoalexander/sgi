# RBAC en la vista de legalización de anticipos

**Fecha:** 2026-07-09
**Módulo:** Advances (pipeline `legalizations`)
**Estado:** Diseño aprobado

## Problema

La fase 2 del anticipo (legalización) no aplica en la capa de vista las reglas de
rol que sí aplica en el backend. El resultado es una pantalla que ofrece acciones
que el usuario no puede ejecutar, una bandeja que no distingue por rol y
redirecciones que devuelven al usuario a una vista que ya no puede operar.

### Lo que sí funciona hoy

Las 16 acciones mutantes de `AdvancesController` pasan por
`AdvanceLegalizationActionPolicy`, que compone
`AuthorizationFacade::canOperate(rol, 'legalizations', paso)` contra
`pipeline_permissions` con el predicado de estado de la entidad. **No existe
agujero de escritura.** Un POST sin permiso obtiene el flash de `_denyAction()`.

El policy expone **15 predicados**. Las 16 acciones usan 14 de ellos:
`canConsolidateApproval` sirve a tres (`moveToRevision`, `sendApprovalLinks`,
`modifyApprovers`), y `canAuthorizeRefundPayment` no lo usa ninguna acción de este
controller — la autorización del reintegro se postea a `InvoicePaymentsController`.

### Lo que está roto

1. **`templates/Advances/legalization.php` es ciego al rol.** Ramifica solo por
   `$leg->status` (líneas 179, 196, 205, 224, 282, 330, 346, 363). Solo 4 de los
   15 predicados del policy llegan al ViewModel: `canRegisterRefund`,
   `canAuthorizeRefundPayment`, `canConfirmRefundPayment` y `canManageApprovers`.
   Los otros 11 nunca se consultan. El botón "Enviar a aprobación de área", los
   formularios de caso exacto / faltante / sobrante con sus campos de causación,
   "Marcar como firmado" y la confirmación de consignación se renderizan a
   cualquier usuario que abra la vista.

2. **Los elements reciben `'editable' => true` hardcodeado**
   (`legalization.php:175` y `:431`), pese a que `_linked_invoices.php` y
   `_soportes.php` ya aceptan ese parámetro y lo AND-ean con el estado.

3. **La vista abre con `#[Permission(action: 'view')]`.** Las seis vistas de
   trabajo del proyecto (Invoices, Refunds, PettyCashRecords, PaymentSchedulings,
   EmployeeNovelties, NoveltyLiquidationDocs) exigen `edit`. Advances es la única
   excepción. Basta `advances.can_view` para ver formularios de escritura, montos
   y la diferencia entre lo legalizado y lo anticipado.

4. **No existe bandeja filtrada por rol.** `legalizations` es el único de los seis
   pipelines sin `getVisibleStatuses()`. `pendingLegalization()` lista todas las
   legalizaciones activas para todos los roles, y
   `SidebarCounterService::getAdvancesPendingLegalizationCount()` es el único
   contador de bandeja de pipeline que no recibe `$roleId` — sus cinco hermanos
   (`pettyCashMineCount`, `refundsMineCount`, `advancesMineCount`,
   `noveltiesCount`, `liquidationMineCount`) sí lo reciben.

5. **Las 14 acciones redirigen siempre a `legalization/{id}`,** incluso las que
   avanzan de paso: el usuario termina en una vista que ya no puede operar. En
   `RefundsController` y `PettyCashRecordsController` avanzar o regresar devuelve
   a la bandeja.

6. **No hay aviso anticipado.** El único feedback es el flash posterior al intento.

## Alcance

Solo el pipeline `legalizations` y su vista. No se tocan los otros cinco módulos
de flujo, ni las reglas de negocio de las transiciones.

**Sin cambios de esquema.** No hay migraciones, columnas nuevas ni valores
persistidos nuevos. `AdvanceLegalizationService` no se toca en absoluto: los dos
métodos nuevos (`canOperateCurrentStep` y `getVisibleStatuses`) viven en
`AdvanceLegalizationActionPolicy`.

## Nota sobre el canon

El proyecto **no tiene** hoy un patrón de ocultamiento rol-aware en la vista de
trabajo, ni un banner de solo lectura. `Refunds/edit.php:471` gatea el botón
Guardar con `canSave`, que es `isAgrupacion || isContabilidad || isTesoreria` —
puro estado, ciego al rol. Su protección real vive en
`RefundFieldAccessPolicy::filterEntityData()` (línea 76), que devuelve patch vacío
si `getEditableFields($roleId, $step) === []`. Es decir: en el canon actual un rol
sin permiso ve el formulario, pulsa Guardar y no ocurre nada, en silencio.

Este diseño **mejora** ese canon en el módulo de anticipos, no lo replica. El
element `readonly_banner` se crea genérico para que los otros cinco módulos lo
adopten después. Advance queda como el único módulo con ocultamiento rol-aware en
la vista: un outlier por arriba, y deuda registrada para los demás.

En todo lo demás el diseño sigue el canon literal:

- Los flags rol-aware viajan como **bools sueltos** por el constructor del
  ViewModel. Es el patrón unánime: `RefundEditViewModel` (7 bools, 25 parámetros
  en total), `PettyCashEditViewModel` (6), `PaymentSchedulingEditViewModel` (4),
  `NoveltyLiquidationDocEditViewModel` (4), `AdvanceLegalizationViewModel` (4).
- **No** se crea un objeto en `ViewModel/Support/` para transportarlos: ninguno de
  los cuatro que existen (`PipelineEditFlags`, `SubmitButton`, `PaymentOptions`,
  `LegalizationSummary`) cruza el constructor ni recibe un servicio. Se construyen
  dentro del VM y se desempaquetan en propiedades escalares
  (`RefundEditViewModel:107-119`).
- La construcción del ViewModel se extrae a un método privado fábrica del
  controller, espejo de `RefundsController::_buildEditViewModel()` (línea 391).

## Decisiones

| # | Decisión | ¿Canon? |
|---|---|---|
| 1 | Vista completa degradada a consulta: sin controles, con banner de solo lectura | No existe canon. Es una mejora |
| 2 | Bandeja y badge del sidebar filtrados por los pasos operables del rol | Canon (5/5 módulos) |
| 3 | Redirección híbrida tras las transiciones | Parcial: avanzar→bandeja es unánime; cierre→`view` sigue a Refunds (PettyCash va a `index`) |
| 4 | `legalization()` exige `#[Permission(action: 'edit')]` | Canon (6/6 vistas de trabajo) |
| 5 | El banner no nombra los roles que operan el paso | Evita una consulta inversa a `pipeline_permissions` que no existe en ningún servicio |

## Arquitectura

Dos capas independientes, y esa independencia es el punto:

- **Los flags deciden qué se dibuja.** Viven en el ViewModel, los calcula el
  controller llamando al policy.
- **El policy decide qué se ejecuta.** Sigue guardando cada POST con
  `_denyAction()`. Ninguno de los 14 gates existentes se toca.

Ocultar un control nunca sustituye al gate del POST. Si alguien borra un `if` del
template, el backend sigue rechazando.

### Flujo: GET `/advances/legalization/{id}`

```
beforeFilter → _enforcePermission → Permission('edit')
   └── falta advances.can_edit → ForbiddenException (403)
legalization()
   ├── no es Anticipo → flash + redirect index      (guarda existente)
   ├── no hay legalización → flash + redirect view  (guarda existente)
   └── _buildLegalizationViewModel()
         └── 14 llamadas a AdvanceLegalizationActionPolicy → ViewModel
template
   ├── !canOperateCurrentStep && !isLegalized() → element readonly_banner
   └── cada control según su flag
```

### Flujo: POST `/advances/markExact/{id}`

```
PipelineAction(legalizations) sin step → salta el gate CRUD
_loadLegalization()
actionPolicy->canMarkExact() → _denyAction() si falla
_ensureExpectedStatus()                    (lock optimista existente)
service->markExact() → muta $leg->status = 'legalizada'
_redirectAfterTransition() → view
```

## Componentes

### 1. `AdvanceLegalizationActionPolicy` — método nuevo

```php
public function canOperateCurrentStep(AdvanceLegalization $leg, int $roleId): bool
{
    return !$leg->isLegalized() && $this->_canOperate($roleId, $leg->status);
}
```

Espejo de `RefundActionPolicy::canOperateCurrentStep()` (línea 46), incluida la
guarda del estado terminal. Alimenta el banner.

La guarda `isLegalized()` es redundante — `legalizada` no figura en
`STEPS_BY_PIPELINE['legalizations']`, así que `canOperate` ya devolvería `false` —
pero se conserva explícita por paridad con el policy hermano y por legibilidad.

### 2. `AdvancesController` — tres cambios

- `legalization()` pasa a `#[Permission(action: 'edit')]`.
- Se extrae `_buildLegalizationViewModel(Invoice $invoice, AdvanceLegalization $leg, object $user): AdvanceLegalizationViewModel`,
  espejo de `RefundsController::_buildEditViewModel()`. Concentra las 14 llamadas
  al policy (10 predicados nuevos + los 4 ya cableados) y la carga de datos
  crudos. La action queda delgada.
- Helper de redirección. El service ya muta `$leg->status` in-place
  (`AdvanceLegalizationService:927`), así que tras `$result->success` el nuevo
  estado está disponible en la entidad:

```php
private function _redirectAfterTransition(AdvanceLegalization $leg, int $advanceId): Response
{
    return $leg->isLegalized()
        ? $this->redirect(['action' => 'view', $advanceId])
        : $this->redirect(['action' => 'pendingLegalization']);
}
```

**El helper solo se invoca en el camino de éxito.** Cuando `$result->success` es
falso, la acción conserva su redirect actual a `legalization/{id}` para que el
usuario vea el flash de error sin perder el contexto. Esto importa: una acción que
mueve el paso pero falla la validación (p. ej. `moveToAprobacion` sin factura
vinculada) deja `$leg->status` sin cambiar, y el helper la mandaría a
`pendingLegalization` — sacando al usuario de la vista con solo un flash.

```php
if (!$result->success) {
    $this->Flash->error($result->firstError() ?? 'Error al avanzar.');
    return $this->redirect(['action' => 'legalization', $id]);
}
$this->Flash->success('…');
return $this->_redirectAfterTransition($leg, $id);
```

Reparto de las 16 acciones:

| Comportamiento | Acciones | Destino |
|---|---|---|
| Mueven el paso | `moveToAprobacion`, `moveToRevision`, `returnFromAprobacion`, `markSigned`, `returnToAprobacion`, `registerShortage`, `registerSurplus`, `registerRefund` | `pendingLegalization` |
| Cierran la legalización | `markExact`, `confirmShortage`, `confirmRefundPayment` | `Advances/view/{id}` |
| No mueven el paso | `linkInvoices`, `unlinkInvoice`, `uploadRelationDocument`, `sendApprovalLinks`, `modifyApprovers` | `legalization/{id}` (sin cambio) |

`linkCandidates` es un endpoint AJAX de solo lectura y no redirige.

### 3. `AdvanceLegalizationViewModel` — 10 bools sueltos más

Ya tiene 4. El constructor queda en 23 parámetros, por debajo de los 25 de
`RefundEditViewModel`.

| Flag | Bloque de UI que gatea |
|---|---|
| `canOperateCurrentStep` | banner de solo lectura (negado) |
| `canLinkInvoices` | `_linked_invoices` → `editable`, y el modal `advanceLinkModal` |
| `canUploadRelationDocument` | `_soportes` → `editable` |
| `canMoveToAprobacion` | card de acción en `validacion` |
| `canMarkSigned` | botón "Marcar como firmado" |
| `canReturnToAprobacion` | botón "Devolver a Aprobación" y el `regressStatusModal` |
| `canMarkExact` | rama `abs($diff) < 0.005` de la card de Contabilidad |
| `canRegisterShortage` | rama `$diff > 0.005` |
| `canRegisterSurplus` | rama `else` |
| `canConfirmShortage` | card de Tesorería, caso faltante |

Los 4 flags existentes (`canRegisterRefund`, `canAuthorizeRefundPayment`,
`canConfirmRefundPayment`, `canManageApprovers`) no cambian.

**Dos de los 15 predicados no se pasan, y no es un olvido.** Ambas exclusiones se
documentan en el docblock del ViewModel:

- `canUnlinkInvoice` — predicado idéntico a `canLinkInvoices` (ambos exigen
  `validacion`), y el element usa un único `$editable` para vincular y desvincular.
- `canReturnFromAprobacion` — el botón vive en `_approval_panel.php:93-101`, ya
  gateado por `canManageApprovers` (= `canConsolidateApproval`). Ambos predicados
  son equivalentes para cualquier par (rol, legalización): los dos exigen
  `status === 'aprobacion'` y `_canOperate($roleId, 'aprobacion')`.

15 predicados − 2 exclusiones + `canOperateCurrentStep` = las 14 llamadas de
`_buildLegalizationViewModel()`.

### 4. `templates/Advances/legalization.php`

Cada `if ($leg->status === X)` pasa a `if ($leg->status === X && $canY)`. Los
`'editable' => true` de las líneas 175 y 431 pasan a ser los flags reales. Los dos
modales del pie (líneas 463 y 487) se gatean igual. Encima de la zona de acciones:

```php
<?php if (!$canOperateCurrentStep && !$leg->isLegalized()): ?>
<?= $this->element('readonly_banner', [
    'stepLabel' => $legPipelineLabels[$leg->status] ?? $leg->status,
]) ?>
<?php endif; ?>
```

Los elements `_linked_invoices.php` y `_soportes.php` **no se tocan**: ya aceptan
`$editable` y lo AND-ean con el estado (`_linked_invoices.php:29,84`;
`_soportes.php:62,81`). Solo cambia lo que el caller les pasa.

### 5. `templates/element/readonly_banner.php` — nuevo

Usa el átomo `.banner` que ya existe en `Invoices/edit.php`, `Advances/view.php` y
`PettyCashRecords/add.php`. Recibe `stepLabel` y renderiza:

> **Solo lectura** — Sin permisos para operar el paso {stepLabel}.

Usa la variante `.banner info` (cian), no `.banner` pelado: en
`webroot/css/components.css:2133-2140` el `.banner` sin variante lleva el borde
`--primary-color` (verde, semántica de éxito) y su `.banner-icon` no recibe fondo.
La voz es impersonal, sin tutear (`docs/design/reglas-copy.md:28-29`).

Genérico, sin dependencias del dominio de anticipos, para que los otros cinco
módulos lo adopten cuando se salde la deuda.

### 6. Enlaces a la vista

Con `Permission('edit')`, un rol con solo `can_view` que escriba la URL a mano
recibe un 403 — el mismo comportamiento que `Refunds::edit`. No se introduce
redirect suave: ningún módulo lo tiene. Para que nadie llegue al 403 por la vía
normal, se ocultan los dos enlaces existentes a quien no tenga `can_edit`:

- `templates/Advances/view.php:170` — botón "Gestionar legalización". Se oculta el
  botón entero.
- `templates/Invoices/add.php:41` — el enlace está **embebido en una oración**
  ("Comprobante para el [Anticipo #…]."). Ocultar el nodo dejaría la frase colgando
  ("Comprobante para el ."). Sin `can_edit`, el número se renderiza como texto
  plano en lugar de enlace.
- `src/Controller/InvoicesController.php:288` — **un redirect, no un enlace.** Tras
  crear un comprobante vinculado a un anticipo, la acción redirige a
  `Advances::legalization`. Un usuario con `invoices.can_create` pero sin
  `advances.can_edit` guardaría con éxito y aterrizaría en un 403. Ocultarlo no es
  posible: hay que ramificar el destino.

```php
return $this->redirect([
    'controller' => 'Advances',
    'action' => $this->_checkPermission('advances', 'edit') ? 'legalization' : 'view',
    $advanceId,
]);
```

`AppController::_checkPermission()` es `protected` y ya se usa así en
`AssetsController`, `ConsumablesController` y `PettyCashRecordsController`.

`$userPermissions` es la matriz completa de todos los módulos
(`AppController::_setUserPermissions()` → `getPermissionsForRoleAsMatrix()`), así
que `['advances']['can_edit']` está disponible incluso desde `Invoices/add.php`.
`Advances/view.php:39` ya usa esa misma clave para otro botón.

### 7. Bandeja y contador

- `AdvanceLegalizationActionPolicy::getVisibleStatuses(int $roleId): array` →
  `$this->auth->operableSteps(new UserContext($roleId), PipelineStepConstants::PIPELINE_LEGALIZATIONS)`.

  **No va en `AdvanceLegalizationService`,** aunque los otros cinco módulos alojen
  su `getVisibleStatuses()` en el `{Modulo}PipelineService`. Ese canon no tiene
  dónde aterrizar aquí: CLAUDE.md excluye explícitamente a
  `AdvanceLegalizationService` de ser un coordinador de pipeline ("gobierna el
  sub-pipeline de legalización sobre `advance_legalizations`"). Y en la práctica
  el service no inyecta `AuthorizationFacade`, así que añadírselo obligaría a
  tocar los **8 tests** que lo instancian a mano.

  El policy sí inyecta `AuthorizationFacade`, ya está registrado en el container y
  su docblock lo define como el lugar donde vive "la dimensión de rol×paso". El
  controller ya lo tiene inyectado.
- `AdvancesController::pendingLegalization()` aplica
  `_visibleStatusConditions('AdvanceLegalization.status', $visibleStatuses)` dentro
  del `innerJoinWith`. La condición `!= legalizada` actual se vuelve redundante
  (`legalizada` no es un step) pero se conserva como defensa en profundidad.
- `SidebarCounterService::getAdvancesPendingLegalizationCount(int $roleId)` recibe
  el rol y aplica el mismo filtro. El caller (línea 99) ya tiene `$roleId` en
  contexto, **pero el servicio necesita una dependencia nueva**: su constructor
  (líneas 26-31) inyecta `InvoicePipelineService`, `NoveltyPipelineService`,
  `PettyCashPipelineService` y `RefundPipelineService`, y ninguno resuelve los
  pasos operables de `legalizations`. Hay que añadir
  `AdvanceLegalizationActionPolicy` al constructor (una sola dependencia, frente a
  las cinco del service). Ojo con la confusión fácil: `getAdvancesMineCount()` usa
  `invoicePipeline` porque el Anticipo vive en el pipeline de **facturas** — la
  legalización es otro pipeline y necesita otra fuente.

### 8. Respuestas AJAX

`confirmShortage` y `uploadRelationDocument` responden JSON y el JS hace
`window.location.reload()` (`legalization.php:546` y `:587`).

`confirmShortage` ahora cierra la legalización y debe llevar a `view`, así que su
respuesta JSON gana un campo `redirect` y el JS pasa a:

```js
data.redirect ? (window.location = data.redirect) : window.location.reload();
```

`uploadRelationDocument` no mueve el paso: sigue recargando. El campo `redirect`
es opcional, así que el JS compartido funciona con ambos.

## Casos límite

**Regresión de acceso — el riesgo serio.** `PipelineViewCoercion::apply()` fuerza
`can_view` cuando se marca ≥1 paso de un pipeline, pero **no** fuerza `can_edit`.
Al mover la vista de `view` a `edit`, cualquier rol que hoy opere legalizaciones
sin tener `advances.can_edit` deja de poder abrir la pantalla. El mismo
acoplamiento implícito ya existe en los otros cinco módulos, pero aquí lo estamos
introduciendo. Verificación obligatoria antes de desplegar:

```sql
SELECT r.name, pp.step, p.can_edit
FROM pipeline_permissions pp
JOIN roles r ON r.id = pp.role_id
LEFT JOIN permissions p ON p.role_id = r.id AND p.module = 'advances'
WHERE pp.pipeline = 'legalizations' AND pp.can_operate = 1;
```

Toda fila con `can_edit` falso o nulo es un rol que se rompe. Se arregla desde
`/roles/edit`, no con código.

**Esa query manual es la única red.** `bin/cake permissions_audit` **no** detecta
esta regresión: `PermissionsAuditCommand:64-68` compara `pipeline_permissions.can_operate`
contra `permissions.can_view` — enforcea "operar implica ver", que es justamente la
invariante que *no* estamos tocando. El acoplamiento nuevo es "operar implica
editar", y ningún comando lo cubre. Ampliar el comando o extender
`PipelineViewCoercion` afectaría a los seis pipelines: queda fuera de alcance, y se
acepta conscientemente la verificación manual.

**El Administrador.** No bypassa `advances`: `ADMIN_BYPASS_MODULES` cubre solo
`users` y `roles`. Hoy ya no puede *ejecutar* acciones de legalización si le faltan
filas en `pipeline_permissions`, pero sí las ve. Después del cambio dejará de
verlas y su bandeja podría quedar vacía. Se verifica con la misma query de arriba.

**Estado terminal.** `legalizada` ya tiene su banner de cierre
(`legalization.php:383`). El banner de solo lectura se suprime con
`&& !$leg->isLegalized()`.

**Rol sin ningún paso operable.** `operableSteps()` devuelve `[]`,
`_visibleStatusConditions()` produce `1 = 0`, la bandeja sale vacía y el badge
marca 0. Es lo que ya hace "Mis Anticipos". No hay caso especial que escribir.

**Carreras entre usuarios.** Sin cambios. `_ensureExpectedStatus()` ya cubre
`markExact`, `registerShortage` y `registerSurplus` con el `expected_status` oculto
del formulario.

## Testing

**Unitarios del policy.** `canOperateCurrentStep` con tres casos: legalización
cerrada (`false`), rol sin permiso del paso (`false`), rol con permiso (`true`).

**Unitario del ViewModel.** Los diez flags nuevos llegan a `build()`.

**Integración sobre el controller** — donde vive el bug reportado:

| Escenario | Aserción |
|---|---|
| `GET legalization` sin `can_edit` | 403 |
| `GET legalization` con `can_edit`, sin paso operable | 200, banner presente, formulario de acción **ausente** |
| `GET legalization` con paso operable | 200, formulario presente |
| `POST markExact` con permiso | redirect a `view` |
| `POST markExact` sin permiso | flash de denegación, estado sin cambios |
| `POST moveToAprobacion` con permiso | redirect a `pendingLegalization` |
| `GET pendingLegalization` como Contabilidad | solo legalizaciones en `contabilidad` |
| `GET pendingLegalization` sin pasos operables | lista vacía |
| `SidebarCounterService` con dos roles distintos | contadores distintos |

Las aserciones negativas son las que atrapan la regresión real: usar
`assertResponseNotContains()` sobre el atributo `name` de los inputs, no sobre
texto visible.

La suite se corre con `vendor/bin/phpunit` directo, no con `composer test`.
Correr limpio antes de concluir que hay regresión: suites consecutivas producen
errores en cascada falsos.

## Fuera de alcance

- Adoptar `readonly_banner` en los otros cinco módulos de flujo (deuda registrada).
- Hacer `PipelineEditFlags` rol-aware en Refunds y PettyCash.
- Extender `PipelineViewCoercion` para forzar `can_edit` además de `can_view`
  (afectaría a los seis pipelines).
- Cualquier cambio en las reglas de negocio de las transiciones o en
  `AdvanceLegalizationService`.
