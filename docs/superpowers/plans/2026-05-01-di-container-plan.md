# DI Container Implementation Plan (Plan 3 — W3 + W5)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar el patrón `?? new ServiceClass()` de todos los servicios y la instanciación manual `new XService()` de todos los controllers, sustituyéndolo por inyección de dependencias real con el container nativo de CakePHP 5.

**Architecture:** Llenar `Application::services()` con bindings `addShared()` para cada clase bajo `src/Service/`. Servicios reescriben sus constructores con propiedades `readonly` promovidas y tipos no-nulables. Controllers reciben sus servicios por constructor (CakePHP 5 `ControllerFactory` los resuelve auto). Los ciclos `Pipeline ↔ Legalization` y `Notification ↔ EmailLog` se rompen con `Closure` factories envueltas en `LiteralArgument` de `league/container`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, `league/container` 4.x (vía `Cake\Core\ContainerInterface`).

**Spec:** [`docs/superpowers/specs/2026-05-01-di-container-design.md`](../specs/2026-05-01-di-container-design.md)

**Project policy:** Sin tests automatizados. Validación manual con `php bin/cake server` + navegador. Cada tarea termina con un smoke check concreto.

---

## Cosas que NO van al container (afinación tras releer servicios reales)

Antes de empezar, fijar estas exclusiones para no perder tiempo:

- **`CircuitBreaker`** — `NotificationService` y `WebhookService` lo construyen localmente con su propio config (`new CircuitBreaker('smtp', failureThreshold: 3, ...)`, `new CircuitBreaker('webhook', failureThreshold: 3, ...)`). Cada uno tiene parámetros distintos. **Sigue como está**: instanciado dentro del constructor del consumidor.
- **`GroupedInvoiceService`** — `PettyCashService::__construct()` lo construye con args posicionales (`documentType`, `fkField`, etc.). Sigue como está.
- **Tables (`InvoicesTable`, etc.)** — los servicios siguen pidiéndolas a `TableRegistry::getTableLocator()->get(...)`. Convención del proyecto.
- **`Strategy/InvoiceApprovalStrategy:44`** — hoy hace `new InvoicePipelineService($this->historyService)`. Lo arreglamos inyectando `InvoicePipelineService` directamente en el constructor de la strategy (no hay ciclo: Strategy → Pipeline; Pipeline no necesita Strategy).

---

## File Structure

**Modificar:**
- `src/Application.php` — método `services()` (hoy vacío, líneas 89–91).
- `src/Service/*.php` — todos los que tienen `?? new` en su constructor (~14 archivos).
- `src/Service/Adapter/CakeMailerAdapter.php`
- `src/Service/Strategy/InvoiceApprovalStrategy.php`
- `src/Service/Strategy/NoveltyApprovalStrategy.php`
- `src/Controller/AppController.php`
- `src/Controller/*.php` — todos los que tienen `new XService()` en `initialize()` o métodos (~18 archivos).
- `src/Controller/Trait/ExcelWizardTrait.php`

**Crear:** ninguno.
**Borrar:** ninguno.

---

## Convenciones de los pasos en este plan

Cada tarea termina con un commit. Steps típicos:

1. **Aplicar cambios de código** (Read + Edit/Write)
2. **Verificación estática** (`grep`, `composer cs-check`)
3. **Smoke check funcional** (`php bin/cake server` + curl o navegador)
4. **Commit**

Si un commit deja la app rota (no arranca, error 500 en `/login`), **rollback inmediato** (`git reset --hard HEAD~1`) y reintento del task.

---

## Task 1: Llenar `Application::services()` con todos los bindings (sin tocar constructores aún)

**Files:**
- Modify: `src/Application.php:89-91`

**Por qué primero:** Si la app sigue funcionando después de este commit, sabemos que el container construye y entrega los servicios correctamente. Como los servicios todavía aceptan `?Foo = null`, el `?? new` queda inerte (recibe instancias del container y nunca dispara el fallback). Es un cambio aditivo de bajo riesgo.

- [ ] **Step 1: Leer `src/Application.php` para confirmar imports actuales y línea exacta de `services()`.**

Comando: usar Read tool sobre `src/Application.php` para verificar que el método está vacío en líneas 89–91.

- [ ] **Step 2: Aplicar el bloque completo de bindings con sus imports.**

Modificar `src/Application.php`:

Añadir imports al bloque `use` superior (después de `use Cake\Core\ContainerInterface;`):

```php
use App\Service\AdvanceLegalizationService;
use App\Service\Adapter\CakeMailerAdapter;
use App\Service\ApprovalTokenService;
use App\Service\AuthorizationService;
use App\Service\Adapter\PhpSpreadsheetAdapter;
use App\Service\Dashboard\EmployeeStatisticsService;
use App\Service\Dashboard\InvoiceStatisticsService;
use App\Service\DashboardStatisticsService;
use App\Service\DianCrosscheckService;
use App\Service\EmailLogService;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeFilterService;
use App\Service\EmployeeHistoryService;
use App\Service\ExcelImportService;
use App\Service\ExcelMappingService;
use App\Service\ExcelService;
use App\Service\Interface\MailerInterface;
use App\Service\Interface\SpreadsheetReaderInterface;
use App\Service\InvoiceApprovalService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFieldAccessPolicy;
use App\Service\InvoiceFilterService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use App\Service\LeaveDocumentService;
use App\Service\LeaveSignatureService;
use App\Service\LiquidationDocPaymentService;
use App\Service\N8nService;
use App\Service\NotificationService;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;
use App\Service\NoveltySignatureService;
use App\Service\PaymentRegistryService;
use App\Service\PaymentSchedulingPipelineService;
use App\Service\PaymentSchedulingService;
use App\Service\PettyCashDocumentService;
use App\Service\PettyCashService;
use App\Service\SidebarCounterService;
use App\Service\Strategy\InvoiceApprovalStrategy;
use App\Service\Strategy\NoveltyApprovalStrategy;
use App\Service\StructuredLogger;
use App\Service\SystemSettingsService;
use App\Service\WebhookService;
use League\Container\Argument\LiteralArgument;
```

Reemplazar el método vacío:

```php
    public function services(ContainerInterface $container): void
    {
    }
```

por:

```php
    public function services(ContainerInterface $container): void
    {
        // === Infrastructure / Adapters ===
        $container->addShared(SystemSettingsService::class);
        $container->addShared(StructuredLogger::class);
        $container->addShared(MailerInterface::class, CakeMailerAdapter::class)
            ->addArgument(SystemSettingsService::class);
        $container->addShared(SpreadsheetReaderInterface::class, PhpSpreadsheetAdapter::class);

        // === Auth / Authorization ===
        $container->addShared(AuthorizationService::class);
        $container->addShared(ApprovalTokenService::class);

        // === Email log + notifications (cycle: closure factory in EmailLog) ===
        $container->addShared(EmailLogService::class)
            ->addArgument(new LiteralArgument(
                fn() => $container->get(NotificationService::class)
            ));
        $container->addShared(NotificationService::class)
            ->addArguments([
                SystemSettingsService::class,
                MailerInterface::class,
                EmailLogService::class,
            ]);

        // === Invoice domain (cycle: closure factory in AdvanceLegalization) ===
        $container->addShared(InvoiceHistoryService::class);
        $container->addShared(InvoiceFieldAccessPolicy::class);
        $container->addShared(InvoiceFilterService::class);
        $container->addShared(InvoiceDocumentService::class);
        $container->addShared(InvoicePaymentService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                AdvanceLegalizationService::class,
            ]);
        $container->addShared(AdvanceLegalizationService::class)
            ->addArgument(new LiteralArgument(
                fn() => $container->get(InvoicePipelineService::class)
            ));
        $container->addShared(InvoicePipelineService::class)
            ->addArguments([
                InvoiceHistoryService::class,
                InvoicePaymentService::class,
                InvoiceFieldAccessPolicy::class,
                AdvanceLegalizationService::class,
            ]);
        $container->addShared(InvoiceApprovalService::class)
            ->addArgument(NotificationService::class);

        // === Strategies ===
        $container->addShared(InvoiceApprovalStrategy::class)
            ->addArguments([
                InvoiceHistoryService::class,
                InvoicePipelineService::class,
            ]);
        $container->addShared(NoveltyApprovalStrategy::class)
            ->addArgument(NoveltyObservationService::class);

        // === Novelty domain ===
        $container->addShared(NoveltyHistoryService::class);
        $container->addShared(NoveltyObservationService::class);
        $container->addShared(NoveltyDocumentService::class);
        $container->addShared(NoveltySignatureService::class);
        $container->addShared(NoveltyPipelineService::class);
        $container->addShared(LeaveDocumentService::class);
        $container->addShared(LeaveSignatureService::class);
        $container->addShared(LiquidationDocPaymentService::class);

        // === Petty cash / payment scheduling / advances ===
        $container->addShared(PettyCashDocumentService::class);
        $container->addShared(PettyCashService::class);
        $container->addShared(PaymentSchedulingPipelineService::class);
        $container->addShared(PaymentSchedulingService::class)
            ->addArgument(InvoicePaymentService::class);
        $container->addShared(PaymentRegistryService::class);

        // === Integrations ===
        $container->addShared(WebhookService::class);
        $container->addShared(N8nService::class)
            ->addArguments([WebhookService::class, SystemSettingsService::class]);
        $container->addShared(DianCrosscheckService::class)
            ->addArgument(N8nService::class);

        // === Excel / import ===
        $container->addShared(ExcelService::class);
        $container->addShared(ExcelMappingService::class);
        $container->addShared(ExcelImportService::class)
            ->addArgument(ExcelMappingService::class);

        // === Employees ===
        $container->addShared(EmployeeFilterService::class);
        $container->addShared(EmployeeDocumentService::class);
        $container->addShared(EmployeeHistoryService::class);

        // === Dashboard ===
        $container->addShared(InvoiceStatisticsService::class);
        $container->addShared(EmployeeStatisticsService::class);
        $container->addShared(DashboardStatisticsService::class)
            ->addArguments([
                InvoiceStatisticsService::class,
                EmployeeStatisticsService::class,
            ]);
        $container->addShared(SidebarCounterService::class)
            ->addArguments([
                InvoicePipelineService::class,
                NoveltyPipelineService::class,
                PettyCashService::class,
            ]);
    }
```

