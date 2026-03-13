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

        // Invoice rejected view
        $builder->connect(
            '/invoices/rejected',
            ['controller' => 'Invoices', 'action' => 'rejected']
        );

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

        // Invoice document management
        $builder->connect(
            '/invoices/upload-document/{invoiceId}',
            ['controller' => 'Invoices', 'action' => 'uploadDocument'],
            ['invoiceId' => '\d+', 'pass' => ['invoiceId']]
        );
        $builder->connect(
            '/invoices/delete-document/{invoiceId}/{documentId}',
            ['controller' => 'Invoices', 'action' => 'deleteDocument'],
            ['invoiceId' => '\d+', 'documentId' => '\d+', 'pass' => ['invoiceId', 'documentId']]
        );

        // Invoice generate approval link
        $builder->connect(
            '/invoices/generate-approval-link/{id}',
            ['controller' => 'Invoices', 'action' => 'generateApprovalLink'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // Employee novelties pipeline
        $builder->connect(
            '/employee-novelties/advance/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'advance'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/employee-novelties/reject/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'reject'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/employee-novelties/export-pdf/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'exportPdf'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/employee-novelties/assign-liquidation/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'assignLiquidation'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/employee-novelties/add-observation/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/employee-novelties/upload-document/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/employee-novelties/delete-document/{noveltyId}/{documentId}',
            ['controller' => 'EmployeeNovelties', 'action' => 'deleteDocument'],
            ['noveltyId' => '\d+', 'documentId' => '\d+', 'pass' => ['noveltyId', 'documentId']]
        );

        // Novelty Liquidation Docs
        $builder->connect(
            '/novelty-liquidation-docs/advance-group/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'advanceGroup'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/novelty-liquidation-docs/add-signature/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'addSignature'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/novelty-liquidation-docs/upload-document/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/novelty-liquidation-docs/delete-document/{id}/{documentId}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'deleteDocument'],
            ['id' => '\d+', 'documentId' => '\d+', 'pass' => ['id', 'documentId']]
        );
        $builder->connect(
            '/novelty-liquidation-docs/add-observation/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // Novelty Types AJAX flags
        $builder->connect(
            '/novelty-types/get-flags/{id}',
            ['controller' => 'NoveltyTypes', 'action' => 'getFlags'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // Leave document template management
        $builder->connect(
            '/leave-document-templates/save-fields/{id}',
            ['controller' => 'LeaveDocumentTemplates', 'action' => 'saveFields'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/leave-document-templates/preview/{id}',
            ['controller' => 'LeaveDocumentTemplates', 'action' => 'preview'],
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

        // Petty Cash Records (Caja Menor)
        $builder->connect(
            '/petty-cash-records/advance-status/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'advanceStatus'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/petty-cash-records/upload-document/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/petty-cash-records/delete-document/{recordId}/{documentId}',
            ['controller' => 'PettyCashRecords', 'action' => 'deleteDocument'],
            ['recordId' => '\d+', 'documentId' => '\d+', 'pass' => ['recordId', 'documentId']]
        );
        $builder->connect(
            '/petty-cash-records/remove-invoice/{recordId}/{invoiceId}',
            ['controller' => 'PettyCashRecords', 'action' => 'removeInvoice'],
            ['recordId' => '\d+', 'invoiceId' => '\d+', 'pass' => ['recordId', 'invoiceId']]
        );
        $builder->connect(
            '/petty-cash-records/add-observation/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']]
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
