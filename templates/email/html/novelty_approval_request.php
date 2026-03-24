<?php
/**
 * @var string $employeeName
 * @var string $noveltyTypeName
 * @var string $reason
 * @var string $approvalUrl
 * @var string $recipientName
 */
?>
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
    <div style="border-bottom:3px solid #469D61;padding-bottom:15px;margin-bottom:20px;">
        <h2 style="margin:0;color:#212529;font-size:18px;">SGI - Solicitud de Aprobaci&oacute;n de Novedad</h2>
    </div>

    <?php if (!empty($recipientName)): ?>
    <p style="color:#333;font-size:14px;line-height:1.6;">
        Estimado/a <strong><?= h($recipientName) ?></strong>,
    </p>
    <?php endif; ?>

    <p style="color:#333;font-size:14px;line-height:1.6;">
        Se le ha asignado la aprobaci&oacute;n de la siguiente novedad:
    </p>

    <div style="background:#f8f9fa;border-left:3px solid #469D61;padding:15px;margin:20px 0;">
        <table style="width:100%;font-size:14px;">
            <tr>
                <td style="color:#666;padding:4px 0;">Empleado:</td>
                <td style="font-weight:600;color:#555;"><?= h($employeeName) ?></td>
            </tr>
            <tr>
                <td style="color:#666;padding:4px 0;">Tipo de Novedad:</td>
                <td style="font-weight:600;color:#555;"><?= h($noveltyTypeName) ?></td>
            </tr>
            <?php if (!empty($reason)): ?>
            <tr>
                <td style="color:#666;padding:4px 0;">Motivo:</td>
                <td style="font-weight:600;color:#555;"><?= h($reason) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <p style="color:#333;font-size:14px;line-height:1.6;">
        Haga clic en el siguiente enlace para revisar y aprobar o rechazar esta novedad:
    </p>

    <div style="text-align:center;margin:25px 0;">
        <a href="<?= h($approvalUrl) ?>"
           style="display:inline-block;background:#469D61;color:#fff;text-decoration:none;padding:12px 30px;font-size:14px;font-weight:600;">
            Revisar y Aprobar
        </a>
    </div>

    <p style="color:#888;font-size:12px;margin-top:30px;border-top:1px solid #eee;padding-top:15px;">
        Este enlace es v&aacute;lido por 48 horas. Si tiene alguna duda, contacte al equipo de RRHH.<br>
        Este correo fue generado autom&aacute;ticamente por el Sistema de Gesti&oacute;n Interna (SGI).
    </p>
</div>
