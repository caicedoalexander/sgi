# Diseño — Módulo de Anticipos

**Fecha:** 2026-04-28
**Estado:** Diseño aprobado, listo para implementación
**Autor:** Brainstorming colaborativo (Alexander + Claude)

---

## Resumen ejecutivo

Nuevo módulo `Anticipos` que cubre dos fases del ciclo de vida de un anticipo entregado a empleados o proveedores:

- **Fase 1 — Registro y pago del anticipo**: reutiliza al 100% el pipeline existente de `Invoices` (5 estados), modelando el anticipo como un `Invoice` con `document_type='Anticipo'`.
- **Fase 2 — Legalización**: pipeline propio (`validacion → revision_firmas → contabilidad → tesoreria → legalizada`) modelado en una tabla nueva `advance_legalizations`, alimentado automáticamente cuando un anticipo llega al estado `pagada`.

Las "facturas de legalización" son `Invoice` con `document_type='Legalización'` y un FK opcional `advance_id` que las agrupa al Anticipo. Estas facturas siguen un **pipeline truncado** (solo `aprobacion → contabilidad → legalizada`) porque ya fueron pagadas por el beneficiario con el dinero del anticipo.

El módulo maneja tres casos al cierre, definidos explícitamente por Contabilidad: **exacto** (cierre directo), **faltante** (consignación del beneficiario) y **sobrante** (reintegro de la empresa, pasa por Autorización de Pago).

---

## Decisiones de diseño

| # | Decisión | Justificación |
|---|---|---|
| 1 | Nombre `Anticipos` (no "Anticipos y Legalizaciones") | La legalización es una fase interna, no un módulo separado. UI más limpia. |
| 2 | El Anticipo ES un `Invoice` con `document_type='Anticipo'` | Reutiliza el 100% del pipeline existente: causación, pagos, autorización, historial, soportes, tokens, controlador, plantillas. |
| 3 | Las facturas-Legalización son `Invoice` con `document_type='Legalización'` y FK `advance_id` | Misma máquina, distinto tipo. Permite filtros, búsqueda y reporting consistentes. |
| 4 | Pipeline truncado para `'Legalización'`: `aprobacion → contabilidad → legalizada` | Esas facturas ya están pagadas por el beneficiario. Tesorería no debe verlas como pendientes. |
| 5 | Vinculación pull-based desde la vista del Anticipo (no en creación de la factura) | La spec describe "van llegando facturas… se pueden ir agrupando". Desacopla creación del agrupamiento. |
| 6 | Contabilidad ingresa **explícitamente** los montos de faltante/sobrante con dos botones | El sistema muestra `diff` calculado como ayuda visual, pero el monto auditable lo confirma Contabilidad. |
| 7 | El reintegro de sobrante reutiliza `InvoicePayment` con flag `is_refund=true` sobre el mismo Invoice del Anticipo | Aprovecha el flujo Tesorería → Autorización de Pago → Pagada existente. |
| 8 | El comprobante de consignación del faltante es un adjunto + metadatos en `advance_legalizations` | Cero tablas extra, suficiente para auditoría. |
| 9 | El Anticipo NO usa aprobación de área (`area_approval='Aprobada'` automático) | Cuando se registra ya está aprobado externamente. La spec no menciona aprobación de área. |
| 10 | La auto-creación de la fila de legalización ocurre cuando el Anticipo llega a `pagada` | Sin botón manual "iniciar legalización"; el sistema sabe que toda factura tipo Anticipo necesita legalizarse. |
| 11 | Para pasar de `validacion` a `revision_firmas`, todas las facturas vinculadas deben estar al menos en `contabilidad` | Garantiza que el documento firmado por la Coordinadora refleja facturas ya causadas. |

---

## Modelo de datos

### Cambios al schema existente

**Tabla `invoices`:**
- `InvoiceConstants::DOCUMENT_TYPES` agrega `'Anticipo'` y `'Legalización'`.
- Nueva columna `advance_id INT UNSIGNED NULL` con FK autorreferenciada a `invoices(id) ON DELETE SET NULL`. Indexada.

