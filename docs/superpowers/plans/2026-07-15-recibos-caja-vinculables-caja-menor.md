# Recibos de Caja vinculables a Caja Menor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir vincular facturas `Recibo de Caja` (además de `Caja menor`) a un registro de Caja Menor, con exclusividad atómica "un recibo = un solo padre" respecto a los anticipos.

**Architecture:** `GroupedInvoiceService` pasa de un `documentType` único a una lista (patrón `string|array` ya usado para `linkableStatus`), añade exclusión por `advance_id`, y convierte su escritura de vínculo en un compare-and-set atómico (espejo del gate del anticipo). El módulo de Anticipos gana la exclusividad inversa (`petty_cash_record_id IS null`) en su selector y en su `updateAll` condicional. Sin migraciones.

**Tech Stack:** CakePHP 5.3 / PHP 8.4, PHPUnit + cakephp-fixture-factories.

## Global Constraints

- **Sin migraciones de esquema.** `advance_id` y `petty_cash_record_id` ya son columnas de `invoices`. Todo es código + tests.
- Idioma: mensajes de UI/errores en español; slugs/doctypes persistidos INMUTABLES (`'Caja menor'`, `'Recibo de Caja'` con su espacio/acento exactos). Constantes siempre desde `App\Constants\InvoiceConstants`, nunca strings crudos.
- Servicios obtienen tablas vía `TableRegistry::getTableLocator()->get()`. `GroupedInvoiceService` sirve a Caja Menor **y** Reintegros — todo cambio debe preservar la semántica de Reintegros.
- **Tests:** `vendor/bin/phpunit <ruta> --filter <nombre>` (timeout 300s). NUNCA `composer test`. **NUNCA dos corridas de phpunit concurrentes** (deadlock contra `sgi_test`). BD de test al día; credenciales vía `config/.env` → `phpunit` corre directo. Baseline verde: 1069 tests, 0 failures/0 errors (exit 1 por notices preexistentes — evaluar por Failures/Errors, no por exit code).
- **Estilo:** NUNCA `composer cs-check`/`cs-fix` (repo con ~1760 errores de deuda preexistente, siempre rojo). Usar `vendor/bin/phpcs <archivos tocados>` / `vendor/bin/phpcbf <archivos tocados>`. Criterio: CERO violaciones NUEVAS (o conteo antes/después declarado).
- **Commits:** `git add` con RUTAS EXPLÍCITAS. **NUNCA `git add -A`** — `config/bootstrap.php` está modificado en el working tree, es ajeno, y NO debe entrar en ningún commit. Sin trailer de atribución. NUNCA `--no-verify`.
- `App\Test\Factory\InvoiceDocumentFactory` NO auto-crea el Invoice padre → siempre `::new(['invoice_id' => $inv->id])->save()`. `InvoiceFactory` tiene `->reciboDeCaja()`, `->withStatus($s)`.

---

### Task 1: `GroupedInvoiceService` — multi-doctype + exclusión `advance_id` + escritura compare-and-set atómica

**Files:**
- Modify: `src/Service/GroupedInvoiceService.php` (constructor `:29-38`, `validateGrouping` `:62-111`, `addInvoices` `:120-138`, `getAvailableInvoices` `:196-227`)
- Test: `tests/TestCase/Service/GroupedInvoiceServiceTest.php` (ampliar)

**Interfaces:**
- Produces: constructor acepta `string|array $documentType` (normaliza a `list<string> $documentTypes`); `validateGrouping()` acepta cualquiera de los doctypes y rechaza filas con `advance_id != null`; `addInvoices()` escribe con un `updateAll` condicional (`{fkField} IS null` + `advance_id IS null`) y retorna error si no vincula TODAS las filas; `getAvailableInvoices()` filtra `document_type IN` + `advance_id IS null`. Consumido por Task 2.
- Consumes: nada nuevo.

- [ ] **Step 1: Tests que fallan** — añadir a `GroupedInvoiceServiceTest.php` (respetar el estilo del archivo; el helper `_service()` existente construye con `documentType: DOCTYPE_REINTEGRO` — NO tocarlo; usar un helper local nuevo para el caso Caja Menor multi-doctype):

