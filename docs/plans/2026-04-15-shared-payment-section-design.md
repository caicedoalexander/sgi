# Shared Payment Section Element

**Fecha:** 2026-04-15
**Objetivo:** Unificar el formulario de registro de pagos en un solo element reutilizable y agregar un botón de acceso rápido para marcar pago total.

## Problema

El formulario de registro de pagos se repite en 4 módulos (Facturas, Caja Menor, Legalizaciones, D. de Liquidación) con código casi idéntico pero divergencias menores. Cualquier cambio de estilo o funcionalidad requiere editar 4 archivos. No existe un acceso rápido para marcar pago total.

## Diseño

### 1. Element compartido: `templates/element/payment_section.php`

Un único element PHP que recibe parámetros para adaptarse a cada módulo:

```php
$this->element('payment_section', [
    'payments'           => $record->petty_cash_payments,
    'bankingEntities'    => $bankingEntities,
    'addPaymentUrl'      => ['controller' => 'PettyCashPayments', 'action' => 'addPayment', $record->id],
    'authorizeUrlFn'     => fn($pId) => ['controller' => 'PettyCashPayments', 'action' => 'authorizePayment', $record->id, $pId],
    'rejectUrlFn'        => fn($pId) => ['controller' => 'PettyCashPayments', 'action' => 'rejectPayment', $record->id, $pId],
    'deleteUrlFn'        => fn($pId) => ['controller' => 'PettyCashPayments', 'action' => 'deletePayment', $record->id, $pId],
    'canRegisterPayment' => $isTesoreriaEdit,
    'canAuthorize'       => $isContadorAutPago,
    'canDelete'          => $isTesoreriaEdit,
    'paymentStatus'      => $record->payment_status ?? null,
    'totalAmount'        => $record->total ?? null,
    'remainingAmount'    => $remainingAmount ?? null,
    'rejectMessage'      => '¿Rechazar? El registro volverá a Tesorería.',
]);
```

El element contiene:
- Header de sección ("Tesorería — Pagos")
- Badge de estado de pago (Pago total / Pago Parcial / Sin pagos)
- Formulario "Agregar Pago" (colapsable): Entidad Bancaria, Monto (COP), Fecha
- Botón "Pago Total" (acceso rápido)
- Tabla de pagos registrados con acciones (Autorizar/Rechazar/Eliminar)

### 2. JS compartido: `webroot/js/sgi-payment.js`

Módulo JS que maneja toda la lógica de pagos sin importar el módulo:

- **Registrar pago:** Crea formulario dinámico (evita nested forms), extrae valor de AutoNumeric, hace POST con CSRF token.
- **Pago Total (acceso rápido):** Abre el formulario, auto-rellena monto restante y fecha de hoy. El usuario solo selecciona banco y confirma.
- **Acciones de pago:** Reutiliza el patrón `btn-post-action` de `sgi-common.js`.

Se inicializa buscando `[data-payment-section]`. Los data attributes controlan todo:

```html
<div data-payment-section
     data-add-url="/petty-cash-payments/add-payment/5"
     data-total-amount="5000000"
     data-remaining-amount="2000000">
```

El script se carga con `$this->Html->script('sgi-payment', ['block' => true])` dentro del element.

### 3. Botón "Pago Total" — UX

Ubicación: junto al botón "Agregar Pago", como `btn-outline-success btn-sm` con ícono `bi-check2-all`.

Comportamiento:
1. Abre el formulario colapsable si está cerrado
2. Auto-rellena monto con `remainingAmount` (calculado server-side)
3. Auto-rellena fecha con hoy
4. Entidad Bancaria queda vacía — el usuario debe seleccionarla
5. El usuario da clic en "Registrar"

Solo visible cuando `canRegisterPayment = true` y `remainingAmount > 0`.

### 4. Migración por módulo

| Módulo | Archivo | Acción |
|--------|---------|--------|
| Facturas | `Invoices/edit.php` | Reemplazar bloque treasury (HTML + JS inline) por element |
| Caja Menor | `PettyCashRecords/edit.php` | Reemplazar bloque treasury por element |
| Legalizaciones | `LegalizationRecords/edit.php` | Reemplazar bloque treasury por element |
| D. de Liquidación | `NoveltyLiquidationDocs/edit.php` | Extraer sección de pago en `STATUS_TESORERIA` + tabla de pagos, reemplazar por element |
| PaymentSchedulings | — | No se toca (concepto diferente) |

### 5. Lo que NO cambia

- Controllers de pagos (`*PaymentsController`) — la API queda igual (POST + redirect + Flash)
- Servicios de pagos — sin cambios
- Lógica de permisos — cada módulo sigue pasando sus flags `canRegisterPayment`, `canAuthorize`, `canDelete`
