<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EducationLevelsTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('education_levels');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Employees', [
            'foreignKey' => 'education_level_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

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
        ];
    }

    public function getExcelSheetTitle(): string
    {
        return 'Niveles Educativos';
    }

    public function getExcelDownloadSlug(): string
    {
        return 'niveles_educativos';
    }
}
