# Vincular "Recibo de Caja" a la legalización de anticipos — Fase 1 (diseño)

**Fecha:** 2026-06-30
**Estado:** Diseño aprobado (incorpora revisión `spi-design-reviewer`) — pendiente plan de implementación
**Autor:** Alexander + brainstorming asistido

---

## 1. Resumen

Hoy, en la fase de **legalización de anticipos**, el modal "Vincular facturas" solo ofrece
facturas `document_type = 'Legalización'` sin anticipo asignado. Esta Fase 1 amplía ese flujo
para que las facturas `document_type = 'Recibo de Caja'` se traten **igual** que las
`Legalización` durante la vinculación: aparecen como candidatas, se vinculan, se muestran como
vinculadas, cuentan para la validación de avance ("todas en Contabilidad") y suman al
total/diferencia (faltante/sobrante) del anticipo.

Un Recibo de Caja vinculado representa un **gasto ya cubierto con la plata del anticipo**
(justificación), por lo que **no debe pagarse por su cuenta**. Para que eso sea seguro, Fase 1
**congela** su pipeline al vincularse (igual que la `Legalización`), cerrando el riesgo de
doble pago (ver §5).

**Decisión de faseo:** lo que se difiere a fases posteriores es **solo el tratamiento de cierre
y visual**, no el freeze:
- **Fase 2** — promoción del RC vinculado al estado terminal `legalizada` al cerrar el anticipo,
  más la capa de presentación para que use el pipeline visual reducido de legalización.
- **Fase 3** — acceso directo al **crear** una factura ya vinculada a una legalización.

En Fase 1 un Recibo de Caja vinculado queda **congelado en `Contabilidad`** tras cerrarse el
anticipo (no se promueve a `legalizada`).

---

## 2. Alcance

### Incluido
- Nueva constante `InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES = [Legalización, Recibo de Caja]`
  como fuente única del conjunto de tipos vinculables a una legalización.
- Reemplazo de la condición `document_type = 'Legalización'` por `document_type IN (...)` en los
  **5 puntos** del ciclo de vinculación (§4.2).
- **Freeze del RC vinculado** vía nueva `ReciboCajaDocumentTypePolicy` que bloquea el avance
  manual del pipeline cuando el Recibo de Caja tiene `advance_id` (§4.3).
- **Filtro de candidatos por estado**: solo se ofrecen Recibos de Caja en `Contabilidad`
  (§4.4) — coherente con el freeze y con el gate de avance de la legalización.
- **Display del beneficiario** del RC con titular `employee`/`manual` en la lista de vinculadas
  y en el fragment del modal (§4.5).
- Ajuste del copy del modal.
- Cobertura de tests para el nuevo tipo vinculable y el freeze.

### Fuera de alcance
- **Fase 2** — Promoción del RC vinculado a `legalizada` al cierre (`LinkedInvoiceLegalizer`,
  el 6.º punto cableado) + capa de presentación para el pipeline reducido de legalización cuando
  `advance_id != null` (`InvoicePresentation`, `InvoiceViewViewModel`, `templates/Invoices/edit.php`).
- **Fase 3** — Acceso directo al **crear** una factura ya vinculada a una legalización.
- Cambios en la regla de validación de avance (la regla "todas en Contabilidad" se mantiene tal
  cual; solo se amplía el conjunto de facturas sobre el que aplica).
- Tocar `templates/element/advance_link_modal.php` (código muerto — ver §8).

---

## 3. Contexto técnico

El criterio que trae facturas al modal **no es el estado del pipeline**, sino el `document_type`
+ `advance_id IS NULL` (+ filtros opcionales de OC/fecha/proveedor). El estado del flujo importa
**después**, como requisito de avance: `ValidacionState::validateAdvance`
(`src/Service/Pipeline/Advance/State/ValidacionState.php:50`) exige que toda factura vinculada
esté en `Contabilidad`.

El ciclo de vida de una factura vinculada está cableado a `document_type = 'Legalización'` en
**6 lugares**. Fase 1 amplía 5 de ellos; el 6.º (promoción al cierre) se difiere a Fase 2.

