# Vincular "Recibo de Caja" a la legalización de anticipos — Fase 2 (diseño)

**Fecha:** 2026-07-01
**Estado:** Diseño aprobado — pendiente plan de implementación
**Autor:** Alexander + brainstorming asistido

---

## 1. Resumen

La **Fase 1** (mergeada) permite vincular facturas `document_type = 'Recibo de Caja'` a la
legalización de un anticipo, congelándolas en `Contabilidad` para evitar doble pago, pero **no**
las promueve al estado terminal `legalizada` al cerrarse el anticipo ni les da el tratamiento
visual de una Legalización (un RC vinculado conserva su pipeline normal de 6 pasos).

La **Fase 2** completa el paralelismo: un Recibo de Caja **vinculado** (`advance_id !== null`) se
comporta y se ve **idénticamente a una Legalización** —
- se **promueve** a `legalizada` cuando el anticipo se legaliza (junto con las Legalización);
- usa el **pipeline visual reducido** (`aprobacion → contabilidad → legalizada`, 3 pasos) en index,
  vista y edición;
- **oculta** las secciones de `tesorería` y `autorización de pago` en el formulario (no aplican);
- muestra el **banner** de "vinculada al Anticipo #X".

**Decisión de alcance (brainstorming):** tratamiento visual **idéntico** a Legalización (no solo
"que no se rompa"). **Sin ajuste de datos**: la Fase 1 se mergeó sin uso real, así que no hay RCs
vinculados a anticipos ya cerrados que reconciliar.

---

## 2. Alcance

