# Filtros del listado de facturas y badge de vinculación

**Fecha:** 2026-07-10
**Estado:** Diseño aprobado
**Módulo:** Invoices (listados `index`, `all`, `rejected`, `overdue`)

## Problema

El listado de facturas no indica en ninguna parte que una factura pertenece a un
registro de otro módulo (caja menor, reintegro, anticipo) ni que está agendada en
una programación de pagos. Y los filtros que deciden qué factura aparece en qué
listado usan tres criterios distintos para el mismo problema, con el resultado de
que unas facturas desaparecen según su estado, otras aparecen siempre aunque
estén agrupadas, y "Todas las Facturas" no muestra todas.

## Diagnóstico

Las cuatro acciones (`index`, `all`, `rejected`, `overdue`) renderizan la misma
plantilla `templates/Invoices/index.php` y comparten `_buildInvoiceQuery()`
(`src/Controller/InvoicesController.php:586-590`), que excluye siempre
`document_type = 'Anticipo'`. Las cuatro acciones vuelven a repetir esa misma
condición por su cuenta.

El hallazgo que ordena todo lo demás: **cuando una factura se agrupa o se vincula,
su `pipeline_status` deja de ser suyo y pasa a ser un espejo del registro padre.**
Los tres servicios padre lo escriben con `updateAll`:

| Servicio | Línea | Escribe sobre |
|---|---|---|
| `PettyCashService` | 836 | `invoices.pipeline_status` where `petty_cash_record_id` |
| `RefundPipelineService` | 702 | `invoices.pipeline_status` where `refund_id` |
| `AdvanceLegalizationService` | 311 | `invoices.pipeline_status` where `advance_id` |

Es decir: esa factura ya no se opera desde el módulo de Facturas. La mueve su
padre. Sin embargo el listado le sigue dibujando una `pipeline-mini` de seis
pasos, como si avanzara sola.

Sobre esa realidad, los filtros actuales son inconsistentes:

| Vínculo | ¿El padre gobierna el estado? | Criterio de filtrado actual en `index` |
|---|---|---|
| Caja menor agrupada | Sí | Por **estado**: se oculta si salió de `aprobacion` |
| Reintegro agrupado | Sí | **Ninguno**: siempre visible |
| Legalización / Recibo de Caja vinculado | Sí | **Ninguno**: siempre visible |
| Anticipo | Es el padre | Por **tipo de documento**: nunca visible, ni en `all` |
| Ítem de programación de pagos | No | Ninguno (correcto) |

Ninguno de los tres criterios pregunta lo único que importa: si la factura tiene
un padre.

La inconsistencia ya obligó a duplicar código. `SidebarCounterService::getInvoiceStatusCounters()`
(`src/Service/SidebarCounterService.php:152-160`) lleva copiada a mano la condición
de caja menor, con este comentario:

> `// Espejo del filtro de InvoicesController::index(): excluir Caja Menor`
> `// que ya pasó a contabilidad o posterior, para que el badge "Mis`
> `// Facturas" no sobre-cuente respecto a la lista.`

Existe además un problema colateral en `overdue()`. En `InvoicesController::add()`
(líneas 246-250) los documentos sin vencimiento real copian `issue_date` en
`due_date` solo para satisfacer el `NOT NULL`. Como `overdue()` filtra por
`due_date < hoy`, cada legalización y cada recibo de caja aparece como "Vencida"
al día siguiente de emitirse.

## Modelo conceptual

El diseño se apoya en distinguir **dos clases de vínculo** que hasta ahora se
trataban como una sola o como ninguna:

**Contención.** El registro padre se lleva el pipeline: escribe el
`pipeline_status` de la factura. Son tres, y las tres viven como columna en
`invoices`: `petty_cash_record_id`, `refund_id`, `advance_id`.

**Referencia.** El vínculo no toca el estado de la factura: solo la agenda. Es
uno, la programación de pagos, y no tiene columna en `invoices` — vive en
`payment_scheduling_items.invoice_id`.

