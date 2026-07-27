<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * La bandeja "Pendientes de Legalización" solo lista las legalizaciones cuyo
 * paso actual el rol puede operar, como las bandejas de los otros 5 módulos.
 */
class AdvancesPendingLegalizationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @param array<int, string> $steps Pasos operables. Vacío = ningún paso.
     */
    private function _seedRole(array $steps): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach ($steps as $step) {
            $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                'step' => $step,
                'can_operate' => true,
            ]));
        }

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    private function _seedLegalization(string $status, string $invoiceNumber): void
    {
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new([
            'provider_id' => $provider->id,
            'invoice_number' => $invoiceNumber,
        ])->anticipo()->withStatus(InvoiceConstants::STATUS_PAGADA)->save();

        AdvanceLegalizationFactory::new()->forAdvance($anticipo)->withStatus($status)->save();
    }

    public function testListsOnlyLegalizationsOnOperableSteps(): void
    {
        $user = $this->_seedRole([AdvanceConstants::STATUS_CONTABILIDAD]);
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD, 'ANT-CONTA');
        $this->_seedLegalization(AdvanceConstants::STATUS_TESORERIA, 'ANT-TESO');

        $this->session(['Auth' => $user]);
        $this->get('/advances/pending-legalization');

        $this->assertResponseOk();
        $this->assertResponseContains('ANT-CONTA');
        $this->assertResponseNotContains('ANT-TESO');
    }

    public function testRoleWithoutOperableStepsSeesEmptyList(): void
    {
        $user = $this->_seedRole([]);
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD, 'ANT-CONTA');

        $this->session(['Auth' => $user]);
        $this->get('/advances/pending-legalization');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ANT-CONTA');
    }

    /**
     * `legalizada` no figura en STEPS_BY_PIPELINE, así que el filtro por pasos
     * operables ya excluye las cerradas aunque el rol opere todos los pasos.
     *
     * NOTA: este test pasa por DOS mecanismos independientes (el filtro de pasos,
     * porque `legalizada` no es un paso operable; y el `!= legalizada` explícito
     * de la cláusula ON). Si uno se rompiera, el test seguiría verde — NO es una
     * guarda de regresión de un mecanismo concreto.
     */
    public function testClosedLegalizationsNeverAppear(): void
    {
        $user = $this->_seedRole(PipelineStepConstants::STEPS_BY_PIPELINE[PipelineStepConstants::PIPELINE_LEGALIZATIONS]);
        $this->_seedLegalization(AdvanceConstants::STATUS_LEGALIZADA, 'ANT-CERRADA');

        $this->session(['Auth' => $user]);
        $this->get('/advances/pending-legalization');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ANT-CERRADA');
    }

    /**
     * El filtro de búsqueda y el de pasos operables se ANDean: viven en sitios
     * distintos de la query (WHERE principal y cláusula ON del JOIN), así que
     * conviene verificar que componen.
     *
     * Nombres elegidos para que ninguno sea subcadena de otro presente en el
     * HTML: la única fila renderizada es `ANT-SI-OPERABLE`, y ni `ANT-NO-OPERABLE`
     * ni `ANT-OTRO-NUMERO` aparecen en ella, así que las 2 aserciones negativas
     * son inequívocas.
     */
    public function testSearchFilterComposesWithStepFilter(): void
    {
        $user = $this->_seedRole([AdvanceConstants::STATUS_CONTABILIDAD]);
        // Coincide con la búsqueda Y está en un paso operable → aparece.
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD, 'ANT-SI-OPERABLE');
        // Coincide con la búsqueda pero NO está en un paso operable → no aparece.
        $this->_seedLegalization(AdvanceConstants::STATUS_TESORERIA, 'ANT-NO-OPERABLE');
        // Está en un paso operable pero NO coincide con la búsqueda → no aparece.
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD, 'ANT-OTRO-NUMERO');

        $this->session(['Auth' => $user]);
        $this->get('/advances/pending-legalization?search=OPERABLE');

        $this->assertResponseOk();
        $this->assertResponseContains('ANT-SI-OPERABLE');
        $this->assertResponseNotContains('ANT-NO-OPERABLE');
        $this->assertResponseNotContains('ANT-OTRO-NUMERO');
    }
}
