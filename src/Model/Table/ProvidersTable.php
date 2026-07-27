<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ProviderConstants;
use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ProvidersTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;

    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('providers');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Invoices', [
            'foreignKey' => 'provider_id',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('document_type')
            ->maxLength('document_type', 20)
            ->requirePresence('document_type', 'create')
            ->notEmptyString('document_type')
            ->inList('document_type', ProviderConstants::DOCUMENT_TYPES);

        $validator
            ->scalar('document_number')
            ->maxLength('document_number', 20)
            ->requirePresence('document_number', 'create')
            ->notEmptyString('document_number');

        $validator
            ->scalar('name')
            ->maxLength('name', 200)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->boolean('active')
            ->notEmptyString('active');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['document_number']), ['errorField' => 'document_number']);

        return $rules;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getExcelFields(): array
    {
        return [
            'document_type' => [
                'label' => 'Tipo de documento', 'type' => 'string',
                'aliases' => ['tipo doc', 'tipo'],
            ],
            'document_number' => [
                'label' => 'NIT/Documento', 'type' => 'string',
                'required' => true, 'is_key' => true,
                'aliases' => ['nit', 'documento', 'numero documento'],
            ],
            'name' => ['label' => 'Nombre', 'type' => 'string', 'required_new' => true],
            'active' => ['label' => 'Activo', 'type' => 'boolean'],
        ];
    }

    /**
     * @return string
     */
    public function getExcelSheetTitle(): string
    {
        return 'Proveedores';
    }

    /**
     * @return string
     */
    public function getExcelDownloadSlug(): string
    {
        return 'proveedores';
    }
}
