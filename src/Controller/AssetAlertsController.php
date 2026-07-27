<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\AssetAlertConstants;
use Cake\Http\Response;
use Cake\I18n\DateTime;

class AssetAlertsController extends AppController
{
    /**
     * @var array<string, int>
     */
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista de alertas filtradas por estado.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $status = (string)$this->request->getQuery('status', AssetAlertConstants::STATUS_ABIERTA);

        $query = $this->AssetAlerts->find()
            ->contain(['Assets', 'Consumables'])
            ->orderBy(['AssetAlerts.priority' => 'ASC', 'AssetAlerts.created' => 'DESC']);
        if ($status !== '' && in_array($status, AssetAlertConstants::STATUSES, true)) {
            $query->where(['AssetAlerts.status' => $status]);
        }

        $alerts = $this->paginate($query);
        $statusLabels = AssetAlertConstants::STATUS_LABELS;
        $typeLabels = AssetAlertConstants::TYPE_LABELS;

        $this->set(compact('alerts', 'status', 'statusLabels', 'typeLabels'));
    }

    /**
     * Marca una alerta como resuelta.
     *
     * @param string|null $id ID de la alerta.
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'edit')]
    public function resolve(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $alert = $this->AssetAlerts->get($id);
        $alert->status = AssetAlertConstants::STATUS_RESUELTA;
        $alert->resolved_at = DateTime::now();

        if ($this->AssetAlerts->save($alert)) {
            $this->Flash->success('Alerta resuelta.');
        } else {
            $this->Flash->error('No se pudo resolver la alerta.');
        }

        return $this->redirect(['action' => 'index']);
    }
}
