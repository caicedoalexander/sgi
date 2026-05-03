<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\InvoiceConstants;
use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use App\Service\InvoicePipelineService;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class InvoicesTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoices');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Providers', [
            'foreignKey' => 'provider_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ExpenseTypes', [
            'foreignKey' => 'expense_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CostCenters', [
            'foreignKey' => 'cost_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('ConfirmedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'confirmed_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('RegisteredByUsers', [
            'className' => 'Users',
            'foreignKey' => 'registered_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ApproverUsers', [
            'className' => 'Users',
            'foreignKey' => 'approver_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('InvoiceHistories', [
            'foreignKey' => 'invoice_id',
        ]);
        $this->hasMany('InvoiceObservations', [
            'foreignKey' => 'invoice_id',
        ]);
        $this->hasMany('InvoiceDocuments', [
            'foreignKey' => 'invoice_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('InvoiceApprovals', [
            'foreignKey' => 'invoice_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->belongsTo('PettyCashRecords', [
            'foreignKey' => 'petty_cash_record_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Refunds', [
            'foreignKey' => 'refund_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Advance', [
            'className' => 'Invoices',
            'foreignKey' => 'advance_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasOne('AdvanceLegalization', [
            'className' => 'AdvanceLegalizations',
            'foreignKey' => 'advance_invoice_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('InvoicePayments', [
            'foreignKey' => 'invoice_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PaymentSchedulingItems', [
            'foreignKey' => 'invoice_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('invoice_number')
            ->maxLength('invoice_number', 50)
            ->allowEmptyString('invoice_number');

        $validator
            ->date('registration_date')
            ->allowEmptyDate('registration_date');

        $validator
            ->date('issue_date')
            ->requirePresence('issue_date', 'create')
            ->notEmptyDate('issue_date');

        $validator
            ->date('due_date')
            ->allowEmptyDate('due_date');

        $validator
            ->scalar('document_type')
            ->maxLength('document_type', 50)
            ->requirePresence('document_type', 'create')
            ->notEmptyString('document_type')
            ->inList('document_type', InvoiceConstants::DOCUMENT_TYPES);

        $validator
            ->scalar('purchase_order')
            ->maxLength('purchase_order', 50)
            ->allowEmptyString('purchase_order');

        $validator
            ->scalar('detail')
            ->requirePresence('detail', 'create')
            ->notEmptyString('detail');

        $validator
            ->decimal('amount')
            ->requirePresence('amount', 'create')
            ->notEmptyString('amount');

        $validator
            ->integer('provider_id')
            ->allowEmptyString('provider_id');

        $validator
            ->scalar('equivalent_holder_type')
            ->allowEmptyString('equivalent_holder_type')
            ->inList('equivalent_holder_type', InvoiceConstants::HOLDER_TYPES)
            ->add('equivalent_holder_type', 'requiredWhenReciboDeCaja', [
                'rule' => function ($value, $context) {
                    $docType = $context['data']['document_type'] ?? null;
                    if ($docType !== InvoiceConstants::DOCTYPE_RECIBO_CAJA) {
                        return true;
                    }

                    return !in_array($value, [null, ''], true);
                },
                'message' => 'Debe seleccionar el titular cuando el tipo es Recibo de Caja.',
            ]);

        $validator
            ->integer('employee_id')
            ->allowEmptyString('employee_id');

        $validator
            ->scalar('manual_document_number')
            ->maxLength('manual_document_number', 30)
            ->allowEmptyString('manual_document_number');

        $validator
            ->integer('operation_center_id')
            ->requirePresence('operation_center_id', 'create')
            ->notEmptyString('operation_center_id');

        $validator
            ->integer('expense_type_id')
            ->requirePresence('expense_type_id', 'create')
            ->notEmptyString('expense_type_id');

        $validator
            ->integer('cost_center_id')
            ->allowEmptyString('cost_center_id');

        $validator
            ->integer('registered_by')
            ->requirePresence('registered_by', 'create')
            ->notEmptyString('registered_by');

        $validator
            ->integer('approver_id')
            ->allowEmptyString('approver_id');

        $validator
            ->scalar('area_approval')
            ->inList('area_approval', InvoiceConstants::APPROVAL_STATUSES);

        $validator
            ->scalar('dian_validation')
            ->inList('dian_validation', InvoiceConstants::DIAN_STATUSES);

        $validator
            ->scalar('pipeline_status')
            ->inList('pipeline_status', InvoicePipelineService::ALL_STATUSES);

        $validator
            ->scalar('ready_for_payment')
            ->allowEmptyString('ready_for_payment')
            ->inList('ready_for_payment', InvoiceConstants::READY_FOR_PAYMENT_OPTIONS);

        $validator
            ->scalar('payment_status')
            ->allowEmptyString('payment_status')
            ->inList('payment_status', InvoiceConstants::PAYMENT_STATUSES);

        $validator
            ->date('area_approval_date')
            ->allowEmptyDate('area_approval_date');

        $validator
            ->boolean('accrued');

        $validator
            ->date('accrual_date')
            ->allowEmptyDate('accrual_date');

        $validator
            ->date('full_payment_date')
            ->allowEmptyDate('full_payment_date');

        $validator
            ->integer('confirmed_by')
            ->allowEmptyString('confirmed_by');

        $validator
            ->integer('advance_id')
            ->allowEmptyString('advance_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['invoice_number'], message: 'El número de factura ya existe.'), [
            'errorField' => 'invoice_number',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('provider_id', 'Providers'), [
            'errorField' => 'provider_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('employee_id', 'Employees'), [
            'errorField' => 'employee_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('operation_center_id', 'OperationCenters'), ['errorField' => 'operation_center_id']);
        $rules->add($rules->existsIn('expense_type_id', 'ExpenseTypes'), ['errorField' => 'expense_type_id']);
        $rules->add($rules->existsIn('cost_center_id', 'CostCenters'), [
            'errorField' => 'cost_center_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('registered_by', 'RegisteredByUsers'), ['errorField' => 'registered_by']);
        $rules->add($rules->existsIn('confirmed_by', 'ConfirmedByUsers'), [
            'errorField' => 'confirmed_by',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('approver_id', 'ApproverUsers'), [
            'errorField' => 'approver_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('advance_id', 'Advance'), [
            'errorField' => 'advance_id',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }

    /**
     * Auto-approve Anticipo invoices on creation: set area_approval and dian_validation
     * so the invoice can flow through the pipeline without external approval.
     *
     * @param \Cake\Event\EventInterface $event Event instance.
     * @param \Cake\Datasource\EntityInterface $entity Invoice entity being saved.
     * @param \ArrayObject $options Save options.
     * @return void
     */
    public function beforeSave(
        EventInterface $event,
        EntityInterface $entity,
        ArrayObject $options,
    ): void {
        if ($entity->isNew() && ($entity->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO) {
            $entity->area_approval = InvoiceConstants::APPROVAL_APPROVED;
            $entity->approver_id = null;
            $entity->area_approval_date = date('Y-m-d');
            if (empty($entity->dian_validation)) {
                $entity->dian_validation = InvoiceConstants::DIAN_APPROVED;
            }
        }

        // Limpiar campos exclusivos de Recibo de Caja cuando el doc type no aplica.
        // Evita persistir datos fantasma si el cliente no respeta el contrato del JS
        // (POST sin JS, switch de tipo en edit, etc.).
        if (($entity->document_type ?? null) !== InvoiceConstants::DOCTYPE_RECIBO_CAJA) {
            $entity->equivalent_holder_type = null;
            $entity->employee_id = null;
            $entity->manual_document_number = null;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getExcelFields(): array
    {
        return [
            'invoice_number' => ['label' => 'Número Factura', 'type' => 'string'],
            'document_type' => ['label' => 'Tipo Documento', 'type' => 'string'],
            'registration_date' => ['label' => 'Fecha Registro', 'type' => 'date'],
            'issue_date' => ['label' => 'Fecha Emisión', 'type' => 'date'],
            'due_date' => ['label' => 'Fecha Vencimiento', 'type' => 'date'],
            // display_only field keys MUST match the association property name on the
            // entity (lowerCamel of the alias). For belongsTo('Providers') Cake exposes
            // $entity->provider.
            'provider' => [
                'label' => 'Proveedor', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Providers', 'fk_target' => 'provider_id',
            ],
            'operation_center' => [
                'label' => 'Centro Operación', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'OperationCenters', 'fk_target' => 'operation_center_id',
            ],
            'expense_type' => [
                'label' => 'Tipo Gasto', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'ExpenseTypes', 'fk_target' => 'expense_type_id',
            ],
            'cost_center' => [
                'label' => 'Centro Costos', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'CostCenters', 'fk_target' => 'cost_center_id',
            ],
            'detail' => ['label' => 'Detalle', 'type' => 'string'],
            'amount' => ['label' => 'Valor', 'type' => 'decimal'],
            'dian_validation' => ['label' => 'Validación DIAN', 'type' => 'string'],
            'accrued' => ['label' => 'Causada', 'type' => 'boolean'],
            'accrual_date' => ['label' => 'Fecha Causación', 'type' => 'date'],
            'ready_for_payment' => ['label' => 'Lista para Pago', 'type' => 'string'],
            'payment_status' => ['label' => 'Estado Pago', 'type' => 'string'],
            'full_payment_date' => ['label' => 'Fecha Pago Total', 'type' => 'date'],
            'pipeline_status' => ['label' => 'Estado Pipeline', 'type' => 'string'],
        ];
    }

    /**
     * @return string
     */
    public function getExcelSheetTitle(): string
    {
        return 'Facturas';
    }

    /**
     * @return string
     */
    public function getExcelDownloadSlug(): string
    {
        return 'facturas';
    }

    /**
     * @return bool
     */
    public function isExcelImportable(): bool
    {
        return false;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getExcelExportContains(): array
    {
        return ['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters'];
    }
}
