<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoiceFieldAccessPolicy — campos/secciones editables rol-aware.
 *
 * Foco en la invariante del canon (CLAUDE.md): un rol SIN `canOperate` del paso
 * obtiene patch/campos vacíos (NO `unset($roleId)` role-blind). La lógica vive en
 * la base PipelineFieldPolicy; aquí se ejercita vía la subclase concreta de Invoice
 * (mapeos FIELDS_BY_STEP / SECTIONS_BY_STEP / alwaysVisible=['ledger']).
 *
 * Suite pura (sin DB): AuthorizationFacade (interfaz) mockeado para controlar
 * canOperate() y operableSteps().
 */
final class InvoiceFieldAccessPolicyTest extends TestCase
{
    private function policy(AuthorizationFacade $auth): InvoiceFieldAccessPolicy
    {
        return new InvoiceFieldAccessPolicy($auth);
    }

    public function testGetEditableFieldsReturnsEmptyWhenRoleCannotOperate(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $fields = $this->policy($auth)->getEditableFields(3, InvoiceConstants::STATUS_APROBACION);

        $this->assertSame([], $fields);
    }

    public function testGetEditableFieldsReturnsStepFieldsWhenRoleCanOperate(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $fields = $this->policy($auth)->getEditableFields(3, InvoiceConstants::STATUS_APROBACION);

        $this->assertContains('invoice_number', $fields);
        $this->assertContains('amount', $fields);
        $this->assertContains('dian_validation', $fields);
    }

    public function testGetEditableFieldsForContabilidadStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $fields = $this->policy($auth)->getEditableFields(2, InvoiceConstants::STATUS_CONTABILIDAD);

        $this->assertSame(['accrued', 'accrual_date', 'ready_for_payment'], $fields);
    }

    public function testGetEditableFieldsEmptyForTreasuryStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        // Tesorería no edita campos del header (registra pagos vía InvoicePaymentService).
        $fields = $this->policy($auth)->getEditableFields(4, InvoiceConstants::STATUS_TESORERIA);

        $this->assertSame([], $fields);
    }

    public function testGetVisibleSectionsAlwaysIncludesLedger(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('operableSteps')->willReturn([]);

        $sections = $this->policy($auth)->getVisibleSections(3);

        $this->assertSame(['ledger'], $sections);
    }

    public function testGetVisibleSectionsUnionsOperableSteps(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('operableSteps')->willReturn([
            InvoiceConstants::STATUS_APROBACION,
            InvoiceConstants::STATUS_CONTABILIDAD,
        ]);

        $sections = $this->policy($auth)->getVisibleSections(2);

        $this->assertEqualsCanonicalizing(['ledger', 'revision', 'accounting'], $sections);
    }

    public function testFilterEntityDataKeepsOnlyEditableFields(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $result = $this->policy($auth)->filterEntityData(
            ['invoice_number' => 'F-1', 'amount' => 100, 'accrued' => true, 'foo' => 'bar'],
            3,
            InvoiceConstants::STATUS_APROBACION,
        );

        // accrued (de contabilidad) y foo (desconocido) se descartan en aprobacion.
        $this->assertSame(['invoice_number' => 'F-1', 'amount' => 100], $result->patch);
    }

    public function testFilterEntityDataReturnsEmptyPatchWhenRoleCannotOperate(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $result = $this->policy($auth)->filterEntityData(
            ['invoice_number' => 'F-1', 'amount' => 100],
            3,
            InvoiceConstants::STATUS_APROBACION,
        );

        $this->assertSame([], $result->patch);
    }
}