```php
    private function _cajaMenorService(): GroupedInvoiceService
    {
        return new GroupedInvoiceService(
            documentType: [InvoiceConstants::DOCTYPE_CAJA_MENOR, InvoiceConstants::DOCTYPE_RECIBO_CAJA],
            fkField: 'petty_cash_record_id',
            recordTableName: 'PettyCashRecords',
            fkLabel: 'Caja Menor',
            historyService: $this->createMock(HistoryServiceInterface::class),
            linkableStatus: [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD],
        );
    }

    public function testValidateGroupingAcceptsBothCajaMenorAndReciboDeCaja(): void
    {
        $cajaMenor = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $reciboLibre = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->assertSame([], $this->_cajaMenorService()->validateGrouping([$cajaMenor->id, $reciboLibre->id]));
    }

    public function testValidateGroupingRejectsReciboLinkedToAdvance(): void
    {
        $reciboConAnticipo = InvoiceFactory::new(['advance_id' => 999])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $errors = $this->_cajaMenorService()->validateGrouping([$reciboConAnticipo->id]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('anticipo', mb_strtolower(implode(' ', $errors)));
    }

    public function testValidateGroupingRejectsUnlinkableDoctypeWithGenericMessage(): void
    {
        // El mensaje nuevo lista los doctypes vinculables derivados de $documentTypes.
        // Servicio de Reintegro → debe incluir "Reintegro" (fkLabel) y el doctype vinculable.
        $factura = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_FACTURA])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $errors = $this->_service(InvoiceConstants::STATUS_APROBACION)->validateGrouping([$factura->id]);
        $this->assertNotEmpty($errors);
        // Discrimina el copy NUEVO: la porción "vinculable a Reintegro (Reintegro)" no existía antes.
        $this->assertStringContainsString('vinculable a Reintegro', implode(' ', $errors));
    }

    public function testAddInvoicesLinksFreeReciboAndValidationRejectsAdvanceLinked(): void
    {
        // NOTA: el rechazo del RC-con-advance_id lo hace validateGrouping (early-return),
        // NO el compare-and-set del updateAll (ambos comparten criterio; la rama de aborto
        // del compare-and-set solo dispara bajo concurrencia real). Este test verifica el
        // comportamiento observable; la atomicidad del updateAll se valida por review del diff.
        $record = PettyCashRecordFactory::new()->save();
        $svc = $this->_cajaMenorService();

        $reciboLibre = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $this->assertSame([], $svc->addInvoices($record, [(int)$reciboLibre->id]));
        $freshLibre = TableRegistry::getTableLocator()->get('Invoices')->get($reciboLibre->id);
        $this->assertSame((int)$record->id, (int)$freshLibre->petty_cash_record_id);

        // Un RC con advance_id NO queda vinculado a caja menor (fila intacta).
        $reciboConAnticipo = InvoiceFactory::new(['advance_id' => 999])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $errors = $svc->addInvoices($record, [(int)$reciboConAnticipo->id]);
        $this->assertNotEmpty($errors);
        $freshLinked = TableRegistry::getTableLocator()->get('Invoices')->get($reciboConAnticipo->id);
        $this->assertNull($freshLinked->petty_cash_record_id);
    }

    public function testAddInvoicesToleratesDuplicateIds(): void
    {
        // Regresión de I3: el loop viejo era idempotente ante ids repetidos; el compare-and-set
        // con count() crudo daría falso "no disponible". array_unique lo evita.
        $record = PettyCashRecordFactory::new()->save();
        $recibo = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->assertSame([], $this->_cajaMenorService()->addInvoices($record, [(int)$recibo->id, (int)$recibo->id]));
    }

    public function testGetAvailableInvoicesExcludesReciboLinkedToAdvance(): void
    {
        $svc = $this->_cajaMenorService();
        // issue_date reciente: getAvailableInvoices aplica un lookback por defecto de 90 días
        // cuando no hay date_from (la factory genera fechas aleatorias fuera de la ventana).
        $today = date('Y-m-d');
        $libre = InvoiceFactory::new(['issue_date' => $today])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $conAnticipo = InvoiceFactory::new(['issue_date' => $today, 'advance_id' => 999])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $ids = array_map('intval', $svc->getAvailableInvoices()->all()->extract('id')->toList());
        $this->assertContains((int)$libre->id, $ids);
        $this->assertNotContains((int)$conAnticipo->id, $ids);
    }
```

Añadir al `use` del test: `use Cake\ORM\TableRegistry;` (si no está).