- [ ] **Step 3: Verificar que la sintaxis compila.**

Comando: `php -l src/Application.php`
Esperado: `No syntax errors detected in src/Application.php`

- [ ] **Step 4: `composer cs-check` para detectar imports desordenados.**

Comando: `composer cs-check src/Application.php`
Si hay quejas de orden de imports, ejecutar `composer cs-fix src/Application.php`.

- [ ] **Step 5: Smoke check — la app arranca y responde 200 en `/login`.**

Comandos:
```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
curl -sI http://localhost:8765/login | head -1
# esperado: HTTP/1.1 200 OK
kill %1
```

Si responde 500: revisar `/tmp/cake.log` y `logs/error.log`. El error más probable es un import incorrecto o un binding mal escrito.

- [ ] **Step 6: Smoke check — la app sigue funcionando con login real.**

En el navegador o con `curl` autenticando vía cookie de sesión, abrir:
- `/login` → muestra formulario.
- Login con `admin` / `Admin2024*`.
- `/dashboard` → carga normal.
- `/invoices` → carga normal.

Si algo se rompe aquí significa que el container está construyendo servicios y rompiendo donde antes el `?? new` lo salvaba. Casi seguro: alguna dep cíclica que el closure factory aún no cubre. Revisar log y corregir antes de commit.

- [ ] **Step 7: Commit.**

```bash
git add src/Application.php
git commit -m "$(cat <<'EOF'
feat(plan-3): registrar grafo de servicios en Application::services()

Llena Application::services() con bindings addShared para todos los
servicios bajo src/Service/. Usa LiteralArgument con closures para
romper los ciclos:
- AdvanceLegalizationService ← Closure → InvoicePipelineService
- EmailLogService ← Closure → NotificationService

Los servicios siguen aceptando ?Foo = null, así que el patrón ?? new
queda inerte (recibe instancias del container en vez de fallback).
App funciona idéntico; este commit solo establece la base para
eliminar los ?? new en commits siguientes.

Refs: docs/audits/architecture-audit-roadmap.md Plan 3 (W3, W5)
EOF
)"
```

---

## Task 2: Migrar constructores — Infrastructure (`SystemSettings`, `CakeMailerAdapter`, `WebhookService`, `N8nService`, `DianCrosschecks`)

**Files:**
- Modify: `src/Service/SystemSettingsService.php`
- Modify: `src/Service/Adapter/CakeMailerAdapter.php`
- Modify: `src/Service/WebhookService.php`
- Modify: `src/Service/N8nService.php`
- Modify: `src/Service/DianCrosscheckService.php`

**Por qué este grupo:** son las hojas del grafo (no dependen de servicios cíclicos), y los consumidores que las usen ya las recibirán del container porque el binding ya existe (Task 1).

- [ ] **Step 1: `SystemSettingsService` — verificar si ya tiene constructor.**

Read `src/Service/SystemSettingsService.php`. Si su constructor está vacío o no existe, no requiere cambio. Saltar al siguiente sub-step.

- [ ] **Step 2: `CakeMailerAdapter` — promover `?SystemSettingsService` a `readonly` no-nulable.**

Read `src/Service/Adapter/CakeMailerAdapter.php`, localizar el constructor. Hoy es:

```php
public function __construct(?SystemSettingsService $settings = null)
{
    $this->settings = $settings ?? new SystemSettingsService();
}
```

Reemplazar por:

```php
public function __construct(
    private readonly SystemSettingsService $settings,
) {
}
```

Eliminar también la propiedad declarada arriba (`private SystemSettingsService $settings;`) — la promoción la define.

- [ ] **Step 3: `WebhookService` — promover constructor.**

Read `src/Service/WebhookService.php`. Si su constructor solo construye `CircuitBreaker` localmente y no recibe servicios del exterior, queda igual:

```php
public function __construct()
{
    $this->circuitBreaker = new CircuitBreaker('webhook', failureThreshold: 3, recoveryTimeoutSeconds: 120);
    // resto igual
}
```

`CircuitBreaker` se queda local porque tiene config posicional. No-op para este task.

- [ ] **Step 4: `N8nService` — promover.**

Hoy:
```php
public function __construct(
    ?WebhookService $webhookService = null,
    ?SystemSettingsService $settingsService = null,
) {
    $this->webhookService = $webhookService ?? new WebhookService();
    $this->settingsService = $settingsService ?? new SystemSettingsService();
}
```

Después:
```php
public function __construct(
    private readonly WebhookService $webhookService,
    private readonly SystemSettingsService $settingsService,
) {
}
```

Eliminar las propiedades declaradas en la cabecera de la clase.

- [ ] **Step 5: `DianCrosscheckService` — promover.**

Hoy:
```php
public function __construct(?N8nService $n8nService = null)
{
    $this->n8nService = $n8nService ?? new N8nService();
}
```

Después:
```php
public function __construct(
    private readonly N8nService $n8nService,
) {
}
```

Eliminar la propiedad declarada.

- [ ] **Step 6: Verificación estática.**

```bash
grep -n "?? new " src/Service/SystemSettingsService.php src/Service/Adapter/CakeMailerAdapter.php src/Service/WebhookService.php src/Service/N8nService.php src/Service/DianCrosscheckService.php
# esperado: 0 líneas
composer cs-check src/Service/Adapter/CakeMailerAdapter.php src/Service/N8nService.php src/Service/DianCrosscheckService.php
# si hay quejas: composer cs-fix
```

- [ ] **Step 7: Smoke check — la app arranca.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
curl -sI http://localhost:8765/login | head -1   # 200
kill %1
```

- [ ] **Step 8: Smoke check — flujo que ejercita N8n.**

Si hay un endpoint en SGI que dispara webhooks (revisar logs cuando se aprueba una factura), confirmar que sigue funcionando. Si no, basta con que la página `/system-settings` cargue (admin) — `SystemSettingsController` instancia `NotificationService` que indirectamente depende de `MailerInterface → CakeMailerAdapter → SystemSettingsService`. Si el container falla en construir uno, falla en construir todos.

Login admin → navegar a `/system-settings` → la página carga sin error 500.

- [ ] **Step 9: Commit.**

```bash
git add src/Service/SystemSettingsService.php src/Service/Adapter/CakeMailerAdapter.php src/Service/WebhookService.php src/Service/N8nService.php src/Service/DianCrosscheckService.php
git commit -m "refactor(plan-3): infrastructure services usan readonly + DI estricta

CakeMailerAdapter, N8nService, DianCrosscheckService: constructores
promovidos con propiedades readonly y tipos no-nulables. Eliminado
el patron ?? new. WebhookService y SystemSettingsService no requieren
cambios estructurales.

Refs: W3"
```

---

## Task 3: Migrar `NotificationService` y `EmailLogService` (cycle pair)

**Files:**
- Modify: `src/Service/NotificationService.php`
- Modify: `src/Service/EmailLogService.php`

**Por qué juntos:** son los dos lados del ciclo Notification ↔ EmailLog. Si solo migras uno, el otro lado de los `??` deja de hacer match con el binding y rompe.

- [ ] **Step 1: `NotificationService` — promover constructor, mantener `CircuitBreaker` local.**

Hoy (`src/Service/NotificationService.php:14-30`):

```php
class NotificationService
{
    private SystemSettingsService $settings;
    private MailerInterface $mailer;
    private CircuitBreaker $smtpCircuitBreaker;
    private EmailLogService $emailLogService;

    public function __construct(
        ?SystemSettingsService $settings = null,
        ?MailerInterface $mailer = null,
        ?EmailLogService $emailLogService = null,
    ) {
        $this->settings = $settings ?? new SystemSettingsService();
        $this->mailer = $mailer ?? new CakeMailerAdapter($this->settings);
        $this->smtpCircuitBreaker = new CircuitBreaker('smtp', failureThreshold: 3, recoveryTimeoutSeconds: 300);
        $this->emailLogService = $emailLogService ?? new EmailLogService();
    }
```

Después:

```php
class NotificationService
{
    private CircuitBreaker $smtpCircuitBreaker;

    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly MailerInterface $mailer,
        private readonly EmailLogService $emailLogService,
    ) {
        $this->smtpCircuitBreaker = new CircuitBreaker('smtp', failureThreshold: 3, recoveryTimeoutSeconds: 300);
    }
```

Notas:
- Se mantiene `private CircuitBreaker $smtpCircuitBreaker;` declarado fuera porque se construye en el cuerpo del constructor (no se promueve).
- Se eliminan las tres propiedades viejas (`$settings`, `$mailer`, `$emailLogService`) porque ahora son promovidas readonly.
- El import `use App\Service\Adapter\CakeMailerAdapter;` se puede dejar o quitar — ya no se usa dentro de la clase. Recomiendo quitarlo para mantener limpio.

- [ ] **Step 2: `EmailLogService` — añadir Closure factory para `NotificationService`.**

Hoy (`src/Service/EmailLogService.php:13-24, 158-225`):

```php
class EmailLogService
{
    private EmailLogsTable $emailLogsTable;
    private StructuredLogger $logger;

