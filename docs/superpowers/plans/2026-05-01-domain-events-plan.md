# Plan 5 — Domain Events para romper el ciclo (C6) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Romper el ciclo `InvoicePipelineService` ↔ `InvoicePaymentService` ↔ `AdvanceLegalizationService` (C6) publicando 4 eventos de dominio síncronos in-process y enrutándolos a 3 subscribers dedicados, eliminando las 5 llamadas directas cruzadas y el `Closure $pipelineFactory` lazy.

**Architecture:** EventManager nativo de Cake 5, sync dentro de `Connection::transactional()`. Eventos como `final readonly class` con entidad + actorUserId. Subscribers en `src/Service/Subscriber/` que delegan a los servicios de dominio existentes. La operación cross-aggregate `legalizeLinkedInvoices` se extrae del coordinador a un nuevo `LinkedInvoiceLegalizer`. Errores de listener lanzan `ListenerFailedException` → rollback automático por `transactional()`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, `Cake\Event\EventManager`, `Cake\Event\EventListenerInterface`, `League\Container` (vía Plan 3 DI). Sin tests automatizados (proyecto política, CLAUDE.md). Validación manual al final.

**Spec:** [`docs/superpowers/specs/2026-05-01-domain-events-design.md`](../specs/2026-05-01-domain-events-design.md)
**Roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) (Plan 5)

**Convenciones del plan:**
- Cada task es una unidad commit-eable independiente. Mensajes de commit usan `refactor(plan-5): ...` salvo los de creación pura (`feat(plan-5): ...`) y el cierre (`chore(plan-5): ...`).
- Sin tests automatizados (CLAUDE.md). Validación manual concentrada al final (Task 11).
- Code quality review (composer cs-check, greps de criterios) corre **una sola vez** al final (Task 11), no por task — política del proyecto en memoria del usuario.
- El smoke check con `php bin/cake server` lo hace el usuario manualmente entre commits si lo desea — el plan no lo dispara automáticamente.

---

## File Structure

**Crea:**
- `src/Event/InvoicePaidEvent.php` — readonly { Invoice, int actorUserId }
- `src/Event/InvoiceRefundAuthorizedEvent.php` — readonly { InvoicePayment, int actorUserId }
- `src/Event/InvoiceRefundRejectedEvent.php` — readonly { InvoicePayment, int actorUserId }
- `src/Event/AdvanceLegalizedEvent.php` — readonly { AdvanceLegalization, int actorUserId }
- `src/Event/ListenerFailedException.php` — extends RuntimeException
- `src/Service/Pipeline/LinkedInvoiceLegalizer.php` — promueve facturas tipo Legalización vinculadas a un Anticipo → `legalizada` (extraído del coordinador)
- `src/Service/Subscriber/LegalizationInitializerSubscriber.php` — `Invoice.paid` → si Anticipo ⇒ `AdvanceLegalizationService::initialize`
- `src/Service/Subscriber/RefundOutcomeSubscriber.php` — `Invoice.refundAuthorized`/`Invoice.refundRejected` ⇒ `closeOnRefundAuthorized`/`reopenAfterRefundRejected`
- `src/Service/Subscriber/LinkedInvoicesPromoterSubscriber.php` — `AdvanceLegalization.legalized` ⇒ `LinkedInvoiceLegalizer::legalizeFor`

**Modifica:**
- `src/Service/AdvanceLegalizationService.php` — quita `Closure $pipelineFactory` y `_getPipelineService()`; añade `EventManagerInterface`; en `_setStatus` dispatch `AdvanceLegalizedEvent`.
- `src/Service/InvoicePaymentService.php` — quita `AdvanceLegalizationService`; añade `EventManagerInterface`; `authorizePayment` dispatchea 2 eventos en lugar de 2 llamadas; `rejectPayment` envuelve la rama refund en `transactional()` y dispatchea 1 evento.
- `src/Service/InvoicePipelineService.php` — quita `AdvanceLegalizationService`; añade `EventManagerInterface`; `saveAndAdvance` dispatchea `InvoicePaidEvent` cuando el avance deja la factura en `pagada`. Borra `legalizeLinkedInvoices()` (~40 LOC).
- `src/Application.php` — registra `EventManagerInterface` en el container; registra los 3 subscribers + `LinkedInvoiceLegalizer`; reescribe wiring de los 3 servicios afectados; ata subscribers en `Application::events()`.
- `docs/audits/architecture-audit-roadmap.md` — actualiza tabla de estado del Plan 5 a 🟢 Completado.

**Ningún archivo de test** — proyecto sin tests por política.

---

## Task 1: Crear los 4 eventos + `ListenerFailedException`

**Files:**
- Create: `src/Event/InvoicePaidEvent.php`
- Create: `src/Event/InvoiceRefundAuthorizedEvent.php`
- Create: `src/Event/InvoiceRefundRejectedEvent.php`
- Create: `src/Event/AdvanceLegalizedEvent.php`
- Create: `src/Event/ListenerFailedException.php`

Este task es 100% aditivo — no se referencia desde ningún publisher todavía.

- [ ] **Step 1.1: Crear `src/Event/InvoicePaidEvent.php`**

```php
<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\Invoice;

/**
 * Domain event: una factura llegó a `pipeline_status = pagada`.
 *
 * Publishers: InvoicePipelineService::saveAndAdvance (avance manual),
 *             InvoicePaymentService::authorizePayment (autorización de pago completo).
 *
 * Subscriber: LegalizationInitializerSubscriber (filtra por doctype Anticipo).
 */
final readonly class InvoicePaidEvent
{
    public function __construct(
        public Invoice $invoice,
        public int $actorUserId,
    ) {
    }
}
```

- [ ] **Step 1.2: Crear `src/Event/InvoiceRefundAuthorizedEvent.php`**

```php
<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\InvoicePayment;

/**
 * Domain event: un pago de tipo refund (`is_refund=true`) fue autorizado.
 *
 * Publisher:  InvoicePaymentService::authorizePayment.
 * Subscriber: RefundOutcomeSubscriber → AdvanceLegalizationService::closeOnRefundAuthorized.
 */
final readonly class InvoiceRefundAuthorizedEvent
{
    public function __construct(
        public InvoicePayment $payment,
        public int $actorUserId,
    ) {
    }
}
```

- [ ] **Step 1.3: Crear `src/Event/InvoiceRefundRejectedEvent.php`**

