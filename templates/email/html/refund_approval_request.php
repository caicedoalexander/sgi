<?php
/**
 * @var string $refundCode
 * @var string $beneficiaryName
 * @var float $amount
 * @var string $approvalUrl
 * @var string $recipientName
 */
?>
<?php if (!empty($recipientName)): ?>
<p style="margin:0 0 12px;">
    Estimado/a <strong><?= h($recipientName) ?></strong>,
</p>
<?php endif; ?>

<p style="margin:0 0 12px;">
    Se le ha asignado la aprobaci&oacute;n del siguiente reintegro:
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f8f9fa;border-left:3px solid #469D61;margin:20px 0;">
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Reintegro:</td>
        <td style="padding:6px 15px;font-weight:600;color:#555;font-size:14px;font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;"><?= h($refundCode) ?></td>
    </tr>
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Beneficiario:</td>
        <td style="padding:6px 15px;font-weight:600;color:#555;font-size:14px;"><?= h($beneficiaryName) ?></td>
    </tr>
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Monto:</td>
        <td style="padding:6px 15px;font-weight:600;color:#469D61;font-size:14px;font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;">$ <?= number_format((float)$amount, 2, ',', '.') ?></td>
    </tr>
</table>

<p style="margin:0 0 12px;">
    Haga clic en el siguiente enlace para revisar y aprobar o rechazar este reintegro:
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:25px auto;">
    <tr>
        <td style="background:#469D61;">
            <a href="<?= h($approvalUrl) ?>" style="display:inline-block;padding:12px 30px;color:#fff;text-decoration:none;font-size:14px;font-weight:600;">Revisar y Aprobar</a>
        </td>
    </tr>
</table>

<p style="margin:12px 0 0;color:#888;font-size:12px;line-height:1.5;">
    Este enlace es v&aacute;lido por 48 horas. Si tiene alguna duda, contacte al equipo de Registro/Revisi&oacute;n.
</p>
