# Diseño — Origen de pagos en Registro de Pagos + Pipeline de Legalizaciones

**Fecha:** 2026-04-30
**Autor:** Brainstorming colaborativo (a.caicedo.dev@gmail.com + Claude)

## Problema

Dos defectos relacionados en el módulo de facturas:

1. **El registro de pagos no distingue el origen real del pago.** Al pagar un Anticipo, el `PaymentRegistryService` lo muestra como "Factura" en lugar de "Anticipo". Lo mismo ocurre con Reintegros (`is_refund=true`), Notas Débito, Recibos, etc. — todos colapsan a la etiqueta "Factura".

2. **Las facturas tipo Legalización siguen el pipeline de pago completo y terminan en `pagada`.** Esto es incorrecto: el dinero de la legalización ya salió cuando se pagó el Anticipo padre. La factura de Legalización solo necesita registrarse en contabilidad y cerrarse junto con el cierre del Anticipo. El pipeline actual hace un "salto" `contabilidad → pagada`, lo cual etiqueta erróneamente a la factura como "pagada".

## Decisiones

- Las facturas tipo Legalización terminan en un estado nuevo `legalizada` (no `pagada`).
- La transición a `legalizada` es **automática**: la dispara el cierre del Anticipo padre (cuando `AdvanceLegalization` llega a `STATUS_LEGALIZADA`).
- Pipeline visual de Legalizaciones: solo 3 pasos — Aprobación → Contabilidad → Legalizada.
- Etiquetado de pagos: nueva columna calculada por fila desde `Invoice.document_type` y `is_refund`. Sin cambios de schema.
- Datos existentes: legalizaciones legacy en `pagada` se quedan ahí. No se migran.

## Asunción explícita

Cuando un Anticipo llega a `STATUS_LEGALIZADA`, todas sus facturas vinculadas (`advance_id = anticipo.id`, `document_type = Legalización`) están en `contabilidad`. Esto es invariante del flujo operativo: confirmado por usuario. Sin catch-up, sin revalidación al cierre.

---

## Cambios por capa

### 1. Constantes (`src/Constants/InvoiceConstants.php`)

Agregar estado terminal específico de Legalizaciones:

```php
public const STATUS_LEGALIZADA = 'legalizada';

public const PIPELINE_STATUSES = [
    self::STATUS_APROBACION,
    self::STATUS_CONTABILIDAD,
    self::STATUS_TESORERIA,
    self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_PAGADA,
    self::STATUS_LEGALIZADA, // nuevo
];
```

### 2. Schema

Si `invoices.pipeline_status` es ENUM en MySQL → migración para agregar `'legalizada'`. Si es VARCHAR, no hay cambio. Verificar con `SHOW CREATE TABLE invoices`.

### 3. `InvoicePipelineService`

**`getNextStatus($status, $documentType)`**:

- Para `document_type = Legalización`, en estado `contabilidad`: retornar `null` (no avanzar solo). El avance lo dispara `AdvanceLegalizationService`.
- Resto sin cambios.

**Eliminar el "salto" actual** `contabilidad → pagada` para Legalizaciones en `validateTransitionRequirements` (líneas 226-243).

**Nuevo método público `legalizeLinkedInvoices(int $advanceInvoiceId, int $userId): int`**:

```php
public function legalizeLinkedInvoices(int $advanceInvoiceId, int $userId): int
{
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $linked = $invoicesTable->find()
        ->where([
            'advance_id' => $advanceInvoiceId,
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
        ])
        ->all();

    $count = 0;
    $invoicesTable->getConnection()->transactional(function () use ($linked, $userId, &$count, $invoicesTable) {
        foreach ($linked as $inv) {
            $from = $inv->pipeline_status;
            $inv->pipeline_status = InvoiceConstants::STATUS_LEGALIZADA;
            if ($invoicesTable->save($inv)) {
                $this->historyService->recordStatusChange($inv->id, $from, InvoiceConstants::STATUS_LEGALIZADA, $userId);
                $count++;
            }
        }
    });
    return $count;
}
```

**Labels e icons:**

```php
STATUS_LABELS[STATUS_LEGALIZADA] = 'Legalizada';
STATUS_ICONS[STATUS_LEGALIZADA]  = 'bi-cash-coin';
```

**Edit lock:** facturas en `legalizada` se tratan como las de `pagada` para no-admins (redirigir `edit` → `view`).

### 4. `AdvanceLegalizationService`

Inyectar `InvoicePipelineService` en el constructor.

En `_setStatus()`, después de guardar:

```php
if ($newStatus === AdvanceConstants::STATUS_LEGALIZADA) {
    $this->pipelineService->legalizeLinkedInvoices($leg->advance_invoice_id, $userId);
}
```

Esto cubre los 3 caminos al cierre:
- `markExact()` (caso exacto)
- `confirmShortageReceipt()` (caso faltante consignado)
- `closeOnRefundAuthorized()` (caso sobrante reintegrado y pago autorizado)

Cuando un reintegro se rechaza (`reopenAfterRefundRejected`), el anticipo nunca llegó a `legalizada`, así que las facturas vinculadas tampoco se han promovido. Este caso no requiere lógica especial.