**Propiedad clave que hace seguro el caso `Legalización` (y que el RC no tiene de fábrica):** la
`Legalización` está **estructuralmente parqueada** en `Contabilidad`.
`LegalizacionDocumentTypePolicy::blocksAdvance()`
(`src/Service/Pipeline/Invoice/Policy/LegalizacionDocumentTypePolicy.php:24`) le impide avanzar
manualmente en ese estado — solo la mueve `LinkedInvoiceLegalizer` al cierre. El `Recibo de
Caja` cae a `StandardDocumentTypePolicy` (avance libre, pipeline completo de 6 pasos), así que
**sin un freeze podría avanzarse y pagarse por su cuenta** → doble pago. Fase 1 replica ese
parqueo para el RC vinculado (§4.3).

---

## 4. Diseño

### 4.1 Constante (fuente única)

En `src/Constants/InvoiceConstants.php`:

```php
public const ADVANCE_LINKABLE_DOCTYPES = [
    self::DOCTYPE_LEGALIZACION,
    self::DOCTYPE_RECIBO_CAJA,
];
```

### 4.2 Los 5 puntos a ampliar (`document_type` → `IN`)

Los 5 puntos se dividen en **dos grupos** según si *seleccionan/escriben* el vínculo (donde la
restricción de estado del RC es **obligatoria**, §4.4) o si *leen* facturas **ya vinculadas**
(donde el estado ya está garantizado por la escritura, y basta el `IN` plano):

| # | Archivo | Línea aprox. | Rol | Forma de la condición |
|---|---------|--------------|-----|-----------------------|
| 1 | `AdvancesController::linkCandidates` | `:415` | Filtro del modal (candidatos) | **`OR` estado-restringido** (§4.4), alias `Invoices.` |
| 2 | `AdvanceLegalizationService::linkInvoices` | `:108` | `updateAll` que **escribe** el vínculo | **`OR` estado-restringido** (§4.4), sin prefijo |
| 3 | `AdvancesController::legalization` | `:337` | Lista de vinculadas en la vista | `IN` plano, alias `Invoices.` |
| 4 | `AdvanceLegalizationGuard::linkedLegalizationInvoices` | `:32` | Validación "todas en Contabilidad" | `IN` plano, sin prefijo |
| 5 | `AdvanceLegalizationService::getLinkedTotal` | `:353` | Suma para faltante/sobrante | `IN` plano, sin prefijo |

- **Puntos 3–5** (leen por `advance_id`): reemplazar `'document_type' => DOCTYPE_LEGALIZACION`
  por `'document_type IN' => ADVANCE_LINKABLE_DOCTYPES`; ninguna otra condición cambia.
- **Puntos 1–2** (seleccionan/escriben): usan la forma `OR` de §4.4 — no el `IN` plano —, para
  que un RC solo sea ofrecido **y solo sea vinculable** estando en `Contabilidad`.

> **Nota de implementación (alias):** los puntos 1 y 3 usan el prefijo `Invoices.` y deben
> conservarlo; los otros van sin prefijo.

### 4.3 Freeze del Recibo de Caja vinculado

Replica el parqueo de la `Legalización`, pero condicionado a `advance_id` (un RC **no** vinculado
conserva su pipeline normal de 6 pasos):

1. **Nueva** `src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php` (espejo de
   `StandardDocumentTypePolicy`, salvo `blocksAdvance`):
   ```php
   public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
   {
       if (($invoice->advance_id ?? null) !== null
           && $state->getStatus() === PipelineStatus::CONTABILIDAD) {
           return 'Este Recibo de Caja está vinculado a una legalización; '
               . 'avanzará junto con ella.';
       }
       return null;
   }
   ```
   `getDocumentType()` devuelve `InvoiceConstants::DOCTYPE_RECIBO_CAJA` (no `'*'`, por
   consistencia con las policies de `Anticipo`/`Legalización`).
   `getPipelineStatusesForView()` devuelve `PIPELINE_STATUSES` (6 pasos) — la visual de
   legalización se difiere a Fase 2. Resto de métodos como `StandardDocumentTypePolicy`.
