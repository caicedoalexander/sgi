# Plan 5 — Domain Events para romper el ciclo Pipeline ↔ Payment ↔ Legalization (C6)

**Plan del roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) · **Plan #5**
**Auditoría origen:** [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md) (item C6, conflicto cruzado #6)
**Fecha:** 2026-05-01
**Tamaño estimado:** ~1 semana
**Depende de:** Plan 3 (DI Container) ✅, Plan 4 (Refactor Pipeline) ✅
**Pivot Plan 2:** el outbox del Plan 2 fue descartado — esta spec abre con la decisión de mecánica de despacho que el roadmap exige (ver Decisión #1).

---

## Resumen

Los servicios `InvoicePipelineService`, `InvoicePaymentService` y `AdvanceLegalizationService` forman una dependencia circular que solo se mantiene compilable mediante un `Closure $pipelineFactory` (lazy-init) en `AdvanceLegalizationService`. Las llamadas cruzadas son cinco:

| # | Origen | Destino | Disparador |
|---|--------|---------|------------|
| 1 | `InvoicePipelineService::saveAndAdvance` (L319) | `AdvanceLegalizationService::initialize` | Anticipo avanza manualmente a `pagada` |
| 2 | `InvoicePaymentService::authorizePayment` (L155) | `AdvanceLegalizationService::initialize` | Pago completo autorizado lleva Anticipo a `pagada` |
| 3 | `InvoicePaymentService::authorizePayment` (L151) | `AdvanceLegalizationService::closeOnRefundAuthorized` | Pago `is_refund` autorizado |
| 4 | `InvoicePaymentService::rejectPayment` (L267) | `AdvanceLegalizationService::reopenAfterRefundRejected` | Pago `is_refund` rechazado |
| 5 | `AdvanceLegalizationService::_setStatus` (L514) | `InvoicePipelineService::legalizeLinkedInvoices` | Legalización llega a `legalizada` |

Este plan resuelve **C6** publicando 4 eventos de dominio síncronos in-process y enrutándolos a 3 subscribers dedicados. Las cinco llamadas directas se reemplazan por `EventManagerInterface::dispatch(...)`. `AdvanceLegalizationService` deja de necesitar el `Closure` lazy. El método cross-aggregate `legalizeLinkedInvoices` se extrae del coordinador a un servicio dedicado `LinkedInvoiceLegalizer` (cumpliendo la promesa que dejó Plan 4 en el comentario de `InvoicePipelineService::legalizeLinkedInvoices` línea 478).

El plan resuelve además el **conflicto cruzado #6** de la auditoría (circular service graph + `?? new` fallbacks). El conflicto cruzado #1 (Strategy → Pipeline) NO está en el alcance de C6 y queda fuera.

---

## Decisiones de diseño tomadas en brainstorming

1. **Despacho síncrono dentro de la transacción.** EventManager nativo de Cake. Los listeners corren en el mismo `transactional()` callback que el publisher. Esto preserva intacta la atomicidad que dejó Plan 1 (todos los side effects dentro de la TX). El "desacople" es a nivel de grafo de constructores (cycle break) y de tipos (publisher no conoce subscriber), **no temporal** (no hay diferimiento). Se descartó la opción "colectar y flush post-commit" porque reintroduciría el hueco de inconsistencia silenciosa que Plan 1 cerró.

2. **Subscribers como clases dedicadas.** Una clase por suscriptor en `src/Service/Subscriber/`, implementa `Cake\Event\EventListenerInterface`, registrada en `Application::events()` resuelta vía DI. Los servicios existentes (Pipeline, Payment, Legalization) **no implementan** `EventListenerInterface` — quedan ignorantes del bus y mantienen SRP estricta. Trade-off aceptado: 3 archivos nuevos a cambio de no contaminar los servicios de dominio.

3. **`legalizeLinkedInvoices` se extrae a `LinkedInvoiceLegalizer` y se borra del pipeline service.** Plan 4 dejó nota explícita en `InvoicePipelineService::legalizeLinkedInvoices` (L478): "Plan 5 (Domain Events) moverá este método a un servicio dedicado." Esta es la oportunidad natural. El subscriber `LinkedInvoicesPromoterSubscriber` delega al nuevo servicio. `InvoicePipelineService` pierde ~40 LOC y deja de saber de Legalización.

4. **Catálogo de 4 eventos según roadmap, sin granularidad extra.** `InvoicePaidEvent` se publica desde **ambas** rutas (`saveAndAdvance` cuando avance manual lleva a `pagada`, y `authorizePayment` cuando `payment_status=FULL`). No se distinguen como eventos distintos porque hoy ambos disparan el mismo handler. Se descartó la fusión de Authorized+Rejected en un solo `RefundOutcomeEvent` con campo `outcome` para preservar la semántica "un evento, una causa".

5. **Payload: entidades completas + `actorUserId`.** Los eventos son `final readonly class` con propiedades públicas que apuntan a la entidad de Cake (`Invoice`, `InvoicePayment`, `AdvanceLegalization`) y al user id del actor. El publisher ya tiene la entidad cargada en mano (cero re-fetch). Sync in-process: cero serialización. Riesgo aceptado: las entidades de Cake son técnicamente mutables; la convención es no mutarlas en el handler.

6. **Errores del subscriber → excepción → rollback.** Si el servicio interno (e.g., `AdvanceLegalizationService::initialize`) devuelve `ServiceResult::fail`, el subscriber lanza `ListenerFailedException`. Como corremos sync dentro de la TX (decisión #1), `transactional()` captura y rollbackea toda la operación originadora. Esto **mejora** el comportamiento actual: hoy `authorizePayment` en L151 ignora silenciosamente el `ServiceResult` de `closeOnRefundAuthorized` (best-effort), dejando posible inconsistencia. Con eventos, el rollback se vuelve garantizado.

---

## Alcance

### Lo que entra

- **Crear `src/Event/`** con 4 eventos `final readonly class` y la excepción `ListenerFailedException`:
  - `InvoicePaidEvent { Invoice $invoice, int $actorUserId }`
  - `InvoiceRefundAuthorizedEvent { InvoicePayment $payment, int $actorUserId }`
  - `InvoiceRefundRejectedEvent { InvoicePayment $payment, int $actorUserId }`
  - `AdvanceLegalizedEvent { AdvanceLegalization $legalization, int $actorUserId }`
  - `ListenerFailedException extends RuntimeException`
- **Crear `src/Service/Subscriber/`** con 3 subscribers:
  - `LegalizationInitializerSubscriber` — `Invoice.paid` → si `triggersAutoLegalization` ⇒ `AdvanceLegalizationService::initialize`
  - `RefundOutcomeSubscriber` — `Invoice.refundAuthorized` ⇒ `closeOnRefundAuthorized`; `Invoice.refundRejected` ⇒ `reopenAfterRefundRejected`
  - `LinkedInvoicesPromoterSubscriber` — `AdvanceLegalization.legalized` ⇒ `LinkedInvoiceLegalizer::legalizeFor`
- **Crear `src/Service/Pipeline/LinkedInvoiceLegalizer`** con un único método `legalizeFor(int $advanceInvoiceId, int $userId): int`. Mismo cuerpo que el actual `InvoicePipelineService::legalizeLinkedInvoices` (transactional + history); recibe `InvoiceHistoryService` por DI.
- **Refactor `InvoicePipelineService`**:
  - Quitar `AdvanceLegalizationService` del constructor.
  - Añadir `EventManagerInterface` (o `EventDispatcherInterface` si se prefiere — ver §Wiring) al constructor.
  - En `saveAndAdvance` (L317–320), reemplazar el bloque `if ($this->docTypePolicies->for(...)->triggersAutoLegalization(...)) { $this->advanceLegalizationService->initialize(...); }` por la publicación del evento cuando el avance dejó la factura en `pagada` (ver §"Forma de publicación" para el snippet idiomático con `Cake\Event\Event`). La condición `triggersAutoLegalization` migra al subscriber.
  - Borrar `legalizeLinkedInvoices()` (~L482–520, ~40 LOC).
- **Refactor `InvoicePaymentService`**:
  - Quitar `AdvanceLegalizationService` del constructor.
  - Añadir `EventManagerInterface` al constructor.
  - En `authorizePayment` (L150–156): reemplazar la llamada a `closeOnRefundAuthorized` por dispatch de `InvoiceRefundAuthorizedEvent`; reemplazar la llamada a `initialize` por dispatch de `InvoicePaidEvent` (sin filtro doctype — el subscriber filtra).
  - En `rejectPayment` (L266–270): reemplazar la llamada a `reopenAfterRefundRejected` por dispatch de `InvoiceRefundRejectedEvent`.
  - **No tocar** la rama no-refund de `rejectPayment` (sigue manipulando invoice→tesoreria directo, no es cross-service).
- **Refactor `AdvanceLegalizationService`**:
  - Quitar `Closure $pipelineFactory` del constructor.
  - Añadir `EventManagerInterface` al constructor.
  - Borrar el método privado `_getPipelineService()`.
  - En `_setStatus` (L513–515), reemplazar la llamada a `_getPipelineService()->legalizeLinkedInvoices(...)` por dispatch de `AdvanceLegalizedEvent($leg, $userId)`.
- **Wiring DI** en `Application::services()`:
  - Registrar `LinkedInvoiceLegalizer`, los 3 subscribers, los 4 eventos no requieren registro (se construyen ad-hoc en publishers).
  - Reescribir las definiciones de los 3 servicios afectados con la nueva firma de constructor.
- **Wiring eventos** en `Application::events()`:
  - Resolver los 3 subscribers vía `$container->get(...)` y registrarlos con `$eventManager->on($subscriber)` (Cake recoge `implementedEvents()` automáticamente).

### Lo que NO entra

- **Eventos para `NoveltyPipelineService` / `PaymentSchedulingPipelineService`** — mismo antipatrón, fuera de scope; replicar requeriría su propio plan.
- **Persistencia de eventos (audit log de eventos publicados)** — `invoice_histories` ya cubre el cambio de estado relevante para auditoría. Agregar tabla de eventos sumaría infra sin necesidad.
- **Bus asíncrono / outbox / cron / worker** — descartado por el pivot del Plan 2.
- **Refactor del conflicto cruzado #1 (`InvoiceApprovalStrategy` instancia `InvoicePipelineService`)** — no está en C6, requiere un plan propio (la auditoría sugiere State pattern allí, pero el roadmap no lo agendó).
- **Cambio de firma `array` → `ServiceResult` en `saveAndAdvance` / `legalizeLinkedInvoices`** — Plan 7 (W15).
- **Migración de `Cake\Log\Log::*` a `StructuredLogger` en los nuevos subscribers** — Plan 7 (W1). Los subscribers de este plan usarán `Cake\Log\Log` para la traza de fallo, igual que los servicios existentes.
- **Idempotencia / dedup de eventos** — Plan 6 (W6); como corremos sync in-TX, no hay reintento ni delivery duplicado a manejar.

---

## Arquitectura

### Estructura de directorios resultante

```
src/
├── Event/                                    [NUEVO]
│   ├── InvoicePaidEvent.php
│   ├── InvoiceRefundAuthorizedEvent.php
│   ├── InvoiceRefundRejectedEvent.php
│   ├── AdvanceLegalizedEvent.php
│   └── ListenerFailedException.php
└── Service/
    ├── Pipeline/
    │   ├── DocumentTypePolicyFactory.php     (existente)
    │   ├── InvoicePipelineStateRegistry.php  (existente)
    │   ├── ...                                (resto del Plan 4)
    │   └── LinkedInvoiceLegalizer.php        [NUEVO]
    ├── Subscriber/                           [NUEVO]
    │   ├── LegalizationInitializerSubscriber.php
    │   ├── RefundOutcomeSubscriber.php
    │   └── LinkedInvoicesPromoterSubscriber.php
    ├── AdvanceLegalizationService.php        (constructor cambia)
    ├── InvoicePaymentService.php             (constructor cambia)
    └── InvoicePipelineService.php            (constructor cambia, -40 LOC)
```

### Diagrama de flujo (eventos sync dentro de TX)

```
[Controller] ──► InvoicePaymentService::authorizePayment
                  └─► transactional(function () {
                        ├─ paymentsTable->save(payment)
                        ├─ recalculatePaymentStatus
                        ├─ invoicesTable->save(invoice → pagada)
                        ├─ historyService->recordStatusChange
                        ├─ if (is_refund):
                        │     events->dispatch(InvoiceRefundAuthorizedEvent)
                        │       └─ RefundOutcomeSubscriber::onRefundAuthorized
                        │           └─ leg.closeOnRefundAuthorized(...)
                        │               └─ if fail → throw ListenerFailedException
                        │                            ↑ rollback toda la TX
                        └─ if (pipeline_status == pagada):
                              events->dispatch(InvoicePaidEvent)
                                └─ LegalizationInitializerSubscriber::onInvoicePaid
                                    └─ if Anticipo → leg.initialize(...)
                                        └─ if fail → throw → rollback
                      })
```

### Catálogo final de eventos

| Evento (clase)                  | Nombre Cake          | Publisher(s)                                                | Subscriber                            | Acción del subscriber                                              |
|---------------------------------|----------------------|-------------------------------------------------------------|---------------------------------------|--------------------------------------------------------------------|
| `InvoicePaidEvent`              | `Invoice.paid`       | `InvoicePipelineService::saveAndAdvance` cuando next=`pagada`; `InvoicePaymentService::authorizePayment` cuando `payment_status=FULL` | `LegalizationInitializerSubscriber`   | Si `triggersAutoLegalization` ⇒ `AdvanceLegalizationService::initialize` |
| `InvoiceRefundAuthorizedEvent`  | `Invoice.refundAuthorized` | `InvoicePaymentService::authorizePayment` si `payment.is_refund` | `RefundOutcomeSubscriber`             | `AdvanceLegalizationService::closeOnRefundAuthorized`              |
| `InvoiceRefundRejectedEvent`    | `Invoice.refundRejected`   | `InvoicePaymentService::rejectPayment` si `payment.is_refund`    | `RefundOutcomeSubscriber`             | `AdvanceLegalizationService::reopenAfterRefundRejected`            |
| `AdvanceLegalizedEvent`         | `AdvanceLegalization.legalized` | `AdvanceLegalizationService::_setStatus` cuando `newStatus = STATUS_LEGALIZADA` | `LinkedInvoicesPromoterSubscriber` | `LinkedInvoiceLegalizer::legalizeFor`                              |

### Wiring

**`Application::services()`** — añadir:

```php
// Domain event subscribers (Plan 5)
$container->add(LinkedInvoiceLegalizer::class)
    ->addArgument(InvoiceHistoryService::class);

$container->add(LegalizationInitializerSubscriber::class)
    ->addArgument(AdvanceLegalizationService::class)
    ->addArgument(DocumentTypePolicyFactory::class);

$container->add(RefundOutcomeSubscriber::class)
    ->addArgument(AdvanceLegalizationService::class);

$container->add(LinkedInvoicesPromoterSubscriber::class)
    ->addArgument(LinkedInvoiceLegalizer::class);
```

**Reescribir** las definiciones de los 3 servicios afectados:

```php
// InvoicePipelineService: quita AdvanceLegalizationService, añade EventManagerInterface
$container->add(InvoicePipelineService::class)
    ->addArgument(InvoiceHistoryService::class)
    ->addArgument(InvoicePaymentService::class)
    ->addArgument(InvoiceFieldAccessPolicy::class)
    ->addArgument(InvoiceLockPolicy::class)
    ->addArgument(InvoiceTransitionValidator::class)
    ->addArgument(InvoicePipelineStateRegistry::class)
    ->addArgument(DocumentTypePolicyFactory::class)
    ->addArgument(EventManagerInterface::class);

// InvoicePaymentService: quita AdvanceLegalizationService
$container->add(InvoicePaymentService::class)
    ->addArgument(InvoiceHistoryService::class)
    ->addArgument(DocumentTypePolicyFactory::class)
    ->addArgument(EventManagerInterface::class);

// AdvanceLegalizationService: quita Closure, añade EventManager
$container->add(AdvanceLegalizationService::class)
    ->addArgument(EventManagerInterface::class);
```

**Registro de `EventManagerInterface`** — Cake ya provee la instancia global (`EventManager::instance()`); registrarla en el container:

```php
$container->addShared(EventManagerInterface::class, fn () => EventManager::instance());
```

**`Application::events()`** — atar subscribers:

```php
public function events(EventManagerInterface $eventManager): EventManagerInterface
{
    $container = $this->getContainer();
    $eventManager->on($container->get(LegalizationInitializerSubscriber::class));
    $eventManager->on($container->get(RefundOutcomeSubscriber::class));
    $eventManager->on($container->get(LinkedInvoicesPromoterSubscriber::class));

    return $eventManager;
}
```

### Forma de los eventos (ejemplo)

```php
namespace App\Event;

use App\Model\Entity\Invoice;

final readonly class InvoicePaidEvent
{
    public function __construct(
        public Invoice $invoice,
        public int $actorUserId,
    ) {
    }
}
```

### Forma de un subscriber (ejemplo)

```php
namespace App\Service\Subscriber;

use App\Event\InvoicePaidEvent;
use App\Event\ListenerFailedException;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

final class LegalizationInitializerSubscriber implements EventListenerInterface
{
    public function __construct(
        private readonly AdvanceLegalizationService $legalizationService,
        private readonly DocumentTypePolicyFactory $documentTypePolicies,
    ) {
    }

    public function implementedEvents(): array
    {
        return ['Invoice.paid' => 'onInvoicePaid'];
    }

    public function onInvoicePaid(EventInterface $event): void
    {
        /** @var InvoicePaidEvent $payload */
        $payload = $event->getData('payload');
        $invoice = $payload->invoice;

        $policy = $this->documentTypePolicies->for($invoice->document_type ?? null);
        if (!$policy->triggersAutoLegalization($invoice->pipeline_status)) {
            return;
        }

        $result = $this->legalizationService->initialize($invoice, $payload->actorUserId);
        if (!$result->success) {
            throw new ListenerFailedException(
                'Legalization init failed: ' . implode(', ', (array)$result->errors),
            );
        }
    }
}
```

### Forma de publicación (ejemplo en `InvoicePaymentService::authorizePayment`)

```php
// Antes:
if ((bool)($payment->is_refund ?? false)) {
    $this->advanceLegalizationService->closeOnRefundAuthorized($payment->id, $authorizedBy);
}
if ($this->docTypePolicies->for(...)->triggersAutoLegalization(...)) {
    $this->advanceLegalizationService->initialize($invoice, $authorizedBy);
}

// Después (Cake 5: dispatch acepta EventInterface; se construye Event con data):
use Cake\Event\Event;

if ((bool)($payment->is_refund ?? false)) {
    $this->events->dispatch(new Event(
        'Invoice.refundAuthorized',
        null,
        ['payload' => new InvoiceRefundAuthorizedEvent($payment, $authorizedBy)],
    ));
}
if ($invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA) {
    $this->events->dispatch(new Event(
        'Invoice.paid',
        null,
        ['payload' => new InvoicePaidEvent($invoice, $authorizedBy)],
    ));
}
```

> **Notas sobre la API de Cake 5:**
> - `EventManagerInterface::dispatch(EventInterface|string $event)` solo acepta una instancia de `EventInterface` cuando hay data; pasar string crea un evento vacío.
> - El payload va dentro de `data['payload']` (convención del proyecto, podría ser otra clave). El subscriber lo recupera con `$event->getData('payload')`.
> - La condición de doctype (`triggersAutoLegalization`) migra del publisher al subscriber. El publisher dispatchea siempre que la factura llegue a `pagada`; el subscriber filtra por doctype. Esto mantiene el evento ortogonal al consumidor.

### Manejo de errores

- **Listener lanza `ListenerFailedException`** cuando el servicio interno retorna `ServiceResult::fail`. La excepción atraviesa `EventManager::dispatch()` (Cake no la captura) y bubbles hasta `Connection::transactional(...)`, que la captura, hace rollback y re-lanza. El controller la atrapa como cualquier otra excepción y responde con error.
- **Mensaje de la excepción** incluye nombre del subscriber + errores del `ServiceResult` para diagnóstico vía `Cake\Log\Log` (que captura excepciones no manejadas en el flujo HTTP).
- **Listener no debería capturar excepciones** internas para "loguear y seguir". Si lo hace, pierde la garantía de rollback.

---

## Verificación de los criterios de éxito del roadmap

| Criterio del roadmap                                                                | Cómo se verifica                                                                                          |
|-------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------|
| No quedan llamadas directas Pipeline ↔ Payment ↔ Legalization                       | `grep "advanceLegalizationService->" src/Service/InvoicePipelineService.php src/Service/InvoicePaymentService.php` → 0 |
| Toda comunicación cruza el bus de eventos                                           | `grep "events->dispatch" src/Service/InvoicePipelineService.php src/Service/InvoicePaymentService.php src/Service/AdvanceLegalizationService.php` → 5 ocurrencias (4 eventos; `Invoice.paid` se publica desde 2 rutas) |
| `Closure $pipelineFactory` y `_getPipelineService()` eliminados                     | `grep -E "pipelineFactory\|_getPipelineService" src/Service/AdvanceLegalizationService.php` → 0           |
| `legalizeLinkedInvoices` ya no vive en el coordinador                               | `grep "function legalizeLinkedInvoices" src/Service/InvoicePipelineService.php` → 0                       |
| Conflicto cruzado #6 (auditoría) cerrado                                            | El grafo de constructores ya no es circular: Pipeline depende de EventManager (no de Legalization); Legalization depende de EventManager (no de un Closure-Pipeline). |
| **Validación funcional manual:** los 6 escenarios de §"Validación manual"           | Ver siguiente sección.                                                                                    |

---

## Validación manual (CLAUDE.md: este proyecto NO usa tests automatizados)

Cada escenario es un flujo end-to-end ejercitado vía navegador (`php bin/cake server` ya corre en local del usuario). Los IDs y datos son ilustrativos.

### Escenario 1 — Anticipo → `pagada` por avance manual (publisher: `saveAndAdvance`)

1. Crear factura tipo Anticipo, monto $1.000.000.
2. Avanzar manualmente desde Aprobación hasta `pagada` (sin pasar por authorizePayment — usar las acciones del pipeline).
3. **Verificar**: tabla `advance_legalizations` tiene una fila con `advance_invoice_id = <id>`, `status = 'validacion'`, `created_by` correcto.

### Escenario 2 — Anticipo → `pagada` por authorizePayment (publisher: `authorizePayment`)

1. Crear Anticipo, llevar a `tesoreria`.
2. Registrar pago completo (monto = total). Factura pasa a `autorizacion_pago`.
3. Autorizar el pago con rol Contador.
4. **Verificar**: factura en `pagada` Y fila en `advance_legalizations` (mismo resultado que escenario 1, pero por la otra ruta).

### Escenario 3 — Refund authorized (publisher: `authorizePayment` con `is_refund=true`)

1. Anticipo en `pagada` con legalización en `tesoreria` (case `sobrante`), con `surplus_payment_id` apuntando a un pago `is_refund=true` pendiente.
2. Como Contador, autorizar el pago refund.
3. **Verificar**: legalización pasa a `legalizada`, `legalized_at` poblado. La factura del Anticipo (Invoice) sigue en `pagada` (el refund no afecta su estado).

### Escenario 4 — Refund rejected (publisher: `rejectPayment`)

1. Mismo setup que escenario 3 hasta que el pago refund queda pendiente.
2. Como Contador, rechazar el pago con motivo.
3. **Verificar**: legalización vuelve a `tesoreria`, `surplus_payment_id` queda en NULL, el pago refund queda con `status='rejected'` y `rejection_reason` poblado.

### Escenario 5 — `AdvanceLegalized` → linked invoices promovidas (publisher: `_setStatus`, subscriber: `LinkedInvoicesPromoterSubscriber`)

1. Crear Anticipo + 2 facturas tipo Legalización vinculadas (`advance_id` apunta al Anticipo) en estado `contabilidad`.
2. Avanzar la legalización hasta `legalizada` (caso exacto, p.ej. monto exacto).
3. **Verificar**: ambas facturas tipo Legalización pasaron a `legalizada` y se generaron filas en `invoice_histories` con el cambio de estado.

### Escenario 6 — Failure path: rollback al fallar el subscriber

1. Editar temporalmente `AdvanceLegalizationService::initialize` para forzar `return ServiceResult::fail('forced for test')` siempre.
2. Repetir el escenario 2 (Anticipo via authorizePayment).
3. **Verificar**:
   - El controller muestra error al usuario.
   - **El pago NO quedó autorizado** (status sigue `pending`).
   - **La factura NO avanzó** a `pagada` (sigue en `autorizacion_pago`).
   - **No hay fila en `advance_legalizations`** para esa factura.
   - No hay fila en `invoice_histories` para la transición fallida.
4. Revertir el cambio temporal en `initialize`.

---

## Riesgos y mitigaciones

| Riesgo                                                                          | Mitigación                                                                                                                                                                                                                            |
|---------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Cambio de comportamiento: hoy `closeOnRefundAuthorized` falla silenciosamente; con eventos hace rollback | Es **intencional** y mejora la consistencia. Documentar en el commit que el cambio resuelve C4 parcialmente además de C6. Validar con escenario 6.                                                                                    |
| Doble dispatch de `InvoicePaidEvent` si una operación cae por ambas rutas       | Las rutas son mutuamente excluyentes en la práctica (avance manual NO autoriza pagos; authorizePayment NO recorre `saveAndAdvance`). Pero el subscriber `LegalizationInitializerSubscriber::initialize` ya es **idempotente** por construcción (chequea fila existente vía `find()->first()` antes de insertar). |
| Subscribers nuevos no se descubren si el wiring `Application::events()` no se modifica | Modificar `Application::events()` está en el alcance del plan; el escenario 1 valida automáticamente que esté hecho.                                                                                                                  |
| `EventManagerInterface` no estaba en el container del Plan 3                    | Se añade su registro como parte de este plan (snippet en §Wiring).                                                                                                                                                                    |
| Excepción del listener no captura por `transactional()` (rollback no ocurre)    | `Cake\Database\Connection::transactional()` SÍ captura cualquier `Throwable`, hace rollback y re-lanza. El escenario 6 lo verifica.                                                                                                  |
| `LinkedInvoiceLegalizer` extraído puede romper algún caller del coordinador      | `legalizeLinkedInvoices` solo era llamado desde `AdvanceLegalizationService::_setStatus`. Verificar con `grep -rn "legalizeLinkedInvoices" src/ templates/` antes de borrar — debe haber 0 callers fuera del propio servicio (que también se modifica). |

---

## Orden sugerido de implementación

> Detalle final lo define el plan de ejecución (`writing-plans`), pero esta es la secuencia natural sin orphan-states intermedios:

1. Crear `src/Event/` con los 4 eventos + `ListenerFailedException`. (Sin uso aún → cambio inocuo.)
2. Crear `LinkedInvoiceLegalizer` con el cuerpo copiado de `InvoicePipelineService::legalizeLinkedInvoices`. Registrar en `Application::services()`. (Aún no usado.)
3. Crear los 3 subscribers. Registrar en `Application::services()`. (Aún no atados al EventManager.)
4. Registrar `EventManagerInterface` en `Application::services()` y atar los 3 subscribers en `Application::events()`. (Subscribers vivos pero nadie publica todavía.)
5. Refactor `AdvanceLegalizationService`: quitar `Closure`, añadir `EventManagerInterface`, dispatchear `AdvanceLegalizedEvent` en `_setStatus`. Actualizar wiring. (Ahora la ruta Legalization → Pipeline está rota; el subscriber 3 toma el relevo.)
6. Refactor `InvoicePaymentService`: quitar dep, añadir `EventManagerInterface`, dispatchear los 3 eventos pertinentes. Actualizar wiring. (Ahora la ruta Payment → Legalization está rota; subscribers 1 y 2 toman el relevo.)
7. Refactor `InvoicePipelineService`: quitar dep `AdvanceLegalizationService`, añadir `EventManagerInterface`, dispatchear `InvoicePaidEvent` en `saveAndAdvance`. Borrar `legalizeLinkedInvoices()`. Actualizar wiring. (Ahora la ruta Pipeline → Legalization está rota.)
8. Verificar visualmente los 6 escenarios.
9. Commit cierre del Plan 5; actualizar tabla de estado del roadmap.

---

## Referencias

- Roadmap: [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) (Plan 5)
- Auditoría origen: [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md) (item C6, conflicto cruzado #6, recomendación "Domain event + subscribers")
- Spec Plan 4 (provee `DocumentTypePolicy`, `LinkedInvoiceLegalizer`-shape mencionado): [`2026-05-01-pipeline-refactor-design.md`](./2026-05-01-pipeline-refactor-design.md)
- Spec Plan 3 (provee container y patrón de `Application::services()`): [`2026-05-01-di-container-design.md`](./2026-05-01-di-container-design.md)
- Convenciones del proyecto: `CLAUDE.md` (raíz)
- Documentación CakePHP EventManager: [book.cakephp.org/5/en/core-libraries/events.html](https://book.cakephp.org/5/en/core-libraries/events.html)
