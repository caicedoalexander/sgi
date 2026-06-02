<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\NoveltyConstants;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyPipelineService;
use App\Service\Pipeline\Novelty\Policy\NoveltyFieldAccessPolicy;
use App\Test\Factory\EmployeeNoveltyFactory;
use App\Test\Factory\NoveltyLiquidationDocFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de NoveltyPipelineService, enfocados en los
 * caminos de GRUPO sobre NoveltyLiquidationDoc (`validateGroupTransition`,
 * `denialReasonForAdvanceGroup`) y en la reasignación a un documento de
 * liquidación existente (`assignToExistingLiquidationDoc`).
 *
 * Los States de grupo consultan la BD vía NoveltyLiquidationGuard (documento de
 * liquidación subido, firmas completas), por lo que el valor de estos casos está
 * en las rutas de validación con datos reales releídos desde la BD.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy = FactoryTransactionStrategy), por eso no se declara
 * `$fixtures`.
 */
final class NoveltyPipelineServiceGroupTest extends TestCase
{
    /**
     * Construye el servicio contra la BD real con un stub de RBAC. Las policies
     * y el history service son reales; el registry y los States usan sus
     * defaults internos (Guard real que consulta la BD).
     *
     * @param bool $canOperate Valor que devuelve el stub de canOperate().
     * @return \App\Service\NoveltyPipelineService
     */
    private function buildService(bool $canOperate = true): NoveltyPipelineService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn($canOperate);
        $auth->method('operableSteps')->willReturn([]);

        return new NoveltyPipelineService(
            $auth,
            new NoveltyFieldAccessPolicy($auth),
            null,
            new NoveltyHistoryService(),
        );
    }

    /**
     * validateGroupTransition: un documento en Contabilidad SIN documento de
     * liquidación subido debe retornar el error del Guard (ContabilidadState).
     *
     * @return void
     */
    public function testValidateGroupTransitionRequiresUploadedLiquidationDocument(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_CONTABILIDAD)
            ->save();

        $errors = $this->buildService()->validateGroupTransition($doc);

        $this->assertNotEmpty($errors);
        $this->assertContains('Debe subir el documento de liquidación antes de avanzar.', $errors);
    }

    /**
     * denialReasonForAdvanceGroup: con canOperate=false el rol no puede operar
     * el paso del documento de liquidación → UNAUTHORIZED.
     *
     * @return void
     */
    public function testDenialReasonForAdvanceGroupUnauthorizedWhenRoleCannotOperate(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_CONTABILIDAD)
            ->save();

        $denial = $this->buildService(false)->denialReasonForAdvanceGroup($doc, 4);

        $this->assertSame(DenialReason::UNAUTHORIZED, $denial);
    }

    /**
     * denialReasonForAdvanceGroup: un documento en estado terminal (pagada)
     * retorna TERMINAL_STATE aun cuando el rol pueda operar.
     *
     * @return void
     */
    public function testDenialReasonForAdvanceGroupTerminalWhenDocIsPaid(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_PAGADA)
            ->save();

        $denial = $this->buildService(true)->denialReasonForAdvanceGroup($doc, 4);

        $this->assertSame(DenialReason::TERMINAL_STATE, $denial);
    }

    /**
     * assignToExistingLiquidationDoc: liga la novedad (en aprobacion) a un
     * documento existente, la mueve a contabilidad y persiste el
     * liquidation_doc_id (releído desde la BD).
     *
     * @return void
     */
    public function testAssignToExistingLinksNoveltyAndMovesToContabilidad(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_CONTABILIDAD)
            ->save();
        $novelty = EmployeeNoveltyFactory::new()
            ->withStatus(NoveltyConstants::STATUS_APROBACION)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->assignToExistingLiquidationDoc($novelty, (int)$doc->id, (int)$user->id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('EmployeeNovelties')->get($novelty->id);
        $this->assertSame((int)$doc->id, (int)$persisted->liquidation_doc_id);
        $this->assertSame(NoveltyConstants::STATUS_CONTABILIDAD, $persisted->pipeline_status);
    }
}