```php
<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\InvoicePayment;

/**
 * Domain event: un pago de tipo refund (`is_refund=true`) fue rechazado.
 *
 * Publisher:  InvoicePaymentService::rejectPayment.
 * Subscriber: RefundOutcomeSubscriber → AdvanceLegalizationService::reopenAfterRefundRejected.
 */
final readonly class InvoiceRefundRejectedEvent
{
    public function __construct(
        public InvoicePayment $payment,
        public int $actorUserId,
    ) {
    }
}
```

- [ ] **Step 1.4: Crear `src/Event/AdvanceLegalizedEvent.php`**

```php
<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\AdvanceLegalization;

/**
 * Domain event: una legalización de anticipo llegó a `status = legalizada`.
 *
 * Publisher:  AdvanceLegalizationService::_setStatus.
 * Subscriber: LinkedInvoicesPromoterSubscriber → LinkedInvoiceLegalizer::legalizeFor.
 */
final readonly class AdvanceLegalizedEvent
{
    public function __construct(
        public AdvanceLegalization $legalization,
        public int $actorUserId,
    ) {
    }
}
```

- [ ] **Step 1.5: Crear `src/Event/ListenerFailedException.php`**

```php
<?php
declare(strict_types=1);

namespace App\Event;

use RuntimeException;

/**
 * Lanzada por un subscriber cuando la operación de dominio interna devuelve
 * ServiceResult::fail. Atraviesa EventManager::dispatch() y bubbles hasta
 * Connection::transactional(...), que captura, hace rollback y re-lanza.
 *
 * Mantener el constructor estándar de RuntimeException: $message + previous opcional.
 */
final class ListenerFailedException extends RuntimeException
{
}
```

- [ ] **Step 1.6: Commit**

```bash
git add src/Event/
git commit -m "$(cat <<'EOF'
feat(plan-5): introducir eventos de dominio + ListenerFailedException

Crea src/Event/ con los 4 eventos del Plan 5 (InvoicePaidEvent,
InvoiceRefundAuthorizedEvent, InvoiceRefundRejectedEvent,
AdvanceLegalizedEvent) y la excepción de fallo de listener.
Aditivo: ningún publisher los usa todavía.
EOF
)"
```

---

## Task 2: Crear `LinkedInvoiceLegalizer` (sin tocar el coordinador todavía)

**Files:**
- Create: `src/Service/Pipeline/LinkedInvoiceLegalizer.php`
- Modify: `src/Application.php` (registrar en `services()`)

El método se copia desde `InvoicePipelineService::legalizeLinkedInvoices` (líneas ~482–520) y se mantiene allí en paralelo. La eliminación del original se hace en Task 10, cuando ya nadie lo llame directamente.

- [ ] **Step 2.1: Crear `src/Service/Pipeline/LinkedInvoiceLegalizer.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Constants\InvoiceConstants;
use App\Service\InvoiceHistoryService;
use Cake\ORM\TableRegistry;

/**
 * Promueve a `legalizada` todas las facturas tipo Legalización vinculadas al
 * Anticipo dado que estén actualmente en `contabilidad`. Disparado por el
 * subscriber LinkedInvoicesPromoterSubscriber al recibir AdvanceLegalizedEvent.
 *
 * Extraído de InvoicePipelineService como parte del Plan 5 (Domain Events)
 * para liberar al coordinador del conocimiento de Legalización.
 */
final class LinkedInvoiceLegalizer
{
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
    ) {
    }

    /**
     * @return int Cantidad de facturas promovidas.
     */
    public function legalizeFor(int $advanceInvoiceId, int $userId): int
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $linked = $invoicesTable->find()
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            ])
            ->all();

        if ($linked->isEmpty()) {
            return 0;
        }

        $count = 0;
        $invoicesTable->getConnection()->transactional(
            function () use ($linked, $userId, &$count, $invoicesTable): bool {
                foreach ($linked as $inv) {
                    $from = $inv->pipeline_status;
                    $inv->pipeline_status = InvoiceConstants::STATUS_LEGALIZADA;
                    if (!$invoicesTable->save($inv)) {
                        return false;
                    }
                    $this->historyService->recordStatusChange(
                        $inv->id,
                        $from,
                        InvoiceConstants::STATUS_LEGALIZADA,
                        $userId,
                    );
                    $count++;
                }

                return true;
            },
        );

        return $count;
    }
}
```

- [ ] **Step 2.2: Registrar en `Application::services()`**

Añadir el `use` import al principio de `src/Application.php` (alfabético, después de `InvoicePipelineStateRegistry`):

```php
use App\Service\Pipeline\LinkedInvoiceLegalizer;
```

Y añadir el registro dentro del bloque `// === Pipeline states (Plan 4 W9) ...` o crear un nuevo bloque `// === Plan 5: Domain events ===` justo antes de `// === Strategies ===`. Bloque sugerido:

```php
        // === Plan 5: Domain events — services + subscribers ===
        $container->addShared(LinkedInvoiceLegalizer::class)
            ->addArgument(InvoiceHistoryService::class);
```

(Los subscribers se añaden en Tasks 4–6; el bloque se irá llenando.)

- [ ] **Step 2.3: Commit**

```bash
git add src/Service/Pipeline/LinkedInvoiceLegalizer.php src/Application.php
git commit -m "$(cat <<'EOF'
feat(plan-5): extraer LinkedInvoiceLegalizer de InvoicePipelineService

Copia legalizeLinkedInvoices() a un servicio dedicado en
src/Service/Pipeline/. El método original sigue vivo en el
coordinador hasta Task 10, cuando se borre con el resto del
refactor. Registrado en Application::services().
EOF
)"
```

---

## Task 3: Registrar `EventManagerInterface` en el container

**Files:**
- Modify: `src/Application.php` (`services()`)

CakePHP expone `EventManager::instance()` (singleton estático). Lo registramos como compartido en el container para inyectarlo en publishers vía DI.

- [ ] **Step 3.1: Añadir use imports en `src/Application.php`**

Añadir junto a los otros `use Cake\Event\...`:

```php
use Cake\Event\EventManager;
```

(Ya existe `use Cake\Event\EventManagerInterface;`.)

- [ ] **Step 3.2: Registrar en el container**

Dentro del bloque `// === Plan 5: Domain events — services + subscribers ===` (creado en Task 2.2), antes del `LinkedInvoiceLegalizer`, añadir:

```php
        // === Plan 5: Domain events — services + subscribers ===
        $container->addShared(EventManagerInterface::class, fn () => EventManager::instance());

        $container->addShared(LinkedInvoiceLegalizer::class)
            ->addArgument(InvoiceHistoryService::class);
```

- [ ] **Step 3.3: Commit**

```bash
git add src/Application.php
git commit -m "$(cat <<'EOF'
feat(plan-5): registrar EventManagerInterface en el container

Mapea la interfaz a EventManager::instance() (singleton de Cake)
para permitir su inyección por constructor en los publishers que
vienen en tasks 8–10.
EOF
)"
```

---

## Task 4: Crear `LegalizationInitializerSubscriber`

**Files:**
- Create: `src/Service/Subscriber/LegalizationInitializerSubscriber.php`
- Modify: `src/Application.php` (registrar en `services()`)

Este subscriber escucha `Invoice.paid` y, si el doctype es Anticipo (vía `triggersAutoLegalization`), llama a `AdvanceLegalizationService::initialize`. Si éste falla, lanza `ListenerFailedException` → rollback.

- [ ] **Step 4.1: Crear `src/Service/Subscriber/LegalizationInitializerSubscriber.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Subscriber;

use App\Event\InvoicePaidEvent;
use App\Event\ListenerFailedException;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * Reacciona a Invoice.paid y, si la doctype policy lo dispara
 * (típicamente Anticipo→pagada), inicializa la legalización del anticipo.
 *
 * Si AdvanceLegalizationService::initialize devuelve fail, lanza
 * ListenerFailedException para que el transactional() del publisher haga
 * rollback. Esto cierra el hueco de "best-effort silencioso" que existía
 * antes (cf. comentario L151 del antiguo InvoicePaymentService::authorizePayment).
 */
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
        /** @var \App\Event\InvoicePaidEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof InvoicePaidEvent) {
            return;
        }

        $invoice = $payload->invoice;
        $policy = $this->documentTypePolicies->for($invoice->document_type ?? null);

        if (!$policy->triggersAutoLegalization($invoice->pipeline_status)) {
            return;
        }

        $result = $this->legalizationService->initialize($invoice, $payload->actorUserId);
        if (!$result->success) {
            throw new ListenerFailedException(
                'LegalizationInitializerSubscriber: initialize falló: '
                . implode(', ', (array)$result->errors),
            );
        }
    }
}
```

- [ ] **Step 4.2: Registrar en `Application::services()`**

Añadir use import:

```php
use App\Service\Subscriber\LegalizationInitializerSubscriber;
```

Dentro del bloque `// === Plan 5: Domain events ...`, después de `LinkedInvoiceLegalizer`:

```php
        $container->addShared(LegalizationInitializerSubscriber::class)
            ->addArguments([
                AdvanceLegalizationService::class,
                DocumentTypePolicyFactory::class,
            ]);
```

- [ ] **Step 4.3: Commit**

```bash
git add src/Service/Subscriber/LegalizationInitializerSubscriber.php src/Application.php
git commit -m "$(cat <<'EOF'
feat(plan-5): añadir LegalizationInitializerSubscriber

Subscriber para Invoice.paid: inicializa la legalización si la
DocumentTypePolicy dispara auto-legalization (Anticipo→pagada).
Lanza ListenerFailedException ante fail de initialize().
Registrado en el container; aún no atado al EventManager.
EOF
)"
```

---

## Task 5: Crear `RefundOutcomeSubscriber`

**Files:**
- Create: `src/Service/Subscriber/RefundOutcomeSubscriber.php`
- Modify: `src/Application.php` (registrar en `services()`)

Un solo subscriber maneja ambos eventos refund (autorizado y rechazado) — comparten la misma dependencia (`AdvanceLegalizationService`).

- [ ] **Step 5.1: Crear `src/Service/Subscriber/RefundOutcomeSubscriber.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Subscriber;

use App\Event\InvoiceRefundAuthorizedEvent;
use App\Event\InvoiceRefundRejectedEvent;
use App\Event\ListenerFailedException;
use App\Service\AdvanceLegalizationService;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * Reacciona a los eventos de outcome de pagos refund (sobrante de anticipo):
 * - Invoice.refundAuthorized → AdvanceLegalizationService::closeOnRefundAuthorized
 * - Invoice.refundRejected   → AdvanceLegalizationService::reopenAfterRefundRejected
 *
 * Cualquier fail del servicio interno se eleva como ListenerFailedException
 * para que el transactional() del publisher haga rollback de la operación
 * (autorización/rechazo del pago) que disparó el evento.
 */
final class RefundOutcomeSubscriber implements EventListenerInterface
{
    public function __construct(
        private readonly AdvanceLegalizationService $legalizationService,
    ) {
    }

    public function implementedEvents(): array
    {
        return [
            'Invoice.refundAuthorized' => 'onRefundAuthorized',
            'Invoice.refundRejected' => 'onRefundRejected',
        ];
    }

    public function onRefundAuthorized(EventInterface $event): void
    {
        /** @var \App\Event\InvoiceRefundAuthorizedEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof InvoiceRefundAuthorizedEvent) {
            return;
        }

        $result = $this->legalizationService->closeOnRefundAuthorized(
            $payload->payment->id,
            $payload->actorUserId,
        );
        if (!$result->success) {
            throw new ListenerFailedException(
                'RefundOutcomeSubscriber: closeOnRefundAuthorized falló: '
                . implode(', ', (array)$result->errors),
            );
        }
    }

    public function onRefundRejected(EventInterface $event): void
    {
        /** @var \App\Event\InvoiceRefundRejectedEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof InvoiceRefundRejectedEvent) {
            return;
        }

        $result = $this->legalizationService->reopenAfterRefundRejected(
            $payload->payment->id,
            $payload->actorUserId,
        );
        if (!$result->success) {
            throw new ListenerFailedException(
                'RefundOutcomeSubscriber: reopenAfterRefundRejected falló: '
                . implode(', ', (array)$result->errors),
            );
        }
    }
}
```

- [ ] **Step 5.2: Registrar en `Application::services()`**

Añadir use import:

```php
use App\Service\Subscriber\RefundOutcomeSubscriber;
```

Después del registro de `LegalizationInitializerSubscriber`:

```php
        $container->addShared(RefundOutcomeSubscriber::class)
            ->addArgument(AdvanceLegalizationService::class);
```

- [ ] **Step 5.3: Commit**

