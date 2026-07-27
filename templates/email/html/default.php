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
 * @var \Cake\View\View $this
 * @var string $content
 */

$lines = explode("\n", $content);

foreach ($lines as $line) :
    echo '<p style="margin:0 0 12px;color:#333;font-family:\'Inter\',system-ui,-apple-system,\'Segoe UI\',sans-serif;"> ' . $line . "</p>\n";
endforeach;