### Incluido
- Predicado unificado `Invoice::usesLegalizationView(): bool` (fuente única del criterio "usa la
  vista de legalización").
- Promoción del RC vinculado a `legalizada` al cierre (`LinkedInvoiceLegalizer`).
- `DocumentTypePolicy` **`advance_id`-aware** para el pipeline visual y las secciones ocultas
  (Enfoque A): `ReciboCajaDocumentTypePolicy` delega en `LegalizacionDocumentTypePolicy` cuando el
  invoice usa la vista de legalización.
- `InvoicePresentation::forRow` (index) y los banners (view/edit) consumen el predicado.
- Copy de los banners generalizado para mostrar el tipo real (Legalización o Recibo de Caja).
- Actualización de la invariante documentada `legalizada` = exclusivo de Legalización.

### Fuera de alcance
- **Fase 3** — Acceso directo al **crear** una factura ya vinculada a una legalización (para que
  "desde el inicio" salga el pipeline correcto).
- Ajuste/migración de datos (no hay RCs vinculados preexistentes; ver §1).
- Cambios en la mecánica de vinculación, freeze o validación de Fase 1 (se mantienen intactas).
- Test de integración de `linkCandidates` (follow-up heredado de Fase 1, no relacionado).

---

## 3. Contexto técnico

El criterio "esta factura usa la vista de legalización" (hoy `document_type === 'Legalización'`)
está **disperso** y usa **dos mecanismos**:
- **Index:** `InvoicePresentation::forRow` (`:72`) lo calcula **inline** (clase estática, sin acceso
  a la policy) y produce `InvoiceRowView.pipelineSteps` / `isLegalization`.
- **Vista/edición individual:** el pipeline y las secciones vienen de la **`DocumentTypePolicy`**
  vía `InvoicePipelineService::getPipelineStatusesFor` (`:55`) y `getVisibleSections` (`:65`), que
  resuelven por `document_type` — **sin ver `advance_id`**.

Como los dos mecanismos son distintos, ampliar el criterio a "RC vinculado" sin unificarlo primero
multiplicaría el drift. La Fase 2 introduce un **predicado único en el entity** que ambos consumen,
y hace la policy `advance_id`-aware para el mecanismo de la vista individual.

`legalizada` es un estado terminal que hoy solo alcanzan las Legalización; la Fase 2 lo extiende a
los RC vinculados (rompe la invariante documentada — ver §4.6).

---

## 4. Diseño

### 4.1 Predicado unificado (fuente única)

En `src/Model/Entity/Invoice.php`:

```php
public function usesLegalizationView(): bool
{
    return $this->document_type === InvoiceConstants::DOCTYPE_LEGALIZACION
        || ($this->document_type === InvoiceConstants::DOCTYPE_RECIBO_CAJA
            && $this->advance_id !== null);
}
```

Es el único lugar que define "usa la vista de legalización". Lo consumen `InvoicePresentation`
(§4.4), la `ReciboCajaDocumentTypePolicy` (§4.3) y los banners (§4.5).

### 4.2 Promoción al cierre

`src/Service/Pipeline/Invoice/LinkedInvoiceLegalizer.php` (`:34-38`): reemplazar la condición
`'document_type' => DOCTYPE_LEGALIZACION` por `'document_type IN' => ADVANCE_LINKABLE_DOCTYPES`
(la constante de Fase 1). El filtro `pipeline_status = STATUS_CONTABILIDAD` se mantiene. Al
legalizarse el anticipo, promueve Legalización **y** Recibo de Caja vinculados (ambos en
Contabilidad) → `legalizada`, dentro de la misma transacción del cierre (sin cambios en el flujo
de eventos).

### 4.3 Policy `advance_id`-aware (Enfoque A)

**Interfaz `DocumentTypePolicy`** (`src/Service/Pipeline/Invoice/DocumentTypePolicy.php`): los dos
métodos de vista pasan a recibir el invoice (default `null`, retrocompatible):

```php
public function getPipelineStatusesForView(?object $invoice = null): array;
public function filterVisibleSections(array $sections, ?object $invoice = null): array;
```

**Implementaciones:**
- `Standard`, `Anticipo`, `Legalizacion`: aceptan el parámetro y lo **ignoran** (comportamiento
  idéntico al actual).
- `ReciboCajaDocumentTypePolicy`: **inyecta** `LegalizacionDocumentTypePolicy` (constructor con
  fallback `?? new`, convención SPI). En ambos métodos: si `$invoice !== null &&
  $invoice->usesLegalizationView()`, **delega** en la policy de Legalización (3 pasos / oculta
  `treasury` + `payment_authorization`); si no, comportamiento Standard (6 pasos / todas las
  secciones). `blocksAdvance` ya es `advance_id`-aware desde Fase 1 (sin cambios).

**`InvoicePipelineService`:** propagar el invoice a la policy (default `null`):
- `getPipelineStatusesFor(?string $documentType = null, ?object $invoice = null)`
- `getVisibleSections(int $roleId, string $status, ?string $documentType = null, ?object $invoice = null)`

**Call-sites (los 3 reales, todos con el `$invoice` disponible):**
- `InvoicesController::view()` (`:215`) → `getPipelineStatusesFor($invoice->document_type, $invoice)`
  — alimenta el pipeline vertical de la **página de vista** (`view.php:35` → `pipeline_sidebar`).
  **Crítico:** sin este, un RC vinculado mostraría 6 pasos en su vista y 3 en su edición
  (inconsistencia con §5). El `$invoice` ya está cargado al inicio de `view()`.
- `InvoicesController` (`:436`) → `getPipelineStatusesFor(..., $invoice)` — alimenta
  `InvoiceEditViewModel.pipelineStatuses` (edición).
- `InvoicesController` (`:431`) → `getVisibleSections(..., $invoice)` — secciones visibles del form
  (edición).

Confirmado que `pipeline_sidebar` NO recomputa pasos por `document_type` (solo consume
`$pipelineStatuses`), y que no hay más call-sites de estos 2 métodos del service para Invoice.

**DI:** `Application.php` (`:316`) debe pasar `->addArgument(LegalizacionDocumentTypePolicy::class)`
al registrar `ReciboCajaDocumentTypePolicy` (inyectar la instancia compartida; el fallback `?? new`
lo haría no-breaking, pero el DI canónico la inyecta — igual que Fase 1).

**De paso (BAJO):** corregir el typo del docblock de la interfaz (`DocumentTypePolicy:28`:
"Standard/Anticipo: 5" → `PIPELINE_STATUSES` tiene 6 estados), ya que la firma de ese método se edita.

### 4.4 Index — `InvoicePresentation`

`src/View/Presentation/InvoicePresentation.php` (`:72-77`): reemplazar el criterio inline por el
predicado:

```php
$isLegalization = $invoice->usesLegalizationView();
$steps          = $isLegalization
    ? InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION
    : InvoiceConstants::PIPELINE_STATUSES;
```

`InvoicePresentation` es estático (no accede a la policy); consumir el predicado del entity es lo
que mantiene el index consistente con la vista individual sin duplicar el criterio.

### 4.5 Banners "vinculada al anticipo"

- `InvoiceViewViewModel` (`:135`): el flag hoy `isLinkedLegalization` se amplía para cubrir el RC
  vinculado. **Guard obligatorio:** el banner requiere `advance_id !== null` — `usesLegalizationView()`
  por sí solo es `true` para una Legalización **sin** vincular, que NO debe mostrar el banner. La
  condición exacta (en el ViewModel **y** en el inline `edit.php:230`, que hoy ya tiene
  `&& !empty($invoice->advance_id)`) es:

  ```php
  !empty($invoice->advance_id) && $invoice->usesLegalizationView()
  ```

  NO reemplazar el inline de `edit.php:230` por `usesLegalizationView()` a secas (rompería el banner
  de una Legalización no vinculada con `Anticipo #` nulo). Idealmente exponer este flag desde
  `InvoiceEditViewModel` para no duplicar la condición en `view.php` y `edit.php` (deriva leve
  preexistente). El banner de `view.php` (`:58`) y el de `edit.php` (`:230`) muestran el **tipo
  real** en vez de decir siempre "Legalización":

  > Este **{Recibo de Caja | Legalización}** está vinculado al Anticipo #X.

  El `{tipo}` se toma de `document_type` (o su label). Se conserva el `h()` de escape y el enlace
  al anticipo.

### 4.6 Documentación / invariante

Actualizar el comentario de `InvoiceConstants::STATUS_LEGALIZADA` ("Estado terminal exclusivo para
document_type = Legalización") y la sección "Invoice Pipeline" de `CLAUDE.md` para reflejar que un
Recibo de Caja **vinculado** también termina en `legalizada`.

---

## 5. Comportamiento resultante

- Un RC vinculado en Contabilidad, al legalizarse su anticipo, pasa a `legalizada` (terminal),
  igual que una Legalización.
- En el index, su fila muestra el mini-pipeline de 3 pasos con el paso activo resaltado
  (`contabilidad` o `legalizada`), no el de 6 pasos.
- En su vista/edición individual: pipeline vertical de 3 pasos; el formulario **oculta**
  tesorería y autorización de pago; muestra el banner "Este Recibo de Caja está vinculado al
  Anticipo #X".
- Un RC **no vinculado** (`advance_id === null`) conserva exactamente su comportamiento actual
  (pipeline de 6 pasos, todas las secciones) — sin regresión.
- Una Legalización (vinculada o no) conserva su comportamiento actual.

---

## 6. Modelo de datos y RBAC

- **Modelo de datos: N/A.** Sin migración; sin ajuste de datos (§1). El estado `legalizada` ya es
  un valor válido de `pipeline_status` (`ALL_STATUSES`).
- **RBAC: N/A.** No cambia ningún permiso ni gate; solo el tratamiento visual y la promoción
  (que ya vive dentro del cierre autorizado de la legalización).

---

## 7. Lo que cambia y lo que NO

**Cambia:** `Invoice` (nuevo predicado), `LinkedInvoiceLegalizer` (IN), interfaz
`DocumentTypePolicy` + 4 implementaciones (firma con invoice; solo RC cambia comportamiento),
`InvoicePipelineService` (propaga invoice), `Application.php` (DI: argumento de la policy RC),
`InvoicesController` (3 call-sites: `:215`, `:431`, `:436`), `InvoicePresentation`,
`InvoiceViewViewModel`, `templates/Invoices/view.php` + `edit.php` (banners), comentarios de docs.

**No cambia (Fase 1 intacta):**
- La vinculación estado-restringida (`linkCandidates`/`linkInvoices` con `OR`).
- El freeze (`blocksAdvance` por `advance_id` en Contabilidad) — el RC vinculado sigue sin poder
  avanzar/pagarse por su cuenta.
- El conteo en validación/total (`AdvanceLegalizationGuard`/`getLinkedTotal`).
- El `InvoiceBeneficiary` helper (Fase 1).
- El párrafo de anti-drift: el mapeo estado→pill sigue **solo** en `InvoicePresentation`
  (`STATUS_BADGES`); `STATUS_LEGALIZADA => 'pill-primary-soft'` ya existe, así que el pill del RC
  legalizado se renderiza sin cambios.

---

## 8. Testing

Respetar la baseline verde (789 tests tras Fase 1). Cobertura objetivo:

- **Predicado** (`InvoiceTest` o unit del entity): `usesLegalizationView()` → true para Legalización
  (con/sin advance_id) y RC vinculado; false para RC suelto y para Factura.
- **Promoción** (integración): un RC vinculado en Contabilidad se promueve a `legalizada` cuando el
  anticipo se legaliza; extender `LinkedInvoiceLegalizerTest` / el lifecycle de legalización.
- **Policy** (`ReciboCajaDocumentTypePolicyTest`): con un invoice `advance_id != null` →
  `getPipelineStatusesForView` = `PIPELINE_STATUSES_LEGALIZACION` y `filterVisibleSections` oculta
  `treasury`/`payment_authorization` (delegación a Legalización); con invoice suelto o `null` →
  comportamiento Standard.
- **Index** (`InvoicePresentationTest`): un RC con `advance_id != null` → `isLegalization = true` y
  `pipelineSteps = PIPELINE_STATUSES_LEGALIZACION`.
- **Firma retrocompatible:** los tests existentes de las 4 policies que llaman los 2 métodos sin el
  nuevo argumento siguen pasando (default `null`).

---

## 9. Convenciones SPI aplicables

- **Fuente única / anti-drift:** el criterio de vista de legalización vive en UN método del entity;
  los dos mecanismos (Presentation + policy) lo consumen. El mapeo estado→pill sigue solo en
  `InvoicePresentation` (no se toca).
- **Patrón State/Policy:** el tratamiento por tipo sigue gobernado por `DocumentTypePolicy`; la de
  RC delega en la de Legalización (reuso, sin duplicar las listas de estados/secciones).
- **Inyección `?? new`:** `ReciboCajaDocumentTypePolicy` inyecta `LegalizacionDocumentTypePolicy`
  con fallback.
- **Slugs persistidos inmutables:** no se tocan `'Legalización'`/`'Recibo de Caja'` ni el valor
  `legalizada`.
- **`ServiceResult` y API pública:** los métodos del service solo ganan parámetros opcionales
  retrocompatibles.
