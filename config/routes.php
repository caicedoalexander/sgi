<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->connect('/', ['controller' => 'Dashboard', 'action' => 'index']);
        $builder->connect('/login', ['controller' => 'Users', 'action' => 'login']);
        $builder->connect('/logout', ['controller' => 'Users', 'action' => 'logout']);

        $builder->connect('/pages/*', 'Pages::display');

        // Invoice pipeline advance action
        $builder->connect(
            '/invoices/advance-status/{id}',
            ['controller' => 'Invoices', 'action' => 'advanceStatus'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // System settings SMTP test
        $builder->connect(
            '/system-settings/test-smtp',
            ['controller' => 'SystemSettings', 'action' => 'testSmtp']
        );

        // Invoice observation
        $builder->connect(
            '/invoices/add-observation/{id}',
            ['controller' => 'Invoices', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // External approval tokens
        $builder->connect(
            '/approve/{token}',
            ['controller' => 'ExternalApprovals', 'action' => 'review'],
            ['token' => '[a-f0-9]{64}', 'pass' => ['token']]
        );
        $builder->connect(
            '/approve/{token}/process',
            ['controller' => 'ExternalApprovals', 'action' => 'process'],
            ['token' => '[a-f0-9]{64}', 'pass' => ['token']]
        );

        // Invoice generate approval link
        $builder->connect(
            '/invoices/generate-approval-link/{id}',
            ['controller' => 'Invoices', 'action' => 'generateApprovalLink'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // Employee leave approve/reject
        $builder->connect(
            '/employee-leaves/approve/{id}',
            ['controller' => 'EmployeeLeaves', 'action' => 'approve'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/employee-leaves/reject/{id}',
            ['controller' => 'EmployeeLeaves', 'action' => 'reject'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // Employee document management routes
        $builder->connect(
            '/employees/add-folder/{employeeId}',
            ['controller' => 'Employees', 'action' => 'addFolder'],
            ['employeeId' => '\d+', 'pass' => ['employeeId']]
        );
        $builder->connect(
            '/employees/upload-document/{employeeId}',
            ['controller' => 'Employees', 'action' => 'uploadDocument'],
            ['employeeId' => '\d+', 'pass' => ['employeeId']]
        );
        $builder->connect(
            '/employees/delete-document/{employeeId}/{documentId}',
            ['controller' => 'Employees', 'action' => 'deleteDocument'],
            ['employeeId' => '\d+', 'documentId' => '\d+', 'pass' => ['employeeId', 'documentId']]
        );

        $builder->fallbacks();
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};