Esta distinción no es taxonómica: gobierna el comportamiento. Una factura
*contenida* sale de la bandeja de trabajo, pierde su `pipeline-mini` y muestra un
badge sólido. Una factura *referenciada* permanece en la bandeja, conserva su
`pipeline-mini` y muestra un badge punteado.

## Decisiones

1. **"Todas las Facturas" (`all`) es el archivo completo, salvo Anticipos.** El
   Anticipo es el registro padre y vive en `/advances`.
2. **"Mis Facturas" (`index`) oculta toda factura con padre**, sin importar su
   estado. Se opera desde su módulo padre. Si se desvincula, reaparece.
3. **"Rechazadas" y "Vencidas" son recortes de la bandeja**, no del archivo:
   heredan el filtro por pasos operables del rol y la exclusión de facturas con
   padre.
4. **Tienen vencimiento real** cuatro tipos de documento: `Factura`,
   `Nota Debito`, `Tarjeta de Crédito` y `Recibo`. El resto nunca vence.
5. **El badge reemplaza la `pipeline-mini`, no la acompaña**, en las facturas con
   padre. Junto al badge queda el pill de estado despintado a gris: el color
   significa "operable desde aquí", el gris significa "espejo de lo que decide el
   padre".
6. **El badge informa, no navega.** La fila sigue siendo un `<a class="row-fact">`
   que lleva a la factura; anidar otro `<a>` dentro sería HTML inválido y rompería
   el canon del índice. El enlace al padre vive dentro de `Invoices/view.php`.

## Arquitectura

Diez archivos —dos de ellos nuevos—, ninguna migración. El criterio "esta factura
tiene padre" se declara una vez y lo consumen tres sitios que hoy lo reimplementan
por separado.

### Fuente única — `src/Constants/InvoiceConstants.php`

Dos constantes nuevas:

```php
public const PARENT_FOREIGN_KEYS = [
    'petty_cash_record_id',
    'refund_id',
    'advance_id',
];

// OJO: 'Recibo' (DOCTYPE_RECIBO) sí vence. 'Recibo de Caja'
// (DOCTYPE_RECIBO_CAJA) NO — está excluido a propósito, es el
// documento de legalización de un anticipo y no tiene plazo.
// Añadirlo aquí reintroduce el bug de "legalizaciones vencidas".
public const DOCTYPES_WITH_DUE_DATE = [
    self::DOCTYPE_FACTURA,
    self::DOCTYPE_NOTA_DEBITO,
    self::DOCTYPE_TARJETA_CREDITO,
    self::DOCTYPE_RECIBO,
];
```

`PARENT_FOREIGN_KEYS` contiene solo las claves de **contención**. La programación
de pagos queda deliberadamente fuera: no es un padre.

El comentario sobre `DOCTYPES_WITH_DUE_DATE` no es decorativo. `'Recibo'` y
`'Recibo de Caja'` son dos valores persistidos a una palabra de distancia, y
confundirlos deshace justamente el arreglo de `overdue()`.

### Scope de query — `src/Model/Table/InvoicesTable.php`

Un custom finder que recorre la constante y exige `IS NULL` en las tres columnas:

```php
public function findWithoutParent(SelectQuery $query): SelectQuery
{
    foreach (InvoiceConstants::PARENT_FOREIGN_KEYS as $fk) {
        $query->where(["Invoices.{$fk} IS" => null]);
    }

    return $query;
}
```

Sus dos consumidores son el controller y `SidebarCounterService`. Al compartir la
cláusula, el contador del sidebar y la lista que anuncia no pueden divergir.

### Controller — `src/Controller/InvoicesController.php`

`_buildInvoiceQuery()` conserva la exclusión de Anticipo una sola vez, en lugar de
las cuatro repeticiones actuales, y recibe un parámetro para contener las
asociaciones de padre solo cuando hacen falta.

