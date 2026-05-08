# Estado intermedio "Verificación de pago" antes de cerrar a Pagada

**Fecha:** 2026-05-08
**Estado:** Diseño aprobado
**Alcance:** Invoices, PaymentScheduling, PettyCash, Refund, Novelty (LiquidationDoc)

---

## Contexto

Hoy en SGI, cuando el Contador autoriza un pago, el registro pasa **directamente** al estado `pagada`. En la operación real, autorizar un pago no significa que el dinero ya salió del banco; queda un paso operativo intermedio (Tesorería ejecuta la transferencia y verifica el comprobante) que actualmente no tiene representación en el sistema.

Se quiere desacoplar dos eventos hoy fusionados:

1. **Autorización del Contador** — visto bueno financiero, el dinero está habilitado para salir.
2. **Verificación de Tesorería** — confirmación explícita de que el dinero efectivamente salió del banco.

## Estado actual del código

Ningún módulo tiene este estado intermedio. En todos, "autorizar" y "marcar pagada" ocurren en una sola operación dentro del mismo método de servicio:

| Módulo | Método actual | Pipeline actual |
|---|---|---|
| Invoices | `InvoicePaymentService::authorizePayment()` | aprobacion → contabilidad → tesoreria → autorizacion_pago → **pagada** |
| PaymentScheduling | `PaymentSchedulingService::applyPayments()` | borrador → tesoreria → autorizacion_pago → **pagada** |
| PettyCash | `PettyCashService::authorizePayment()` | agrupacion → contabilidad → tesoreria → autorizacion_pago → **pagada** |
| Refund | `RefundPaymentService::authorize…()` | agrupacion → contabilidad → tesoreria → autorizacion_pago → **pagada** |
| Novelty (LiquidationDoc) | `LiquidationDocPaymentService::authorizePayment()` | … → tesoreria → autorizacion_pago → **pagada** |

Advances queda fuera del alcance porque su pipeline termina en `legalizada`, no en `pagada`.

## Objetivo

Insertar un estado `verificacion_pago` con label "Verificación de pago" entre `autorizacion_pago` y `pagada` en los 5 módulos. La autorización del Contador deja el registro en `verificacion_pago`; Tesorería ejecuta una acción explícita "Pasar a Pagada" que cierra el flujo.

## Decisiones de diseño

| Decisión | Elección |
|---|---|
| Semántica | Verificación pre-pago: autorizado, falta validar/ejecutar antes de cerrar |
| Rol que confirma | Tesorería (más Administrador) |
| Modelo | Nuevo estado en el pipeline, slug español `verificacion_pago` |
| Slug + label | `verificacion_pago` → "Verificación de pago" |
| Pago parcial en Invoices | Sin cambios — un pago parcial autorizado sigue regresando a `tesoreria`, NO entra al estado intermedio |
| Granularidad agrupados | Una sola confirmación a nivel del record padre cierra todas las facturas hijas |
| Migración de datos | Registros existentes en `pagada` permanecen en `pagada`, no se devuelven |
| Feature flag | No se usa — el cambio aplica directo |

## Arquitectura

### Cambios en enums (`src/Constants/Domain/{Modulo}/PipelineStatus.php`)

Cinco archivos: `Invoice`, `PaymentScheduling`, `PettyCash`, `Refund`, `Novelty`.

En cada uno:

- Añadir `case VERIFICACION_PAGO = 'verificacion_pago';`.
- En `label()`: `self::VERIFICACION_PAGO => 'Verificación de pago'`.
- En `next()`: cambiar la transición `AUTORIZACION_PAGO => self::PAGADA` a `AUTORIZACION_PAGO => self::VERIFICACION_PAGO`, y añadir `VERIFICACION_PAGO => self::PAGADA`.
- En `pipelineCases()` (o equivalente): incluir `VERIFICACION_PAGO` entre `AUTORIZACION_PAGO` y `PAGADA`.
- `isTerminal()` no cambia: `verificacion_pago` no es terminal.

### Cambios en Constants

- `InvoiceConstants`, `PaymentSchedulingConstants`, `NoveltyConstants`: añadir `STATUS_VERIFICACION_PAGO`, insertarlo en `PIPELINE_STATUSES`, `STATUS_LABELS`, `TRANSITIONS`. En `NoveltyConstants` también revisar `ALL_STATUSES` y `ACTIVE_STATUSES` (incluir `verificacion_pago` como activo).
- `GroupingPipelineConstantsTrait`: añadir `STATUS_VERIFICACION_PAGO` y propagar a `STATUSES`, `STATUS_LABELS`, `TRANSITIONS`, `BACKWARD_TRANSITIONS`. Esto cubre `PettyCash` y `Refund` con una sola edición.

