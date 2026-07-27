<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetAlertConstants;
use App\Constants\AssetConstants;
use Cake\ORM\TableRegistry;

/**
 * Calcula y persiste alertas del inventario. Idempotente: no duplica alertas ya
 * abiertas. NO hace push a n8n (eso vive en el plan ITAM posterior).
 */
class AssetAlertService
{
    /**
     * @return array{created: int, overdue: int, created_by_type: array<string, int>}
     */
    public function generate(): array
    {
        $byType = [
            AssetAlertConstants::TYPE_STOCK_BAJO => $this->_lowStockAlerts(),
            AssetAlertConstants::TYPE_ACTA_PENDIENTE => $this->_pendingActaAlerts(),
            AssetAlertConstants::TYPE_ACTIVO_SIN_RESPONSABLE => $this->_assetsWithoutResponsible(),
            AssetAlertConstants::TYPE_REGISTRO_INCOMPLETO => $this->_incompleteRecords(),
        ];

        return [
            'created' => array_sum($byType),
            'overdue' => $this->_markOverdueActas(),
            'created_by_type' => $byType,
        ];
    }

    /** Crea alertas de stock bajo para consumibles que están por debajo del mínimo. */
    private function _lowStockAlerts(): int
    {
        $consumables = TableRegistry::getTableLocator()->get('Consumables')->find('lowStock')->all();
        $created = 0;
        foreach ($consumables as $consumable) {
            $msg = sprintf(
                'Stock bajo: %s (%d <= %d).',
                $consumable->reference,
                $consumable->current_stock,
                $consumable->minimum_stock,
            );
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_STOCK_BAJO,
                ['consumable_id' => $consumable->id],
                $msg,
                AssetAlertConstants::PRIORITY_ALTA,
            );
        }

        return $created;
    }

    /** Crea alertas para movimientos con acta pendiente más antiguos que PENDING_DAYS (sin techo). */
    private function _pendingActaAlerts(): int
    {
        $pendingThreshold = date('Y-m-d H:i:s', strtotime('-' . AssetAlertConstants::ACTA_PENDING_DAYS . ' days'));

        $movements = TableRegistry::getTableLocator()->get('AssetMovements')->find()
            ->where([
                'acta_status' => AssetConstants::ACTA_PENDIENTE,
                'movement_date <=' => $pendingThreshold,
            ])
            ->all();

        $created = 0;
        foreach ($movements as $movement) {
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_ACTA_PENDIENTE,
                ['asset_id' => $movement->asset_id, 'asset_movement_id' => $movement->id],
                'Acta pendiente de cargar para el movimiento registrado.',
                AssetAlertConstants::PRIORITY_MEDIA,
            );
        }

        return $created;
    }

    /** Crea alertas para activos en estado asignado sin responsable registrado. */
    private function _assetsWithoutResponsible(): int
    {
        $assets = TableRegistry::getTableLocator()->get('Assets')->find()
            ->where(['status' => AssetConstants::STATUS_ASIGNADO, 'responsible_employee_id IS' => null])
            ->all();

        $created = 0;
        foreach ($assets as $asset) {
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_ACTIVO_SIN_RESPONSABLE,
                ['asset_id' => $asset->id],
                sprintf('El activo %s está asignado pero no tiene responsable.', $asset->code),
                AssetAlertConstants::PRIORITY_ALTA,
            );
        }

        return $created;
    }

    /** Crea alertas para activos sin número de serie. */
    private function _incompleteRecords(): int
    {
        $assets = TableRegistry::getTableLocator()->get('Assets')->find()
            ->where(['OR' => ['serial_number IS' => null, 'serial_number' => '']])
            ->all();

        $created = 0;
        foreach ($assets as $asset) {
            $created += $this->_createIfAbsent(
                AssetAlertConstants::TYPE_REGISTRO_INCOMPLETO,
                ['asset_id' => $asset->id],
                sprintf('El activo %s está incompleto (sin número de serie).', $asset->code),
                AssetAlertConstants::PRIORITY_BAJA,
            );
        }

        return $created;
    }

    /**
     * Marca como vencida toda alerta de acta pendiente abierta cuyo movimiento
     * supera el umbral de vencimiento y sigue sin acta cargada.
     */
    private function _markOverdueActas(): int
    {
        $alertsTable = TableRegistry::getTableLocator()->get('AssetAlerts');
        $overdueThreshold = date('Y-m-d H:i:s', strtotime('-' . AssetAlertConstants::ACTA_OVERDUE_DAYS . ' days'));

        $alerts = $alertsTable->find()
            ->where([
                'AssetAlerts.alert_type' => AssetAlertConstants::TYPE_ACTA_PENDIENTE,
                'AssetAlerts.status' => AssetAlertConstants::STATUS_ABIERTA,
                'AssetAlerts.asset_movement_id IS NOT' => null,
            ])
            ->contain(['AssetMovements'])
            ->all();

        $overdue = 0;
        foreach ($alerts as $alert) {
            $movement = $alert->asset_movement;
            if ($movement === null || $movement->acta_status !== AssetConstants::ACTA_PENDIENTE) {
                continue;
            }
            if ($movement->movement_date->format('Y-m-d H:i:s') > $overdueThreshold) {
                continue;
            }
            $alert->status = AssetAlertConstants::STATUS_VENCIDA;
            if ($alertsTable->save($alert)) {
                $overdue++;
            }
        }

        return $overdue;
    }

    /**
     * Crea una alerta si no existe una ABIERTA del mismo tipo y entidad.
     *
     * @param array<string, int> $entityKeys Llaves de entidad (asset_id, consumable_id, asset_movement_id).
     */
    private function _createIfAbsent(string $alertType, array $entityKeys, string $message, string $priority): int
    {
        $alertsTable = TableRegistry::getTableLocator()->get('AssetAlerts');

        $conditions = ['alert_type' => $alertType, 'status' => AssetAlertConstants::STATUS_ABIERTA] + $entityKeys;
        if ($alertsTable->exists($conditions)) {
            return 0;
        }

        $data = ['alert_type' => $alertType, 'message' => $message, 'priority' => $priority] + $entityKeys;
        $alert = $alertsTable->newEntity($data);
        $alert->status = AssetAlertConstants::STATUS_ABIERTA;

        return $alertsTable->save($alert) ? 1 : 0;
    }
}