- [ ] **Step 2: Verificar que fallan** — `vendor/bin/phpunit tests/TestCase/Service/GroupedInvoiceServiceTest.php` → FAIL (el servicio aún no acepta array en `documentType` / no filtra `advance_id`).

- [ ] **Step 3: Implementar** en `src/Service/GroupedInvoiceService.php`.

Constructor — cambiar `documentType` a `string|array` y normalizar (espejo de `linkableStatus`):

```php
    /**
     * @var list<string>
     */
    private readonly array $documentTypes;

    public function __construct(
        string|array $documentType,
        private readonly string $fkField,
        private readonly string $recordTableName,
        private readonly string $fkLabel,
        private readonly HistoryServiceInterface $historyService,
        string|array $linkableStatus = InvoiceConstants::STATUS_CONTABILIDAD,
    ) {
        $this->documentTypes = array_values((array)$documentType);
        $this->linkableStatuses = array_values((array)$linkableStatus);
    }
```

(El parámetro `$documentType` deja de ser promovido: se guarda como `$this->documentTypes`.)

En `validateGrouping()`, reemplazar el bloque del `foreach` (el check de doctype + añadir el de `advance_id`):

```php
            if (!in_array($invoice->document_type, $this->documentTypes, true)) {
                $errors[] = sprintf(
                    'La factura #%s no es un tipo vinculable a %s (%s).',
                    $invoice->invoice_number ?? $invoice->id,
                    $this->fkLabel,
                    implode(' o ', $this->documentTypes),
                );
            }
            if (!empty($invoice->advance_id)) {
                $errors[] = sprintf(
                    'La factura #%s ya está vinculada a un anticipo.',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
```

(Los checks existentes de `pipeline_status` y `{fkField}` se conservan sin cambios.)

En `addInvoices()`, reemplazar el loop incondicional por un compare-and-set atómico:

```php
    public function addInvoices(object $record, array $invoiceIds): array
    {
        // Deduplicar: el compare-and-set compara filas afectadas contra count($invoiceIds);
        // sin esto, ids repetidos (POST malformado) darían un falso "no disponible".
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));

        $errors = $this->validateGrouping($invoiceIds);
        if (!empty($errors)) {
            return $errors;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        // Compare-and-set atómico: solo vincula filas que SIGUEN libres de ambos FKs.
        // Garantía de exclusividad D1 bajo concurrencia — NO borrar la cláusula
        // `advance_id IS null` creyéndola redundante con validateGrouping: ese check es
        // read-then-write; esta es la escritura condicional que cierra la carrera.
        $affected = $invoicesTable->updateAll(
            [$this->fkField => $record->id],
            [
                'id IN' => $invoiceIds,
                $this->fkField . ' IS' => null,
                'advance_id IS' => null,
            ],
        );
        if ($affected !== count($invoiceIds)) {
            return ['Una o más facturas ya no están disponibles para vincular. Refresque e intente de nuevo.'];
        }

        $this->calculateAndSaveTotal($record);

        return [];
    }
```

En `getAvailableInvoices()`, cambiar el `where` de doctype y añadir `advance_id`:

```php
            ->where([
                'Invoices.document_type IN' => $this->documentTypes,
                'Invoices.pipeline_status IN' => $this->linkableStatuses,
                "Invoices.{$this->fkField} IS" => null,
                'Invoices.advance_id IS' => null,
            ])
```

- [ ] **Step 4: Verificar** — `vendor/bin/phpunit tests/TestCase/Service/GroupedInvoiceServiceTest.php` → PASS. Regresión: `vendor/bin/phpunit tests/TestCase/Service/ --filter "Refund|PettyCash|Grouped"` → verde (Reintegros sigue igual). `vendor/bin/phpcs src/Service/GroupedInvoiceService.php` → cero nuevas.

- [ ] **Step 5: Commit**

```bash
git add src/Service/GroupedInvoiceService.php tests/TestCase/Service/GroupedInvoiceServiceTest.php
git commit -m "feat: GroupedInvoiceService acepta multiples doctypes + exclusion atomica por advance_id"
```

---

### Task 2: `PettyCashPipelineService` registra `Recibo de Caja` + integración del flujo

**Files:**
- Modify: `src/Service/PettyCashPipelineService.php:49-55` (el `new GroupedInvoiceService(...)`)
- Test: `tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php` (ampliar)