2. **`DocumentTypePolicyFactory`** (`:28`): registrar `DOCTYPE_RECIBO_CAJA => $reciboCaja` e
   inyectar la nueva policy en el constructor.
3. **`Application.php`**: añadir la policy al DI del factory.
4. **`InvoicePipelineService::getNextStatus`** (`:147`): el freeze depende de `advance_id`, pero
   hoy arma un stub solo con `document_type`/`pipeline_status` (`:159`). Se añade un parámetro
   **opcional retrocompatible** `?int $advanceId = null`, se incluye en el stub, y los **3**
   llamadores lo pasan (`InvoicePipelineService:126` y `:243`, `InvoicesController:377` — todos
   tienen el `$invoice`). Tests que llaman con 2 args siguen válidos (un RC sin `advance_id` no
   se congela).
5. **Dos gates, en este orden:**
   - **Gate primario (UI + lógica):** `denialReasonForAdvance` → `getNextStatus(..., advanceId)`
     devuelve `null` para un RC vinculado en Contabilidad → `canAdvance = false` → el botón
     "avanzar" no se renderiza y `saveAndAdvance` (`:235`) **no llega** a `validateAdvance`. Este
     es el gate que efectivamente bloquea el avance.
   - **Gate secundario (defensa en profundidad):** `InvoiceTransitionValidator::validateAdvance`
     (`:72`) recibe el invoice real y también bloquea vía `blocksAdvance` si alguna ruta
     alcanzara la validación directamente. No es la línea de defensa principal, pero queda como
     red de seguridad.

**Por qué congelar solo en `Contabilidad`** (y no en cualquier estado): si se congelara en
`Aprobación`, el RC nunca llegaría a `Contabilidad` y el gate `ValidacionState` (`=== contabilidad`)
bloquearía el avance de la legalización → deadlock. Como los candidatos se filtran a `Contabilidad`
(§4.4), el RC vinculado siempre nace ahí; congelar en `Contabilidad` es suficiente y simétrico con
`Legalización`.

### 4.4 Restricción de estado del RC — en **ambas** capas (candidatos **y** escritura)

El freeze (§4.3) solo engancha en `Contabilidad`; su seguridad depende de la invariante
**"todo RC vinculado está en Contabilidad"**. Esa invariante debe garantizarse en la capa
**autoritativa** que escribe el vínculo, no solo en el listado — de lo contrario un RC en
`Tesorería`/`Pagada` (form rancio o segunda pestaña) podría vincularse sin quedar congelado y
pagarse aparte (doble pago). Por eso la condición `OR` estado-restringida se aplica en **los dos**
puntos:

```php
'OR' => [
    ['document_type' => DOCTYPE_LEGALIZACION],
    ['document_type' => DOCTYPE_RECIBO_CAJA, 'pipeline_status' => STATUS_CONTABILIDAD],
],
```

- **`linkCandidates` (`:414`)** — con prefijo `Invoices.` en las claves; el `'OR'` se AND-ea con
  `advance_id IS NULL` y los filtros opcionales (OC/fecha/proveedor). No colisiona con ninguna
  clave `'OR'` preexistente.
- **`linkInvoices` `updateAll` (`:104-111`)** — **el fix de seguridad clave**: hoy filtra
  `id IN $invoiceIds` + `advance_id IS NULL` + `document_type`. Se reemplaza la condición de
  `document_type` por el mismo `'OR'`, de modo que un `id` de un RC fuera de `Contabilidad`
  enviado por el cliente simplemente **no hace match** y no se vincula. `$invoiceIds` viene del
  POST (`AdvancesController::linkInvoices` → `getData('invoice_ids')`), por lo que la validación
  debe vivir aquí y no confiarse al listado.

Esto **sí altera** la condición del punto 2 respecto a "solo cambiar a `IN`": para `linkInvoices`
la `Legalización` mantiene su semántica (cualquier estado, parqueada de hecho en Contabilidad) y
el RC se restringe a Contabilidad. El comportamiento de `Legalización` no cambia.

### 4.5 Display del beneficiario