**Tabla `invoice_payments`:**
- Nueva columna `is_refund TINYINT(1) NOT NULL DEFAULT 0`.
- Restricción a nivel de servicio: `is_refund=true` solo válido en pagos contra Invoices con `document_type='Anticipo'`.

### Tabla nueva `advance_legalizations`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | BIGINT PK | |
| `advance_invoice_id` | FK → invoices.id | UNIQUE, NOT NULL |
| `status` | VARCHAR | `validacion`, `revision_firmas`, `contabilidad`, `tesoreria`, `legalizada` |
| `case_type` | VARCHAR NULL | `exacto`, `faltante`, `sobrante` |
| `shortage_amount` | DECIMAL(15,2) NULL | Monto explícito ingresado por Contabilidad |
| `surplus_amount` | DECIMAL(15,2) NULL | Monto explícito ingresado por Contabilidad |
| `shortage_received_at` | DATETIME NULL | Confirmación de Tesorería |
| `shortage_receipt_number` | VARCHAR NULL | # comprobante de consignación |
| `shortage_receipt_path` | VARCHAR NULL | PDF/imagen del soporte |
| `surplus_payment_id` | FK → invoice_payments NULL | Pago de reintegro creado |
| `legalized_at` | DATETIME NULL | Sello del cierre |
| `created_by` | FK → users | |
| `updated_by` | FK → users | |
| `created` / `modified` | DATETIME | timestamps |

### Tabla nueva `advance_legalization_signatures`

Réplica del patrón `NoveltyLiquidationSignatures`:

| Campo | Tipo | Notas |
|---|---|---|
| `id` | BIGINT PK | |
| `legalization_id` | FK → advance_legalizations.id | ON DELETE CASCADE |
| `signed_by_user_id` | FK → users.id NULL | |
| `signed_at` | DATETIME NULL | |
| `document_path` | VARCHAR | Relación de facturas (PDF) |
| `signature_status` | VARCHAR | `pending`, `signed`, `rejected` |
| `created` / `modified` | DATETIME | timestamps |

### Auto-creación

Cuando un `Invoice` con `document_type='Anticipo'` transiciona a `pagada`, `InvoicePipelineService` invoca `AdvanceLegalizationService::initialize($invoice)` que inserta la fila correspondiente en `advance_legalizations` con `status='validacion'`. Idempotente (no falla si ya existe).

---

## Fase 1 — Pipeline del Anticipo

**Estados (reutilizados):** `aprobacion → contabilidad → tesoreria → autorizacion_pago → pagada`

### Diferencias vs. Invoice normal

1. **Sin aprobación de área:** al crear, se setea `area_approval='Aprobada'`, `approver_id=NULL`. La sección `revision` del formulario se oculta para `document_type='Anticipo'`.
2. **`document_type` fijo:** el formulario de creación del Anticipo envía `'Anticipo'` hardcoded.
3. **Beneficiario obligatorio:** validación `provider_id OR employee_id` (al menos uno).
4. **Concepto/Detalle:** se reutiliza el campo `description` existente.
5. **Validaciones contables:** mismas que Invoice normal (Contabilidad debe causar antes de avanzar).

### Hooks específicos

- `Invoice::beforeSave` (cuando `_isNew && document_type='Anticipo'`): asigna `area_approval='Aprobada'`.
- `InvoicePipelineService` al transicionar a `pagada` con `document_type='Anticipo'`: invoca `AdvanceLegalizationService::initialize()`.
- `SidebarCounterService`: nueva badge "Anticipos por legalizar" (count de `advance_legalizations.status != 'legalizada'`).

### Roles que pueden crear

Cualquier rol con `permissions(module='advances', can_create)=true`.

---

## Fase 2 — Pipeline de la Legalización

