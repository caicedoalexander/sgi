<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use Cake\Cache\Cache;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;

/**
 * Invalida el cache de contadores del sidebar (config 'sidebar') ante cualquier
 * cambio en la tabla que alimenta esos contadores.
 *
 * Los contadores se cachean por rol (`sidebar_counters_{roleId}`), pero una sola
 * transición de pipeline mueve el registro entre las bandejas de varios roles a
 * la vez (el que lo pierde y el que lo gana). Por eso se limpia TODO el cache en
 * lugar de un rol concreto: no es posible saber qué roles cambian sin recalcular.
 *
 * Adjuntar SOLO a las tablas que alimentan SidebarCounterService (Invoices,
 * PettyCashRecords, Refunds, EmployeeNovelties, NoveltyLiquidationDocs,
 * AdvanceLegalizations), no a catálogos.
 */
class SidebarCacheBehavior extends Behavior
{
    /**
     * @param \Cake\Event\EventInterface $event Save event.
     * @param \Cake\Datasource\EntityInterface $entity Entidad guardada.
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity): void
    {
        Cache::clear('sidebar');
    }

    /**
     * @param \Cake\Event\EventInterface $event Delete event.
     * @param \Cake\Datasource\EntityInterface $entity Entidad eliminada.
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity): void
    {
        Cache::clear('sidebar');
    }
}