| Acción | Filtros |
|---|---|
| `all()` | base |
| `index()` | base + `find('withoutParent')` + `pipeline_status IN visibleStatuses($rol)` |
| `rejected()` | igual que `index()` + `area_approval = 'Rechazada'` |
| `overdue()` | igual que `index()` + `due_date < hoy` + `document_type IN DOCTYPES_WITH_DUE_DATE` + `pipeline_status NOT IN [pagada, legalizada]` |

Se elimina de `index()` el bloque `OR` de caja menor
(`src/Controller/InvoicesController.php:96-102`), sustituido por el finder.

La cláusula `pipeline_status NOT IN [pagada, legalizada]` de `overdue()` queda
técnicamente cubierta por `visibleStatuses` —ningún rol operativo tiene estados
terminales entre sus pasos— pero **se conserva**: es la afirmación de que una
factura pagada no está vencida, y no debe depender de cómo esté poblada la tabla
`pipeline_permissions`.

### Contadores — `src/Service/SidebarCounterService.php`

Se alinean uno a uno con la vista que anuncian. `getInvoiceStatusCounters()`
cambia su `OR` copiado por el finder; `rejectedInvoicesCount` y
`overdueInvoicesCount` incorporan el rol, el finder y —el segundo— la lista blanca
de vencimiento. `totalInvoicesCount` no cambia: sigue siendo el archivo sin
Anticipos.

### Capa de vista

**`src/View/Presentation/InvoiceLinkBadge.php`** (nuevo). Value object
`final readonly` con `code`, `label`, `icon` e `isContainment`.

**`src/View/Presentation/InvoicePresentation.php`.** Una constante nueva que mapea
cada clave foránea a su etiqueta, su asociación y el campo del que sale el código:

```php
public const PARENT_BADGES = [
    'petty_cash_record_id' => [
        'label' => 'Caja menor',
        'association' => 'petty_cash_record',
        'code_field' => 'code',
        'icon' => 'bi-link-45deg',
    ],
    'refund_id' => [
        'label' => 'Reintegro',
        'association' => 'refund',
        'code_field' => 'code',
        'icon' => 'bi-link-45deg',
    ],
    'advance_id' => [
        'label' => 'Anticipo',
        'association' => 'advance',
        'code_field' => 'invoice_number',
        'icon' => 'bi-link-45deg',
    ],
];
```

El anticipo se identifica por su `invoice_number` (prefijo `ANT`, generado por
`CodeGeneratorService::generateAdvanceInvoiceNumber()`); los otros dos por su
columna `code`. Los tres prefijos son autoexplicativos, así que el badge muestra
el código pelado sin repetir el tipo delante.

`forRow()` gana la derivación del badge. Cuando el vínculo es de contención hace
dos cosas más: fuerza `stageIdx = -1` —el mecanismo que el template **ya** usa
para no dibujar la `pipeline-mini`— y despinta el pill de estado a `pill-muted`.

**`src/View/Presentation/InvoiceRowView.php`.** Una propiedad nueva:
`?InvoiceLinkBadge $linkBadge`.

**`templates/Invoices/index.php`.** Solo añade el render del badge. Toda la
decisión queda en `forRow()`; ningún mapa vive inline en la plantilla.

**`templates/element/invoice_parent_notice.php`** (nuevo) y
**`templates/Invoices/view.php`.** Hoy la vista de una factura tiene tres avisos
distintos, con estilos y reglas de visibilidad distintas, y uno de los tres padres
no avisa nada:

| Vínculo | Aviso actual | Líneas | Enlace al padre | Visible para |
|---|---|---|---|---|
| Anticipo (legalización) | `alert-info` | 58-66 | Sí | Todos |
| Caja menor | `alert-warning` "Factura bloqueada" | 68-80 | Sí | Solo si `showPettyCashLock` (oculto a Admin) |
| Reintegro | **ninguno** | — | — | — |
| Programación pagada | `alert-warning` "Factura bloqueada" | 82-89 | No | Solo si `showSchedulingLock` |