```bash
git add src/Service/Subscriber/RefundOutcomeSubscriber.php src/Application.php
git commit -m "$(cat <<'EOF'
feat(plan-5): añadir RefundOutcomeSubscriber

Subscriber unificado para Invoice.refundAuthorized y
Invoice.refundRejected. Delega a AdvanceLegalizationService
y eleva ListenerFailedException si la operación falla, para
que el transactional() del publisher rollbackee.
EOF
)"
```

---

## Task 6: Crear `LinkedInvoicesPromoterSubscriber`

**Files:**
- Create: `src/Service/Subscriber/LinkedInvoicesPromoterSubscriber.php`
- Modify: `src/Application.php` (registrar en `services()`)

Subscriber para `AdvanceLegalization.legalized`. Delega a `LinkedInvoiceLegalizer::legalizeFor` (creado en Task 2). El método retorna `int` (cantidad promovida); el subscriber no necesita evaluar fail porque `LinkedInvoiceLegalizer` no devuelve `ServiceResult` — gestiona errores internamente vía el rollback de su propio `transactional()`.

- [ ] **Step 6.1: Crear `src/Service/Subscriber/LinkedInvoicesPromoterSubscriber.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Subscriber;

use App\Event\AdvanceLegalizedEvent;
use App\Service\Pipeline\LinkedInvoiceLegalizer;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * Reacciona a AdvanceLegalization.legalized: promueve las facturas tipo
 * Legalización vinculadas al Anticipo (en estado contabilidad) a `legalizada`.
 *
 * Delega 100% en LinkedInvoiceLegalizer. Cualquier error de DB durante la
 * promoción provoca el rollback del transactional() interno del legalizer
 * y elevará la excepción al publisher (AdvanceLegalizationService::_setStatus).
 */
final class LinkedInvoicesPromoterSubscriber implements EventListenerInterface
{
    public function __construct(
        private readonly LinkedInvoiceLegalizer $legalizer,
    ) {
    }

    public function implementedEvents(): array
    {
        return ['AdvanceLegalization.legalized' => 'onLegalized'];
    }

    public function onLegalized(EventInterface $event): void
    {
        /** @var \App\Event\AdvanceLegalizedEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof AdvanceLegalizedEvent) {
            return;
        }

        $this->legalizer->legalizeFor(
            $payload->legalization->advance_invoice_id,
            $payload->actorUserId,
        );
    }
}
```

- [ ] **Step 6.2: Registrar en `Application::services()`**

Añadir use import:

```php
use App\Service\Subscriber\LinkedInvoicesPromoterSubscriber;
```

Después del registro de `RefundOutcomeSubscriber`:

```php
        $container->addShared(LinkedInvoicesPromoterSubscriber::class)
            ->addArgument(LinkedInvoiceLegalizer::class);
```

- [ ] **Step 6.3: Commit**

```bash
git add src/Service/Subscriber/LinkedInvoicesPromoterSubscriber.php src/Application.php
git commit -m "$(cat <<'EOF'
feat(plan-5): añadir LinkedInvoicesPromoterSubscriber

Subscriber para AdvanceLegalization.legalized; delega a
LinkedInvoiceLegalizer::legalizeFor. El legalizer maneja sus
propios errores vía el rollback de su transactional() interno.
EOF
)"
```

---

## Task 7: Atar los 3 subscribers en `Application::events()`

**Files:**
- Modify: `src/Application.php` (`events()`)

Hasta aquí los subscribers viven en el container pero el EventManager no los conoce. Este task los engancha. Después de esto los listeners están "vivos" pero ningún publisher dispatchea todavía → cero efecto runtime.

- [ ] **Step 7.1: Reescribir `Application::events()`**

Reemplazar el método `events()` (líneas ~303–306) por:

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

> Nota: `EventManager::on(EventListenerInterface)` lee `implementedEvents()` automáticamente y registra todos los pares `evento → método`.

- [ ] **Step 7.2: Commit**

```bash
git add src/Application.php
git commit -m "$(cat <<'EOF'
feat(plan-5): atar subscribers de dominio en Application::events()

Los 3 subscribers se resuelven vía DI y se registran en el
EventManager global. Sin efecto runtime aún: ningún publisher
dispatchea hasta tasks 8–10.
EOF
)"
```

---

## Task 8: Refactor `AdvanceLegalizationService` — quitar Closure, dispatch `AdvanceLegalizedEvent`

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php` (constructor, borrar `_getPipelineService`, modificar `_setStatus`)
- Modify: `src/Application.php` (wiring del servicio)

Después de este task, la ruta `Legalization → Pipeline` está rota: `AdvanceLegalizationService` ya no conoce a `InvoicePipelineService`. El subscriber `LinkedInvoicesPromoterSubscriber` (Task 6) toma el relevo.

- [ ] **Step 8.1: Modificar `src/Service/AdvanceLegalizationService.php` — imports + constructor**

Reemplazar las líneas de imports (1–13) por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Event\AdvanceLegalizedEvent;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\Trait\DocumentUploadTrait;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
```

(Borra el `use Closure;`.)

Reemplazar el bloque del constructor + `_getPipelineService()` (líneas ~15–30):

```php
class AdvanceLegalizationService
{
    use DocumentUploadTrait;

    public function __construct(
        private readonly EventManagerInterface $events,
    ) {
    }
```

(Borra completo el método `_getPipelineService()` y la propiedad `$pipelineFactory`.)

- [ ] **Step 8.2: Modificar `_setStatus()` para dispatchear `AdvanceLegalizedEvent`**

Reemplazar las líneas ~499–518 (método `_setStatus` completo) por:

```php
    /**
     * Persist a status transition and updated_by stamp. Cuando el nuevo estado es
     * STATUS_LEGALIZADA, publica AdvanceLegalizedEvent (Plan 5) en lugar de llamar
     * directamente al pipeline service. El subscriber LinkedInvoicesPromoterSubscriber
     * promueve las facturas vinculadas vía LinkedInvoiceLegalizer.
     */
    private function _setStatus(AdvanceLegalization $leg, string $newStatus, int $userId): ServiceResult
    {
        $leg->status = $newStatus;
        $leg->updated_by = $userId;
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        if (!$table->save($leg)) {
            return ServiceResult::fail('No se pudo guardar la legalización: ' . json_encode($leg->getErrors()));
        }

        if ($newStatus === AdvanceConstants::STATUS_LEGALIZADA) {
            $this->events->dispatch(new Event(
                'AdvanceLegalization.legalized',
                null,
                ['payload' => new AdvanceLegalizedEvent($leg, $userId)],
            ));
        }

        return ServiceResult::ok($leg);
    }
```

