<?php
/**
 * Filtro de rango de fechas: UN solo input (Flatpickr en modo rango) que
 * escribe dos hidden inputs, preservando el contrato `date_from`/`date_to`
 * que ya leen los controllers. Reemplaza los pares de inputs date sueltos.
 *
 * Uso:
 *   <?= $this->element('date_range_filter', [
 *       'id' => 'inv-range',
 *       'from' => $this->request->getQuery('date_from'),
 *       'to' => $this->request->getQuery('date_to'),
 *   ]) ?>
 *
 * @var \App\View\AppView $this
 * @var string $id          Base única para los ids (requerido si hay >1 en la página).
 * @var string $fromName    Nombre del hidden "desde" (default date_from).
 * @var string $toName      Nombre del hidden "hasta" (default date_to).
 * @var string|null $from   Valor actual Y-m-d.
 * @var string|null $to     Valor actual Y-m-d.
 * @var string $placeholder Placeholder del input.
 * @var string $inputClass  Clases del input visible.
 * @var string $inputStyle  Estilo inline del input visible.
 */
$id = $id ?? 'daterange';
$fromName = $fromName ?? 'date_from';
$toName = $toName ?? 'date_to';
$from = $from ?? '';
$to = $to ?? '';
$placeholder = $placeholder ?? 'Período de fechas';
$inputClass = $inputClass ?? 'form-control form-control-sm';
$inputStyle = $inputStyle ?? 'min-width:210px;';
?>
<input type="text" class="flatpickr-range <?= h($inputClass) ?>" id="<?= h($id) ?>"
       placeholder="<?= h($placeholder) ?>" autocomplete="off"
       data-from="#<?= h($id) ?>-from" data-to="#<?= h($id) ?>-to"
       data-from-value="<?= h($from) ?>" data-to-value="<?= h($to) ?>"
       style="<?= $inputStyle ?>">
<input type="hidden" name="<?= h($fromName) ?>" id="<?= h($id) ?>-from" value="<?= h($from) ?>">
<input type="hidden" name="<?= h($toName) ?>" id="<?= h($id) ?>-to" value="<?= h($to) ?>">
