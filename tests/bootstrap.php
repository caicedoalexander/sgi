<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Carga el bootstrap completo de la app (config/bootstrap.php): define paths,
 * funciones globales de Cake (h(), env(), ...), Configure (app + app_local +
 * config/.env) y registra ConnectionManager con `default` y `test`.
 *
 * Los tests pure-unit siguen sin tocar BD (ConnectionManager es lazy). Los de
 * integración usan la conexión `test`. Se aliasa `default` -> `test` como
 * blindaje para que ningún test pueda tocar la base real.
 */

use Cake\Datasource\ConnectionManager;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/bootstrap.php';

if (in_array('test', ConnectionManager::configured(), true)) {
    ConnectionManager::alias('test', 'default');
}
