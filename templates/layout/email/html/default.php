<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         0.10.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= $this->fetch('title') ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f5;">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background:#fff;max-width:600px;width:100%;font-family:'Inter',system-ui,-apple-system,'Segoe UI',sans-serif;">
                    <!-- HEADER -->
                    <tr>
                        <td style="background:#212529;padding:16px 24px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="36" style="width:36px;">
                                        <div style="width:36px;height:36px;background:#469D61;color:#fff;font-weight:700;font-size:13px;text-align:center;line-height:36px;">SPI</div>
                                    </td>
                                    <td style="padding-left:12px;">
                                        <div style="color:#fff;font-size:15px;font-weight:700;letter-spacing:-.02em;">SPI &middot; COPCSA</div>
                                        <div style="font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);">Sistema de Procesos Internos</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- TIRA DE ACENTO -->
                    <tr>
                        <td style="height:3px;background:#469D61;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <!-- CONTENIDO -->
                    <tr>
                        <td style="padding:24px;color:#333;font-size:14px;line-height:1.6;">
                            <?= $this->fetch('content') ?>
                        </td>
                    </tr>
                    <!-- FOOTER -->
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #ececec;color:#888;font-size:12px;line-height:1.5;">
                            Generado autom&aacute;ticamente por el Sistema de Procesos Internos (SPI).
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
