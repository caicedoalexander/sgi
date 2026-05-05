<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 */
$invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');

// Restringimos los candidatos al mismo centro de operación del anticipo:
// (1) reduce drásticamente el set de datos enviado al modal (audit MA-008);
// (2) coincide con la regla de negocio: una legalización pertenece al mismo
//     centro de operación que el anticipo. Si el anticipo no tiene OC asignado,
//     se muestran todas (caso defensivo).
$advance = $invoices->get($leg->advance_invoice_id, [
    'fields' => ['id', 'operation_center_id'],
]);

$conditions = [
    'Invoices.document_type' => \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION,
    'Invoices.advance_id IS' => null,
];
if (!empty($advance->operation_center_id)) {
    $conditions['Invoices.operation_center_id'] = $advance->operation_center_id;
}

$candidates = $invoices->find()
    ->where($conditions)
    ->contain(['Providers', 'Employees', 'OperationCenters'])
    ->order(['Invoices.issue_date' => 'DESC'])
    ->all();

echo $this->element('link_invoices_modal', [
    'modalId'    => 'advanceLinkModal',
    'formUrl'    => ['controller' => 'Advances', 'action' => 'linkInvoices', $leg->advance_invoice_id],
    'candidates' => $candidates,
    'title'      => 'Vincular facturas — Legalización',
    'helpText'   => 'Facturas tipo "Legalización" sin anticipo asignado del mismo centro de operación.',
]);
