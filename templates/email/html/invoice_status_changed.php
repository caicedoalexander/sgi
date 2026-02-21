<?php
/**
 * @var string $invoiceNumber
 * @var string $fromLabel
 * @var string $toLabel
 * @var int $invoiceId
 */
?>
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
    <div style="border-bottom:3px solid #469D61;padding-bottom:15px;margin-bottom:20px;">
        <h2 style="margin:0;color:#212529;font-size:18px;">SGI - Cambio de Estado</h2>
    </div>

    <p style="color:#333;font-size:14px;line-height:1.6;">
        La factura <strong><?= h($invoiceNumber) ?></strong> ha avanzado en el pipeline:
    </p>

    <div style="background:#f8f9fa;border-left:3px solid #469D61;padding:15px;margin:20px 0;">
        <table style="width:100%;font-size:14px;">
            <tr>
                <td style="color:#666;padding:4px 0;">Estado anterior:</td>
                <td style="font-weight:600;color:#555;"><?= h($fromLabel) ?></td>
            </tr>
            <tr>
                <td style="color:#666;padding:4px 0;">Nuevo estado:</td>
                <td style="font-weight:600;color:#469D61;"><?= h($toLabel) ?></td>
            </tr>
        </table>
    </div>

    <p style="color:#888;font-size:12px;margin-top:30px;border-top:1px solid #eee;padding-top:15px;">
        Este correo fue generado automáticamente por el Sistema de Gestión Interna (SGI).
    </p>
</div>
