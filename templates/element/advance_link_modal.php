<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 */
$invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
$candidates = $invoices->find()
    ->where([
        'Invoices.document_type' => \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION,
        'Invoices.advance_id IS' => null,
    ])
    ->contain(['Providers', 'Employees', 'OperationCenters'])
    ->order(['Invoices.issue_date' => 'DESC'])
    ->all();

echo $this->element('link_invoices_modal', [
    'modalId'    => 'advanceLinkModal',
    'formUrl'    => ['controller' => 'Advances', 'action' => 'linkInvoices', $leg->advance_invoice_id],
    'candidates' => $candidates,
    'title'      => 'Vincular facturas — Legalización',
    'helpText'   => 'Solo se muestran facturas con tipo "Legalización" sin anticipo asignado.',
]);
