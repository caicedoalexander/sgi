<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\ExpenseTypeFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\OperationCenterFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class InvoicesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testAddRequiresAuthentication(): void
    {
        $this->get('/invoices/add');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testAddWithAdvanceIdRequiresAuthentication(): void
    {
        $this->get('/invoices/add?advance_id=1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    /**
     * @param array<string, bool> $advancesPermissions
     */
    private function userWithInvoicesCreate(array $advancesPermissions = []): object
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'invoices',
            'can_view' => true,
            'can_create' => true,
        ]));
        if ($advancesPermissions !== []) {
            $permissions->saveOrFail($permissions->newEntity(array_merge(
                ['role_id' => $role->id, 'module' => 'advances'],
                $advancesPermissions,
            )));
        }

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    /**
     * @return array{0: object, 1: array<string, mixed>}
     */
    private function linkedInvoicePostData(): array
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $operationCenter = OperationCenterFactory::new()->save();
        $expenseType = ExpenseTypeFactory::new()->save();

        $data = [
            'advance_id' => $anticipo->id,
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'issue_date' => date('Y-m-d'),
            'detail' => 'Comprobante de prueba',
            'amount' => '100000',
            'operation_center_id' => $operationCenter->id,
            'expense_type_id' => $expenseType->id,
        ];

        return [$anticipo, $data];
    }

    /**
     * Sin `advances.can_edit`, el redirect tras guardar debe caer en el hub de
     * consulta (`Advances::view`), no en `legalization` — que exige ese permiso
     * y devolvería un 403 justo después de guardar con éxito.
     */
    public function testAddWithAdvanceIdRedirectsToViewWithoutAdvancesEditPermission(): void
    {
        [$anticipo, $data] = $this->linkedInvoicePostData();
        $user = $this->userWithInvoicesCreate(['can_view' => true, 'can_edit' => false]);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/invoices/add', $data);

        $this->assertRedirect('/advances/view/' . $anticipo->id);
    }

    /**
     * Con `advances.can_edit`, el redirect sigue aterrizando en la vista de
     * trabajo (`Advances::legalization`) — camino feliz preexistente.
     */
    public function testAddWithAdvanceIdRedirectsToLegalizationWithAdvancesEditPermission(): void
    {
        [$anticipo, $data] = $this->linkedInvoicePostData();
        $user = $this->userWithInvoicesCreate(['can_view' => true, 'can_edit' => true]);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/invoices/add', $data);

        $this->assertRedirect('/advances/legalization/' . $anticipo->id);
    }
}