```
validacion ──> revision_firmas ──> contabilidad ──┬──> legalizada (caso exacto)
                                                   │
                                                   ├──> tesoreria (faltante) ──> legalizada
                                                   │
                                                   └──> tesoreria (sobrante) ──> [autorizacion_pago del Invoice] ──> legalizada
```

### 1. `validacion` — Registro/Revisión

- Vincula/desvincula facturas-Legalización al anticipo.
- Adjunta la "relación de facturas" (PDF) → guardada en `advance_legalization_signatures.document_path` con `signature_status='pending'`.
- Botón "Pasar a aprobación" siempre visible. Valida solo: ≥1 factura vinculada Y existe el documento Y todas las facturas vinculadas están al menos en `contabilidad`.

### 2. `revision_firmas` — Coordinador Adm. y Financiero (rol único)

- Puede reemplazar el documento de relación de facturas.
- Botón "Marcar como firmado" → setea `signed_by_user_id`, `signed_at`, `signature_status='signed'` y avanza a `contabilidad`.
- Puede devolver a `validacion` con motivo (registrado en historial).

### 3. `contabilidad` — Contabilidad

Panel con:
- Total Anticipo (Invoice.total).
- Suma de facturas-Legalización vinculadas (auto-calculada).
- Diferencia con indicador visual (verde/naranja/rojo).

Tres botones excluyentes:
- **"Marcar legalizada"** — habilitado si `diff=0 AND case_type IS NULL`. Setea `case_type='exacto'`, `status='legalizada'`, `legalized_at=now()`.
- **"Registrar faltante"** — modal pide `shortage_amount` (precargado con `diff` si >0). Setea `case_type='faltante'`, `shortage_amount=X`, `status='tesoreria'`.
- **"Registrar sobrante"** — modal pide `surplus_amount` (precargado con `|diff|` si <0). Setea `case_type='sobrante'`, `surplus_amount=X`, `status='tesoreria'`.

### 4a. `tesoreria` (faltante) — Tesorería

Form para subir comprobante de consignación, # comprobante, fecha. Botón "Confirmar consignación" → `status='legalizada'`.

### 4b. `tesoreria` (sobrante) — Tesorería

Form que crea un `InvoicePayment` con `is_refund=true` sobre el Invoice del Anticipo. El Invoice automáticamente regresa a `autorizacion_pago` (lógica de pagos parciales existente). Guarda `surplus_payment_id`. La legalización **se queda en `tesoreria`** esperando.

### 5. Espera de autorización (sobrante)

Hook en `InvoicePaymentService::authorize()`: si el pago tiene `is_refund=true`, busca la legalización por `surplus_payment_id` y la avanza a `legalizada`.

---

## Vinculación de facturas-Legalización (UX)

### Vista del Anticipo (`/advances/view/{id}`)

Sección "Facturas vinculadas":
- Tabla con: # documento, beneficiario, fecha, monto, estado pipeline, botón "Desvincular".
- Total acumulado.
- Botón "Agregar facturas" — visible solo si `legalization.status='validacion'` y rol permitido.

### Modal "Agregar facturas"

Buscador con filtros:
- `document_type='Legalización'`
- `advance_id IS NULL`
- Filtros opcionales: proveedor/empleado, rango de fecha.

Selección múltiple → UPDATE masivo `advance_id`.

### Desvinculación

Setea `advance_id=NULL`. Permitido solo en `validacion`.

### Validaciones

- Una factura-Legalización solo puede tener un `advance_id` (FK simple).
- El monto total vinculado **no se valida** contra el monto del anticipo — eso es justamente lo que detecta Contabilidad en el paso 3.
- Si el Anticipo está en `legalizada`, no se puede desvincular.

### Banner en vista de factura-Legalización

En `templates/Invoices/view.php` y `edit.php`, si `document_type='Legalización' AND advance_id IS NOT NULL`: banner "Vinculada al Anticipo #X" con link.

---

## Matriz de roles × estados

### Permiso de módulo (`permissions.module='advances'`)