### 5. `PaymentRegistryService`

**`_queryInvoicePayments()`** — calcular `type` / `type_label` por fila:

```php
$docType = $p->invoice->document_type ?? null;

$typeKey = match (true) {
    (bool)$p->is_refund                  => 'refund',
    $docType === DOCTYPE_ANTICIPO        => 'advance',
    $docType === DOCTYPE_NOTA_DEBITO     => 'debit_note',
    $docType === DOCTYPE_RECIBO          => 'receipt',
    $docType === DOCTYPE_TARJETA_CREDITO => 'credit_card',
    $docType === DOCTYPE_REINTEGRO       => 'reintegro_doc',
    default                              => 'invoice',
};

$typeLabels = [
    'refund'        => 'Reintegro',
    'advance'       => 'Anticipo',
    'debit_note'    => 'Nota Débito',
    'receipt'       => 'Recibo',
    'credit_card'   => 'Tarjeta de Crédito',
    'reintegro_doc' => 'Reintegro (Doc)',
    'invoice'       => 'Factura',
];
```

Notas:
- `is_refund` tiene prioridad sobre `document_type` (un Reintegro sobre Anticipo se etiqueta como "Reintegro", no "Anticipo").
- Liquidaciones de novedades (`liquidation`) siguen igual.

**Filtros del formulario:**

`<select name="type">` con: Todos, Factura, Anticipo, Reintegro, Nota Débito, Recibo, Tarjeta de Crédito, Liquidación.

`_applyCommonFilters` mapea cada valor a `WHERE` correspondiente:
- `type=advance` → `Invoices.document_type = 'Anticipo' AND is_refund = false`
- `type=refund` → `is_refund = true`
- `type=invoice` → `is_refund = false AND document_type IN ('Factura','Caja menor')` (y default null)
- etc.

### 6. UI

**`templates/element/pipeline_progress.php`:**

Aceptar parámetro `documentType`. Si `documentType === DOCTYPE_LEGALIZACION`, dibujar 3 pasos: Aprobación, Contabilidad, Legalizada. Si no, los 5 actuales.

**Controllers** (`InvoicesController::edit`, `view`, index):

- Pasar `$invoice->document_type` al element.
- Tratar `legalizada` como terminal (igual que `pagada`) para no-admins.

**Index de facturas:**

- Badge label/color para `legalizada` → "Legalizada", `bg-success`.
- Filtro de pipeline_status: agregar opción "Legalizada".

**Sidebar:**

`SidebarCounterService` no debe contar `legalizada` como pendiente. Verificar y excluir.

**Template `PaymentRegistry/index.php`:**

- Mapa `$typeBadge` extendido con todas las nuevas claves (sugerido: `advance` → `bg-warning text-dark`, `refund` → `bg-danger`, `invoice`/`debit_note`/`receipt`/`credit_card` → `bg-primary`).
- Opciones del select de tipo.

## Casos borde

1. **Anticipo se legaliza con varias facturas vinculadas en contabilidad** → todas pasan a `legalizada` en una sola transacción. Si una falla, rollback total.
2. **Anticipo se legaliza sin facturas vinculadas en contabilidad** → `legalizeLinkedInvoices()` retorna 0, no falla.
3. **Reintegro rechazado** (`reopenAfterRefundRejected`) → anticipo vuelve a `tesoreria`, facturas nunca se promovieron, no hay nada que revertir.
4. **Legalizaciones legacy en `pagada`** → coexisten con `legalizada`. Edit/view debe tratar ambos como terminales.
5. **Pago de Anticipo en registro de pagos** → aparece etiquetado como "Anticipo".

## Orden de implementación

1. Constantes: `STATUS_LEGALIZADA` en `InvoiceConstants`.
2. Verificar/migrar schema si pipeline_status es ENUM.
3. `InvoicePipelineService`: ajustar `getNextStatus`, `validateTransitionRequirements`, agregar `legalizeLinkedInvoices()`.
4. `AdvanceLegalizationService::_setStatus`: invocar `legalizeLinkedInvoices()` cuando llega a `STATUS_LEGALIZADA`.
5. `PaymentRegistryService`: nueva lógica de etiquetado y filtros.
6. Template `PaymentRegistry/index.php`: opciones de filtro, badges.
7. Template `pipeline_progress.php`: parámetro `documentType`.
8. Controllers: pasar `document_type` al element, tratar `legalizada` como terminal.
9. Index de facturas: badge y filtro de estado.
10. `SidebarCounterService`: excluir `legalizada` de pendientes.
11. Tests unitarios: `InvoicePipelineServiceTest`, `AdvanceLegalizationServiceTest`, `PaymentRegistryServiceTest`.
12. Verificación manual:
    - Pagar un Anticipo → debe aparecer como "Anticipo" en el registro.
    - Legalizar un Anticipo (caso exacto / faltante / sobrante) → facturas vinculadas en `contabilidad` pasan a `legalizada`.
    - Pipeline visual de una Legalización muestra 3 pasos.
