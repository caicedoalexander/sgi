<?php
/**
 * AJAX fragment del modal "Vincular facturas — Legalización".
 *
 * Wrapper sobre el element genérico `link_invoices_modal` en modo fragment-only:
 * el shell del modal vive en `legalization.php` con `data-load-url`, y el JS
 * global reemplaza `.modal-content` con lo que renderiza este view (audit SU-003).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var iterable<\App\Model\Entity\Invoice> $candidates
 * @var array{date_from:string, date_to:string, provider_id:int|string, operation_center_id:int|string} $filters
 * @var array<int, string> $providers
 * @var array<int, string> $operationCenters
 */
echo $this->element('link_invoices_modal', [
    'modalId' => 'advanceLinkModal',
    'formUrl' => ['controller' => 'Advances', 'action' => 'linkInvoices', $leg->advance_invoice_id],
    'filterUrl' => ['controller' => 'Advances', 'action' => 'linkCandidates', $leg->advance_invoice_id],
    'candidates' => $candidates,
    'filters' => $filters,
    'operationCenters' => $operationCenters,
    'providers' => $providers,
    'title' => 'Vincular facturas — Legalización',
    'helpText' => 'Facturas tipo "Legalización" sin anticipo asignado.',
    'fragmentOnly' => true,
]);
