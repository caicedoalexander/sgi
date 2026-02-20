<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.0.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\View;

use Cake\View\View;

/**
 * Application View
 *
 * Your application's default view class
 *
 * @link https://book.cakephp.org/5/en/views.html#the-app-view
 * @extends \Cake\View\View<\App\View\AppView>
 */
class AppView extends View
{
    /**
     * Initialization hook method.
     */
    public function initialize(): void
    {
    }

    /**
     * Format a date in long Spanish format: "Lunes, 17 Febrero 2026".
     * Returns '—' if $date is null.
     *
     * @param \DateTimeInterface|\Cake\I18n\Date|\Cake\I18n\DateTime|null $date
     */
    public function formatDateEs($date): string
    {
        if (!$date) {
            return '—';
        }

        $days = [
            'Domingo', 'Lunes', 'Martes', 'Miércoles',
            'Jueves', 'Viernes', 'Sábado',
        ];
        $months = [
            '', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
        ];

        $dayName = $days[(int)$date->format('w')];
        $day     = $date->format('j');
        $month   = $months[(int)$date->format('n')];
        $year    = $date->format('Y');

        return "{$dayName}, {$day} {$month} {$year}";
    }
}
