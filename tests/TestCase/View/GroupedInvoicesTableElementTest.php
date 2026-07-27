<?php
declare(strict_types=1);

namespace App\Test\TestCase\View;

use App\View\AppView;
use App\View\Presentation\GroupedInvoiceRowView;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;

/**
 * Verifica las afordances editables opcionales del element grouped_invoices_table:
 * con editable=true aparece la columna de desvincular; el <tfoot> de Total se liga a
 * totalAmount (no a editable); con los defaults (modo view.php) no hay desvincular.
 */
final class GroupedInvoicesTableElementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // El element reverse-routea (Url->build / postLink) al renderizar; sin
        // rutas cargadas lanzaría MissingRouteException. Un TestCase plano NO
        // las conecta solo.
        $this->loadRoutes();
    }

    private function _row(): GroupedInvoiceRowView
    {
        return new GroupedInvoiceRowView(
            id: 7,
            number: 'F-7',
            beneficiaryName: 'ACME',
            documentType: 'Caja menor',
            amount: 5000.0,
            issueDate: '01/01/2026',
            statusLabel: 'Contabilidad',
            statusPill: 'pill-info-soft',
            dianMode: 'na',
            dianValue: 'Pendiente',
            dianPill: 'pill-muted',
            supportRequired: true,
            docsCount: 0,
            supportOk: false,
            childStatus: 'contabilidad',
        );
    }

    private function _view(): AppView
    {
        $request = new ServerRequest([
            'url' => '/petty-cash-records/edit/3',
            'params' => ['controller' => 'PettyCashRecords', 'action' => 'edit', 'pass' => ['3'], 'plugin' => null],
        ]);
        // El postLink de desvincular reverse-routea con Router::url(), que
        // resuelve `controller` desde el contexto ESTÁTICO de Router (no desde
        // $request de la View). Sin esto, MissingRouteException: 'controller'
        // => null. En una request HTTP real lo setea RoutingMiddleware.
        Router::setRequest($request);

        return new AppView($request);
    }

    public function testEditableModeAddsUnlinkColumnAndTotalFooter(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
            'editable' => true,
            'unlinkAction' => 'removeInvoice',
            'totalAmount' => 5000.0,
        ]);

        $this->assertStringContainsString('bi-x-lg', $html, 'Falta el ícono de desvincular en modo editable');
        $this->assertStringContainsString('Total:', $html, 'Falta el footer de Total');
    }

    public function testReadOnlyModeOmitsUnlinkColumn(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
        ]);

        $this->assertStringNotContainsString('bi-x-lg', $html, 'No debe haber desvincular en modo view');
        $this->assertStringContainsString('grouped-invoices-petty_cash_record_id', $html);
    }

    public function testTotalFooterRendersEvenWhenNotEditable(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
            'editable' => false,
            'totalAmount' => 5000.0,
        ]);

        $this->assertStringContainsString('Total:', $html);
        $this->assertStringNotContainsString('bi-x-lg', $html);
    }

    public function testHeaderActionsHtmlSlotRenders(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
            'headerActionsHtml' => '<button id="mi-boton-vincular">Vincular</button>',
        ]);

        $this->assertStringContainsString('mi-boton-vincular', $html);
    }

    public function testRendersBeneficiarioHeaderNotProveedor(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
        ]);

        $this->assertStringContainsString('Beneficiario', $html);
        $this->assertStringNotContainsString('>Proveedor<', $html);
    }

    public function testRendersDocumentTypeColumn(): void
    {
        $html = $this->_view()->element('grouped_invoices_table', [
            'rows' => [$this->_row()],
            'parentField' => 'petty_cash_record_id',
            'parentId' => 3,
        ]);

        $this->assertStringContainsString('<th>Tipo</th>', $html);
        $this->assertStringContainsString('Caja menor', $html);
    }
}