    public function __construct()
    {
        /** @var \App\Model\Table\EmailLogsTable $table */
        $table = TableRegistry::getTableLocator()->get('EmailLogs');
        $this->emailLogsTable = $table;
        $this->logger = new StructuredLogger('EmailLog');
    }
    // ...
    public function retry(int $id, ?NotificationService $notificationService = null): ServiceResult
    {
        // ...
        $notificationService = $notificationService ?? new NotificationService();
        // ...
    }

    public function retryAllFailed(?NotificationService $notificationService = null): array
    {
        // ...
        $notificationService = $notificationService ?? new NotificationService();
        // ...
    }
}
```

Después:

```php
use Closure;

class EmailLogService
{
    private EmailLogsTable $emailLogsTable;
    private StructuredLogger $logger;

    public function __construct(
        private readonly Closure $notificationFactory,
    ) {
        /** @var \App\Model\Table\EmailLogsTable $table */
        $table = TableRegistry::getTableLocator()->get('EmailLogs');
        $this->emailLogsTable = $table;
        $this->logger = new StructuredLogger('EmailLog');
    }
    // ...
    public function retry(int $id): ServiceResult
    {
        // ...
        $notificationService = ($this->notificationFactory)();
        // ...
    }

    public function retryAllFailed(): array
    {
        // ...
        $notificationService = ($this->notificationFactory)();
        // ...
    }
}
```

Cambios concretos:
1. Añadir `use Closure;` al bloque de imports.
2. Añadir `private readonly Closure $notificationFactory,` como primer parámetro del constructor.
3. **Eliminar el parámetro opcional `?NotificationService $notificationService = null` de los métodos `retry()` y `retryAllFailed()`** (líneas 158 y 205 actuales).
4. Reemplazar `$notificationService = $notificationService ?? new NotificationService();` por `$notificationService = ($this->notificationFactory)();`.
5. Dentro de `retryAllFailed()` la llamada `$this->retry((int)$log->id, $notificationService);` (línea 219) ahora es `$this->retry((int)$log->id);` porque `retry()` ya no acepta el parámetro.

- [ ] **Step 3: Confirmar que ningún caller pasa `NotificationService` a `retry`/`retryAllFailed`.**

```bash
grep -rn "->retry(.*,\|->retryAllFailed(.*[A-Z]" src/Controller/ src/Service/
# esperado: 0 líneas — nadie pasa el segundo argumento
```

Si hay resultados (ej. `EmailLogsController` pasando un `NotificationService`), simplificar la llamada quitando el segundo argumento.

- [ ] **Step 4: Verificación estática.**

```bash
grep -n "?? new " src/Service/NotificationService.php src/Service/EmailLogService.php
# esperado: 0 líneas
php -l src/Service/NotificationService.php && php -l src/Service/EmailLogService.php
# esperado: No syntax errors
composer cs-check src/Service/NotificationService.php src/Service/EmailLogService.php
```

- [ ] **Step 5: Smoke check — flujo crítico de email.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
```

En el navegador, login como admin:
1. `/system-settings` → "Probar SMTP" → debe enviar correo (valida que `NotificationService` se construye con el `MailerInterface` real).
2. `/email-logs` → debe cargar la lista (valida que `EmailLogService` se construye con el closure correctamente).
3. Tomar un log con status `failed` (si no hay, crear uno fallando un envío) → click en "Reintentar" → debe ejecutar `EmailLogService::retry()` → invocar la closure → obtener `NotificationService` del container → enviar.

Si `retry()` falla con "Argument 1 is not a Closure" → revisar el binding en `Application::services()`.

`kill %1`

- [ ] **Step 6: Commit.**

```bash
git add src/Service/NotificationService.php src/Service/EmailLogService.php
git commit -m "refactor(plan-3): NotificationService y EmailLogService usan DI

NotificationService promueve sus 3 deps a readonly. CircuitBreaker
se mantiene local (config posicional).

EmailLogService rompe el ciclo recibiendo un Closure que el container
resuelve perezosamente a NotificationService. Los métodos retry() y
retryAllFailed() pierden su parámetro opcional ?NotificationService;
ahora todos los callers reciben el mismo NotificationService cacheado
por el container.

Refs: W3, ciclo Notification <-> EmailLog"
```

---

## Task 4: Migrar Invoice domain (sin Pipeline ni Legalization)

**Files:**
- Modify: `src/Service/InvoiceHistoryService.php`
- Modify: `src/Service/InvoicePaymentService.php`
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`
- Modify: `src/Service/InvoiceFilterService.php`
- Modify: `src/Service/InvoiceDocumentService.php`
- Modify: `src/Service/InvoiceApprovalService.php`
- Modify: `src/Service/GroupedInvoiceService.php`

- [ ] **Step 1: Servicios sin deps externas — verificar que su constructor está vacío o solo accede a TableRegistry.**

`InvoiceHistoryService`, `InvoiceFieldAccessPolicy`, `InvoiceFilterService`, `InvoiceDocumentService`. Read cada uno y confirmar que no tienen `?? new`. Si su constructor es vacío o solo usa `TableRegistry`, no requiere cambio.

- [ ] **Step 2: `InvoicePaymentService` — promover.**

Hoy (`src/Service/InvoicePaymentService.php` aprox. líneas 18–25):

```php
public function __construct(
    ?InvoiceHistoryService $historyService = null,
    ?AdvanceLegalizationService $advanceLegalizationService = null,
) {
    $this->historyService = $historyService ?? new InvoiceHistoryService();
    $this->advanceLegalizationService = $advanceLegalizationService ?? new AdvanceLegalizationService();
}
```

Después:

```php
public function __construct(
    private readonly InvoiceHistoryService $historyService,
    private readonly AdvanceLegalizationService $advanceLegalizationService,
) {
}
```

Eliminar las propiedades declaradas en la cabecera de la clase.

- [ ] **Step 3: `InvoiceApprovalService` — promover.**

Hoy:
```php
public function __construct(
    ?NotificationService $notificationService = null,
) {
    $this->invoiceApprovalsTable = TableRegistry::getTableLocator()->get('InvoiceApprovals');
    $this->notificationService = $notificationService ?? new NotificationService();
}
```

Después:
```php
private $invoiceApprovalsTable;

public function __construct(
    private readonly NotificationService $notificationService,
) {
    $this->invoiceApprovalsTable = TableRegistry::getTableLocator()->get('InvoiceApprovals');
}
```

Mantener `$invoiceApprovalsTable` como propiedad normal porque viene de `TableRegistry`.

- [ ] **Step 4: `GroupedInvoiceService` — promover (mantiene los args posicionales).**

Hoy:
```php
public function __construct(
    string $documentType,
    string $fkField,
    string $recordTableName,
    string $fkLabel,
    ?HistoryServiceInterface $historyService = null,
) {
    $this->documentType = $documentType;
    $this->fkField = $fkField;
    $this->recordTableName = $recordTableName;
    $this->fkLabel = $fkLabel;
    $this->historyService = $historyService ?? new InvoiceHistoryService();
}
```

Después:
```php
public function __construct(
    private readonly string $documentType,
    private readonly string $fkField,
    private readonly string $recordTableName,
    private readonly string $fkLabel,
    private readonly HistoryServiceInterface $historyService,
) {
}
```

**Importante:** `GroupedInvoiceService` NO está registrada en el container (tiene strings posicionales). Su único caller hoy es `PettyCashService::__construct():45` que hace:

```php
$this->grouped = new GroupedInvoiceService(
    documentType: ...,
    fkField: ...,
    recordTableName: ...,
    fkLabel: ...,
);
```

Tras este cambio, ese caller debe pasar también el `HistoryServiceInterface` explícitamente. Localiza `PettyCashService.php:45` y:

```php
// Antes
$this->grouped = new GroupedInvoiceService(
    documentType: ...,
    fkField: ...,
    recordTableName: ...,
    fkLabel: ...,
);

// Después
$this->grouped = new GroupedInvoiceService(
    documentType: ...,
    fkField: ...,
    recordTableName: ...,
    fkLabel: ...,
    historyService: $historyService,  // recibido por ctor de PettyCashService
);
```

Esto fuerza un cambio adicional en `PettyCashService::__construct()`: aceptar `HistoryServiceInterface` (o `InvoiceHistoryService`) como dep. Por consistencia con el resto del refactor, declararla en `Application::services()` registrando esta dep:

```php
$container->addShared(PettyCashService::class)
    ->addArgument(InvoiceHistoryService::class);
```

(añadir esta línea al binding existente en `Application.php` — actualmente `PettyCashService` está registrado sin args).

- [ ] **Step 5: Verificación estática.**

```bash
grep -n "?? new " src/Service/InvoicePaymentService.php src/Service/InvoiceApprovalService.php src/Service/GroupedInvoiceService.php src/Service/PettyCashService.php
# esperado: 0 líneas
composer cs-check src/Service/
# si quejas: composer cs-fix
```

- [ ] **Step 6: Smoke check — flujo de pago de facturas.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
```

Login admin:
1. `/invoices` → carga la lista.
2. Tomar una factura en `tesoreria` → registrar un pago → debe ejecutar `InvoicePaymentService::registerPayment()` (que a su vez usa `InvoiceHistoryService` y `AdvanceLegalizationService`).
3. Verificar que la factura avanza a `autorizacion_pago`.

`kill %1`

- [ ] **Step 7: Commit.**

```bash
git add src/Service/InvoicePaymentService.php src/Service/InvoiceApprovalService.php src/Service/GroupedInvoiceService.php src/Service/PettyCashService.php src/Application.php
git commit -m "refactor(plan-3): invoice domain services usan DI estricta

InvoicePaymentService, InvoiceApprovalService, GroupedInvoiceService:
constructores promovidos con readonly y tipos no-nulables.
InvoiceHistoryService, InvoiceFieldAccessPolicy, InvoiceFilterService,
InvoiceDocumentService no requieren cambios (no tienen ?? new).

PettyCashService recibe HistoryServiceInterface vía container y la
pasa explícitamente al GroupedInvoiceService que construye localmente
(args posicionales lo dejan fuera del container).

Refs: W3"
```

---

## Task 5: Migrar Strategies (`InvoiceApprovalStrategy`, `NoveltyApprovalStrategy`)

**Files:**
- Modify: `src/Service/Strategy/InvoiceApprovalStrategy.php`
- Modify: `src/Service/Strategy/NoveltyApprovalStrategy.php`

- [ ] **Step 1: `InvoiceApprovalStrategy` — añadir `InvoicePipelineService` como dep.**

Hoy (`src/Service/Strategy/InvoiceApprovalStrategy.php`):

```php
class InvoiceApprovalStrategy implements ApprovalStrategyInterface
{
    private InvoiceHistoryService $historyService;

    public function __construct(
        ?InvoiceHistoryService $historyService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
    }

    public function apply(...): bool {
        // ...
        if ($action === 'approve') {
            $pipeline = new InvoicePipelineService($this->historyService);
            $result = $pipeline->saveAndAdvance(...);
            // ...
        }
    }
}
```

Después:

```php
class InvoiceApprovalStrategy implements ApprovalStrategyInterface
{
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
        private readonly InvoicePipelineService $pipeline,
    ) {
    }

    public function apply(...): bool {
        // ...
        if ($action === 'approve') {
            $result = $this->pipeline->saveAndAdvance(...);
            // ...
        }
    }
}
```

Cambios:
1. Constructor promovido con dos deps `readonly`.
2. Línea 24 actual (`$this->historyService = ...`) eliminada.
3. Línea 44 actual (`$pipeline = new InvoicePipelineService($this->historyService);`) eliminada.
4. Línea 45 (`$result = $pipeline->saveAndAdvance(...);`) reemplazada por `$result = $this->pipeline->saveAndAdvance(...);`.
5. Eliminar la propiedad declarada `private InvoiceHistoryService $historyService;` (la promoción la define).

- [ ] **Step 2: `NoveltyApprovalStrategy` — promover.**

Hoy:
```php
public function __construct(?NoveltyObservationService $observationService = null)
{
    $this->observationService = $observationService ?? new NoveltyObservationService();
}
```

Después:
```php
public function __construct(
    private readonly NoveltyObservationService $observationService,
) {
}
```

Eliminar la propiedad declarada.

- [ ] **Step 3: Verificación estática.**

```bash
grep -n "?? new \|new InvoicePipelineService" src/Service/Strategy/
# esperado: 0 líneas
composer cs-check src/Service/Strategy/
```

- [ ] **Step 4: Smoke check — aprobación de factura.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
```

Login admin:
1. Tomar una factura en `aprobacion`.
2. Aprobarla (botón en `/invoices/edit`) → debe disparar `InvoiceApprovalStrategy::apply('approve', ...)` → ese ahora invoca `$this->pipeline->saveAndAdvance(...)` con la instancia inyectada.
3. La factura debe pasar a `contabilidad`.

`kill %1`

- [ ] **Step 5: Commit.**

```bash
git add src/Service/Strategy/InvoiceApprovalStrategy.php src/Service/Strategy/NoveltyApprovalStrategy.php
git commit -m "refactor(plan-3): strategies usan DI

InvoiceApprovalStrategy ahora recibe InvoicePipelineService inyectado
en lugar de hacer 'new InvoicePipelineService(\$this->historyService)'
dentro de apply(). NoveltyApprovalStrategy: constructor promovido.

Refs: W3"
```

---

## Task 6: Migrar Novelty domain + petty cash + payment scheduling

**Files:**
- Modify: `src/Service/NoveltyHistoryService.php`
- Modify: `src/Service/NoveltyObservationService.php`
- Modify: `src/Service/NoveltyDocumentService.php`
- Modify: `src/Service/NoveltySignatureService.php`
- Modify: `src/Service/NoveltyPipelineService.php`
- Modify: `src/Service/LeaveDocumentService.php`
- Modify: `src/Service/LeaveSignatureService.php`
- Modify: `src/Service/LiquidationDocPaymentService.php`
- Modify: `src/Service/PettyCashDocumentService.php`
- Modify: `src/Service/PaymentSchedulingService.php`
- Modify: `src/Service/PaymentSchedulingPipelineService.php`
- Modify: `src/Service/PaymentRegistryService.php`

- [ ] **Step 1: Verificar cada uno con `grep "?? new"`.**

```bash
grep -n "?? new " src/Service/Novelty*.php src/Service/Leave*.php src/Service/LiquidationDocPaymentService.php src/Service/PettyCash*.php src/Service/PaymentScheduling*.php src/Service/PaymentRegistryService.php
```

Solo aplicar la transformación readonly + sin `??` a los archivos que aparezcan en la lista. La mayoría de estos no tendrán resultados — sus constructores son vacíos o solo cargan tablas.

- [ ] **Step 2: `PaymentSchedulingService` — promover.**

Hoy:
```php
public function __construct(?InvoicePaymentService $paymentService = null)
{
    $this->paymentService = $paymentService ?? new InvoicePaymentService();
}
```

Después:
```php
public function __construct(
    private readonly InvoicePaymentService $paymentService,
) {
}
```

Eliminar la propiedad declarada.

- [ ] **Step 3: Aplicar la misma transformación a cualquier otro servicio del listado que tenga `?? new`.**

Patrón siempre idéntico: parámetros nullable → promovidos a `readonly`, sin fallback.

- [ ] **Step 4: Verificación estática.**

```bash
grep -n "?? new " src/Service/Novelty*.php src/Service/Leave*.php src/Service/LiquidationDocPaymentService.php src/Service/PettyCash*.php src/Service/PaymentScheduling*.php
# esperado: 0 líneas
composer cs-check src/Service/
```

- [ ] **Step 5: Smoke check — flujos de novedades y caja menor.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
```

Login admin:
1. `/employee-novelties` → carga.
2. Crear una novedad → guardar → debe usar `NoveltyPipelineService`.
3. `/petty-cash-records` → carga.
4. `/payment-schedulings` → carga.

`kill %1`

- [ ] **Step 6: Commit.**

```bash
git add src/Service/Novelty*.php src/Service/Leave*.php src/Service/LiquidationDocPaymentService.php src/Service/PettyCash*.php src/Service/PaymentScheduling*.php src/Service/PaymentRegistryService.php
git commit -m "refactor(plan-3): novelty + petty cash + payment scheduling usan DI

PaymentSchedulingService promueve InvoicePaymentService a readonly.
Otros servicios del bloque no requieren cambios (sin ?? new).

Refs: W3"
```

---

## Task 7: Migrar Excel + Dashboard + Sidebar

**Files:**
- Modify: `src/Service/ExcelService.php`
- Modify: `src/Service/ExcelMappingService.php`
- Modify: `src/Service/ExcelImportService.php`
- Modify: `src/Service/Adapter/PhpSpreadsheetAdapter.php`
- Modify: `src/Service/DashboardStatisticsService.php`
- Modify: `src/Service/Dashboard/InvoiceStatisticsService.php`
- Modify: `src/Service/Dashboard/EmployeeStatisticsService.php`
- Modify: `src/Service/SidebarCounterService.php`
- Modify: `src/Service/EmployeeFilterService.php`
- Modify: `src/Service/EmployeeDocumentService.php`
- Modify: `src/Service/EmployeeHistoryService.php`

- [ ] **Step 1: `ExcelImportService` — promover.**

Hoy:
```php
public function __construct(?ExcelMappingService $mappingService = null)
{
    $this->mappingService = $mappingService ?? new ExcelMappingService();
}
```

Después:
```php
public function __construct(
    private readonly ExcelMappingService $mappingService,
) {
}
```

- [ ] **Step 2: `DashboardStatisticsService` — promover.**

Hoy:
```php
public function __construct(
    ?InvoiceStatisticsService $invoiceStats = null,
    ?EmployeeStatisticsService $employeeStats = null,
) {
    $this->invoiceStats = $invoiceStats ?? new InvoiceStatisticsService();
    $this->employeeStats = $employeeStats ?? new EmployeeStatisticsService();
}
```

Después:
```php
public function __construct(
    private readonly InvoiceStatisticsService $invoiceStats,
    private readonly EmployeeStatisticsService $employeeStats,
) {
}
```

- [ ] **Step 3: `SidebarCounterService` — promover.**

Hoy:
```php
public function __construct(
    ?InvoicePipelineService $invoicePipeline = null,
    ?NoveltyPipelineService $noveltyPipeline = null,
    ?PettyCashService $pettyCashService = null,
) {
    $this->invoicePipeline = $invoicePipeline ?? new InvoicePipelineService();
    $this->noveltyPipeline = $noveltyPipeline ?? new NoveltyPipelineService();
    $this->pettyCashService = $pettyCashService ?? new PettyCashService();
}
```

Después:
```php
public function __construct(
    private readonly InvoicePipelineService $invoicePipeline,
    private readonly NoveltyPipelineService $noveltyPipeline,
    private readonly PettyCashService $pettyCashService,
) {
}
```

- [ ] **Step 4: Otros archivos del bloque — verificar `?? new` y promover si aplica.**

```bash
grep -n "?? new " src/Service/Excel*.php src/Service/Adapter/PhpSpreadsheetAdapter.php src/Service/Dashboard/ src/Service/Employee*.php
```

Aplicar transformación readonly a cualquier coincidencia.

- [ ] **Step 5: Verificación estática.**

```bash
grep -n "?? new " src/Service/Excel*.php src/Service/Dashboard*.php src/Service/Sidebar*.php src/Service/Dashboard/ src/Service/Employee*.php src/Service/Adapter/
# esperado: 0 líneas
composer cs-check src/Service/
```

- [ ] **Step 6: Smoke check — dashboard y sidebar.**

Login admin → `/dashboard` debe cargar (las estadísticas usan `DashboardStatisticsService`). Sidebar muestra contadores (usa `SidebarCounterService`). Cargar también `/invoices` para verificar import Excel desde el wizard (todavía con `new` directo en el trait — se arregla en Task 14).

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Excel*.php src/Service/Adapter/PhpSpreadsheetAdapter.php src/Service/Dashboard*.php src/Service/SidebarCounterService.php src/Service/Dashboard/ src/Service/Employee*.php
git commit -m "refactor(plan-3): excel + dashboard + sidebar usan DI

DashboardStatisticsService, SidebarCounterService, ExcelImportService
promueven sus deps a readonly. Sub-servicios del bloque sin cambios.

Refs: W3"
```

---

## Task 8: Cerrar el ciclo Pipeline ↔ Legalization

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `src/Service/AdvanceLegalizationService.php`

**Por qué se hace último entre los servicios:** ambos lados se reescriben en el mismo commit para que el container pueda construirlos desde cero sin recursión. Si se hace en commits separados, queda un estado intermedio donde uno espera tipos no-nulables y el otro aún hace `?? new` — el container falla.

- [ ] **Step 1: `InvoicePipelineService` — promover sus 4 deps.**

Hoy (`src/Service/InvoicePipelineService.php:13-30`):

```php
class InvoicePipelineService
{
    private HistoryServiceInterface $historyService;
    private InvoicePaymentService $paymentService;
    private InvoiceFieldAccessPolicy $fieldPolicy;
    private AdvanceLegalizationService $advanceLegalizationService;