**Interfaces:**
- Consumes: `GroupedInvoiceService` multi-doctype (Task 1).
- Produces: Caja Menor acepta `[Caja menor, Recibo de Caja]` como candidatas; un RC libre en `aprobacion` se vincula y auto-avanza a `contabilidad`; un RC con `advance_id` no es vinculable.

- [ ] **Step 1: Test que falla** — añadir a `PettyCashPipelineServiceTest.php` (usar el helper de construcción del service que ya exista en el archivo; si construye `PettyCashPipelineService` a mano, reusarlo). Sembrar soporte con `InvoiceDocumentFactory` (el gate de soporte de caja menor aplica al RC):

```php
    public function testLinksReciboDeCajaAndAutoAdvances(): void
    {
        $record = PettyCashRecordFactory::new()->save();
        $svc = $this->buildService(); // helper existente en este archivo
        $user = UserFactory::new()->save();

        $recibo = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $recibo->id])->save();

        $errors = $svc->addInvoices($record, [(int)$recibo->id], (int)$user->id);

        $this->assertSame([], $errors);
        $fresh = TableRegistry::getTableLocator()->get('Invoices')->get($recibo->id);
        $this->assertSame((int)$record->id, (int)$fresh->petty_cash_record_id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $fresh->pipeline_status);
    }

    public function testDoesNotLinkReciboAlreadyOnAnAdvance(): void
    {
        $record = PettyCashRecordFactory::new()->save();
        $svc = $this->buildService();
        $user = UserFactory::new()->save();

        $recibo = InvoiceFactory::new(['advance_id' => 999])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $errors = $svc->addInvoices($record, [(int)$recibo->id], (int)$user->id);

        $this->assertNotEmpty($errors);
        $fresh = TableRegistry::getTableLocator()->get('Invoices')->get($recibo->id);
        $this->assertNull($fresh->petty_cash_record_id);
    }
```

Verificar el nombre real del helper de construcción del service (`grep -n "buildService\|new PettyCashPipelineService" tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php`) y usarlo tal cual. Añadir los `use` faltantes (`InvoiceDocumentFactory`, `UserFactory`, `TableRegistry`) si no están.

- [ ] **Step 2: Verificar que falla** — `vendor/bin/phpunit tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php --filter testLinksReciboDeCajaAndAutoAdvances` → FAIL (el RC no es un doctype vinculable todavía → `addInvoices` retorna error, `assertSame([], $errors)` falla). Nota: `testDoesNotLinkReciboAlreadyOnAnAdvance` **ya pasa antes del fix** (el RC se rechaza por doctype-mismatch); es una regresión que confirma que la exclusión de T1 se propaga por la capa PettyCash, no un RED de esta task. El RED que discrimina el one-liner es `testLinksReciboDeCajaAndAutoAdvances`.

- [ ] **Step 3: Implementar** — en `src/Service/PettyCashPipelineService.php` (`:50`), cambiar el `documentType` del `new GroupedInvoiceService(...)`:

```php
            documentType: [InvoiceConstants::DOCTYPE_CAJA_MENOR, InvoiceConstants::DOCTYPE_RECIBO_CAJA],
```

(Nada más cambia: el auto-avance y el gate de soporte ya son doctype-agnósticos. **NO tocar `src/Service/PettyCashService.php`** — es un gemelo muerto: tiene su propio `new GroupedInvoiceService(documentType: DOCTYPE_CAJA_MENOR)` pero no se instancia en ningún lado de `src/`; el controller vivo usa `PettyCashPipelineService`.)

- [ ] **Step 4: Verificar** — `vendor/bin/phpunit tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php` → PASS. Regresión: `vendor/bin/phpunit tests/TestCase/ --filter PettyCash` → verde. `vendor/bin/phpcs src/Service/PettyCashPipelineService.php` → cero nuevas.

- [ ] **Step 5: Commit**

```bash
git add src/Service/PettyCashPipelineService.php tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php
git commit -m "feat: caja menor acepta Recibos de Caja como facturas vinculables"
```

---

