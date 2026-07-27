<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\NotificationService;
use App\Service\RefundApprovalService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RefundApprovalServiceTest extends TestCase
{
    private function _service(): RefundApprovalService
    {
        return new RefundApprovalService($this->createMock(NotificationService::class));
    }

    public function testAssignThenAllApproved(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        $svc = $this->_service();

        $svc->assignApprovers($refund, [$u1->id, $u2->id], 'https://x', (int)$u1->id);
        $this->assertFalse($svc->areAllApproved((int)$refund->id));

        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        foreach ($table->find()->where(['refund_id' => $refund->id])->all() as $a) {
            $secret = $svc->applyFreshToken($a);
            $table->saveOrFail($a);
            $svc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');
        }
        $this->assertTrue($svc->areAllApproved((int)$refund->id));
    }

    public function testRejectRegressesRefundToAgrupacion(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $u1 = UserFactory::new()->save();
        $svc = $this->_service();

        $svc->assignApprovers($refund, [$u1->id], 'https://x', (int)$u1->id);
        $a = TableRegistry::getTableLocator()->get('RefundApprovals')
            ->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        TableRegistry::getTableLocator()->get('RefundApprovals')->saveOrFail($a);

        $svc->processResponse($secret, ApprovalConstants::ACTION_REJECT, 'faltan soportes', '127.0.0.1', 'phpunit');

        $reloaded = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $this->assertSame('agrupacion', $reloaded->status);
    }

    public function testAllApprovedSetsChildInvoicesApproved(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $inv1 = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $inv2 = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        $svc = $this->_service();

        $svc->assignApprovers($refund, [$u1->id, $u2->id], 'https://x', (int)$u1->id);

        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        foreach ($table->find()->where(['refund_id' => $refund->id])->all() as $a) {
            $secret = $svc->applyFreshToken($a);
            $table->saveOrFail($a);
            $svc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $reloadedInv1 = $invoices->get($inv1->id);
        $reloadedInv2 = $invoices->get($inv2->id);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloadedInv1->area_approval);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloadedInv2->area_approval);
    }

    public function testRejectInvalidatesSiblingPendingToken(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        $svc = $this->_service();

        $svc->assignApprovers($refund, [$u1->id, $u2->id], 'https://x', (int)$u1->id);

        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        $first = $table->find()->where(['refund_id' => $refund->id, 'user_id' => $u1->id])->firstOrFail();
        $second = $table->find()->where(['refund_id' => $refund->id, 'user_id' => $u2->id])->firstOrFail();
        $secondId = $second->id;

        $secret1 = $svc->applyFreshToken($first);
        $table->saveOrFail($first);

        $svc->processResponse($secret1, ApprovalConstants::ACTION_REJECT, 'motivo', '127.0.0.1', 'phpunit');

        $reloadedRefund = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_AGRUPACION, $reloadedRefund->status);

        $reloadedSecond = $table->get($secondId);
        $this->assertNull($reloadedSecond->token_hash);

        $observations = TableRegistry::getTableLocator()->get('RefundObservations')
            ->find()
            ->where(['refund_id' => $refund->id, 'type' => RefundConstants::OBSERVATION_TYPE_REGRESSION])
            ->all();
        $this->assertCount(1, $observations);
    }
}