    public function __construct(
        ?HistoryServiceInterface $historyService = null,
        ?InvoicePaymentService $paymentService = null,
        ?InvoiceFieldAccessPolicy $fieldPolicy = null,
        ?AdvanceLegalizationService $advanceLegalizationService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->paymentService = $paymentService ?? new InvoicePaymentService();
        $this->fieldPolicy = $fieldPolicy ?? new InvoiceFieldAccessPolicy();
        $this->advanceLegalizationService = $advanceLegalizationService ?? new AdvanceLegalizationService();
    }
```

Después:

```php
class InvoicePipelineService
{
    public function __construct(
        private readonly HistoryServiceInterface $historyService,
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
        private readonly AdvanceLegalizationService $advanceLegalizationService,
    ) {
    }
```

Eliminar las 4 propiedades declaradas en cabecera de clase (líneas 15–18 actuales).

- [ ] **Step 2: `AdvanceLegalizationService` — sustituir el lazy por un `Closure` factory.**

Hoy (`src/Service/AdvanceLegalizationService.php:18-28`):

```php
class AdvanceLegalizationService
{
    private ?InvoicePipelineService $pipelineService;

    public function __construct(?InvoicePipelineService $pipelineService = null)
    {
        $this->pipelineService = $pipelineService;
    }

