# Anticipos: redirecciones contextuales

## Problema

Un Anticipo es una `Invoice` con `document_type = 'Anticipo'`. La interfaz de
listado/vista vive en `/advances` (`AdvancesController`), pero la edición y los
pagos se manejan en `InvoicesController` e `InvoicePaymentsController`. Cuando
el usuario edita un anticipo, registra un pago, agrega un soporte, etc., al
finalizar la acción los controladores hacen `redirect(['action' => 'index'])`
o `redirect(['controller' => 'Invoices', 'action' => 'edit', $id])`, que
mandan al usuario a `/invoices` (el listado de facturas, donde los anticipos
están filtrados y por tanto invisibles) en vez de a `/advances`.

## Objetivo

Los anticipos se quedan en su propia sección (`/advances/...`) durante todo el
ciclo de vida. El usuario nunca aterriza en `/invoices` editando un anticipo.

## Diseño

### Helper compartido en `AppController`

```php
protected function _redirectForInvoice(
    int|\App\Model\Entity\Invoice $invoiceOrId,
    string $action,
    mixed ...$args,
): \Cake\Http\Response {
    $invoice = is_int($invoiceOrId)
        ? $this->fetchTable('Invoices')->get($invoiceOrId)
        : $invoiceOrId;

    $controller = $invoice->document_type === InvoiceConstants::DOCTYPE_ANTICIPO
        ? 'Advances'
        : 'Invoices';

    return $this->redirect(['controller' => $controller, 'action' => $action, ...$args]);
}
```

### Mapeo por acción para Anticipos

- `index` → `/advances` (listado de anticipos)
- `view/{id}` → `/advances/view/{id}` (vista del anticipo, redirige a
  `legalization` si ya hay legalización iniciada)
- `edit/{id}` → `/advances/edit/{id}` que hace 302 a `/invoices/edit/{id}`
  (única ruta con un hop adicional; mantiene la URL visible consistente)

### Sitios a refactorizar

**`src/Controller/InvoicesController.php`** — 16 redirects:

- `edit()` paid lock → `view`
- `edit()` general lock → `view`
- `edit()` save+advance → `index` o `edit`
- `advanceStatus()` lock → `view`
- `advanceStatus()` éxito → `index`
- `advanceStatus()` fallo → `edit`
- `addObservation()` → `edit` (recibe solo `$id`, helper lo resuelve)
- `delete()` → `index`
- `uploadDocument()` sin archivo → `edit`
- `uploadDocument()` éxito → `edit`
- `deleteDocument()` no permitido → `view`
- `deleteDocument()` éxito → `view`
- `sendApprovalLinks()` → `edit`
- `modifyApprovers()` → `edit`
- `resetFlow()` → `edit`

(El redirect en `add()` post-save no aplica: anticipos usan su propio
`AdvancesController::add` que ya redirige a `view` dentro de Advances.)

**`src/Controller/InvoicePaymentsController.php`** — 10 redirects:

- `addPayment()` × 2 (permiso, éxito)
- `editPayment()` × 2
- `authorizePayment()` × 2
- `rejectPayment()` × 2
- `deletePayment()` × 2

En `editPayment/authorizePayment/rejectPayment` y `addObservation` se pasa
`(int)$invoiceId` al helper porque no cargan la entidad invoice; el helper la
resuelve internamente.

## Alternativas consideradas

**B. Condicional inline en cada redirect.** Explícito pero duplica la lógica
~26 veces. Descartado por mantenimiento.

**C. Override `Controller::redirect()` o evento `beforeRedirect`.** Demasiado
mágico, dificulta el rastreo del flujo.

## Verificación

- `composer cs-fix` y `composer cs-check` limpios.
- `composer test` pasa.
- Smoke manual: editar un anticipo, guardar, avanzar, registrar pago,
  autorizar, agregar soporte, agregar observación — confirmar que cada acción
  termina en una URL `/advances/...` (excepto el formulario de edición que
  vive en `/invoices/edit/{id}` por diseño).
