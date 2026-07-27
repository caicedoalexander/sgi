<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\NotificationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationApprovalServiceTest extends TestCase
{
    private function _service(): AdvanceLegalizationApprovalService
    {
        return new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
    }

    private function _legInAprobacion(): object
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        return $leg;
    }

    public function testAllApprovedSetsAreaApprovalOnChildren(): void
    {
        $leg = $this->_legInAprobacion();
        $child = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => $leg->advance_invoice_id,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $u1 = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($leg, [$u1->id], 'https://x', (int)$u1->id);

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);
        $svc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');

        $this->assertTrue($svc->areAllApproved((int)$leg->id));
        $reloaded = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloaded->area_approval);
    }

    public function testRejectRegressesLegToValidacion(): void
    {
        $leg = $this->_legInAprobacion();
        $u1 = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($leg, [$u1->id], 'https://x', (int)$u1->id);

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);
        $svc->processResponse($secret, ApprovalConstants::ACTION_REJECT, 'faltan soportes', '127.0.0.1', 'phpunit');

        $reloaded = TableRegistry::getTableLocator()->get('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $reloaded->status);
    }
}