### Task 3: Exclusividad inversa en Anticipos + feedback de vínculo parcial

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php:133-144` (el `updateAll` condicional de `linkInvoices`)
- Modify: `src/Controller/AdvancesController.php:579-586` (`$conditions` de `linkCandidates`) y `:644-649` (flash de `linkInvoices` → feedback parcial)
- Test: `tests/TestCase/Service/AdvanceLegalizationLinkFilterTest.php` (ampliar)

**Interfaces:**
- Consumes: nada de Tasks 1-2 (independiente; cierra la exclusividad del lado Anticipo).
- Produces: `AdvanceLegalizationService::linkInvoices` no vincula un RC con `petty_cash_record_id != null`; `linkCandidates` no lo ofrece; el flash informa "N de M" cuando `linked < solicitadas`.

- [ ] **Step 1: Test que falla** — añadir a `AdvanceLegalizationLinkFilterTest.php` (espejo de `testDoesNotLinkLegalizacionInvoiceInContabilidad`, pero con un RC ya en caja menor):

```php
    public function testDoesNotLinkReciboAlreadyOnPettyCash(): void
    {
        $anticipo = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new([
            'advance_invoice_id' => $anticipo->id,
        ])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        // RC en aprobacion pero YA vinculado a un registro de caja menor.
        $recibo = InvoiceFactory::new(['petty_cash_record_id' => 777])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $result = $this->_service()->linkInvoices($leg, [(int)$recibo->id], (int)$anticipo->registered_by ?: 1);
        // No-op: el compare-and-set no alcanza al RC ya en caja menor.
        $this->assertTrue($result->success);
        $this->assertSame(0, $result->data['linked']);

        $reloaded = TableRegistry::getTableLocator()->get('Invoices')->get($recibo->id);
        $this->assertNull($reloaded->advance_id);
    }
```

- [ ] **Step 2: Verificar que falla** — `vendor/bin/phpunit tests/TestCase/Service/AdvanceLegalizationLinkFilterTest.php --filter testDoesNotLinkReciboAlreadyOnPettyCash` → FAIL (hoy el `updateAll` no filtra `petty_cash_record_id`, así que vincularía el RC → `linked = 1`, `advance_id` seteado).

- [ ] **Step 3: Implementar.**

En `src/Service/AdvanceLegalizationService.php`, añadir la condición al `WHERE` del `updateAll` de `linkInvoices` (dentro del array de condiciones, junto a `advance_id IS null`):

```php
                    [
                        'id IN' => $invoiceIds,
                        'advance_id IS' => null,
                        'petty_cash_record_id IS' => null,
                        'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
                        'document_type IN' => [
                            InvoiceConstants::DOCTYPE_LEGALIZACION,
                            InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                        ],
                    ],
```

En `src/Controller/AdvancesController.php`, añadir la condición al `$conditions` de `linkCandidates` (`:579`):

```php
        $conditions = [
            'Invoices.advance_id IS' => null,
            'Invoices.petty_cash_record_id IS' => null,
            'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'Invoices.document_type IN' => [
                InvoiceConstants::DOCTYPE_LEGALIZACION,
                InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            ],
        ];
```

En `src/Controller/AdvancesController.php`, `linkInvoices` (`:644-649`), reportar vínculo parcial:

```php
        $result = $this->legalizationService->linkInvoices($leg, $invoiceIds, $userId);
        if ($result->success) {
            $linked = (int)($result->data['linked'] ?? 0);
            $requested = count($invoiceIds);
            if ($linked < $requested) {
                $this->Flash->warning(sprintf(
                    '%d de %d factura(s) vinculada(s); el resto ya no estaba disponible.',
                    $linked,
                    $requested,
                ));
            } else {
                $this->Flash->success($linked . ' factura(s) vinculada(s).');
            }
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al vincular.');
        }
