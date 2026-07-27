<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de las transiciones de estado de
 * AdvanceLegalizationService.
 *
 * Cada caso siembra la legalización DIRECTAMENTE en el estado de entrada con
 * AdvanceLegalizationFactory->withStatus()->forAdvance() y relee desde la BD
 * tras la operación para verificar la persistencia. El EventManager se construye
 * aislado (sin subscribers) para que el cierre a `legalizada` no dispare efectos
 * colaterales (promoción de facturas vinculadas) fuera del alcance del test.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy), por eso no se declara `$fixtures`.
 */
final class AdvanceLegalizationTransitionsTest extends TestCase
{
    /** Payload de causación válido para las salidas del paso Contabilidad. */
    private const ACCOUNTING = [
        'accrued' => true,
        'accrual_date' => '2026-06-23',
        'ready_for_payment' => 'Si',
    ];

    /**
     * Construye el servicio con un EventManager aislado y los history/document
     * services reales. El stateRegistry queda en su default.
     *
     * @return \App\Service\AdvanceLegalizationService
     */
    private function buildService(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            new EventManager(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
    }

    /**
     * markExact: en Contabilidad, con case_type null y diferencia ≈0 (anticipo
     * 1000 + facturas de legalización vinculadas que suman 1000), cierra la
     * legalización en `legalizada` con case_type='exacto' y legalized_at fijado.
     *
     * @return void
     */
    public function testMarkExactClosesLegalizationWhenDifferenceIsZero(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(600.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(400.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->markExact($leg, self::ACCOUNTING, $user->id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_LEGALIZADA, $persisted->status);
        $this->assertSame(AdvanceConstants::CASE_EXACTO, $persisted->case_type);
        $this->assertNotNull($persisted->legalized_at);
    }

    /**
     * markExact: cuando la diferencia no es cero (anticipo 1000 vs vinculadas
     * 700) falla y la legalización permanece en Contabilidad.
     *
     * @return void
     */
    public function testMarkExactFailsWhenDifferenceIsNotZero(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(700.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->markExact($leg, self::ACCOUNTING, $user->id);

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }

    /**
     * markExact con causación incompleta falla contra el gate del
     * ContabilidadState aunque la diferencia sea cero.
     */
    public function testMarkExactFailsWhenAccountingIsIncomplete(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $incomplete = ['accrued' => true, 'accrual_date' => '2026-06-23', 'ready_for_payment' => null];
        $result = $this->buildService()->markExact($leg, $incomplete, $user->id);

        $this->assertFalse($result->success);
        $this->assertSame('Campo "Lista para Pago" es requerido', $result->firstError());

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }

    /**
     * returnToAprobacion: desde Revisión y Firmas con un motivo no vacío,
     * devuelve la legalización a Aprobación (paso anterior; persistido en BD).
     *
     * @return void
     */
    public function testReturnToAprobacionBouncesBackWithReason(): void
    {
        $leg = AdvanceLegalizationFactory::new()
            ->withStatus(AdvanceConstants::STATUS_REVISION_FIRMAS)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->returnToAprobacion(
            $leg,
            'La relación de facturas tiene un error de monto.',
            $user->id,
        );

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_APROBACION, $persisted->status);
    }

    /**
     * returnToAprobacion: con motivo vacío falla y la legalización permanece en
     * Revisión y Firmas.
     *
     * @return void
     */
    public function testReturnToAprobacionFailsWithEmptyReason(): void
    {
        $leg = AdvanceLegalizationFactory::new()
            ->withStatus(AdvanceConstants::STATUS_REVISION_FIRMAS)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->returnToAprobacion($leg, '   ', $user->id);

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_REVISION_FIRMAS, $persisted->status);
    }

    /**
     * moveToAprobacion (CAMINO DE ERROR): en Validación sin requisitos cumplidos
     * (sin facturas vinculadas y sin documento de relación pendiente),
     * ValidacionState::validateAdvance retorna errores y la legalización NO avanza
     * a `aprobacion`.
     *
     * (En el flujo nuevo `validacion → revision_firmas` directo ya no existe: el
     * salto que gatea Validación y corre ValidacionState es `moveToAprobacion`.)
     *
     * @return void
     */
    public function testMoveToAprobacionFailsWhenRequirementsAreNotMet(): void
    {
        $leg = AdvanceLegalizationFactory::new()
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->moveToAprobacion($leg, $user->id);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $persisted->status);
    }

    /**
     * markSigned (happy path): con una firma pendiente sembrada en
     * advance_legalization_signatures, la legalización en Revisión y Firmas
     * marca la firma como `signed` y avanza a Contabilidad.
     *
     * @return void
     */
    public function testMarkSignedMarksSignatureAndAdvancesToContabilidad(): void
    {
        $leg = AdvanceLegalizationFactory::new()
            ->withStatus(AdvanceConstants::STATUS_REVISION_FIRMAS)->save();
        $user = UserFactory::new()->save();

        $signatures = $this->fetchTable('AdvanceLegalizationSignatures');
        $signature = $signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion-facturas.pdf',
            'file_name' => 'relacion-facturas.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]);
        $signatures->saveOrFail($signature);

        $result = $this->buildService()->markSigned($leg, $user->id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);

        $persistedSignature = $signatures->get($signature->id);
        $this->assertSame(AdvanceConstants::SIGNATURE_SIGNED, $persistedSignature->signature_status);
        $this->assertSame($user->id, $persistedSignature->signed_by_user_id);
    }
}
