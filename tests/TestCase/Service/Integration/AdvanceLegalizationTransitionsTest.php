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

        $result = $this->buildService()->markExact($leg, $user->id);

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

        $result = $this->buildService()->markExact($leg, $user->id);

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }

    /**
     * returnToValidacion: desde Revisión y Firmas con un motivo no vacío,
     * devuelve la legalización a Validación (persistido en BD).
     *
     * @return void
     */
    public function testReturnToValidacionBouncesBackWithReason(): void
    {
        $leg = AdvanceLegalizationFactory::new()
            ->withStatus(AdvanceConstants::STATUS_REVISION_FIRMAS)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->returnToValidacion(
            $leg,
            'La relación de facturas tiene un error de monto.',
            $user->id,
        );

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $persisted->status);
    }

    /**
     * returnToValidacion: con motivo vacío falla y la legalización permanece en
     * Revisión y Firmas.
     *
     * @return void
     */
    public function testReturnToValidacionFailsWithEmptyReason(): void
    {
        $leg = AdvanceLegalizationFactory::new()
            ->withStatus(AdvanceConstants::STATUS_REVISION_FIRMAS)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->returnToValidacion($leg, '   ', $user->id);

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_REVISION_FIRMAS, $persisted->status);
    }

    /**
     * moveToRevisionFirmas (CAMINO DE ERROR): en Validación sin requisitos
     * cumplidos (sin facturas vinculadas y sin documento de relación pendiente),
     * validateAdvance retorna errores y la legalización NO avanza.
     *
     * El happy path requiere sembrar una factura vinculada en Contabilidad y un
     * documento de relación pendiente vía guard (hasPendingRelationDocument);
     * se cubre el camino de error por pragmatismo (ver notes).
     *
     * @return void
     */
    public function testMoveToRevisionFirmasFailsWhenRequirementsAreNotMet(): void
    {
        $leg = AdvanceLegalizationFactory::new()
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->moveToRevisionFirmas($leg, $user->id);

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
