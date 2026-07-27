<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Smoke test de PettyCashRecordsController::view() (integración T13, espejo de
 * T12): un rol con `petty_cash.can_view` abre el detalle de un registro de
 * Caja Menor con 1 factura hija y ve la tabla de facturas agrupadas (element
 * grouped_invoices_table). Verifica que la vista renderiza 200 y emite el
 * root id del element (`grouped-invoices-petty_cash_record_id`) que ancla el
 * JS de acciones inline.
 *
 * Seeding de permisos directo en `permissions` (patrón de RefundsViewGroupedTableTest):
 * no hay factory para esa tabla.
 */
final class PettyCashViewGroupedTableTest extends TestCase
{
    use IntegrationTestTrait;

    public function testViewRendersGroupedInvoicesTable(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'petty_cash',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();

        $this->session(['Auth' => $user]);
        $this->get('/petty-cash-records/view/' . $record->id);

        $this->assertResponseOk();
        $this->assertResponseContains('grouped-invoices-petty_cash_record_id');
    }

    public function testViewShowsEmployeeBeneficiaryAndDocumentType(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'petty_cash',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $employee = EmployeeFactory::new([
            'first_name' => 'Ana', 'last_name1' => 'Gomez', 'last_name2' => 'Ruiz',
        ])->save();
        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new([
            'petty_cash_record_id' => $record->id,
            'employee_id' => $employee->id,
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
        ])->save();

        $this->session(['Auth' => $user]);
        $this->get('/petty-cash-records/view/' . $record->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Ana Gomez Ruiz');
        $this->assertResponseContains('<th>Tipo</th>');
    }
}
