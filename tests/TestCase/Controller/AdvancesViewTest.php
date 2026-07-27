<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * view() ya NO redirige a legalization() cuando el anticipo está en Fase 2; el
 * hub de consulta se renderiza en cualquier estado. Requiere permiso
 * advances.can_view (sembrado directo en `permissions`, patrón de
 * RefundsControllerGroupSupersessionTest). El anticipo se crea con un provider
 * porque InvoiceFactory no asocia provider/employee por defecto y el render
 * del hub accede a un beneficiario.
 */
class AdvancesViewTest extends TestCase
{
    use IntegrationTestTrait;

    private function userWithAdvancesView(): object
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    private function anticipo(string $status): object
    {
        $provider = ProviderFactory::new()->save();

        return InvoiceFactory::new(['provider_id' => $provider->id])
            ->anticipo()->withStatus($status)->save();
    }

    public function testViewDoesNotRedirectWhenLegalizationExists(): void
    {
        $anticipo = $this->anticipo(InvoiceConstants::STATUS_PAGADA);
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $this->userWithAdvancesView()]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertNoRedirect();
    }

    public function testNonAdvanceRedirectsToIndex(): void
    {
        $invoice = InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $this->session(['Auth' => $this->userWithAdvancesView()]);
        $this->get('/advances/view/' . $invoice->id);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/advances');
    }

    public function testViewRendersLegalizationBlockWithManageButton(): void
    {
        $anticipo = $this->anticipo(InvoiceConstants::STATUS_PAGADA);
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Gestionar legalización');
        $this->assertResponseContains('/advances/legalization/' . $anticipo->id);
    }

    /**
     * El hub de consulta no ofrece el botón hacia la vista de trabajo a quien no
     * tiene `advances.can_edit`: ese enlace terminaría en un 403.
     */
    public function testManageLegalizationButtonHiddenWithoutEditPermission(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => false,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseNotContains('Gestionar legalización');
    }

    public function testViewShowsBannerWhenNoLegalization(): void
    {
        $anticipo = $this->anticipo(InvoiceConstants::STATUS_APROBACION);

        $this->session(['Auth' => $this->userWithAdvancesView()]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('La legalización iniciará automáticamente');
        $this->assertResponseNotContains('Gestionar legalización');
    }
}