- [ ] **Step 8.3: Actualizar wiring en `src/Application.php`**

Reemplazar el registro de `AdvanceLegalizationService` (líneas ~192–195):

```php
        $container->addShared(AdvanceLegalizationService::class)
            ->addArgument(EventManagerInterface::class);
```

- [ ] **Step 8.4: Commit**

```bash
git add src/Service/AdvanceLegalizationService.php src/Application.php
git commit -m "$(cat <<'EOF'
refactor(plan-5): AdvanceLegalizationService publica AdvanceLegalizedEvent (C6)

Quita el Closure $pipelineFactory y _getPipelineService(): el
servicio deja de conocer a InvoicePipelineService. _setStatus
dispatchea AdvanceLegalizedEvent cuando newStatus=LEGALIZADA;
LinkedInvoicesPromoterSubscriber recoge y delega en
LinkedInvoiceLegalizer. Wiring del container actualizado.

Ruta Legalization → Pipeline rota.
EOF
)"
```

---

## Task 9: Refactor `InvoicePaymentService` — dispatch 3 eventos + envolver `rejectPayment`-refund en `transactional()`

**Files:**
- Modify: `src/Service/InvoicePaymentService.php` (constructor, `authorizePayment`, `rejectPayment`)
- Modify: `src/Application.php` (wiring)

Después de este task, la ruta `Payment → Legalization` está rota: `InvoicePaymentService` ya no conoce a `AdvanceLegalizationService`. La rama refund de `rejectPayment` se envuelve en `transactional()` para mantener la invariante "dispatches dentro de TX" del spec.

- [ ] **Step 9.1: Modificar imports + constructor**

Reemplazar las líneas 1–23 de `src/Service/InvoicePaymentService.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Event\InvoicePaidEvent;
use App\Event\InvoiceRefundAuthorizedEvent;
use App\Event\InvoiceRefundRejectedEvent;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;
use DateTimeInterface;

class InvoicePaymentService
{
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
        private readonly DocumentTypePolicyFactory $docTypePolicies,
        private readonly EventManagerInterface $events,
    ) {
    }
```

(Borra el `private readonly AdvanceLegalizationService $advanceLegalizationService` del constructor — el orden de los demás args queda: history, docTypePolicies, events.)

- [ ] **Step 9.2: Reescribir `authorizePayment()` — dispatch en lugar de llamadas directas**

Reemplazar el método `authorizePayment` completo (líneas ~108–168) por:

```php
    /**
     * Autoriza un pago individual, recalcula estado, y maneja transiciones de pipeline.
     * Registra historial para los cambios de estado. Todo el flujo (pago, recálculo,
     * actualización de pipeline, historial y eventos de dominio) ocurre dentro de una
     * sola transacción para evitar inconsistencias parciales.
     *
     * Plan 5: las llamadas directas a AdvanceLegalizationService se reemplazan por
     * dispatch de InvoiceRefundAuthorizedEvent (cuando is_refund) y InvoicePaidEvent
     * (cuando la factura quedó en pagada). Si un subscriber falla, lanza
     * ListenerFailedException → rollback de toda la operación.
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $connection = $paymentsTable->getConnection();

        $result = $connection->transactional(function () use (
            $paymentsTable,
            $invoicesTable,
            $paymentId,
            $authorizedBy,
        ) {
            $payment = $paymentsTable->get($paymentId);

            $payment->authorized = true;
            $payment->status = InvoiceConstants::PAYMENT_RECORD_AUTHORIZED;
            $payment->authorized_by = $authorizedBy;
            $payment->authorized_date = date('Y-m-d');

            if (!$paymentsTable->save($payment)) {
                return false; // → rollback
            }

            $this->recalculatePaymentStatus($payment->invoice_id);

            $invoice = $invoicesTable->get($payment->invoice_id);
            $previousStatus = $invoice->pipeline_status;

            $newPipelineStatus = $invoice->payment_status === InvoiceConstants::PAYMENT_FULL
                ? InvoiceConstants::STATUS_PAGADA
                : InvoiceConstants::STATUS_TESORERIA;

            $invoice->pipeline_status = $newPipelineStatus;
            $invoicesTable->save($invoice);

            $this->historyService->recordStatusChange(
                $invoice->id,
                $previousStatus,
                $newPipelineStatus,
                $authorizedBy,
            );

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

            return [
                'success' => true,
                'paymentStatus' => $invoice->payment_status,
                'newPipelineStatus' => $newPipelineStatus,
            ];
        });

        return $result === false
            ? ['success' => false, 'paymentStatus' => null, 'newPipelineStatus' => null]
            : $result;
    }
```

- [ ] **Step 9.3: Reescribir `rejectPayment()` — envolver rama refund en `transactional()` + dispatch evento**

Reemplazar el método `rejectPayment` completo (líneas ~239–283) por:

```php
    /**
     * Rechaza un pago pendiente marcando status=rejected con motivo,
     * y devuelve la factura a tesorería. No elimina el registro.
     *
     * Plan 5: la rama refund se envuelve en transactional() para mantener la
     * invariante "dispatches dentro de TX" del spec. La llamada directa a
     * AdvanceLegalizationService se reemplaza por dispatch de InvoiceRefundRejectedEvent.
     */
    public function rejectPayment(int $paymentId, int $rejectedBy, string $reason): ServiceResult
    {
        if (trim($reason) === '') {
            return ServiceResult::fail('El motivo de rechazo es obligatorio.');
        }

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $payment = $paymentsTable->get($paymentId);

        if ($payment->status === InvoiceConstants::PAYMENT_RECORD_AUTHORIZED) {
            return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
        }

        $invoiceId = $payment->invoice_id;
        $invoice = $invoicesTable->get($invoiceId);
        $previousStatus = $invoice->pipeline_status;

        // Refund: la factura del Anticipo permanece en `pagada`; el rechazo solo
        // afecta a la legalización vía evento. TX preserva atomicidad save+dispatch.
        if ((bool)($payment->is_refund ?? false)) {
            $connection = $paymentsTable->getConnection();
            $ok = $connection->transactional(function () use ($paymentsTable, $payment, $reason, $rejectedBy) {
                $payment->status = InvoiceConstants::PAYMENT_RECORD_REJECTED;
                $payment->rejection_reason = $reason;

                if (!$paymentsTable->save($payment)) {
                    return false;
                }

                $this->events->dispatch(new Event(
                    'Invoice.refundRejected',
                    null,
                    ['payload' => new InvoiceRefundRejectedEvent($payment, $rejectedBy)],
                ));

                return true;
            });

            if ($ok === false) {
                return ServiceResult::fail('No se pudo rechazar el pago.');
            }

            return ServiceResult::ok('Reintegro rechazado. La legalización volvió a Tesorería.');
        }

        // No-refund: comportamiento original (sin transactional, fuera del scope del Plan 5).
        $payment->status = InvoiceConstants::PAYMENT_RECORD_REJECTED;
        $payment->rejection_reason = $reason;

        if (!$paymentsTable->save($payment)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
        $invoicesTable->save($invoice);

        $this->historyService->recordStatusChange(
            $invoiceId,
            $previousStatus,
            InvoiceConstants::STATUS_TESORERIA,
            $rejectedBy,
        );

        return ServiceResult::ok('Pago rechazado. Factura devuelta a Tesorería.');
    }
```

