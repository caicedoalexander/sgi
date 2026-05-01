# Plan 3 — DI Container (W3 + W5)

**Plan del roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) · **Plan #3**
**Auditoría origen:** [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md)
**Fecha:** 2026-05-01
**Tamaño estimado:** 3–5 días

---

## Resumen

Hoy SGI carece de inyección de dependencias real. El método `Application::services(ContainerInterface $container)` existe vacío. Cada servicio bajo `src/Service/` se construye a sí mismo en su constructor con el patrón `?? new XService()` (~26 ocurrencias) y los controllers instancian sus servicios con `new` directo dentro de `initialize()`. Resultado: en una sola request, `InvoiceHistoryService` puede construirse 3 veces, `AuthorizationService` 3+ veces (su caché interna nunca acumula), y la firma "explícita" de los constructores de servicios miente porque cualquiera de ellos sigue funcionando sin recibir nada.

Este plan resuelve los items **W3** (anti-patrón `?? new`) y **W5** (caché muerta de `AuthorizationService`) llenando `Application::services()` con el grafo completo, eliminando el patrón `?? new` de todos los constructores de servicios, y migrando los controllers a constructor injection nativo de CakePHP 5.

---

## Decisiones de diseño tomadas en brainstorming

1. **Constructor injection nativo de CakePHP 5 en controllers** (opción A). El `ControllerFactory` de CakePHP 5 inspecciona la firma del constructor del controller y resuelve sus dependencias desde el container automáticamente. Las dependencias quedan explícitas en la firma; `initialize()` deja de instanciar servicios.

2. **Registrar todo el grafo + interfaces existentes** (opción C). Cualquier clase en `src/Service/` (incluidos `Adapter/`, `Strategy/`, `Dashboard/`) se registra. Las interfaces existentes (`MailerInterface`, `HistoryServiceInterface`, `SpreadsheetReaderInterface`) se bindean a su implementación por defecto cuando hay una sola.

3. **Closure factory para romper el ciclo Pipeline ↔ Legalization** (opción A). `AdvanceLegalizationService` deja de recibir `?InvoicePipelineService` y recibe `Closure $pipelineFactory`. El método `_getPipelineService()` invoca la closure cuando hace falta. Plan 5 elimina la closure cuando los Domain Events sustituyan la llamada cruzada.

4. **Bindings en un único `Application::services()`** (opción A). ~36 bindings en un método con secciones por dominio (`// === Invoice domain ===`). No se introducen Service Providers — el tamaño del proyecto no lo justifica.

---

## Alcance

### Lo que entra

- `Application::services()` se llena con el grafo completo de servicios bajo `src/Service/` (incluyendo `Adapter/`, `Strategy/`, `Dashboard/`).
- Las interfaces que ya existen (`MailerInterface`, `SpreadsheetReaderInterface`) se bindean a su implementación por defecto.
- Todos los servicios cuyos constructores usan `?? new X()` se reescriben a constructores con tipos no-nulables y propiedades `readonly` promovidas.
- `AdvanceLegalizationService` y `EmailLogService` reciben un `Closure` factory para resolver perezosamente sus dependencias circulares (`InvoicePipelineService` y `NotificationService`, respectivamente).
- `AppController` recibe `AuthorizationService` y `SidebarCounterService` por constructor. Todos los controllers concretos extienden la firma del padre.
- Cada controller con `new XService()` en `initialize()` migra a constructor injection. Servicios usados en una única acción puntual pueden quedarse con `$this->getContainer()->get(X::class)` inline.
- `Trait/ExcelWizardTrait` migra de `new ExcelService()` a `$this->getContainer()->get(ExcelService::class)` (los traits no tienen constructor).

### Lo que NO entra

- Refactor del god-service `InvoicePipelineService` (Plan 4: C5, W2, W9, W10).
- Eliminar las llamadas cruzadas Pipeline ↔ Payment ↔ Legalization (Plan 5: C6).
- Introducir un `RetryPolicy` reutilizable inyectado (Plan 6: W13).
- Migrar `Cake\Log\Log::*` a `StructuredLogger` inyectado (Plan 7: W1).
- Registrar Tables (`InvoicesTable`, etc.) en el container — siguen consumiéndose vía `TableRegistry::getTableLocator()->get(...)` que es la convención del proyecto y CakePHP la resuelve solo.
- Cambiar firmas de servicios para aceptar Tables.

