<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Application;
use Cake\ORM\TableRegistry;

/**
 * Subclase de Application usada solo por
 * `RefundsControllerGroupSupersessionTest::testLinkInvoicesSupersedesActiveInvoiceApprovals`
 * (vía `ContainerStubTrait::configApplication()`): reaplica el swap de
 * `InvoiceApprovals` → `InvoiceApprovalsTableWithoutTokenHashColumn` DESPUÉS de
 * `parent::bootstrap()`, que es cuando `App\Application::bootstrap()` reemplaza
 * el `TableLocator` global por uno nuevo en cada request simulado por
 * `IntegrationTestTrait` (un swap hecho antes del request quedaría descartado).
 * Ver docblock de `RefundsControllerGroupSupersessionTest` para el detalle
 * completo del drift que motiva este seam.
 */
class TestApplicationWithoutInvoiceApprovalsTokenHash extends Application
{
    public function bootstrap(): void
    {
        parent::bootstrap();

        TableRegistry::getTableLocator()->get('InvoiceApprovals', [
            'className' => InvoiceApprovalsTableWithoutTokenHashColumn::class,
        ]);
    }
}
