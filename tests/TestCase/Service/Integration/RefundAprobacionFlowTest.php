<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\InvoiceHistoryService;
use App\Service\RefundPipelineService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RefundAprobacionFlowTest extends TestCase
{
    private function buildService(bool $canOperate = true): RefundPipelineService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn($canOperate);
        $auth->method('operableSteps')->willReturn([]);

        return new RefundPipelineService(new InvoiceHistoryService(), $auth);
    }

    public function testRegressFromContabilidadReturnsChildrenToAprobacion(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_CONTABILIDAD)->save();
        $invoice = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->regress($refund, $user->role_id, $user->id, 'motivo suficiente de regresion');
        $this->assertTrue($result->success, implode(' ', (array)$result->errors));

        $reloadedRefund = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $reloadedInvoice = TableRegistry::getTableLocator()->get('Invoices')->get($invoice->id);
        $this->assertSame(RefundConstants::STATUS_APROBACION, $reloadedRefund->status);
        $this->assertSame(InvoiceConstants::STATUS_APROBACION, $reloadedInvoice->pipeline_status);
    }
}