| Rol | view | create | edit | delete |
|---|:-:|:-:|:-:|:-:|
| Administrador | ✓ | ✓ | ✓ | ✓ |
| Contabilidad | ✓ | ✓ | ✓ | — |
| Tesorería | ✓ | ✓ | ✓ | — |
| Registro/Revisión | ✓ | ✓ | ✓ | — |
| Contador | ✓ | — | ✓ | — |
| Coordinador Adm. y Financiero | ✓ | — | ✓ | — |
| Auxiliar/Asistente Personal | — | — | — | — |

### Actor por estado de Fase 2

| Estado | Rol único que avanza | Edita | Restricciones |
|---|---|---|---|
| `validacion` | Registro/Revisión | Vincular/desvincular, subir relación, avanzar | No puede firmar |
| `revision_firmas` | Coordinador Adm. y Financiero | Reemplazar relación, marcar firmado, devolver | No puede vincular |
| `contabilidad` | Contabilidad | Botones (exacto/faltante/sobrante), monto explícito | No puede modificar facturas |
| `tesoreria` (faltante) | Tesorería | Comprobante, # consignación, confirmar | No puede editar montos |
| `tesoreria` (sobrante) | Tesorería | Crear `InvoicePayment` refund | El cierre lo hace el Contador al autorizar |
| (esperando) | Contador | Autoriza/rechaza pago refund vía Invoice pipeline | — |
| `legalizada` | — (terminal) | — | Solo Admin podría reabrir (fuera de MVP) |

---

## Servicios y archivos

### Archivos NUEVOS

**Modelo y datos:**
- `src/Constants/AdvanceConstants.php`
- `src/Model/Entity/AdvanceLegalization.php`
- `src/Model/Entity/AdvanceLegalizationSignature.php`
- `src/Model/Table/AdvanceLegalizationsTable.php`
- `src/Model/Table/AdvanceLegalizationSignaturesTable.php`

**Lógica de negocio:**
- `src/Service/AdvanceLegalizationService.php`
  - `initialize(Invoice $advance): ServiceResult`
  - `linkInvoices(AdvanceLegalization $leg, array $invoiceIds): ServiceResult`
  - `unlinkInvoice(AdvanceLegalization $leg, int $invoiceId): ServiceResult`
  - `attachRelationDocument(AdvanceLegalization $leg, $file): ServiceResult`
  - `markSigned(AdvanceLegalization $leg, User $user): ServiceResult`
  - `markExact(AdvanceLegalization $leg): ServiceResult`
  - `registerShortage(AdvanceLegalization $leg, float $amount): ServiceResult`
  - `registerSurplus(AdvanceLegalization $leg, float $amount): ServiceResult`
  - `confirmShortageReceipt(AdvanceLegalization $leg, array $data): ServiceResult`
  - `registerRefundPayment(AdvanceLegalization $leg, array $data): ServiceResult`
  - `canTransition(AdvanceLegalization $leg, User $user): bool`
  - `getDifference(AdvanceLegalization $leg): float`

**Controlador y vistas:**
- `src/Controller/AdvancesController.php` — `index`, `add`, `edit`, `view`, `linkInvoices`, `unlinkInvoice`, `uploadRelationDocument`, `markSigned`, `markExact`, `registerShortage`, `registerSurplus`, `confirmShortage`, `registerRefund`.
- `templates/Advances/index.php`, `add.php`, `edit.php`, `view.php`
- `templates/element/advance_legalization_progress.php`
- `templates/element/advance_link_modal.php`

### Archivos a MODIFICAR