- [ ] **Step 9.4: Actualizar wiring en `src/Application.php`**

Reemplazar el registro de `InvoicePaymentService` (líneas ~186–191):

```php
        $container->addShared(InvoicePaymentService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                DocumentTypePolicyFactory::class,
                EventManagerInterface::class,
            ]);
```

- [ ] **Step 9.5: Commit**

```bash
git add src/Service/InvoicePaymentService.php src/Application.php
git commit -m "$(cat <<'EOF'
refactor(plan-5): InvoicePaymentService publica eventos en lugar de llamar Legalization (C6)

- authorizePayment dispatchea InvoiceRefundAuthorizedEvent (si
  is_refund) e InvoicePaidEvent (si pipeline_status=pagada).
- rejectPayment envuelve la rama refund en transactional() y
  dispatchea InvoiceRefundRejectedEvent. Esto mantiene la
  invariante "dispatches dentro de TX" del spec; antes el save
  del payment y la llamada a leg.reopenAfterRefundRejected vivían
  fuera de cualquier transacción.
- Constructor pierde AdvanceLegalizationService, gana EventManagerInterface.
- Wiring del container actualizado.

Ruta Payment → Legalization rota.
EOF
)"
```

---

## Task 10: Refactor `InvoicePipelineService` — dispatch `InvoicePaidEvent`, borrar `legalizeLinkedInvoices()`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (constructor, `saveAndAdvance`, borrar `legalizeLinkedInvoices`)
- Modify: `src/Application.php` (wiring)

Último servicio del refactor. Después de este task el ciclo Pipeline ↔ Payment ↔ Legalization está completamente roto. Cada uno depende solo de `EventManagerInterface` y de sus propias deps verticales.

- [ ] **Step 10.1: Modificar imports + constructor**

Reemplazar las líneas 1–32 de `src/Service/InvoicePipelineService.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Event\InvoicePaidEvent;
use App\Model\Entity\Invoice;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use App\Service\Pipeline\InvoicePipelineStateRegistry;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;

/**
 * Coordinador delgado del pipeline de facturas.
 * Delega a States, DocumentTypePolicy, LockPolicy y TransitionValidator.
 *
 * API pública preservada para no romper callers (controllers, strategies, templates).
 */
class InvoicePipelineService
{
    public function __construct(
        private readonly HistoryServiceInterface $historyService,
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
        private readonly InvoiceLockPolicy $lockPolicy,
        private readonly InvoiceTransitionValidator $transitionValidator,
        private readonly InvoicePipelineStateRegistry $states,
        private readonly DocumentTypePolicyFactory $docTypePolicies,
        private readonly EventManagerInterface $events,
    ) {
    }
```

(Borra `private readonly AdvanceLegalizationService $advanceLegalizationService` del constructor.)

- [ ] **Step 10.2: Reemplazar el dispatch en `saveAndAdvance()`**

Localizar el bloque actual en `saveAndAdvance` (alrededor de líneas 317–320):

```php
                    // Auto-init de legalización cuando la doctype policy lo dispara (Anticipo → pagada).
                    if ($this->docTypePolicies->for($invoice->document_type ?? null)->triggersAutoLegalization($invoice->pipeline_status)) {
                        $this->advanceLegalizationService->initialize($invoice, $userId);
                    }
```

Reemplazarlo por:

```php
                    // Plan 5: publicar InvoicePaidEvent cuando el avance dejó la factura
                    // en pagada. El subscriber LegalizationInitializerSubscriber filtra por
                    // doctype y dispara la inicialización de legalización si corresponde.
                    if ($invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA) {
                        $this->events->dispatch(new Event(
                            'Invoice.paid',
                            null,
                            ['payload' => new InvoicePaidEvent($invoice, $userId)],
                        ));
                    }
```

- [ ] **Step 10.3: Borrar el método `legalizeLinkedInvoices()`**

Borrar las líneas ~473–522 (todo el método `legalizeLinkedInvoices` incluyendo su PHPDoc). Esto elimina ~50 LOC del coordinador.

Verificar que no quede ninguna referencia:

```bash
grep -rn "legalizeLinkedInvoices" src/ templates/
```

Esperado: cero ocurrencias (la única era la del subscriber/legalizer, pero ya están desacoplados).

- [ ] **Step 10.4: Actualizar wiring en `src/Application.php`**

Reemplazar el registro de `InvoicePipelineService` (líneas ~196–206):

```php
        $container->addShared(InvoicePipelineService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                InvoicePaymentService::class,
                InvoiceFieldAccessPolicy::class,
                InvoiceLockPolicy::class,
                InvoiceTransitionValidator::class,
                InvoicePipelineStateRegistry::class,
                DocumentTypePolicyFactory::class,
                EventManagerInterface::class,
            ]);
```

(Quita `AdvanceLegalizationService::class` del array.)

- [ ] **Step 10.5: Commit**

