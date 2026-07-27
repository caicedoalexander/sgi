<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class MaritalStatusesTable extends Table implements ExcelExportableInterface
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

        $this->setTable('marital_statuses');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Employees', [
            'foreignKey' => 'marital_status_id',
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
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

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
        $rules->add($rules->isUnique(['name'], message: 'El nombre ya existe.'), [
            'errorField' => 'name',
        ]);

        return $rules;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getExcelFields(): array
    {
        return [
            'name' => ['label' => 'Nombre', 'type' => 'string', 'is_key' => true, 'required' => true],
        ];
    }

    /**
     * Título de la hoja al exportar/importar el catálogo en Excel.
     *
     * @return string
     */
    public function getExcelSheetTitle(): string
    {
        return 'Estados Civiles';
    }

    /**
     * Slug del archivo al descargar la plantilla Excel del catálogo.
     *
     * @return string
     */
    public function getExcelDownloadSlug(): string
    {
        return 'estados_civiles';
    }
}