---

## Componentes y patrones

### Container de CakePHP 5

CakePHP 5 expone el container basado en `league/container` vía el método `Application::services(ContainerInterface $container)`. API relevante:

| Llamada | Significado |
|---|---|
| `$container->add(Foo::class)` | Registra `Foo`. Cada `get(Foo::class)` reconstruye una instancia nueva. |
| `$container->addShared(Foo::class)` | Registra `Foo` como singleton **por request**. Cada `get(Foo::class)` devuelve la misma instancia durante esa request. |
| `->addArgument(Bar::class)` | Declara que `Foo` espera un `Bar` como argumento del constructor; el container lo resuelve. |
| `->addArguments([...])` | Variante con múltiples argumentos. |
| `->addArgument(new LiteralArgument(fn() => ...))` | Pasa un valor literal (típicamente una closure) sin intentar resolverlo como una clase. `LiteralArgument` viene de `League\Container\Argument`. |

**Decisión:** todos los servicios de SGI se registran con `addShared`. No hay servicios con estado mutable que justifique transient (todos son stateless o tienen caché in-memory que se beneficia del singleton).

### Closure factory para romper ciclos

Hoy hay dos ciclos circulares masked con lazy-init via `?? new`:

1. **`InvoicePipelineService` ↔ `AdvanceLegalizationService`** — Pipeline llama `legalize.initialize()` en `saveAndAdvance`; Legalization llama `pipeline.legalizeLinkedInvoices()` en `_setStatus`.

2. **`NotificationService` ↔ `EmailLogService`** — NotificationService usa EmailLog para registrar cada intento; EmailLog usa NotificationService dentro de `retry()` y `retryAllFailed()` para reenviar.

El container no puede construir ninguno de los dos lados sin entrar en recursión. Solución: el lado "consumidor lazy" recibe un `Closure` que el container conoce como factory diferida:

```php
// AdvanceLegalizationService
public function __construct(
    private readonly Closure $pipelineFactory,
) {}

private function _getPipelineService(): InvoicePipelineService
{
    return ($this->pipelineFactory)();
}
```

```php
// Application::services()
$container->addShared(AdvanceLegalizationService::class)
    ->addArgument(new LiteralArgument(
        fn() => $container->get(InvoicePipelineService::class)
    ));
```

Cuando el container resuelve `AdvanceLegalizationService`, le pasa la closure capturada. Cuando esa closure se invoca dentro de `_setStatus`, el container ya tiene a `InvoicePipelineService` cacheado por `addShared` y lo devuelve sin reentrar.

Mismo patrón en `EmailLogService` con `NotificationService`. Plan 5 (Domain Events) borrará ambos factories cuando los eventos sustituyan las llamadas cruzadas.

### Constructor de servicios — patrón antes/después

**Antes:**
```php
class InvoicePipelineService
{
    private InvoiceHistoryService $historyService;
    private InvoicePaymentService $paymentService;
    private InvoiceFieldAccessPolicy $fieldPolicy;
    private AdvanceLegalizationService $advanceLegalizationService;

    public function __construct(
        ?InvoiceHistoryService $historyService = null,
        ?InvoicePaymentService $paymentService = null,
        ?InvoiceFieldAccessPolicy $fieldPolicy = null,
        ?AdvanceLegalizationService $advanceLegalizationService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->paymentService = $paymentService ?? new InvoicePaymentService();
        $this->fieldPolicy = $fieldPolicy ?? new InvoiceFieldAccessPolicy();
        $this->advanceLegalizationService = $advanceLegalizationService
            ?? new AdvanceLegalizationService();
    }
}
```

**Después:**
```php
class InvoicePipelineService
{
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
        private readonly AdvanceLegalizationService $advanceLegalizationService,
    ) {}
}
```

### Migración de controllers — patrón antes/después

**Restricción de CakePHP 5:** la firma base de `Controller::__construct()` exige aceptar `?ServerRequest $request, ?Response $response, ?string $name, ?EventManager $eventManager, ?ComponentRegistry $components`. PHP no permite saltarse esos parámetros, así que se aceptan al final del constructor del controller y se delegan al `parent::__construct()`.