Un element nuevo, `invoice_parent_notice`, reemplaza los bloques 58-66 y 68-80 y
cubre además el caso de Reintegro. Recibe el padre y elige la variante: informativa
cuando la factura no está bloqueada, de bloqueo cuando lo está. Un solo markup para
los tres vínculos de contención.

El aviso de programación pagada (82-89) **no** entra en el element: la programación
no es un padre, y su alerta habla de pagos aplicados, no de pertenencia.

## Permisos

No hay permisos nuevos: ni módulo, ni paso de pipeline, ni fila en `permissions` o
`pipeline_permissions`. Los cuatro listados siguen exigiendo `can_view` del módulo
`invoices`.

Lo que sí cambia es el **alcance** de tres de ellos. `index`, `rejected` y
`overdue` pasan a filtrar por `PipelineAuthorizationService::getVisibleStatuses($rol)`,
que hoy solo aplica `index`. Un rol sigue viendo el enlace en el sidebar —eso lo
gobierna `can_view`— pero la lista solo contiene lo que ese rol puede operar. La
invariante "operar implica ver" se mantiene: nadie pierde acceso a nada que pudiera
tocar.

## Flujo de datos

Los tres padres son `belongsTo` (`src/Model/Table/InvoicesTable.php:78-85` y
`90-94`; el `belongsTo('Employees')` intercalado en 86-89 no es un padre), así
que entran como `LEFT JOIN` sin query adicional. Pero en la bandeja son siempre
`NULL` por construcción, y unirlos sería trabajo inútil: se contienen **solo en
`all()`**.

La programación se contiene en las cuatro acciones, porque una factura sin padre
sí puede estar agendada. Es un `hasMany` sobre `PaymentSchedulingItems →
PaymentSchedulings`: dos queries extra por página de quince filas, seleccionando
únicamente el `code`.

**Caso de borde.** Nada en la base impide que una factura esté en dos
programaciones (`payment_scheduling_items.invoice_id` no tiene índice único).
Cuando ocurra, el badge muestra la programación más reciente. No se añade un
contador "+N" para lo que hoy sería un error de datos.

## Cambios de comportamiento

Tres consecuencias reales, deliberadas, que conviene tener presentes:

**"Vencidas" deja de ser una vista transversal.** Hoy `overdue()`
(`src/Controller/InvoicesController.php:150-171`) y `getOverdueInvoicesCount()`
(`src/Service/SidebarCounterService.php:179-191`) son globales: cualquier rol ve
toda factura vencida del sistema. Al heredar el filtro de bandeja, cada rol verá
solo las vencidas paradas en pasos que él opera. Una factura vencida atascada en
`tesoreria` deja de aparecerle a Registro/Revisión. Es la definición aceptada:
"Vencidas" es una lista de acción, no un tablero de alertas. Quien necesite la
visión transversal la tiene en "Todas las Facturas".

**"Rechazadas" quedará vacía para casi todos los roles.** Una factura rechazada
está siempre en `aprobacion` —el rechazo bloquea el avance—, de modo que al
filtrar por pasos operables solo la ve el rol que opera ese paso: Registro/Revisión.
Contabilidad y Tesorería verán la lista vacía y el badge rojo en cero. Es la
consecuencia correcta de una lista de acción: solo quien puede ejecutar
`resetFlow` la ve. Consultar qué se rechazó pasa a ser trabajo de "Todas las
Facturas".

**Agrupar una factura la saca de la bandeja aunque su aprobación de área siga
pendiente.** Hoy una factura de caja menor recién agrupada sigue apareciendo en
"Mis Facturas" mientras el registro está en `agrupacion`, porque el filtro actual
mira el estado y ella sigue en `aprobacion`. Con `findWithoutParent()` desaparece
en cuanto se le escribe el `petty_cash_record_id`. Para reintegros es inofensivo:
`RefundApprovalService::onAllApproved()` aprueba las facturas del reintegro en
lote desde el módulo padre. **No existe un `PettyCashApprovalService` equivalente**,
de modo que una factura de caja menor cuya aprobación de área se gestionara desde
la bandeja dejaría de ser alcanzable allí. La salida existe: desvincularla desde
el registro (`GroupedInvoiceService::removeInvoice()`) la devuelve a la bandeja.