```bash
git add src/Service/InvoicePipelineService.php src/Application.php
git commit -m "$(cat <<'EOF'
refactor(plan-5): InvoicePipelineService publica InvoicePaidEvent y borra legalizeLinkedInvoices (C6)

- saveAndAdvance dispatchea InvoicePaidEvent cuando el avance
  deja la factura en pagada. El filtro de doctype migra del
  publisher al subscriber LegalizationInitializerSubscriber.
- legalizeLinkedInvoices() eliminado del coordinador (~50 LOC).
  Su lógica vive desde Task 2 en LinkedInvoiceLegalizer y se
  ejecuta vía LinkedInvoicesPromoterSubscriber.
- Constructor pierde AdvanceLegalizationService, gana EventManagerInterface.
- Wiring actualizado.

Ruta Pipeline → Legalization rota. Ciclo C6 completamente
deshecho: Pipeline, Payment y Legalization solo dependen del
EventManager y de sus deps verticales propias.
EOF
)"
```

---

## Task 11: Sweep final — checks automáticos, validación manual, cierre del Plan 5

**Files:**
- Modify: `docs/audits/architecture-audit-roadmap.md` (tabla de estado)

Este task ejecuta el code quality review (que en este proyecto se hace **una sola vez al final**, no por task), valida los criterios técnicos del spec con greps, dirige la validación manual de los 6 escenarios al usuario, y cierra el plan actualizando el roadmap.

- [ ] **Step 11.1: Ejecutar `composer cs-check`**

```bash
composer cs-check
```

Esperado: PASS sin errores. Si hay errores de estilo en los archivos nuevos/modificados, corregir con:

```bash
composer cs-fix
git add -u
git commit -m "style(plan-5): cs-fix sobre archivos del plan"
```

- [ ] **Step 11.2: Verificar criterios técnicos del spec con greps**

Ejecutar y verificar resultados:

```bash
# 1. Cero llamadas directas Pipeline/Payment → Legalization:
grep "advanceLegalizationService->" src/Service/InvoicePipelineService.php src/Service/InvoicePaymentService.php
# Esperado: SIN OUTPUT

# 2. Closure factory eliminado:
grep -E "pipelineFactory|_getPipelineService" src/Service/AdvanceLegalizationService.php
# Esperado: SIN OUTPUT

# 3. legalizeLinkedInvoices borrado del coordinador:
grep "function legalizeLinkedInvoices" src/Service/InvoicePipelineService.php
# Esperado: SIN OUTPUT

# 4. Subscribers atados:
grep "eventManager->on" src/Application.php
# Esperado: 3 ocurrencias (las 3 líneas $eventManager->on($container->get(...)))

# 5. Eventos dispatcheados desde los 3 publishers:
grep -c "events->dispatch" src/Service/InvoicePipelineService.php src/Service/InvoicePaymentService.php src/Service/AdvanceLegalizationService.php
# Esperado:
#   InvoicePipelineService.php:1
#   InvoicePaymentService.php:3 (refundAuthorized + paid en authorizePayment + refundRejected en rejectPayment)
#   AdvanceLegalizationService.php:1
#   Total: 5 dispatches que cubren los 4 eventos (InvoicePaidEvent dispatcheado 2 veces, una por publisher)

# 6. Listener interface implementado en los 3 subscribers:
grep -l "implements EventListenerInterface" src/Service/Subscriber/
# Esperado: 3 archivos
```

Si algún check falla, identificar el origen y corregir antes de continuar. Crear un commit `fix(plan-5): ...` por cada corrección.

- [ ] **Step 11.3: Validación manual — Escenario 1 (Anticipo → pagada por avance manual)**

Pasos para el usuario (no se automatizan):

1. Crear factura tipo Anticipo, monto $1.000.000.
2. Avanzar manualmente desde Aprobación hasta `pagada` usando las acciones del pipeline (sin pasar por authorizePayment).
3. Verificar en MySQL:

```sql
SELECT id, advance_invoice_id, status, created_by FROM advance_legalizations
  WHERE advance_invoice_id = <ID_DE_LA_FACTURA>;
```

   Esperado: 1 fila con `status='validacion'` y `created_by` = usuario que avanzó.

- [ ] **Step 11.4: Validación manual — Escenario 2 (Anticipo → pagada por authorizePayment)**

1. Crear Anticipo, llevarlo manualmente a `tesoreria`.
2. Registrar pago completo (monto = total). La factura pasa a `autorizacion_pago`.
3. Como rol Contador, autorizar el pago.
4. Verificar:
   - Factura en `pipeline_status='pagada'`.
   - Fila en `advance_legalizations` (mismo SQL que escenario 1).

- [ ] **Step 11.5: Validación manual — Escenario 3 (Refund authorized)**

1. Anticipo en `pagada` con legalización en `tesoreria` con `case_type='sobrante'` y un pago `is_refund=true` pendiente (`surplus_payment_id` poblado).
2. Como Contador, autorizar el pago refund.
3. Verificar:
   - `advance_legalizations.status` = `legalizada` para esa fila.
   - `legalized_at` poblado.
   - La factura del Anticipo (Invoice) sigue en `pipeline_status='pagada'`.

- [ ] **Step 11.6: Validación manual — Escenario 4 (Refund rejected)**

1. Mismo setup del escenario 3 hasta que el pago refund queda pendiente.
2. Como Contador, rechazar el pago con motivo (≥ 1 carácter).
3. Verificar:
   - `advance_legalizations.status` vuelve a `tesoreria`.
   - `advance_legalizations.surplus_payment_id` queda en NULL.
   - El pago refund queda con `status='rejected'` y `rejection_reason` poblado.

- [ ] **Step 11.7: Validación manual — Escenario 5 (Linked invoices promovidas)**

1. Crear Anticipo + 2 facturas tipo Legalización vinculadas (`advance_id` apunta al Anticipo) en estado `contabilidad`.
2. Avanzar la legalización hasta `legalizada` (caso exacto: monto del anticipo = suma de vinculadas).
3. Verificar:
   - Ambas facturas tipo Legalización pasaron a `pipeline_status='legalizada'`.
   - 2 nuevas filas en `invoice_histories` con `field_changed='pipeline_status'`, `old_value='contabilidad'`, `new_value='legalizada'`.

- [ ] **Step 11.8: Validación manual — Escenario 6 (Failure path: rollback al fallar el subscriber)**

1. Editar temporalmente `src/Service/AdvanceLegalizationService.php`, método `initialize`. Añadir como primera línea del método:

```php
return ServiceResult::fail('forced for plan 5 manual test');
```

2. Repetir el escenario 2 (Anticipo via authorizePayment).
3. Verificar:
   - El controller responde con error visible al usuario.
   - El pago NO quedó autorizado (`status='pending'` en `invoice_payments`).
   - La factura NO avanzó a `pagada` (sigue en `autorizacion_pago`).
   - Cero filas en `advance_legalizations` para esa factura.
   - Cero filas nuevas en `invoice_histories` para esa transición.