### Schema

Verificar antes de implementar si las columnas que almacenan el estado son `VARCHAR` o `ENUM`:

- `invoices.pipeline_status`
- `payment_schedulings.pipeline_status`
- `petty_cash_records.status`
- `refunds.status`
- `novelty_liquidation_docs.pipeline_status`
- `employee_novelties.pipeline_status`

Si alguna es `ENUM`, añadir migración `Migrations\BaseMigration` con `ALTER TABLE … MODIFY COLUMN … ENUM(…, 'verificacion_pago', …)`. Si son `VARCHAR` no se requiere migración de schema.

No hay migración de datos: registros existentes en `pagada` se quedan en `pagada`.

### Backward transitions

- `GroupingPipelineConstantsTrait`: `verificacion_pago => autorizacion_pago` (permite que Tesorería rechace y devuelva al Contador).
- `PaymentSchedulingConstants`: `verificacion_pago => autorizacion_pago` en `BACKWARD_TRANSITIONS`.
- Invoices y Novelty mantienen su patrón actual de regresión por motivo (no se introduce regresión nueva, solo se respeta el ciclo).
- En ningún módulo se permite regresar **desde** `pagada` (igual que hoy).

## Servicios

Punto crítico del cambio: separar dos eventos hoy fusionados (autorizar = pagar) en dos métodos distintos. Los eventos de dominio (`InvoicePaidEvent`, `InvoiceRefundAuthorizedEvent`) se posponen al método de **confirmación final**, no al de autorización.

### `InvoicePaymentService` (Invoices)

Modificar `authorizePayment(int $paymentId, int $authorizedBy): array`:

- Cuando `payment_status === PAYMENT_FULL`: dejar `pipeline_status = STATUS_VERIFICACION_PAGO` (en lugar de `STATUS_PAGADA`).
- Cuando `payment_status === PAYMENT_PARTIAL`: setear `pipeline_status = STATUS_TESORERIA` (sin cambios).
- **No** disparar `InvoicePaidEvent` aquí. `InvoiceRefundAuthorizedEvent` se mantiene si `is_refund` (el Contador autorizó el reembolso, ese evento sigue siendo correcto en este punto).
- Registrar el cambio en `invoice_histories` con `recordStatusChange`.

Añadir nuevo método `confirmPaymentExecuted(int $invoiceId, int $confirmedBy): ServiceResult`:

- Validar que `pipeline_status === STATUS_VERIFICACION_PAGO`. Si no, `ServiceResult::fail`.
- Dentro de transacción:
  - Setear `pipeline_status = STATUS_PAGADA`.
  - Recalcular `payment_status`/`full_payment_date` con `recalculatePaymentStatus()` (defensivo, debería estar ya en `Pago total`).
  - Registrar transición en `invoice_histories`.
  - Disparar `InvoicePaidEvent` — esto activa `LegalizationInitializerSubscriber` para anticipos.

### `PaymentSchedulingService`

Modificar `applyPayments(int $schedulingId, int $authorizedBy): array`:

- Materializar `invoice_payments` igual que hoy (siguen `authorized = true`, `status = authorized`).
- Para cada factura cuyos pagos sumen total: setear `STATUS_VERIFICACION_PAGO` en lugar de `STATUS_PAGADA`. Las parciales siguen el flujo actual (regresan a `tesoreria` en su próxima iteración).
- Setear el scheduling en `STATUS_VERIFICACION_PAGO`.
- **No** disparar `InvoicePaidEvent` para las facturas hijas.

Añadir nuevo método `confirmExecution(int $schedulingId, int $confirmedBy): ServiceResult`:

- Validar que el scheduling esté en `STATUS_VERIFICACION_PAGO`.
- Dentro de transacción:
  - Mover scheduling a `STATUS_PAGADA`.
  - Para cada factura hija en `STATUS_VERIFICACION_PAGO` (por idempotencia, ignorar las que ya estén en otro estado): setear `STATUS_PAGADA`, recalcular `payment_status`/`full_payment_date`, registrar transición en `invoice_histories`, disparar `InvoicePaidEvent`.
  - Registrar transición del scheduling en su tabla de historial.

### `PettyCashService`

Modificar `authorizePayment(…)`:

- Materializar los `invoice_payments` igual que hoy.
- Las facturas hijas pasan a `STATUS_VERIFICACION_PAGO` con `payment_status = PAYMENT_FULL`.
- El record padre pasa a `PettyCashConstants::STATUS_VERIFICACION_PAGO`.
- **No** disparar eventos de pagada.