**Antes:**
```php
class InvoicesController extends AppController
{
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
}
```

**Después:**
```php
class InvoicesController extends AppController
{
    public function __construct(
        private readonly InvoicePipelineService $pipeline,
        private readonly InvoiceFilterService $filterService,
        private readonly InvoiceDocumentService $documentService,
        private readonly InvoiceApprovalService $approvalService,
        private readonly InvoicePaymentService $paymentService,
        ?ServerRequest $request = null,
        ?Response $response = null,
        ?string $name = null,
        ?EventManager $eventManager = null,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($request, $response, $name, $eventManager, $components);
    }

    public function initialize(): void
    {
        parent::initialize();
    }
}
```

`AppController` aplica el mismo patrón con `AuthorizationService` y `SidebarCounterService` como deps que heredan todos los controllers.

### Regla para servicios usados en una sola acción

Si un servicio se usa en **≥2 acciones** del mismo controller → al constructor.
Si es **1 acción puntual** (ej. `EmployeeNoveltiesController:443` usa `EmailLogService` solo en una action) → `$this->getContainer()->get(EmailLogService::class)` inline en esa action.

Esto evita inflar constructores como el de `EmployeeNoveltiesController` (8 deps actuales) sin justificación.

---

## Bindings completos en `Application::services()`

