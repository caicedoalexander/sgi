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

use App\Middleware\RateLimitMiddleware;
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
        $builder->registerMiddleware(
            'rateLimit',
            new RateLimitMiddleware(10, 60),
        );
        $builder->registerMiddleware(
            'rateLimitLogin',
            new RateLimitMiddleware(5, 300),
        );
        $builder->registerMiddleware(
            'rateLimitUpload',
            new RateLimitMiddleware(30, 3600),
        );

        $builder->connect('/', ['controller' => 'Dashboard', 'action' => 'index']);
        $builder->scope('/login', function (RouteBuilder $loginBuilder): void {
            $loginBuilder->applyMiddleware('rateLimitLogin');
            $loginBuilder->connect('', ['controller' => 'Users', 'action' => 'login']);
        });
        $builder->connect('/logout', ['controller' => 'Users', 'action' => 'logout']);
        $builder->connect('/health', ['controller' => 'Health', 'action' => 'index']);

        $builder->connect('/pages/*', 'Pages::display');

        // Invoice rejected view
        $builder->connect(
            '/invoices/rejected',
            ['controller' => 'Invoices', 'action' => 'rejected'],
        );

        // Invoice overdue view
        $builder->connect(
            '/invoices/overdue',
            ['controller' => 'Invoices', 'action' => 'overdue'],
        );

        // Invoice pipeline regress action
        $builder->connect(
            '/invoices/regress-status/{id}',
            ['controller' => 'Invoices', 'action' => 'regressStatus'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // System settings SMTP test
        $builder->connect(
            '/system-settings/test-smtp',
            ['controller' => 'SystemSettings', 'action' => 'testSmtp'],
        );

        // System settings — regenerar API key de notificaciones
        $builder->connect(
            '/system-settings/regenerate-api-key',
            ['controller' => 'SystemSettings', 'action' => 'regenerateApiKey'],
        );

        // Invoice observation
        $builder->connect(
            '/invoices/add-observation/{id}',
            ['controller' => 'Invoices', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // External approval tokens (rate-limited)
        $builder->scope('/approve', function (RouteBuilder $approveBuilder): void {
            $approveBuilder->applyMiddleware('rateLimit');
            $approveBuilder->connect(
                '/{token}',
                ['controller' => 'ExternalApprovals', 'action' => 'review'],
                ['token' => '[a-f0-9]{64}', 'pass' => ['token']],
            );
            $approveBuilder->connect(
                '/{token}/process',
                ['controller' => 'ExternalApprovals', 'action' => 'process'],
                ['token' => '[a-f0-9]{64}', 'pass' => ['token']],
            );
        });

        // Invoice document management
        $builder->connect(
            '/invoices/upload-document/{invoiceId}',
            ['controller' => 'Invoices', 'action' => 'uploadDocument'],
            ['invoiceId' => '\d+', 'pass' => ['invoiceId']],
        );
        $builder->connect(
            '/invoices/delete-document/{invoiceId}/{documentId}',
            ['controller' => 'Invoices', 'action' => 'deleteDocument'],
            ['invoiceId' => '\d+', 'documentId' => '\d+', 'pass' => ['invoiceId', 'documentId']],
        );

        // Employee novelties views
        $builder->connect(
            '/employee-novelties/all',
            ['controller' => 'EmployeeNovelties', 'action' => 'all'],
        );
        $builder->connect(
            '/employee-novelties/rejected',
            ['controller' => 'EmployeeNovelties', 'action' => 'rejected'],
        );

        // Employee novelties active/calendar view
        $builder->connect(
            '/employee-novelties/active',
            ['controller' => 'EmployeeNovelties', 'action' => 'active'],
        );
        $builder->connect(
            '/employee-novelties/active-events',
            ['controller' => 'EmployeeNovelties', 'action' => 'activeEvents'],
        );
        $builder->connect(
            '/employee-novelties/all-events',
            ['controller' => 'EmployeeNovelties', 'action' => 'allEvents'],
        );

        // Employee novelties pipeline
        $builder->connect(
            '/employee-novelties/advance/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'advance'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/employee-novelties/reject/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'reject'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/employee-novelties/export-pdf/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'exportPdf'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/employee-novelties/assign-liquidation/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'assignLiquidation'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/employee-novelties/resend-approval/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'resendApproval'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/employee-novelties/add-observation/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/employee-novelties/upload-document/{id}',
            ['controller' => 'NoveltyDocuments', 'action' => 'upload'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/employee-novelties/delete-document/{noveltyId}/{documentId}',
            ['controller' => 'NoveltyDocuments', 'action' => 'delete'],
            ['noveltyId' => '\d+', 'documentId' => '\d+', 'pass' => ['noveltyId', 'documentId']],
        );

        // Novelty Liquidation Docs
        $builder->connect(
            '/novelty-liquidation-docs/advance-group/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'advanceGroup'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/novelty-liquidation-docs/add-signature/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'addSignature'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/novelty-liquidation-docs/upload-document/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/novelty-liquidation-docs/delete-document/{id}/{documentId}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'deleteDocument'],
            ['id' => '\d+', 'documentId' => '\d+', 'pass' => ['id', 'documentId']],
        );
        $builder->connect(
            '/novelty-liquidation-docs/add-observation/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/novelty-liquidation-docs/upload-liquidation-document/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadLiquidationDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/novelty-liquidation-docs/update-liquidation-document/{id}',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'updateLiquidationDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/liquidation-doc-payments/confirm-payment/{docId}',
            ['controller' => 'LiquidationDocPayments', 'action' => 'confirmPayment'],
            ['docId' => '\d+', 'pass' => ['docId']],
        );

        // Novelty Types AJAX flags
        $builder->connect(
            '/novelty-types/get-flags/{id}',
            ['controller' => 'NoveltyTypes', 'action' => 'getFlags'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Leave document template management
        $builder->connect(
            '/leave-document-templates/save-fields/{id}',
            ['controller' => 'LeaveDocumentTemplates', 'action' => 'saveFields'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/leave-document-templates/preview/{id}',
            ['controller' => 'LeaveDocumentTemplates', 'action' => 'preview'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Employee document management routes
        $builder->connect(
            '/employees/add-folder/{employeeId}',
            ['controller' => 'Employees', 'action' => 'addFolder'],
            ['employeeId' => '\d+', 'pass' => ['employeeId']],
        );
        $builder->scope('/employees', function (RouteBuilder $employeeUploadBuilder): void {
            // Rate limit hardening (CR-028): 30 uploads/hora por IP+path.
            // El middleware existente limita por IP, no por usuario; oficinas con
            // NAT comparten cuota — ajustar el límite si genera falsos positivos.
            $employeeUploadBuilder->applyMiddleware('rateLimitUpload');
            $employeeUploadBuilder->connect(
                '/upload-document/{employeeId}',
                ['controller' => 'Employees', 'action' => 'uploadDocument'],
                ['employeeId' => '\d+', 'pass' => ['employeeId']],
            );
        });
        $builder->connect(
            '/employees/delete-document/{employeeId}/{documentId}',
            ['controller' => 'Employees', 'action' => 'deleteDocument'],
            ['employeeId' => '\d+', 'documentId' => '\d+', 'pass' => ['employeeId', 'documentId']],
        );
        $builder->connect(
            '/employees/download-document/{employeeId}/{documentId}',
            ['controller' => 'Employees', 'action' => 'downloadDocument'],
            ['employeeId' => '\d+', 'documentId' => '\d+', 'pass' => ['employeeId', 'documentId']],
        );

        // Employee Excel import/export AJAX
        $builder->connect(
            '/employees/export-config',
            ['controller' => 'Employees', 'action' => 'exportConfig'],
        );
        $builder->connect(
            '/employees/import-upload',
            ['controller' => 'Employees', 'action' => 'importUpload'],
        );
        $builder->connect(
            '/employees/import-process',
            ['controller' => 'Employees', 'action' => 'importProcess'],
        );

        // Petty Cash Records (Caja Menor)
        $builder->connect(
            '/petty-cash-records/regress-status/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'regressStatus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/petty-cash-records/upload-document/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/petty-cash-records/delete-document/{recordId}/{documentId}',
            ['controller' => 'PettyCashRecords', 'action' => 'deleteDocument'],
            ['recordId' => '\d+', 'documentId' => '\d+', 'pass' => ['recordId', 'documentId']],
        );
        $builder->connect(
            '/petty-cash-records/remove-invoice/{recordId}/{invoiceId}',
            ['controller' => 'PettyCashRecords', 'action' => 'removeInvoice'],
            ['recordId' => '\d+', 'invoiceId' => '\d+', 'pass' => ['recordId', 'invoiceId']],
        );
        $builder->connect(
            '/petty-cash-records/add-observation/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/petty-cash-records/register-payment/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'registerPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/petty-cash-records/authorize-payment/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'authorizePayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/petty-cash-records/confirm-payment/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'confirmPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/petty-cash-records/reject-payment/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'rejectPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Refunds (Reintegros)
        $builder->connect(
            '/refunds/advance-status/{id}',
            ['controller' => 'Refunds', 'action' => 'advanceStatus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/refunds/regress-status/{id}',
            ['controller' => 'Refunds', 'action' => 'regressStatus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/refunds/remove-invoice/{recordId}/{invoiceId}',
            ['controller' => 'Refunds', 'action' => 'removeInvoice'],
            ['recordId' => '\d+', 'invoiceId' => '\d+', 'pass' => ['recordId', 'invoiceId']],
        );
        $builder->connect(
            '/refunds/add-observation/{id}',
            ['controller' => 'Refunds', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/refunds/register-payment/{id}',
            ['controller' => 'Refunds', 'action' => 'registerPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/refunds/authorize-payment/{id}',
            ['controller' => 'Refunds', 'action' => 'authorizePayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/refunds/confirm-payment/{id}',
            ['controller' => 'Refunds', 'action' => 'confirmPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/refunds/reject-payment/{id}',
            ['controller' => 'Refunds', 'action' => 'rejectPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Employee observations
        $builder->connect(
            '/employees/add-observation/{id}',
            ['controller' => 'Employees', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Invoice payments
        $builder->connect(
            '/invoices/add-payment/{invoiceId}',
            ['controller' => 'InvoicePayments', 'action' => 'addPayment'],
            ['pass' => ['invoiceId']],
        );
        $builder->connect(
            '/invoices/delete-payment/{invoiceId}/{paymentId}',
            ['controller' => 'InvoicePayments', 'action' => 'deletePayment'],
            ['pass' => ['invoiceId', 'paymentId']],
        );
        $builder->connect(
            '/invoices/authorize-payment/{invoiceId}/{paymentId}',
            ['controller' => 'InvoicePayments', 'action' => 'authorizePayment'],
            ['pass' => ['invoiceId', 'paymentId']],
        );
        $builder->connect(
            '/invoices/confirm-payment/{invoiceId}',
            ['controller' => 'InvoicePayments', 'action' => 'confirmPayment'],
            ['invoiceId' => '\d+', 'pass' => ['invoiceId']],
        );

        $builder->connect(
            '/invoices/reject-payment/{invoiceId}/{paymentId}',
            ['controller' => 'InvoicePayments', 'action' => 'rejectPayment'],
            ['pass' => ['invoiceId', 'paymentId']],
        );
        $builder->connect(
            '/invoices/edit-payment/{invoiceId}/{paymentId}',
            ['controller' => 'InvoicePayments', 'action' => 'editPayment'],
            ['pass' => ['invoiceId', 'paymentId']],
        );

        // Invoice approval workflow
        $builder->connect(
            '/invoices/send-approval-links/{id}',
            ['controller' => 'Invoices', 'action' => 'sendApprovalLinks'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/invoices/modify-approvers/{id}',
            ['controller' => 'Invoices', 'action' => 'modifyApprovers'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/invoices/reset-flow/{id}',
            ['controller' => 'Invoices', 'action' => 'resetFlow'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Payment Schedulings (Programación)
        $builder->connect(
            '/payment-schedulings/advance/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'advance'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/reject/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'reject'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/regress-status/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'regressStatus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/confirm-payment/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'confirmPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/import-excel/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'importExcel'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/preview-import/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'previewImport'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/confirm-import/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'confirmImport'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/add-item/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'addItem'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/remove-item/{id}/{itemId}',
            ['controller' => 'PaymentSchedulings', 'action' => 'removeItem'],
            ['id' => '\d+', 'itemId' => '\d+', 'pass' => ['id', 'itemId']],
        );
        $builder->connect(
            '/payment-schedulings/upload-document/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/payment-schedulings/delete-document/{id}/{documentId}',
            ['controller' => 'PaymentSchedulings', 'action' => 'deleteDocument'],
            ['id' => '\d+', 'documentId' => '\d+', 'pass' => ['id', 'documentId']],
        );
        $builder->connect(
            '/payment-schedulings/add-observation/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Advances (Anticipos)
        $builder->connect(
            '/advances/legalization/{id}',
            ['controller' => 'Advances', 'action' => 'legalization'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/link-invoices/{id}',
            ['controller' => 'Advances', 'action' => 'linkInvoices'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/link-candidates/{id}',
            ['controller' => 'Advances', 'action' => 'linkCandidates'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/unlink-invoice/{id}/{invoiceId}',
            ['controller' => 'Advances', 'action' => 'unlinkInvoice'],
            ['id' => '\d+', 'invoiceId' => '\d+', 'pass' => ['id', 'invoiceId']],
        );
        $builder->connect(
            '/advances/upload-relation-document/{id}',
            ['controller' => 'Advances', 'action' => 'uploadRelationDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/move-to-revision/{id}',
            ['controller' => 'Advances', 'action' => 'moveToRevision'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/mark-signed/{id}',
            ['controller' => 'Advances', 'action' => 'markSigned'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/return-to-validacion/{id}',
            ['controller' => 'Advances', 'action' => 'returnToValidacion'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/mark-exact/{id}',
            ['controller' => 'Advances', 'action' => 'markExact'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/register-shortage/{id}',
            ['controller' => 'Advances', 'action' => 'registerShortage'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/confirm-shortage/{id}',
            ['controller' => 'Advances', 'action' => 'confirmShortage'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/register-surplus/{id}',
            ['controller' => 'Advances', 'action' => 'registerSurplus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/register-refund/{id}',
            ['controller' => 'Advances', 'action' => 'registerRefund'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/confirm-refund-payment/{id}',
            ['controller' => 'Advances', 'action' => 'confirmRefundPayment'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        $builder->fallbacks();
    });

    // API externa (consumida por n8n). Autenticada por header X-Api-Key.
    $routes->prefix('Api', function (RouteBuilder $apiBuilder): void {
        $apiBuilder->connect(
            '/notifications/pending',
            ['controller' => 'Notifications', 'action' => 'pending'],
        );
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
