<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Smoke test de RefundsController::view() (integración T12): un rol con
 * `refunds.can_view` abre el detalle de un reintegro con 1 factura hija y ve
 * la tabla de facturas agrupadas (element grouped_invoices_table). Verifica que
 * la vista renderiza 200 y emite el root id del element
 * (`grouped-invoices-refund_id`) que ancla el JS de acciones inline.
 *
 * Seeding de permisos directo en `permissions` (patrón de AdvancesViewTest /
 * RefundsControllerGroupSupersessionTest): no hay factory para esa tabla.
 */
final class RefundsViewGroupedTableTest extends TestCase
{
    use IntegrationTestTrait;

    public function testViewRendersGroupedInvoicesTable(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'refunds',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])->save();

        $this->session(['Auth' => $user]);
        $this->get('/refunds/view/' . $refund->id);

        $this->assertResponseOk();
        $this->assertResponseContains('grouped-invoices-refund_id');
    }

    public function testViewShowsEmployeeBeneficiaryAndDocumentType(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'refunds',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $employee = EmployeeFactory::new([
            'first_name' => 'Ana', 'last_name1' => 'Gomez', 'last_name2' => 'Ruiz',
        ])->save();
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'employee_id' => $employee->id,
            'document_type' => InvoiceConstants::DOCTYPE_REINTEGRO,
        ])->save();

        $this->session(['Auth' => $user]);
        $this->get('/refunds/view/' . $refund->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Ana Gomez Ruiz');
        $this->assertResponseContains('<th>Tipo</th>');
    }
}
