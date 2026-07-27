<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PositionsTable extends Table implements ExcelExportableInterface
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

        $this->setTable('positions');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Employees', [
            'foreignKey' => 'position_id',
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
            ->scalar('code')
            ->maxLength('code', 20)
            ->allowEmptyString('code');

        $validator
            ->scalar('name')
            ->maxLength('name', 150)
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
        $rules->add($rules->isUnique(['code'], message: 'El código ya existe.'), [
            'errorField' => 'code',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }

    /**
     * Custom finder: arma la lista clave-valor (id => etiqueta) para poblar selects.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query builder.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findCodeList(SelectQuery $query): SelectQuery
    {
        return $query->select(['id', 'code', 'name'])
            ->formatResults(function ($results) {
                return $results->combine('id', function ($row) {
                    return $row->code ? $row->code . ' - ' . $row->name : $row->name;
                });
            });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getExcelFields(): array
    {
        return [
            'code' => ['label' => 'Código', 'type' => 'string', 'is_key' => true, 'required' => true],
            'name' => ['label' => 'Cargo', 'type' => 'string', 'required_new' => true],
        ];
    }

    /**
     * Título de la hoja al exportar/importar el catálogo en Excel.
     *
     * @return string
     */
    public function getExcelSheetTitle(): string
    {
        return 'Cargos';
    }

    /**
     * Slug del archivo al descargar la plantilla Excel del catálogo.
     *
     * @return string
     */
    public function getExcelDownloadSlug(): string
    {
        return 'cargos';
    }
}