```php
public function services(ContainerInterface $container): void
{
    // === Infrastructure / Adapters ===
    $container->addShared(SystemSettingsService::class);
    $container->addShared(StructuredLogger::class);
    $container->addShared(CircuitBreaker::class);
    $container->addShared(MailerInterface::class, CakeMailerAdapter::class)
        ->addArgument(SystemSettingsService::class);
    $container->addShared(SpreadsheetReaderInterface::class, PhpSpreadsheetAdapter::class);

    // === Auth / Authorization ===
    $container->addShared(AuthorizationService::class);
    $container->addShared(ApprovalTokenService::class);

    // === Email log + notifications ===
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

    // === Invoice domain ===
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
    $container->addShared(GroupedInvoiceService::class)
        ->addArgument(InvoiceHistoryService::class);

    // === Strategies ===
    $container->addShared(InvoiceApprovalStrategy::class)
        ->addArgument(InvoiceHistoryService::class);
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
    $container->addShared(WebhookService::class)
        ->addArguments([CircuitBreaker::class, StructuredLogger::class]);
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

**Notas:**

- Los servicios sin dependencias (`InvoiceHistoryService`, `SystemSettingsService`, `ApprovalTokenService`, etc.) se registran solo con `addShared` — el container los construye con su constructor vacío.
- `HistoryServiceInterface` no se bindea globalmente porque hay dos implementaciones (`InvoiceHistoryService`, `NoveltyHistoryService`) y los consumidores piden la concreta de su dominio.
- El orden de las llamadas dentro de `services()` no importa — `league/container` resuelve dependencias por demanda, no en orden de registro.

---

## Plan de migración (commits)

El refactor es mecánico y largo (~36 servicios + ~17 controllers). Se parte en pasos pequeños donde **cada commit deja la app funcionando**.

### Paso 0 — Bindings vacíos (1 commit)

`Application::services()` se llena con todos los bindings, pero ningún servicio cambia su constructor todavía. Cada `addShared()` apunta a la clase con su firma actual (que sigue aceptando `?Foo = null`). El container construye los servicios pasando las deps que sí conoce; los `?? new` quedan inertes porque ahora reciben instancias reales. App arranca igual.

### Paso 1 — Servicios sin deps circulares (~6 commits, uno por dominio)

Reescribir constructores quitando `??` y promoviendo a `readonly`:

1. **Infrastructure:** `SystemSettingsService`, `Adapter/CakeMailerAdapter`, `CircuitBreaker`, `WebhookService`, `N8nService`, `DianCrosscheckService`.
2. **Notification + EmailLog:** `NotificationService`, `EmailLogService` (con su Closure factory).
3. **Invoice domain (sin Pipeline ni Legalization):** `InvoiceHistoryService`, `InvoicePaymentService`, `InvoiceFieldAccessPolicy`, `InvoiceApprovalService`, `GroupedInvoiceService`, `InvoiceFilterService`, `InvoiceDocumentService`.
4. **Strategies:** `InvoiceApprovalStrategy`, `NoveltyApprovalStrategy`.
5. **Novelty + petty cash + payment scheduling:** todos los del bloque.
6. **Excel + Dashboard + Sidebar:** `ExcelImportService`, `DashboardStatisticsService`, `SidebarCounterService`, sub-servicios.

### Paso 2 — Cierre del ciclo Pipeline ↔ Legalization (1 commit)

`AdvanceLegalizationService` recibe `Closure $pipelineFactory`. `InvoicePipelineService` reescribe su constructor sin `??`. Validación: la app arranca y `_setStatus()` sigue llamando a `legalizeLinkedInvoices` correctamente.

### Paso 3 — `AppController` y migración de controllers (~5 commits)

Primero `AppController` (porque es el padre de todos). Después los controllers en bloques:

- **Bloque 1** — facturas y anticipos: `InvoicesController`, `AdvancesController`, `InvoicePaymentsController`.
- **Bloque 2** — novedades: `EmployeeNoveltiesController`, `NoveltyLiquidationDocsController`, `NoveltyDocumentsController`.
- **Bloque 3** — catálogos y administración: `SystemSettingsController`, `RolesController`, `DianCrosschecksController`, `EmailLogsController`, `PaymentRegistryController`.
- **Bloque 4** — pagos, caja y dashboard: `PaymentSchedulingsController`, `PettyCashRecordsController`, `EmployeesController`, `LeaveDocumentTemplatesController`, `DashboardController`.

### Paso 4 — `ExcelWizardTrait` y restos (1 commit)

El trait pasa a `$this->getContainer()->get(...)`. Cualquier `new XService()` que haya quedado en métodos puntuales se resuelve.

### Paso 5 — Verificación final + cs-fix (1 commit)

`grep -r "?? new " src/Service/` → 0. `grep -rn "= new [A-Z][a-zA-Z]*Service" src/Controller/` → 0 (excluyendo DTOs como `ImportResult`). `composer cs-fix` aplicado.

**Total estimado:** 14–18 commits a lo largo de ~3–5 días.

---

## Validación manual

**Comandos de verificación estática (al final de cada paso):**

```bash
grep -rn "?? new " src/Service/                    # esperado: 0 líneas
grep -rn "= new [A-Z][a-zA-Z]*Service\|= new [A-Z][a-zA-Z]*Policy\|= new [A-Z][a-zA-Z]*Strategy" src/Controller/  # 0 líneas
composer cs-check                                  # limpio
php bin/cake server &
curl -sI http://localhost:8765/login | head -1     # esperado: HTTP/1.1 200
```

**Pruebas funcionales en navegador (post-merge de cada bloque del paso 3):**

Bloque 1 — facturas y anticipos:
1. Login como Administrador.
2. `/invoices` carga el listado paginado; los filtros funcionan.
3. Crear una factura nueva → guardar → verificar que avanza por el pipeline (`aprobacion → contabilidad`).
4. Registrar un pago en una factura en `tesoreria` → verificar que avanza a `autorizacion_pago`.
5. Autorizar un pago como Contador → verificar que la factura llega a `pagada`.
6. Crear un anticipo → legalizarlo → verificar que `AdvanceLegalizationService::_setStatus` ejecuta `legalizeLinkedInvoices` correctamente. **Test crítico del Closure factory de Pipeline ↔ Legalization.**

Bloque 2 — novedades:
7. `/employee-novelties` carga.
8. Crear una novedad → enviar links de aprobación → ver que el correo se registra en `email_logs`.
9. Reintentar un correo fallido desde el panel inline → verifica el ciclo `EmailLogService → NotificationService` (el otro Closure factory).

Bloque 3 — catálogos:
10. Login como rol no-admin con permisos limitados → `_enforcePermission` redirige correctamente cuando se pisa un endpoint prohibido. Este flujo prueba que `AuthorizationService` ahora es singleton por request — la caché interna acumula entre las múltiples llamadas a `_checkPermission`.
11. `/email-logs` (admin) → reintentar masivo → verifica el container resolviendo `EmailLogService` con su closure.
12. `/system-settings` → enviar correo de prueba → verifica `NotificationService` con `MailerInterface` real.

Bloque 4 — pagos y caja:
13. `/payment-schedulings` → avanzar una programación por el pipeline.
14. `/petty-cash-records` → registrar un movimiento.
15. `/dashboard` → cargar la página → estadísticas y sidebar cargan sin errores.
16. Importar un Excel desde `InvoicesController` (`ExcelWizardTrait`) → verifica que el trait resolvió `ExcelImportService` vía container.

**Smoke check post-merge final:**

- Recorrer las 5 transiciones del pipeline de facturas con una factura de prueba (`aprobacion → pagada`).
- Recorrer el flujo de novedad con aprobación externa (link por email) → aprobar desde el navegador como aprobador externo (sin sesión).
- Logs sin `Class not found`, `Cannot resolve dependency`, ni `Argument of type ?X cannot be null`.

---

## Criterios de éxito

- ✅ `grep -r "?? new " src/Service/` devuelve **0** resultados.
- ✅ `grep -rn "= new [A-Z][a-zA-Z]*Service" src/Controller/` devuelve **0** resultados (excluyendo DTOs).
- ✅ Suite de operaciones manuales pasa sin que ningún flujo invoque `new` de un servicio.
- ✅ En una request, cualquier servicio se construye una sola vez. Verificable agregando temporalmente un `Log::debug(__CLASS__ . ' constructed')` en el constructor de `AuthorizationService` y observando que aparece **una vez** en el log durante una página cargada.
- ✅ `composer cs-check` limpio.
- ✅ La app responde HTTP 200 en `/login`, `/invoices`, `/employee-novelties`, `/dashboard`, `/email-logs` para usuarios con permisos.

---

## Riesgos conocidos y mitigaciones

| Riesgo | Síntoma | Mitigación |
|---|---|---|
| Servicio referenciado pero no registrado en `services()` | Error 500: `ContainerException: cannot resolve X` | Paso 0 registra todos *antes* de cambiar constructores. Si Paso 1 introduce un servicio nuevo, ese commit lo registra simultáneamente. |
| Constructor de controller con muchas deps (EmployeeNoveltiesController tiene 8) | Ruido visual; constructor largo | Aceptado como **smell visible** que avisa para futuro split. No se parte en este plan. |
| Closure factory invocada antes de que el container termine de construir el target | Recursión infinita / stack overflow al disparar `_setStatus` o `EmailLogService::retry` | `addShared` cachea la primera resolución; mientras la closure no se invoque durante la construcción del propio target, no hay reentrada. La verificación funcional #6 del Bloque 1 (anticipo + legalization) lo valida en runtime. |
| Consumidor externo (script bin/, comando CLI) instancia servicios manualmente | El script falla al construir un servicio que ahora exige deps no-nulables | `bin/seed-admin.php` revisado al inicio; cualquier otro CLI bajo `bin/` se ajusta para resolver desde el container o queda fuera de alcance documentado. |
| Trait `ExcelWizardTrait` no tiene constructor | No puede recibir deps por inyección | Resolución vía `$this->getContainer()->get(...)` dentro del trait, asumiendo que solo se usa en `Controller` (que tiene `getContainer()`). |

---

## Impacto en planes futuros

- **Plan 4 (Refactor del Pipeline).** Más fácil con DI ya en sitio: extraer `InvoiceLockPolicy` y `InvoiceTransitionValidator` desde `InvoicePipelineService` solo requiere registrarlos en `services()` y declararlos como deps del Pipeline.
- **Plan 5 (Domain Events).** El Closure factory en `AdvanceLegalizationService` y en `EmailLogService` se elimina cuando los Domain Events sustituyan las llamadas cruzadas. Los suscriptores se registran también en el container.
- **Plan 6 (Resilience Hardening).** Inyectar un `RetryPolicy` reutilizable en `NotificationService` y `WebhookService` se vuelve un cambio de una línea en `services()`.
- **Plan 7 (Observability).** Inyectar `StructuredLogger` en cualquier servicio (W1) es trivial — el container ya lo tiene registrado.

---

## Referencias

- Roadmap: [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md), Plan 3.
- Auditoría origen: [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md), W3 + W5.
- CLAUDE.md (raíz) — convenciones del proyecto y política de no-tests.
