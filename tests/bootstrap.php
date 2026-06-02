<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Carga el bootstrap completo de la aplicación (config/bootstrap.php), que define
 * los paths, las funciones globales de Cake (h(), env(), ...), Configure (app +
 * app_local + config/.env) y registra ConnectionManager con las conexiones
 * `default` y `test`.
 *
 * Los tests pure-unit siguen funcionando sin tocar BD (ConnectionManager es lazy:
 * no conecta hasta que se ejecuta una query). Los tests de integración usan la
 * conexión `test`.
 *
 * Blindaje: se aliasa `default` → `test` para que ningún test pueda tocar la base
 * de datos real por accidente, incluso si algún código pide la conexión `default`.
 */

use Cake\Datasource\ConnectionManager;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/bootstrap.php';

if (in_array('test', ConnectionManager::configured(), true)) {
    ConnectionManager::alias('test', 'default');
}