Añadir `confirmPayment(int $recordId, int $confirmedBy): ServiceResult`:

- Validar record en `verificacion_pago`.
- En transacción: avanzar record a `pagada`, avanzar todas las facturas hijas a `STATUS_PAGADA`, disparar `InvoicePaidEvent` por cada hija, registrar transiciones.

### `RefundPaymentService`

Mismo patrón que `PettyCashService`. El método existente que cierra a `pagada` ahora deja todo en `verificacion_pago`. Nuevo método `confirmPayment(…)` cierra el ciclo.

### `LiquidationDocPaymentService` (Novelty)

Modificar `authorizePayment(int $paymentId, int $authorizedBy): array`:

- El doc y todas las novedades hijas pasan a `STATUS_VERIFICACION_PAGO` (en lugar de `STATUS_PAGADA`).
- `payment_status` y `payment_date` ya se establecen aquí (sin cambios).

Añadir `confirmPayment(int $docId, int $confirmedBy): ServiceResult`:

- Validar doc en `verificacion_pago`.
- En transacción: doc → `pagada`, todas las novedades hijas → `pagada`.

## Eventos de dominio

| Evento | Cuándo se dispara hoy | Cuándo se disparará |
|---|---|---|
| `InvoicePaidEvent` | Al cerrar a `pagada` | Solo al confirmar (no al autorizar) |
| `InvoiceRefundAuthorizedEvent` | Al autorizar un pago de reembolso | Sin cambios — el Contador ya dio el OK financiero del reembolso |
| `InvoiceRefundRejectedEvent` | Al rechazar pago de reembolso | Sin cambios |

`LegalizationInitializerSubscriber` consume `InvoicePaidEvent`. Esta es la razón clave para mover el dispatch del evento al método de confirmación: la inicialización de legalización debe ocurrir cuando el dinero efectivamente salió, no cuando se autorizó.

## UI

### Rutas

Añadir 5 rutas en `config/routes.php` antes de `$builder->fallbacks()`:

- `POST /invoices/confirm-payment/:id`
- `POST /payment-schedulings/confirm-payment/:id`
- `POST /petty-cash/confirm-payment/:id`
- `POST /refunds/confirm-payment/:id`
- `POST /employee-novelties/confirm-liquidation-payment/:docId` (nombre exacto se ajusta al patrón del módulo durante implementación)

### Controllers

Cinco acciones nuevas, una por controller, todas con el mismo contrato:

```php
public function confirmPayment(int $id): Response
{
    $this->request->allowMethod(['post']);
    if (!in_array($currentRole, [RoleConstants::TESORERIA, RoleConstants::ADMIN], true)) {
        $this->Flash->error('No tiene permisos para confirmar pagos.');
        return $this->redirect($this->referer());
    }
    $result = $this->paymentService->confirmPayment($id, $userId);
    // flash + redirect
}
```

### Vistas

- Actualizar `templates/element/pipeline_progress.php` (Invoices, Scheduling), `petty_cash_progress.php`, `legalization_progress.php` o el que aplique a Refund/Novelty para soportar 6 pasos. El element ya recibe el array de estados; el cambio se concentra en pasarle el array actualizado desde los controllers.
- En la vista `view` de cada módulo, cuando `pipeline_status = verificacion_pago` y el usuario es Tesorería/Admin: mostrar bloque con botón **"Pasar a Pagada"** (clase `.sgi-btn-primary`, sin redondeo) y mensaje informativo: *"El pago fue autorizado por {Contador}. Confirme cuando el dinero haya salido del banco."*
- Indexes: añadir `verificacion_pago` a los selects de filtro por estado y a los badges de estado.

### Sidebar y badges

`SidebarCounterService` debe contabilizar registros en `verificacion_pago` como pendientes para Tesorería en cada módulo afectado. La lista de "estados pendientes" para Tesorería pasa a incluir `verificacion_pago` además de `tesoreria`.

## Permisos

- No se crean módulos nuevos en `AuthorizationService::MODULES`.
- La acción `confirmPayment` en cada controller se mapea a `can_edit` del módulo correspondiente (Invoices, PaymentSchedulings, PettyCash, Refunds, EmployeeNovelties/NoveltyLiquidationDocs según corresponda).
- Restricción adicional por rol en el controller: solo `RoleConstants::TESORERIA` y `RoleConstants::ADMIN`. El Contador no puede confirmar (separación de funciones).
- No se requiere modificar la tabla `permissions` ni añadir filas — la columna `can_edit` ya cubre la acción.

## Historial

Cada transición se registra con `historyService->recordStatusChange()` en su tabla de historial:

- `invoice_histories` (Invoices, Scheduling, PettyCash, Refund a través de `invoices` hijas)
- Tabla de historial de cada módulo padre (`payment_scheduling_histories`, `petty_cash_histories`, `refund_histories`, `novelty_liquidation_doc_histories` — usar la que ya exista en cada módulo)

Transiciones a registrar:
- `autorizacion_pago → verificacion_pago` (autor: Contador)
- `verificacion_pago → pagada` (autor: Tesorería/Admin)

## No-objetivos

- **Advances** queda fuera (termina en `legalizada`, no en `pagada`).
- **No** se introduce un proceso de "rechazo desde verificación" en Invoices/Novelty: si Tesorería detecta un problema en estos módulos, usa el flujo existente de regresión por motivo.
- **No** se mueven registros existentes en `pagada`; el cambio aplica solo a nuevos flujos.
- **No** se introduce feature flag.
- **No** se modifica el flujo de pago parcial en Invoices.

## Validación manual

Sustituye la sección de tests automatizados (este proyecto no usa tests — ver CLAUDE.md "Testing Policy").

Levantar `php bin/cake server` y ejecutar:

1. **Invoices, pago total**: crear factura, avanzar hasta `tesoreria`, registrar pago total, autorizar como Contador → verificar `pipeline_status = verificacion_pago`. Login como Tesorería → click "Pasar a Pagada" → verificar `pagada`. Si la factura es anticipo (`document_type = Anticipo`), verificar que `LegalizationInitializerSubscriber` se disparó (la legalización aparece en su listado).
2. **Invoices, pago parcial**: registrar pago parcial, autorizarlo como Contador → verificar que la factura regresa a `tesoreria` (NO a `verificacion_pago`).
3. **PaymentScheduling**: crear, avanzar a `autorizacion_pago`, autorizar → scheduling y todas sus facturas hijas en `verificacion_pago`. Confirmar como Tesorería → todas las hijas y el scheduling en `pagada`. Verificar que ningún `InvoicePaidEvent` se disparó hasta la confirmación final.
4. **PettyCash**: autorizar record → record y todas las facturas hijas en `verificacion_pago`. Confirmar como Tesorería → todas en `pagada`.
5. **Refund**: idéntico patrón a PettyCash.
6. **Novelty (LiquidationDoc)**: autorizar pago → doc y todas las novedades hijas en `verificacion_pago`. Confirmar como Tesorería → todas en `pagada`.
7. **Permisos**: con rol Contador, intentar `POST /invoices/confirm-payment/X` → debe ser denegado con flash de error. Con Tesorería, permitido.
8. **Regresión en agrupados**: en PettyCash/Refund/PaymentScheduling, regresar de `verificacion_pago → autorizacion_pago` con motivo → debe funcionar. Confirmar que `pagada` sigue siendo terminal (no se puede regresar desde ahí).
9. **Sidebar counters**: como Tesorería, verificar que el contador del sidebar incluye registros en `verificacion_pago` además de los `tesoreria`.
10. **Histórico no afectado**: registros previos en `pagada` siguen apareciendo correctamente en index/view, con stepper completo. No deben aparecer en colas de pendientes.
11. **Historial**: en `view` de un registro recién confirmado, verificar las dos entradas de historial (`autorizacion_pago → verificacion_pago` por Contador, y `verificacion_pago → pagada` por Tesorería).
12. **Idempotencia**: enviar dos POSTs consecutivos a `confirm-payment/X` → el segundo debe fallar con un mensaje claro (la validación `pipeline_status === verificacion_pago` ya cubre esto).

## Riesgos

- **Eventos rotos:** mover el dispatch de `InvoicePaidEvent` cambia cuándo se ejecutan los subscribers (notablemente `LegalizationInitializerSubscriber`). Hay que confirmar que ningún consumidor depende del evento al momento de la autorización. Auditar `App\Service\Subscriber\*` y los registros de `EventManager`.
- **Estado huérfano:** si Tesorería nunca confirma, los registros se quedan en `verificacion_pago` indefinidamente. Mitigación: el badge en sidebar lo hace visible; eventualmente puede añadirse un job de recordatorio (fuera de alcance).
- **Schema ENUM:** si alguna columna es `ENUM`, requiere migración con downtime corto (`ALTER TABLE`). Verificar antes y, si hace falta, secuenciar las migraciones para que corran en una sola ventana.
- **Vistas inconsistentes:** los stepper visuales se renderizan en varios elements distintos. Riesgo de olvidar uno y mostrar 5 pasos cuando ya hay 6 estados. Inventariar todos los `*_progress.php` antes de implementar.
