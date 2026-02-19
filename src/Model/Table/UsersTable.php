<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('full_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('Approvers', [
            'foreignKey' => 'user_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('username')
            ->maxLength('username', 50)
            ->requirePresence('username', 'create')
            ->notEmptyString('username');

        $validator
            ->scalar('password')
            ->maxLength('password', 255)
            ->requirePresence('password', 'create')
            ->notEmptyString('password');

        $validator
            ->scalar('full_name')
            ->maxLength('full_name', 150)
            ->requirePresence('full_name', 'create')
            ->notEmptyString('full_name');

        $validator
            ->email('email')
            ->maxLength('email', 100)
            ->requirePresence('email', 'create')
            ->notEmptyString('email');

        $validator
            ->boolean('active')
            ->notEmptyString('active');

        $validator
            ->integer('role_id')
            ->requirePresence('role_id', 'create')
            ->notEmptyString('role_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['username']), ['errorField' => 'username']);
        $rules->add($rules->isUnique(['email']), ['errorField' => 'email']);
        $rules->add($rules->existsIn('role_id', 'Roles'), ['errorField' => 'role_id']);

        return $rules;
    }

    public function findAuth(SelectQuery $query, array $options = []): SelectQuery
    {
        return $query
            ->contain(['Roles'])
            ->where([$this->aliasField('active') => true]);
    }
}