```

- [ ] **Step 4: Verificar** — `vendor/bin/phpunit tests/TestCase/Service/AdvanceLegalizationLinkFilterTest.php` → PASS. Regresión: `vendor/bin/phpunit tests/TestCase/ --filter "Advance|Legalization"` → verde. `vendor/bin/phpcs src/Service/AdvanceLegalizationService.php src/Controller/AdvancesController.php` → cero nuevas. `php -l` no aplica (no hay templates tocados).

- [ ] **Step 5: Commit**

```bash
git add src/Service/AdvanceLegalizationService.php src/Controller/AdvancesController.php tests/TestCase/Service/AdvanceLegalizationLinkFilterTest.php
git commit -m "feat: exclusividad inversa Recibo de Caja (no vinculable a anticipo si ya esta en caja menor) + feedback parcial"
```

---

### Task 4: Verificación final

**Files:** — (solo verificación)

- [ ] **Step 1:** Suite completa `vendor/bin/phpunit` (timeout 900000). Criterio de verde: **Failures: 0 y Errors: 0** (ignorar exit 1 y notices/deprecations preexistentes; baseline 1069 tests). Si hay cascadas raras, re-correr UNA vez limpia (contaminación back-to-back conocida). NUNCA dos corridas concurrentes.
- [ ] **Step 2:** `vendor/bin/phpcs` sobre los 4 archivos `src/` tocados (`GroupedInvoiceService`, `PettyCashPipelineService`, `AdvanceLegalizationService`, `AdvancesController`) → cero violaciones nuevas respecto al baseline.
- [ ] **Step 3:** Smoke manual con `php bin/cake server` (opcional, para el usuario): (a) en el modal de vincular de un registro de Caja Menor aparecen Recibos de Caja libres además de facturas Caja menor; (b) vincular un RC en `aprobacion` lo lleva a `contabilidad`; (c) un RC ya vinculado a un anticipo NO aparece como candidato de Caja Menor, y viceversa.
- [ ] **Step 4:** Actualizar `CLAUDE.md` si corresponde: en la fila de `GroupedInvoiceService` (Key Services) mencionar que `documentType` acepta lista de doctypes y que Caja Menor vincula `Caja menor` + `Recibo de Caja` con exclusividad atómica respecto a anticipos. Commit: `docs: CLAUDE.md — Recibos de Caja vinculables a Caja Menor`.

---

## Self-review del plan (+ hallazgos de spi-plan-reviewer incorporados)

- **Cobertura spec→tasks:** §3.1 (multi-doctype + advance_id + compare-and-set + copy genérico) → Task 1; §3.2 (registrar RC en PettyCash) → Task 2; §3.3 (exclusividad inversa + feedback parcial) → Task 3; §3.4 (RBAC, sin cambio) → sin task (se reusan gates vigentes, verificado en el spec); §4 casos borde → tests de T1 (rechazo RC con advance_id, estado final de fila) y T3 (RC con petty_cash_record_id no vinculable a anticipo); §5 criterios de aceptación → tests por task + T4 suite.
- **Consistencia de tipos:** `documentType: string|array` en el constructor de `GroupedInvoiceService` (T1) = usado con array en `PettyCashPipelineService` (T2) y con string en el helper `_service()` de Reintegro (preservado); `addInvoices(record, ids): array` conserva su firma pública (T1) — el caller `PettyCashPipelineService::addInvoices(record, ids, userId)` la envuelve sin cambios (y su transacción hace rollback si el compare-and-set retorna error, antes del auto-avance); `linkInvoices(...): ServiceResult` con `data['linked']` (T3) = leído por el controller.
- **Atomicidad (I2 del reviewer):** la rama de aborto del compare-and-set (`$affected !== count`) es inalcanzable determinísticamente en un test secuencial (validate y updateAll comparten criterio; solo divergen bajo concurrencia real). El test de T1 verifica el comportamiento observable; el `updateAll` condicional lleva un comentario en el código señalando que la garantía es por-review para que un refactor no lo borre creyéndolo redundante.
- **Duplicados (I3 del reviewer):** `addInvoices` deduplica con `array_unique` antes del compare-and-set (el loop viejo era idempotente ante ids repetidos; el count crudo daría falso "no disponible"). Cubierto por `testAddInvoicesToleratesDuplicateIds`. Aplica también a Reintegros (mismo endurecimiento).
- **Lookback de 90 días (I1 del reviewer):** los tests de `getAvailableInvoices` siembran `issue_date` reciente — sin eso la factory genera fechas aleatorias fuera de la ventana por defecto y el test fallaría por una razón ajena.
- **Copy genérico (M1 del reviewer):** el test asserta sobre la porción NUEVA del mensaje (`'vinculable a Reintegro'`), no sobre `fkLabel` a secas (que el mensaje viejo ya contenía) → discrimina el refactor de verdad.
- **Cobertura por-review (M4):** el mapeo `linked < requested → Flash->warning` de T3 Step 3 se verifica por review del diff + smoke manual (T4 Step 3); el servicio (`linked === 0`) sí tiene test que discrimina (T3 Step 1). Un flash delgado no justifica un IntegrationTest de controller nuevo.
- **Placeholders:** ninguno — todo el código está completo. Los "verificar el nombre real del helper" (T2 Step 1) son instrucciones de robustez contra números de línea, no placeholders de lógica.
