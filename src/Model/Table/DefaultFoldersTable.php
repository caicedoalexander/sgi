<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class DefaultFoldersTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('default_folders');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->integer('sort_order')
            ->allowEmptyString('sort_order');

        return $validator;
    }

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
            'sort_order' => ['label' => 'Orden', 'type' => 'integer'],
        ];
    }

    public function getExcelSheetTitle(): string
    {
        return 'Carpetas por Defecto';
    }

    public function getExcelDownloadSlug(): string
    {
        return 'carpetas_defecto';
    }
}