## Manejo de errores

No hay entrada de usuario nueva, ningún servicio nuevo, ninguna transacción y
ninguna escritura. Todo el cambio es de lectura. El riesgo está entero del lado de
la regresión silenciosa, no de la excepción.

Existe un punto donde el código puede callarse: `forRow()` deriva el badge de las
asociaciones contenidas, y la bandeja no las contiene. Si alguien pidiera un badge
sólido en una vista sin `contain`, la factura tendría `refund_id` no nulo y la
asociación `refund` ausente; `forRow()` devolvería `linkBadge = null` y el badge no
aparecería. Se acepta —lanzar una excepción desde la capa de presentación sería
peor— y se cubre con un test que afirma que `all()` sí pinta el badge, de modo que
quitar el `contain` haga caer la suite.

## Testing

Se escriben los tests antes que el código, empezando por los del controller: son
los que reproducen el bug reportado.

Hay precedentes casi calcados sobre los que apoyarse: `AdvanceLegalizationLinkFilterTest`
para probar un filtro de vínculo, `SidebarCounterLegalizationTest` para probar un
contador. Las factories `InvoiceFactory` y `AdvanceLegalizationFactory` ya existen.

**Finder.** `findWithoutParent()` excluye facturas con cada una de las tres claves,
por separado y combinadas, y **no** excluye una factura que solo está agendada en
una programación. Esta última aserción fija por escrito la distinción
contención/referencia.

**Controller.** Cuatro casos que hoy fallarían: `index` oculta una factura de
reintegro agrupada; `index` oculta una de caja menor agrupada que sigue en
`aprobacion`; `index` sí muestra una de reintegro sin agrupar; `all` muestra ambas.
Más `rejected` filtrando por rol, y `overdue` dejando fuera legalización, recibo de
caja, caja menor y reintegro por tipo de documento.

**Contadores.** El invariante que ya se rompió una vez: el `count()` del contador
y el `count()` de la query de la vista devuelven el mismo número para un rol dado.
Aplicado a `sidebarCounters`, `rejectedInvoicesCount` y `overdueInvoicesCount`.

**Presentation.** Una factura con `refund_id` produce badge sólido, `stageIdx === -1`
y pill gris. Una factura solo agendada produce badge punteado y conserva su
`stageIdx`. Una factura limpia produce `linkBadge === null`.

`PipelineColorConsistencyTest` debe seguir verde: es el test que guarda la
coherencia de pills entre módulos.

**Ejecución.** `vendor/bin/phpunit` directo, no `composer test`. La suite sale con
código 1 incluso en verde por *notices* preexistentes: el criterio es el conteo de
fallos, no el exit code.

## Fuera de alcance

**No existe `isLockedByRefund`.** `InvoicesController::view()` calcula
`isLockedByPettyCash` e `isLockedByPaidScheduling`, pero nada equivalente para
reintegros. En consecuencia, una factura de reintegro agrupada —cuyo pipeline
gobierna `RefundPipelineService`— puede editarse hoy entrando por URL a
`/invoices/edit`. Ocultarla de la bandeja no cierra esa puerta: solo deja de
señalarla. Es un hueco de integridad anterior a este trabajo y merece su propio
arreglo, con su propio test. Queda anotado, no resuelto.

**Invalidación de la caché del sidebar.** Los contadores se cachean cinco minutos
y nadie los invalida. Al agrupar una factura, el badge de "Mis Facturas" seguirá
contándola hasta que expire. Es un problema real y anterior a este trabajo; este
diseño lo hace más visible, porque ahora agrupar sí cambia el conteo, pero no lo
causa. Queda anotado como deuda.

**Filtro por vínculo en el drawer de "Todas las Facturas".** Podría ser útil poder
listar "solo las facturas de reintegros", y la vista de auditoría de rechazadas lo
necesitaría si se decide recuperarla. No se incluye porque nadie lo ha pedido.
