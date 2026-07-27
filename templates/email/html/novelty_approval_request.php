<?php
/**
 * @var string $employeeName
 * @var string $noveltyTypeName
 * @var string $reason
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
    Se le ha asignado la aprobaci&oacute;n de la siguiente novedad:
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f8f9fa;border-left:3px solid #469D61;margin:20px 0;">
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Empleado:</td>
        <td style="padding:6px 15px;font-weight:600;color:#555;font-size:14px;"><?= h($employeeName) ?></td>
    </tr>
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Tipo de Novedad:</td>
        <td style="padding:6px 15px;font-weight:600;color:#555;font-size:14px;"><?= h($noveltyTypeName) ?></td>
    </tr>
    <?php if (!empty($reason)): ?>
    <tr>
        <td style="padding:6px 15px;color:#666;font-size:14px;">Motivo:</td>
        <td style="padding:6px 15px;font-weight:600;color:#555;font-size:14px;"><?= h($reason) ?></td>
    </tr>
    <?php endif; ?>
</table>

<p style="margin:0 0 12px;">
    Haga clic en el siguiente enlace para revisar y aprobar o rechazar esta novedad:
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:25px auto;">
    <tr>
        <td style="background:#469D61;">
            <a href="<?= h($approvalUrl) ?>" style="display:inline-block;padding:12px 30px;color:#fff;text-decoration:none;font-size:14px;font-weight:600;">Revisar y Aprobar</a>
        </td>
    </tr>
</table>

<p style="margin:12px 0 0;color:#888;font-size:12px;line-height:1.5;">
    Este enlace es v&aacute;lido por 48 horas. Si tiene alguna duda, contacte al equipo de RRHH.
</p>