    private function _getPipelineService(): InvoicePipelineService
    {
        return $this->pipelineService ??= new InvoicePipelineService();
    }
```

Después:

```php
use Closure;

class AdvanceLegalizationService
{
    public function __construct(
        private readonly Closure $pipelineFactory,
    ) {
    }

    private function _getPipelineService(): InvoicePipelineService
    {
        return ($this->pipelineFactory)();
    }
```

Cambios concretos:
1. Añadir `use Closure;` al bloque de imports.
2. Eliminar la propiedad `private ?InvoicePipelineService $pipelineService;` (línea 18).
3. Reemplazar el constructor para promover `Closure $pipelineFactory`.
4. `_getPipelineService()` invoca la closure en lugar del lazy `??=`.

El resto del archivo (incluyendo línea 512 que llama `$this->_getPipelineService()->legalizeLinkedInvoices(...)`) no cambia.

- [ ] **Step 3: Verificación estática.**

```bash
grep -n "?? new " src/Service/InvoicePipelineService.php src/Service/AdvanceLegalizationService.php
# esperado: 0 líneas
grep -rn "?? new " src/Service/
# esperado: 0 líneas EN TODO src/Service/. Si aparece algo, alguna tarea anterior dejó un residuo.
php -l src/Service/InvoicePipelineService.php && php -l src/Service/AdvanceLegalizationService.php
composer cs-check src/Service/InvoicePipelineService.php src/Service/AdvanceLegalizationService.php
```

- [ ] **Step 4: Smoke check — el ciclo en runtime (test crítico).**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
```

Login admin. **Flujo crítico que ejercita el ciclo:**

1. Crear un anticipo (`/advances/add`) → debe construir `InvoicePipelineService` (Pipeline necesita Legalization, Legalization recibe la closure sin invocarla).
2. Aprobar y avanzar el anticipo hasta `pagada`.
3. **Legalizar el anticipo** (`/advances/{id}/legalization` o flujo equivalente) → esto dispara `AdvanceLegalizationService::initialize` y `_setStatus`, que invoca la closure → el container devuelve el `InvoicePipelineService` cacheado → llama `legalizeLinkedInvoices`.
4. Verificar que la factura asociada al anticipo termina en estado `legalizada`.

Si en el paso 3 hay error 500 con "stack overflow" o "circular reference", revisar `Application::services()` — puede estar invocando la closure eagermente en lugar de envolver en `LiteralArgument`.

`kill %1`

- [ ] **Step 5: Commit.**

```bash
git add src/Service/InvoicePipelineService.php src/Service/AdvanceLegalizationService.php
git commit -m "refactor(plan-3): cerrar ciclo Pipeline <-> Legalization con Closure factory

InvoicePipelineService: 4 deps promovidas a readonly, sin ??.
AdvanceLegalizationService: recibe Closure pipelineFactory en lugar
de ?InvoicePipelineService. _getPipelineService() invoca la closure;
el container se la entrega gracias al LiteralArgument registrado en
Application::services(). Plan 5 borrara la closure cuando los Domain
Events sustituyan la llamada cruzada.

Resultado: grep -r '?? new ' src/Service/ -> 0 lineas.

Refs: W3 (criterio de exito), C6 ciclo (parcial)"
```

---

## Task 9: Migrar `AppController` (la base)

**Files:**
- Modify: `src/Controller/AppController.php`

**Por qué primero entre los controllers:** todos los controllers concretos lo extienden. Si el constructor de `AppController` cambia, sus hijos heredan la firma. Lo migramos solo, en commit propio, para detectar problemas de propagación temprano.

- [ ] **Step 1: Añadir constructor a `AppController`.**

Read `src/Controller/AppController.php`. Hoy NO tiene constructor — usa `initialize()` con `loadComponent`.

Añadir constructor (entre los imports y `initialize()`):

```php
use App\Service\AuthorizationService;
use App\Service\SidebarCounterService;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

class AppController extends Controller
{
    public function __construct(
        protected readonly AuthorizationService $authService,
        protected readonly SidebarCounterService $counterService,
        ?ServerRequest $request = null,
        ?Response $response = null,
        ?string $name = null,
        ?EventManagerInterface $eventManager = null,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($request, $response, $name, $eventManager, $components);
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
    }
```

**Notas:**
- Las propiedades son `protected` (no `private`) para que los controllers hijos puedan acceder si lo necesitan.
- Importes: `EventManagerInterface` (no `EventManager`), `ComponentRegistry`, `ServerRequest`, `Response`.

- [ ] **Step 2: Reemplazar `new AuthorizationService()` por `$this->authService` en `_setUserPermissions` y `_checkPermission`.**

`src/Controller/AppController.php:110` (`_setUserPermissions`):

```php
// Antes
$authService = new AuthorizationService();
$this->set('userPermissions', $authService->getPermissionsForRoleAsMatrix((int)$user->role_id));

// Después
$this->set('userPermissions', $this->authService->getPermissionsForRoleAsMatrix((int)$user->role_id));
```

`src/Controller/AppController.php:196` (`_checkPermission`):

```php
// Antes
$authService = new AuthorizationService();
return $authService->isAllowed((int)$user->role_id, $roleName, $module, $action);

// Después
return $this->authService->isAllowed((int)$user->role_id, $roleName, $module, $action);
```

- [ ] **Step 3: Reemplazar `new SidebarCounterService()` por `$this->counterService` en `_setSidebarCounters`.**

`src/Controller/AppController.php:164`:

```php
// Antes
$counterService = new SidebarCounterService();
$counters = $counterService->getCounters($roleName);

// Después
$counters = $this->counterService->getCounters($roleName);
```

- [ ] **Step 4: Verificación estática.**

```bash
grep -n "new AuthorizationService\|new SidebarCounterService" src/Controller/AppController.php
# esperado: 0 líneas
php -l src/Controller/AppController.php
composer cs-check src/Controller/AppController.php
```

- [ ] **Step 5: Smoke check — la app arranca y `/login` responde 200.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
curl -sI http://localhost:8765/login | head -1   # 200
```

**Importante:** este es el cambio que más probablemente rompa la app si algún controller hijo no propagó bien la firma. Si `/login` da 500, leer `/tmp/cake.log` y `logs/error.log` — probablemente `UsersController` (o `PagesController` si redirige a login) intenta extender `AppController` con un `initialize()` o constructor incompatible.

Si rompe: rollback (`git checkout src/Controller/AppController.php`) y revisar qué controller usa parent::__construct sin extender la firma. Lo más probable es que algún controller deba migrarse antes que AppController. En CakePHP 5 los controllers solo necesitan constructor si se inyectan deps; si solo definen `initialize()`, heredan el constructor del padre y todo va. Así que si rompe, es porque algún hijo definió un constructor propio incompatible — buscarlo y ajustarlo.

```bash
grep -rn "public function __construct" src/Controller/
```

Login admin → cargar `/dashboard` → debe funcionar normal. La caché de `AuthorizationService` ahora es real.

`kill %1`

- [ ] **Step 6: Commit.**

```bash
git add src/Controller/AppController.php
git commit -m "refactor(plan-3): AppController inyecta Auth y SidebarCounter

Reemplaza 'new AuthorizationService()' (3 sitios) y
'new SidebarCounterService()' (1 sitio) por inyeccion via constructor.
Los controllers hijos heredan automaticamente la firma extendida.

Efecto colateral: AuthorizationService es ahora singleton por request,
asi que su cache interna acumula entre llamadas (cierra W5).

Refs: W3, W5"
```

---

## Task 10: Migrar controllers — bloque 1 (facturas y anticipos)

**Files:**
- Modify: `src/Controller/InvoicesController.php`
- Modify: `src/Controller/AdvancesController.php`
- Modify: `src/Controller/InvoicePaymentsController.php`
- Modify: `src/Controller/ExternalApprovalsController.php`

- [ ] **Step 1: `InvoicesController` — añadir constructor con sus 5 deps.**

Hoy (`src/Controller/InvoicesController.php:24-40`):

```php
class InvoicesController extends AppController
{
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private InvoicePipelineService $pipeline;
    private InvoiceFilterService $filterService;
    private InvoiceDocumentService $documentService;
    private InvoiceApprovalService $approvalService;
    private InvoicePaymentService $paymentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pipeline = new InvoicePipelineService();
        $this->filterService = new InvoiceFilterService();
        $this->documentService = new InvoiceDocumentService();
        $this->approvalService = new InvoiceApprovalService();
        $this->paymentService = new InvoicePaymentService();
    }
```

Después:

```php
use App\Service\AuthorizationService;
use App\Service\InvoiceApprovalService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFilterService;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use App\Service\SidebarCounterService;
use Cake\Controller\ComponentRegistry;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

class InvoicesController extends AppController
{
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function __construct(
        private readonly InvoicePipelineService $pipeline,
        private readonly InvoiceFilterService $filterService,
        private readonly InvoiceDocumentService $documentService,
        private readonly InvoiceApprovalService $approvalService,
        private readonly InvoicePaymentService $paymentService,
        AuthorizationService $authService,
        SidebarCounterService $counterService,
        ?ServerRequest $request = null,
        ?Response $response = null,
        ?string $name = null,
        ?EventManagerInterface $eventManager = null,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($authService, $counterService, $request, $response, $name, $eventManager, $components);
    }

