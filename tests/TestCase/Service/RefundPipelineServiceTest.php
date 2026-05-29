<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\RefundPipelineService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para RefundPipelineService::saveAndAdvance() (A2).
 *
 * Cobertura pure-unit (la suite es sin BD, ver tests/bootstrap.php): los dos
 * guards de entrada de saveAndAdvance — fail-fast por errores de la policy, y
 * skip del avance cuando el registro es terminal sin patch — que NO tocan
 * TableRegistry. El camino feliz (patch+save+advance con FOR UPDATE) toca BD y
 * se valida fuera de la suite, igual que el canon PettyCash.
 */
final class RefundPipelineServiceTest extends TestCase
{
    private function buildService(AuthorizationFacade $auth): RefundPipelineService
    {
        return new RefundPipelineService(
            $this->createStub(HistoryServiceInterface::class),
            $auth,
        );
    }

    public function testSaveAndAdvanceFailsWhenPolicyHasInlineErrors(): void
    {
        // canOperate=true => en CONTABILIDAD la policy exige accrual_date cuando accrued=true.
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $service = $this->buildService($auth);
        $record = new Refund([
            'id' => 1,
            'status' => RefundConstants::STATUS_CONTABILIDAD,
        ]);

        $result = $service->saveAndAdvance($record, 2, 9, ['accrued' => '1', 'accrual_date' => '']);

        $this->assertFalse($result->success);
        $this->assertSame(
            ['La fecha de causación es requerida cuando el registro está marcado como causado.'],
            $result->errors,
        );
    }

    public function testSaveAndAdvanceSkipsAdvanceWhenTerminalAndNoPatch(): void
    {
        // Estado PAGADA (terminal): isPagada()=true => no se intenta avanzar; en
        // PAGADA la policy no edita campos => patch vacío, sin tocar BD.
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $service = $this->buildService($auth);
        $record = new Refund([
            'id' => 1,
            'status' => RefundConstants::STATUS_PAGADA,
        ]);

        $result = $service->saveAndAdvance($record, 2, 9, []);

        $this->assertTrue($result->success);
        $this->assertFalse($result->data['advanced']);
        $this->assertNull($result->data['advanceWarning']);
        $this->assertSame([], $result->data['linkWarnings']);
    }
}
