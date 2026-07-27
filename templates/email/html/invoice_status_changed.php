<?php
/**
 * @var string $invoiceNumber
 * @var string $fromLabel
 * @var string $toLabel
 * @var int $invoiceId
 */
?>
<p style="margin:0 0 12px;">
    La factura <strong style="font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;"><?= h($invoiceNumber) ?></strong> ha avanzado en el pipeline:
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f8f9fa;border-left:3px solid #469D61;margin:20px 0;">
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Estado anterior:</td>
        <td style="padding:6px 15px;font-weight:600;color:#555;font-size:14px;"><?= h($fromLabel) ?></td>
    </tr>
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Nuevo estado:</td>
        <td style="padding:6px 15px;font-weight:600;color:#469D61;font-size:14px;"><?= h($toLabel) ?></td>
    </tr>
</table>