    public function initialize(): void
    {
        parent::initialize();
    }
```

Notas:
- Las 5 deps específicas del controller van primero (con `private readonly`).
- Las 2 deps heredadas del `AppController` (`AuthorizationService`, `SidebarCounterService`) van a continuación, sin `private` ni `readonly` — son parámetros para pasar a `parent::__construct`.
- `initialize()` queda solo con `parent::initialize()` (puede dejarse o eliminarse; CakePHP no exige el método si está vacío de lógica propia).
- `InvoicesController:340` hace `$emailLogService = new EmailLogService();` dentro de un método. Como `EmailLogService` se usa en una sola action puntual, sustituir esa línea por `$emailLogService = $this->getContainer()->get(EmailLogService::class);`.

- [ ] **Step 2: `AdvancesController` — análogo.**

Read `src/Controller/AdvancesController.php`. Hoy:

```php
public function initialize(): void
{
    parent::initialize();
    $this->legalizationService = new AdvanceLegalizationService();
    $this->pipelineService = new InvoicePipelineService();
}
```

Constructor nuevo (igual estructura que InvoicesController):

```php
public function __construct(
    private readonly AdvanceLegalizationService $legalizationService,
    private readonly InvoicePipelineService $pipelineService,
    AuthorizationService $authService,
    SidebarCounterService $counterService,
    ?ServerRequest $request = null,
    ?Response $response = null,
    ?string $name = null,
    ?EventManagerInterface $eventManager = null,
    ?ComponentRegistry $components = null,
) {
    parent::__construct($authService, $counterService, $request, $response, $name, $eventManager, $components);
}
```

Vaciar `initialize()` (solo `parent::initialize()`). Añadir los imports correspondientes.

- [ ] **Step 3: `InvoicePaymentsController` — análogo, 1 dep.**

Read `src/Controller/InvoicePaymentsController.php`. Hoy:

```php
public function initialize(): void
{
    parent::initialize();
    $this->paymentService = new InvoicePaymentService();
}
```

Aplicar misma transformación con 1 dep promovida.

- [ ] **Step 4: `ExternalApprovalsController` — análogo, 3 deps.**

Hoy (`src/Controller/ExternalApprovalsController.php:19-25`):

```php
public function initialize(): void
{
    parent::initialize();
    $this->tokenService = new ApprovalTokenService();
    $this->approvalService = new InvoiceApprovalService();
    $this->pipelineService = new InvoicePipelineService();
}
```

Constructor nuevo con 3 deps + las 2 del AppController + los 5 base de Controller. Vaciar `initialize()`.

- [ ] **Step 5: Verificación estática.**

```bash
grep -n "new InvoicePipelineService\|new InvoiceFilterService\|new InvoiceDocumentService\|new InvoiceApprovalService\|new InvoicePaymentService\|new AdvanceLegalizationService\|new ApprovalTokenService\|new EmailLogService" src/Controller/InvoicesController.php src/Controller/AdvancesController.php src/Controller/InvoicePaymentsController.php src/Controller/ExternalApprovalsController.php
# esperado: 0 líneas (solo posibles falsos positivos en strings/comentarios)
php -l src/Controller/InvoicesController.php src/Controller/AdvancesController.php src/Controller/InvoicePaymentsController.php src/Controller/ExternalApprovalsController.php
composer cs-check src/Controller/Invoices*.php src/Controller/Advances*.php src/Controller/External*.php src/Controller/InvoicePayments*.php
```

- [ ] **Step 6: Smoke check — flujo de facturas + anticipos + aprobación externa.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
```

Login admin:
1. `/invoices` carga la lista paginada.
2. Editar una factura → guardar → debe persistir.
3. `/advances/add` → crear anticipo → debe persistir.
4. Si hay un link de aprobación externa pendiente, abrir `/approve/{token}` (sesión externa) → debe cargar.

`kill %1`

- [ ] **Step 7: Commit.**

```bash
git add src/Controller/InvoicesController.php src/Controller/AdvancesController.php src/Controller/InvoicePaymentsController.php src/Controller/ExternalApprovalsController.php
git commit -m "refactor(plan-3): controllers de facturas y anticipos usan DI

InvoicesController, AdvancesController, InvoicePaymentsController,
ExternalApprovalsController: constructor injection nativo de CakePHP 5.
initialize() ya no instancia servicios. EmailLogService usado en 1
sola action puntual de InvoicesController se resuelve via getContainer().

Refs: W3"
```

---

## Task 11: Migrar controllers — bloque 2 (novedades)

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`
- Modify: `src/Controller/NoveltyDocumentsController.php`

- [ ] **Step 1: `EmployeeNoveltiesController` — el más grande, 8 deps.**

Hoy (`src/Controller/EmployeeNoveltiesController.php:38-49`):

```php
public function initialize(): void
{
    parent::initialize();
    $this->pipelineService = new NoveltyPipelineService();
    $this->documentService = new NoveltyDocumentService();
    $this->observationService = new NoveltyObservationService();
    $this->historyService = new NoveltyHistoryService();
    $this->leaveDocumentService = new LeaveDocumentService();
    $this->signatureService = new NoveltySignatureService();
    $this->tokenService = new ApprovalTokenService();
    $this->notificationService = new NotificationService();
}
```

Después:

```php
public function __construct(
    private readonly NoveltyPipelineService $pipelineService,
    private readonly NoveltyDocumentService $documentService,
    private readonly NoveltyObservationService $observationService,
    private readonly NoveltyHistoryService $historyService,
    private readonly LeaveDocumentService $leaveDocumentService,
    private readonly NoveltySignatureService $signatureService,
    private readonly ApprovalTokenService $tokenService,
    private readonly NotificationService $notificationService,
    AuthorizationService $authService,
    SidebarCounterService $counterService,
    ?ServerRequest $request = null,
    ?Response $response = null,
    ?string $name = null,
    ?EventManagerInterface $eventManager = null,
    ?ComponentRegistry $components = null,
) {
    parent::__construct($authService, $counterService, $request, $response, $name, $eventManager, $components);
}

public function initialize(): void
{
    parent::initialize();
}
```

Eliminar las 8 propiedades declaradas (líneas 26–33). Añadir los imports faltantes.

`EmployeeNoveltiesController:443` hace `$emailLogService = new EmailLogService()` en un método. Sustituir por `$emailLogService = $this->getContainer()->get(EmailLogService::class);` (regla: 1 sola action → inline).

- [ ] **Step 2: `NoveltyLiquidationDocsController` — análogo, 5–6 deps.**

Read el archivo, identificar las deps en `initialize()`, aplicar misma transformación.

- [ ] **Step 3: `NoveltyDocumentsController` — análogo.**

Hoy:
```php
public function initialize(): void
{
    parent::initialize();
    $this->documentService = new NoveltyDocumentService();
}
```

1 dep, transformación trivial.

- [ ] **Step 4: Verificación estática.**

```bash
grep -n "new NoveltyPipelineService\|new NoveltyDocumentService\|new NoveltyObservationService\|new NoveltyHistoryService\|new LeaveDocumentService\|new NoveltySignatureService\|new ApprovalTokenService\|new NotificationService" src/Controller/EmployeeNovelties*.php src/Controller/NoveltyLiquidationDocs*.php src/Controller/NoveltyDocuments*.php
# esperado: 0 líneas
composer cs-check src/Controller/EmployeeNovelties*.php src/Controller/Novelty*.php
```

- [ ] **Step 5: Smoke check — flujo de novedades.**

Login admin:
1. `/employee-novelties` carga.
2. Crear novedad nueva → asignar aprobador → click "Enviar links de aprobación" → debe registrar email en `email_logs` (verifica que `NotificationService` y `EmailLogService` se construyen vía container).
3. Reintentar un correo desde el panel inline → debe disparar `EmailLogService::retry()` con la closure correctamente.

- [ ] **Step 6: Commit.**

```bash
git add src/Controller/EmployeeNovelties*.php src/Controller/Novelty*.php
git commit -m "refactor(plan-3): controllers de novedades usan DI

EmployeeNoveltiesController (8 deps), NoveltyLiquidationDocsController
y NoveltyDocumentsController: constructor injection nativo.

EmployeeNoveltiesController:443 usa getContainer()->get() para el
EmailLogService que se invoca solo en una action puntual.

Refs: W3"
```

---

## Task 12: Migrar controllers — bloque 3 (catálogos y administración)

**Files:**
- Modify: `src/Controller/SystemSettingsController.php`
- Modify: `src/Controller/RolesController.php`
- Modify: `src/Controller/DianCrosschecksController.php`
- Modify: `src/Controller/EmailLogsController.php`
- Modify: `src/Controller/PaymentRegistryController.php`
- Modify: `src/Controller/LeaveDocumentTemplatesController.php`

- [ ] **Step 1: Para cada controller, leer su `initialize()` y aplicar la transformación constructor injection.**

Patrón uniforme: cada `$this->xService = new XService()` en `initialize()` se mueve al constructor como `private readonly`. La firma extendida debe pasar `AuthorizationService` y `SidebarCounterService` al `parent::__construct()`.

- [ ] **Step 2: `EmailLogsController` — caso especial: 2 instanciaciones.**

Hoy:
```php
public function initialize(): void
{
    parent::initialize();
    $this->emailLogService = new EmailLogService();
}

// línea 147 dentro de un método:
$authorizationService = new AuthorizationService();
```

`EmailLogService` al constructor (se usa en múltiples actions: `index`, `retry`, `retryAllFailed`).

`AuthorizationService` en línea 147: ese ya viene de `AppController` heredado como `$this->authService`. Sustituir `$authorizationService = new AuthorizationService();` por `$authorizationService = $this->authService;` (o usar `$this->authService` directamente sin la variable local).

- [ ] **Step 3: Verificación estática para todo el bloque.**

```bash
grep -n "= new [A-Z][a-zA-Z]*Service\|= new AuthorizationService" src/Controller/SystemSettings*.php src/Controller/Roles*.php src/Controller/DianCrosschecks*.php src/Controller/EmailLogs*.php src/Controller/PaymentRegistry*.php src/Controller/LeaveDocumentTemplates*.php
# esperado: 0 líneas
composer cs-check src/Controller/SystemSettings*.php src/Controller/Roles*.php src/Controller/DianCrosschecks*.php src/Controller/EmailLogs*.php src/Controller/PaymentRegistry*.php src/Controller/LeaveDocumentTemplates*.php
```

- [ ] **Step 4: Smoke check.**

Login admin:
1. `/system-settings` carga; "Probar SMTP" funciona.
2. `/roles` carga (verifica `RolesController` con `AuthorizationService` heredado).
3. `/email-logs` carga; reintento individual y masivo funcionan.
4. `/dian-crosschecks` carga (DIAN webhook puede fallar si N8n down — eso es OK, lo que verificamos es que el controller construye sus deps).
5. `/payment-registry` carga.

Login no-admin con permisos limitados → intentar acceder a una vista prohibida → ver flash de error y redirección. Esto valida que `_enforcePermission` (en AppController, ahora con `$this->authService` cacheado) sigue funcionando.

- [ ] **Step 5: Commit.**

```bash
git add src/Controller/SystemSettings*.php src/Controller/Roles*.php src/Controller/DianCrosschecks*.php src/Controller/EmailLogs*.php src/Controller/PaymentRegistry*.php src/Controller/LeaveDocumentTemplates*.php
git commit -m "refactor(plan-3): controllers de catalogos y admin usan DI

SystemSettingsController, RolesController, DianCrosschecksController,
EmailLogsController, PaymentRegistryController,
LeaveDocumentTemplatesController: constructor injection.

EmailLogsController:147 reemplaza 'new AuthorizationService()' por
\$this->authService heredado de AppController.

Refs: W3, W5"
```

---

## Task 13: Migrar controllers — bloque 4 (pagos, caja, dashboard, empleados)

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php`
- Modify: `src/Controller/PettyCashRecordsController.php`
- Modify: `src/Controller/EmployeesController.php`
- Modify: `src/Controller/DashboardController.php`

- [ ] **Step 1: `PaymentSchedulingsController` — 2 deps (`PaymentSchedulingPipelineService`, `PaymentSchedulingService`).**

Hoy:
```php
public function initialize(): void
{
    parent::initialize();
    $this->pipeline = new PaymentSchedulingPipelineService();
    $this->schedulingService = new PaymentSchedulingService();
}
```

Constructor injection con 2 deps + las 2 heredadas + los 5 base. Mismo patrón.

- [ ] **Step 2: `PettyCashRecordsController` — 2 deps.**

Hoy:
```php
public function initialize(): void
{
    parent::initialize();
    $this->pettyCashService = new PettyCashService();
    $this->documentService = new PettyCashDocumentService();
}
```

Mismo patrón.

- [ ] **Step 3: `EmployeesController` — `EmployeeFilterService` y posiblemente otros.**

Read y aplicar.

- [ ] **Step 4: `DashboardController` — instancia `DashboardStatisticsService` dentro de un método.**

Hoy (`src/Controller/DashboardController.php:28`):

```php
public function index() {
    // ...
    $stats = new DashboardStatisticsService();
    // ...
}
```

Como solo se usa en `index()` (una sola action), aplicar la regla: inline con `getContainer()`:

```php
$stats = $this->getContainer()->get(DashboardStatisticsService::class);
```

Si después se ve que se usa en otras actions, promover al constructor en un commit posterior.

- [ ] **Step 5: Verificación estática.**

```bash
grep -n "= new [A-Z][a-zA-Z]*Service" src/Controller/PaymentSchedulings*.php src/Controller/PettyCashRecords*.php src/Controller/Employees*.php src/Controller/Dashboard*.php
# esperado: 0 líneas
composer cs-check src/Controller/PaymentSchedulings*.php src/Controller/PettyCashRecords*.php src/Controller/Employees*.php src/Controller/Dashboard*.php
```

- [ ] **Step 6: Smoke check.**

Login admin:
1. `/payment-schedulings` carga; avanzar una programación por el pipeline.
2. `/petty-cash-records` carga; registrar un movimiento.
3. `/employees` carga.
4. `/dashboard` carga; las estadísticas y el sidebar funcionan.

- [ ] **Step 7: Commit.**

```bash
git add src/Controller/PaymentSchedulings*.php src/Controller/PettyCashRecords*.php src/Controller/Employees*.php src/Controller/Dashboard*.php
git commit -m "refactor(plan-3): controllers de pagos, caja, empleados, dashboard usan DI

PaymentSchedulingsController, PettyCashRecordsController,
EmployeesController, DashboardController: constructor injection.
DashboardController usa getContainer() inline para
DashboardStatisticsService que se invoca solo en index().

Refs: W3"
```

---

## Task 14: Migrar `ExcelWizardTrait` y limpiar restos

**Files:**
- Modify: `src/Controller/Trait/ExcelWizardTrait.php`
- Modify: cualquier otro archivo que aún tenga `new XService()` (ver grep en step 1).

- [ ] **Step 1: Localizar restos.**

```bash
grep -rn "= new [A-Z][a-zA-Z]*Service\|new [A-Z][a-zA-Z]*Service()\|new [A-Z][a-zA-Z]*Strategy()\|new [A-Z][a-zA-Z]*Policy()" src/Controller/ src/Service/ | grep -v "ImportResult\|ServiceResult\|HistoryServiceInterface\|GroupedInvoiceService\|CircuitBreaker"
```

`ImportResult` es DTO, `ServiceResult` es DTO, `GroupedInvoiceService` queda fuera del container, `CircuitBreaker` queda local. Cualquier otra coincidencia debe migrarse.

- [ ] **Step 2: `ExcelWizardTrait` — sustituir `new` por `$this->getContainer()->get(...)`.**

Hoy (4 sitios en `src/Controller/Trait/ExcelWizardTrait.php` líneas 56, 72, 120, 174–175, 244):

```php
$fields = (new ExcelMappingService())->getExportableFields(...);
$mapping = new ExcelMappingService();
$excelService = new ExcelService();
$importService = new ExcelImportService();
$mapping = new ExcelMappingService();
$importService = new ExcelImportService();
```

Sustituir por:

```php
$fields = $this->getContainer()->get(ExcelMappingService::class)->getExportableFields(...);
$mapping = $this->getContainer()->get(ExcelMappingService::class);
$excelService = $this->getContainer()->get(ExcelService::class);
$importService = $this->getContainer()->get(ExcelImportService::class);
$mapping = $this->getContainer()->get(ExcelMappingService::class);
$importService = $this->getContainer()->get(ExcelImportService::class);
```

El trait asume que se compone en una clase Controller (que tiene `getContainer()` heredado de `Cake\Controller\Controller`). Esa asunción ya se cumple — se usa en `InvoicesController` y otros.

- [ ] **Step 3: Verificación estática final.**

```bash
# Servicios: 0 ocurrencias
grep -rn "?? new " src/Service/
# esperado: 0 líneas

# Controllers: 0 instanciaciones de servicios
grep -rn "= new [A-Z][a-zA-Z]*Service\|= new [A-Z][a-zA-Z]*Strategy\|= new [A-Z][a-zA-Z]*Policy" src/Controller/ | grep -v "ImportResult\|ServiceResult"
# esperado: 0 líneas
```

Si alguno sigue, atender ese caso específico ahora.

- [ ] **Step 4: Smoke check — Excel wizard end-to-end.**

Login admin:
1. `/invoices` → click en "Importar Excel" → completar el wizard hasta confirmar import → debe ejecutar `ExcelImportService` resuelto vía container.
2. Si el flujo no está disponible para facturas, probar con cualquier otro controller que use `ExcelWizardTrait`.

- [ ] **Step 5: Commit.**

```bash
git add src/Controller/Trait/ExcelWizardTrait.php
git commit -m "refactor(plan-3): ExcelWizardTrait resuelve servicios via container

Los traits no tienen constructor; usan getContainer() del Controller
host. Sustituye 'new ExcelService()' (3 sitios), 'new ExcelImportService()'
(2 sitios) y 'new ExcelMappingService()' (1 sitio inline) por
\$this->getContainer()->get(...).

Refs: W3"
```

---

## Task 15: Verificación final + cs-fix general + actualización de roadmap

**Files:**
- Possibly modify: cualquier archivo con violaciones de cs detectadas.
- Modify: `docs/audits/architecture-audit-roadmap.md`

- [ ] **Step 1: Grep totales (criterios de éxito del spec).**

```bash
echo "=== ?? new en services ==="
grep -rn "?? new " src/Service/

echo "=== new XService() en controllers ==="
grep -rn "= new [A-Z][a-zA-Z]*Service\|= new [A-Z][a-zA-Z]*Strategy\|= new [A-Z][a-zA-Z]*Policy" src/Controller/ | grep -v "ImportResult\|ServiceResult"

echo "=== AuthorizationService instanciado manualmente ==="
grep -rn "new AuthorizationService" src/

echo "=== Total de bindings registrados ==="
grep -c "\$container->addShared" src/Application.php
# esperado: ~36 líneas
```

Los primeros 3 deben dar 0 resultados.

- [ ] **Step 2: `composer cs-fix` global.**

```bash
composer cs-fix
```

- [ ] **Step 3: Smoke check end-to-end completo.**

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
```

Recorrer las transiciones del pipeline de facturas con una factura de prueba: `aprobacion → contabilidad → tesoreria → autorizacion_pago → pagada`. Cada avance dispara servicios distintos (Pipeline, Payment, Approval, History, etc.), validando que el container está construyendo todo correctamente.

Recorrer flujo de novedad con aprobación externa (link por email) → aprobar desde el navegador como aprobador externo (sin sesión) → verifica `ExternalApprovalsController` con sus deps.

Logs: `tail -100 /tmp/cake.log logs/error.log` → no debe haber `Class not found`, `Cannot resolve dependency`, ni `Argument of type ?X cannot be null`.

`kill %1`

- [ ] **Step 4: Test crítico de cierre de W5 — `AuthorizationService` es singleton por request.**

Añadir temporalmente al constructor de `AuthorizationService`:

```php
public function __construct() {
    \Cake\Log\Log::debug('AuthorizationService constructed @ ' . microtime(true));
}
```

```bash
php bin/cake server > /tmp/cake.log 2>&1 &
sleep 2
# Login y cargar una página que use múltiples permission checks (ej. dashboard)
curl -c /tmp/cookies.txt -b /tmp/cookies.txt -d "username=admin&password=Admin2024*" http://localhost:8765/login
curl -c /tmp/cookies.txt -b /tmp/cookies.txt http://localhost:8765/dashboard
kill %1

grep "AuthorizationService constructed" logs/debug.log | tail -10
# esperado: aparece UNA SOLA VEZ por request HTTP
```

Si aparece más de una vez por request, la instancia no es singleton — revisar el binding (debe ser `addShared`, no `add`).

Quitar el `Log::debug` del constructor antes del commit final.

- [ ] **Step 5: Actualizar la tabla de estado del roadmap.**

Modificar `docs/audits/architecture-audit-roadmap.md`:

```markdown
| 3 | DI Container | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-di-container-design.md) | [plan](../superpowers/plans/2026-05-01-di-container-plan.md) | — | YYYY-MM-DD |
```

(Sustituir `YYYY-MM-DD` por la fecha del merge real.)

También cambiar el "Estado global" arriba si aplica.

- [ ] **Step 6: Commit final.**

```bash
git add -A
git commit -m "$(cat <<'EOF'
chore(plan-3): verificacion final y cierre del Plan 3 (DI Container)

- composer cs-fix aplicado en todo el codigo modificado.
- Grep de criterios de exito: 0 ocurrencias de '?? new ' en src/Service/,
  0 de 'new XService' en src/Controller/, 0 de 'new AuthorizationService'.
- AuthorizationService verificado como singleton por request
  (cierre real de W5 — su cache interna acumula).
- Roadmap actualizado: Plan 3 -> Completado.

Cierra W3 (?? new como anti-patron de DI) y W5 (cache de
AuthorizationService dead por instanciacion multiple).

Refs: docs/audits/architecture-audit-2026-04-30.md
EOF
)"
```

---

## Resumen de validación final (criterios de éxito copiados del spec)

- [ ] `grep -r "?? new " src/Service/` devuelve **0** resultados.
- [ ] `grep -rn "= new [A-Z][a-zA-Z]*Service" src/Controller/` devuelve **0** resultados (excluyendo DTOs `ImportResult` y `ServiceResult`).
- [ ] La app responde HTTP 200 en `/login`, `/invoices`, `/employee-novelties`, `/dashboard`, `/email-logs`.
- [ ] Una factura recorre las 5 transiciones del pipeline sin error.
- [ ] Una novedad con aprobación externa se aprueba correctamente desde el link.
- [ ] `AuthorizationService::__construct` se invoca **una sola vez** por request (verificado con `Log::debug` temporal).
- [ ] `composer cs-check` limpio.
- [ ] Roadmap actualizado a 🟢 Completado.

---

## Riesgos y procedimiento de rollback

Si después de un commit la app no arranca:

1. `git log --oneline -5` para identificar el commit malo.
2. `git revert <hash>` (preferido) o `git reset --hard HEAD~1` (si nada depende de él).
3. Releer ese task del plan; corregir el error puntual; reintentarlo.

**Errores frecuentes esperados:**

| Síntoma | Causa probable | Fix |
|---|---|---|
| `ContainerException: cannot resolve App\Service\X` | Servicio usado pero no registrado en `Application::services()` | Añadir el `$container->addShared(X::class)` correspondiente. |
| `Argument of type ?X cannot be null` | Algún caller (script `bin/`, comando) sigue construyendo el servicio sin pasar la dep | Adaptar el caller para usar el container o restaurar el `?` en ese constructor específico (idealmente el primero). |
| Stack overflow en `_setStatus` o `EmailLogService::retry` | El binding usa `addArgument(fn() => ...)` directo en lugar de `addArgument(new LiteralArgument(fn() => ...))` | Envolver la closure en `LiteralArgument`. |
| `Call to undefined method getContainer()` en un trait | El trait se está usando en una clase que no es `Controller` | Localizar la clase host; si es controller, verificar que extiende `AppController`. |

---

## Self-review

**Cobertura del spec:**
- ✅ Sección "Bindings completos" → Task 1.
- ✅ Sección "Constructor de servicios" → Tasks 2–7.
- ✅ Sección "Closure factory" → Task 3 (Email/Notif) y Task 8 (Pipeline/Legalization).
- ✅ Sección "Migración de controllers" → Tasks 9–13.
- ✅ Sección "Trait" → Task 14.
- ✅ Sección "Validación manual" + "Criterios de éxito" → Task 15.

**Afinaciones aplicadas durante la escritura del plan (no estaban en el spec):**
- `CircuitBreaker` y `GroupedInvoiceService` excluidos del container (parámetros posicionales / config local).
- `InvoiceApprovalStrategy` recibe `InvoicePipelineService` directamente (no closure — no hay ciclo).
- `ExternalApprovalsController` añadido al bloque 1 (faltaba en la lista del spec).
- `PettyCashService` necesita ahora `HistoryServiceInterface` inyectada para pasarla al `GroupedInvoiceService` que crea internamente.

**Sin placeholders.** Cada step tiene comando o código concreto.
