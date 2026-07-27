<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * La etapa Agrupación de Reintegros (edit) renderiza la tabla rica de facturas
 * (element grouped_invoices_table) con la columna de desvincular.
 */
final class RefundEditGroupedTableTest extends TestCase
{
    use IntegrationTestTrait;

    public function testEditAgrupacionRendersRichGroupedTableWithUnlink(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'refunds',
            'can_view' => true,
            'can_edit' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_AGRUPACION)->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'document_type' => InvoiceConstants::DOCTYPE_REINTEGRO,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/refunds/edit/' . $refund->id);

        $this->assertResponseOk();
        $this->assertResponseContains('grouped-invoices-refund_id');
        $this->assertResponseContains('>Soporte<');
        // DashedRoute + ruta explícita `/refunds/remove-invoice/...` → URL dasherizada.
        $this->assertResponseContains('remove-invoice');
    }
}