4. Revertir el cambio temporal:

```bash
git checkout src/Service/AdvanceLegalizationService.php
```

   (No commitear el rollback — el archivo nunca debió cambiar.)

- [ ] **Step 11.9: Actualizar tabla de estado en el roadmap**

Editar `docs/audits/architecture-audit-roadmap.md`. Localizar la fila del Plan 5 en la "Tabla de estado":

```markdown
| 5 | Domain Events | ⬜ Pendiente | — | — | — | — |
```

Reemplazar por:

```markdown
| 5 | Domain Events | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-domain-events-design.md) | [plan](../superpowers/plans/2026-05-01-domain-events-plan.md) | — | 2026-05-01 |
```

(Si la fecha real de cierre es distinta, ajustar.)

También actualizar el "Estado global" arriba si todos los planes están cerrados — opcional. Y actualizar la fila de resumen ejecutivo:

```markdown
| 5 | Domain Events (romper ciclo) | C6 | S (~1 sem) | — *(originalmente Plan 2; ver "Cambios al roadmap")* | ⬜ Pendiente |
```

a:

```markdown
| 5 | Domain Events (romper ciclo) | C6 | S (~1 sem) | — *(originalmente Plan 2; ver "Cambios al roadmap")* | 🟢 Completado |
```

- [ ] **Step 11.10: Commit de cierre**

```bash
git add docs/audits/architecture-audit-roadmap.md
git commit -m "$(cat <<'EOF'
chore(plan-5): cierre del Plan 5 (Domain Events / C6)

Plan 5 mergeado: ciclo Pipeline ↔ Payment ↔ Legalization
deshecho mediante 4 eventos de dominio sync in-TX y 3
subscribers dedicados. Validación manual de los 6 escenarios
del spec ejecutada. Roadmap actualizado a Completado.
EOF
)"
```

---

## Self-Review

Verificación final de que el plan cubre el spec sin huecos:

| Sección del spec                                                          | Task que la cubre |
|---------------------------------------------------------------------------|-------------------|
| §Decisiones #1 (sync in-TX)                                               | Task 8/9/10 (cada dispatch dentro de transactional o equivalente) |
| §Decisiones #2 (subscribers dedicados)                                    | Tasks 4, 5, 6     |
| §Decisiones #3 (LinkedInvoiceLegalizer extraído)                          | Task 2 (crear) + Task 10.3 (borrar original) |
| §Decisiones #4 (4 eventos del roadmap)                                    | Task 1            |
| §Decisiones #5 (payload con entidades)                                    | Task 1 (eventos como readonly con entidad) |
| §Decisiones #6 (errores → ListenerFailedException → rollback)             | Tasks 4 + 5 (subscribers throw); Task 11.8 valida rollback |
| §Alcance — crear src/Event/                                               | Task 1            |
| §Alcance — crear src/Service/Subscriber/                                  | Tasks 4, 5, 6     |
| §Alcance — crear LinkedInvoiceLegalizer                                   | Task 2            |
| §Alcance — refactor InvoicePipelineService                                | Task 10           |
| §Alcance — refactor InvoicePaymentService                                 | Task 9            |
| §Alcance — refactor AdvanceLegalizationService                            | Task 8            |
| §Alcance — wiring DI                                                      | Tasks 2.2, 3, 4.2, 5.2, 6.2, 8.3, 9.4, 10.4 |
| §Alcance — wiring eventos en `Application::events()`                      | Task 7            |
| §Catálogo de eventos (4 eventos, publishers/subscribers)                  | Task 1 (clases) + Tasks 8/9/10 (publishers) + Tasks 4/5/6 (subscribers) |
| §Forma de los eventos (ejemplo)                                           | Task 1.1          |
| §Forma de un subscriber (ejemplo)                                         | Task 4.1          |
| §Forma de publicación (ejemplo)                                           | Task 9.2          |
| §Manejo de errores (excepción → rollback)                                 | Tasks 4 + 5 (throws) + Task 11.8 (validación) |
| §Verificación de criterios de éxito (greps)                               | Task 11.2         |
| §Validación manual (6 escenarios)                                         | Tasks 11.3 a 11.8 |
| §Riesgos — doble dispatch InvoicePaid (idempotencia)                      | Cubierto por construcción: `AdvanceLegalizationService::initialize` ya hace `find()->first()` antes de insertar (preexistente, no requiere task) |
| §Orden sugerido de implementación                                         | Tasks 1–11 siguen ese orden literalmente |

**Placeholder scan:** Sin TBD/TODO/"add appropriate"/"similar to". Cada step tiene código completo o comando exacto.

**Type consistency:** Nombres y firmas verificados:
- `InvoicePaidEvent` / `InvoiceRefundAuthorizedEvent` / `InvoiceRefundRejectedEvent` / `AdvanceLegalizedEvent` — usados consistentemente en Tasks 1, 4, 5, 6, 8, 9, 10.
- `LinkedInvoiceLegalizer::legalizeFor(int, int): int` — definido en Task 2.1, usado en Task 6.1.
- `LegalizationInitializerSubscriber`, `RefundOutcomeSubscriber`, `LinkedInvoicesPromoterSubscriber` — usados consistentemente en Tasks 4, 5, 6 (creación), 4.2/5.2/6.2 (registro), Task 7 (atadura).
- Nombres de eventos Cake: `'Invoice.paid'`, `'Invoice.refundAuthorized'`, `'Invoice.refundRejected'`, `'AdvanceLegalization.legalized'` — consistentes entre publishers (Tasks 8/9/10) y `implementedEvents()` de subscribers (Tasks 4/5/6).
- `EventManagerInterface` (Cake interface) — registrado en Task 3, inyectado en publishers (Tasks 8.1, 9.1, 10.1).
- `ListenerFailedException` — definido en Task 1.5, usado en Tasks 4.1 y 5.1.

Plan listo para ejecución.

---

## Execution Handoff

Dos opciones para ejecutar este plan:

1. **Subagent-Driven (recomendado)** — Despacho un subagente fresco por task, review entre tasks, iteración rápida.
2. **Inline Execution** — Ejecuto las tasks en esta sesión usando executing-plans, batch con checkpoints para revisión.

¿Cuál prefieres?