La lista de vinculadas (`templates/Advances/legalization.php:202`) y el fragment del modal
(`element('link_invoices_modal')`) resuelven el beneficiario como
`provider->name ?? employee->full_name ?? '—'`. Un `Recibo de Caja` puede tener
`equivalent_holder_type = 'employee'` o `'manual'` (sin provider). Se adapta el display para esos
casos reutilizando el patrón ya existente en `InvoiceViewViewModel:94-97`
(`employee` → nombre del empleado; `manual` → `manual_document_number`). Preferible extraer un
helper compartido para no duplicar la lógica en tres lugares.

### 4.6 Copy del modal

`templates/Advances/link_candidates.php:25` — actualizar el `helpText`
(*"Facturas tipo 'Legalización' sin anticipo asignado."*) para mencionar también Recibo de Caja.
El `title` nombra el flujo, no el tipo, y puede conservarse. Único cambio de texto.

---

## 5. Comportamiento resultante

- Un Recibo de Caja **en Contabilidad** y sin vincular, del OC del anticipo (o el OC/fecha/proveedor
  filtrado), aparece como candidato junto a las Legalización. Un RC fuera de Contabilidad **ni se
  ofrece ni se puede vincular** aunque su id se envíe directo en el POST (§4.4).
- Al vincularlo, recibe `advance_id`, aparece en la lista de vinculadas y **queda congelado**: no
  puede avanzar a `Tesorería` ni pagarse por su cuenta (cierra el doble pago).
- Para avanzar la legalización (`Validación → Revisión y firmas`), el RC vinculado cuenta en la
  regla "todas en Contabilidad" (la cumple por construcción).
- Su monto suma al total vinculado → la diferencia (exacto/faltante/sobrante) es correcta.
- Al **cerrar** el anticipo, el RC vinculado **no** se promueve a `legalizada`: queda congelado en
  `Contabilidad` (Fase 2 lo promoverá).
- Desvincular un RC (que ya filtra por `advance_id`) lo libera; al perder `advance_id` deja de
  estar congelado y recupera su pipeline normal.

---

## 6. Modelo de datos y RBAC

- **Modelo de datos: N/A.** Sin migración. `document_type`, `advance_id`,
  `equivalent_holder_type` y `manual_document_number` ya existen en `invoices`.
- **RBAC: N/A en lo sustantivo.** El gate `canLinkInvoices` (pipeline `legalizations`,
  `PIPELINE_LEGALIZATIONS`) sigue gobernando quién vincula. El pool de candidatos ahora expone
  Recibos de Caja del OC del anticipo; como ya expone las Legalización del mismo OC, no cruza un
  límite de visibilidad nuevo (mismo OC, mismo gate).

---

## 7. Lo que cambia en la capa de pipeline (y lo que NO)

**Sí cambia (necesario para el freeze, §4.3):** nueva `ReciboCajaDocumentTypePolicy`, registro en
`DocumentTypePolicyFactory` + DI en `Application.php`, parámetro opcional en
`InvoicePipelineService::getNextStatus`.

**No cambia (y por qué es seguro):**
- **`LinkedInvoiceLegalizer` (6.º punto): intacto.** Sigue promoviendo solo `Legalización` → el
  RC queda en `Contabilidad` tras el cierre (diferido a Fase 2).
- **Capa de vista intacta.** `InvoicePresentation` / `InvoiceViewViewModel::isLinkedLegalization`
  siguen significando "solo tipo Legalización"; un RC vinculado mantiene su pipeline visual de
  6 pasos (con `Contabilidad` resaltado, sin romperse). Solo se toca el display del beneficiario
  (§4.5), que no es mapeo estado→pill.
- **`updateAll` no dispara validaciones de entidad** (`InvoicesTable`) → las reglas de
  creación/edición de Recibo de Caja no interfieren con la vinculación.
- **`unlinkInvoice` filtra por `advance_id`** (no por `document_type`) → desvincular un RC
  funciona sin cambios.

---

## 8. Deuda y notas

