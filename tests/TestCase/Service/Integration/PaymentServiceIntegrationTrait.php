<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Service\AdvanceLegalizationService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy;
use Cake\Event\EventManager;

/**
 * Construcción compartida del InvoicePaymentService para los tests de integración.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy = FactoryTransactionStrategy).
 *
 * - InvoiceHistoryService real (asienta en invoice_histories, tabla de la BD test).
 * - DocumentTypePolicyFactory real (AdvanceLegalizationService stubbeado; no se
 *   ejercita en pagos no-refund).
 * - EventManager aislado (sin subscribers) para que confirmPayment/refund no
 *   disparen efectos colaterales fuera del alcance del test.
 */
trait PaymentServiceIntegrationTrait
{
    private function buildPaymentService(): InvoicePaymentService
    {
        return new InvoicePaymentService(
            new InvoiceHistoryService(),
            new DocumentTypePolicyFactory(
                new StandardDocumentTypePolicy(),
                new AnticipoDocumentTypePolicy($this->createStub(AdvanceLegalizationService::class)),
                new LegalizacionDocumentTypePolicy(),
            ),
            new EventManager(),
        );
    }
}
