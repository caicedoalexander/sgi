<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Throwable;

class HealthController extends AppController
{
    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->allowUnauthenticated(['index']);
    }

    /**
     * Health check endpoint.
     *
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        $checks = [];

        try {
            $connection = ConnectionManager::get('default');
            $connection->execute('SELECT 1');
            $checks['database'] = 'ok';
        } catch (Throwable $e) {
            $checks['database'] = 'fail';
        }

        $allOk = !in_array('fail', $checks);

        return $this->response
            ->withType('application/json')
            ->withStatus($allOk ? 200 : 503)
            ->withStringBody((string)json_encode([
                'status' => $allOk ? 'healthy' : 'unhealthy',
                'checks' => $checks,
            ]));
    }
}