- `src/Constants/InvoiceConstants.php` — agregar `'Anticipo'` y `'Legalización'` a `DOCUMENT_TYPES`.
- `src/Service/InvoicePipelineService.php` — hook a `pagada` para Anticipos; pipeline truncado para `'Legalización'`.
- `src/Service/InvoiceFieldAccessPolicy.php` — ocultar `revision` para Anticipos; excluir `tesoreria`/`autorizacion_pago` para Legalizaciones.
- `src/Service/InvoicePaymentService.php` — validar `is_refund` solo en Anticipos; cerrar legalización al autorizar pago refund.
- `src/Service/SidebarCounterService.php` — contador "Anticipos por legalizar".
- `src/Service/AuthorizationService.php` — agregar `'advances' => 'Anticipos'` a `MODULES`.
- `src/Controller/AppController.php` — `'Advances' => 'advances'` en `$controllerModuleMap`.
- `templates/layout/default.php` — link en sidebar (sección financiera).
- `config/routes.php` — rutas custom para endpoints POST de Fase 2 antes de `$builder->fallbacks()`.
- `templates/Invoices/view.php` y `edit.php` — banner "Vinculada al Anticipo #X".
- `src/Model/Entity/Invoice.php` y `src/Model/Table/InvoicesTable.php` — `advance_id` accesible y `belongsTo Advance` (autorreferencia).

---

## Migraciones

Orden:

1. **`AddAdvanceFieldsToInvoices`** — agrega `advance_id` con FK autorreferenciada.
2. **`AddIsRefundToInvoicePayments`** — agrega `is_refund` con default 0.
3. **`CreateAdvanceLegalizations`** — tabla principal de Fase 2.
4. **`CreateAdvanceLegalizationSignatures`** — patrón firmas.
5. **`SeedAdvancesPermissions`** — fila por rol con la matriz definida.

Todas usan `Migrations\BaseMigration` con `$this->hasTable()` defensivo.

---

## Plan de implementación (PRs sugeridos)

1. **PR 1 — Schema + constantes**: migraciones, `AdvanceConstants`, entidades, tablas, asociaciones. Sin lógica.
2. **PR 2 — Fase 1**: ramificación en `InvoicePipelineService` y `InvoiceFieldAccessPolicy` para `'Anticipo'` y `'Legalización'`. `AdvancesController` con CRUD básico. Sidebar + contadores.
3. **PR 3 — Fase 2 estados básicos**: `AdvanceLegalizationService` con `initialize`, `linkInvoices`, `unlinkInvoice`, `attachRelationDocument`, `markSigned`, `markExact`. UI hasta caso exacto.
4. **PR 4 — Faltante**: `registerShortage`, `confirmShortageReceipt`. Vistas tesorería con form de consignación.
5. **PR 5 — Sobrante**: `registerSurplus`, `registerRefundPayment`. Hook en `InvoicePaymentService::authorize`.
6. **PR 6 — Pulido**: historial detallado, banner en vista de factura-Legalización, tests, documentación de usuario.

---

## Tests críticos

- **Fase 1 happy path**: crear Anticipo → causar → registrar pago → autorizar → llega a `pagada` → fila en `advance_legalizations` con `status='validacion'`.
- **Fase 2 caso exacto**: vincular facturas → subir relación → firmar → marcar legalizada.
- **Fase 2 faltante**: contabilidad registra → tesorería confirma consignación → legalizada.
- **Fase 2 sobrante**: contabilidad registra → tesorería crea `InvoicePayment` con `is_refund=true` → contador autoriza → legalización se cierra automáticamente.
- **Validación**: bloquear avance a `revision_firmas` si alguna factura vinculada está en `aprobacion`.
- **Validación**: bloquear vincular una factura-Legalización ya vinculada a otro anticipo.
- **Validación**: bloquear `is_refund=true` en pagos de Invoices que no sean tipo `'Anticipo'`.
- **Permisos**: cada rol solo puede ejecutar las acciones de su estado.

---

## Fuera de alcance (post-MVP)

- Reapertura de legalizaciones cerradas.
- Mover una factura-Legalización entre anticipos en un solo paso (actualmente: desvincular + vincular).
- Aprobación de área para Anticipos (configurable).
- Reportes consolidados (anticipos por beneficiario, antigüedad, etc.).
- Notificaciones automáticas a los actores de cada estado de Fase 2.