- **Deuda de faseo (acotada por el freeze):** un Recibo de Caja vinculado queda **congelado en
  `Contabilidad`** tras el cierre del anticipo — estado ahora **garantizado** por el freeze (ya
  no depende de disciplina operativa). La Fase 2 lo promoverá a `legalizada`; los anticipos ya
  cerrados con RC vinculado requerirán un ajuste de datos puntual (volumen bajo si las fases van
  seguidas).
- **Código muerto detectado (no se toca):** `templates/element/advance_link_modal.php` no se
  referencia desde ningún `element('advance_link_modal')`; el modal real se carga vía
  `data-load-url` → `linkCandidates` → `link_invoices_modal`.
- **Deriva de nombres (no renombrar; documentar):** tras pasar a `IN`,
  `AdvanceLegalizationGuard::linkedLegalizationInvoices` (`:26`) y `getLinkedTotal` (`:346`)
  pasan a significar "facturas vinculadas (Legalización **+** Recibo de Caja)" pese a conservar
  "Legalization" en el nombre. El comentario `MA-006` en `ValidacionState.php:48-49` ("para que
  `LinkedInvoiceLegalizer` pueda promoverla") **deja de aplicar al RC** en Fase 1 (el RC no se
  promueve); ajustar/aclarar ese comentario al implementar.
- **Caso operativo:** un RC vinculado **regresado** a `Aprobación` solo puede re-avanzar hasta
  `Contabilidad` (se re-congela); no escapa al tramo pagadero. Efecto colateral aceptable:
  bloquea transitoriamente el avance de la legalización (gate `=== contabilidad`) hasta
  re-avanzar el RC.

---

## 9. Testing

Respetar la baseline verde de PHPUnit. Cobertura objetivo de Fase 1:

- **Candidatos:** `linkCandidates` incluye un Recibo de Caja **en Contabilidad** sin vincular del
  OC; **excluye** un RC en `Tesorería`/`Pagada`.
- **Vinculación:** `linkInvoices` vincula un Recibo de Caja en `Contabilidad` (recibe `advance_id`)
  y **rechaza** (no vincula, `linked = 0`) un RC en `Tesorería`/`Pagada` cuyo id se inyecte
  directamente en el POST — test de seguridad del fix de §4.4.
- **Freeze:** un RC con `advance_id` en `Contabilidad` no puede avanzar — gate primario:
  `denialReasonForAdvance` retorna `TERMINAL_STATE` / `getNextStatus(..., advanceId)` devuelve
  `null` → `canAdvance = false`; gate secundario: `validateAdvance` retorna el motivo. Un RC
  **sin** `advance_id` avanza normal. Unit test directo de
  `ReciboCajaDocumentTypePolicy::blocksAdvance` (con/sin `advance_id`, en/fuera de Contabilidad).
- **Validación de la legalización:** el guard cuenta el RC vinculado; el avance procede cuando
  está en Contabilidad.
- **Total:** `getLinkedTotal` / `getDifference` incluyen el monto del RC.
- **Vista:** la lista de vinculadas incluye el RC; el beneficiario de un RC `manual`/`employee`
  se muestra correctamente (no `—`).

Apoyo: `tests/Factory/InvoiceFactory.php` (helper para `Recibo de Caja`) y extensión de
`tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php` + unit tests de la
policy, el guard y el servicio.

---

## 10. Convenciones SPI aplicables

- **Constantes, no literales:** el conjunto de tipos vive en `InvoiceConstants` (fuente única).
- **Patrón State/Policy como fuente de transiciones:** el freeze se implementa como una
  `DocumentTypePolicy` (no como un `if` suelto en el coordinador), coherente con la "Estructura
  canónica de un módulo de flujo".
- **Slugs persistidos inmutables:** no se tocan los valores `'Legalización'` / `'Recibo de Caja'`
  ni los slugs `'advances'`/`'legalizations'`.
- **`ServiceResult` y API pública:** sin cambios de contrato; `getNextStatus` solo gana un
  parámetro opcional retrocompatible.
- **Anti-drift de vista:** Fase 1 no introduce mapeos estado→pill ni toca `InvoicePresentation`.
