<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * La etapa Agrupación de Caja Menor (edit) renderiza la tabla rica de facturas
 * (element grouped_invoices_table) con la columna de desvincular, en vez de la
 * tabla artesanal de 3 columnas.
 */
final class PettyCashEditGroupedTableTest extends TestCase
{
    use IntegrationTestTrait;

    public function testEditAgrupacionRendersRichGroupedTableWithUnlink(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'petty_cash',
            'can_view' => true,
            'can_edit' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $record = PettyCashRecordFactory::new()->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        InvoiceFactory::new([
            'petty_cash_record_id' => $record->id,
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
        ])->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/petty-cash-records/edit/' . $record->id);

        $this->assertResponseOk();
        // Root del element rico (no la tabla artesanal).
        $this->assertResponseContains('grouped-invoices-petty_cash_record_id');
        // Columna DIAN/Soporte del element rico (ausente en la tabla artesanal de 3 columnas).
        $this->assertResponseContains('>Soporte<');
        // Acción de desvincular por fila. OJO: DashedRoute + ruta explícita
        // `/petty-cash-records/remove-invoice/...` → la URL sale dasherizada.
        $this->assertResponseContains('remove-invoice');
    }
}
