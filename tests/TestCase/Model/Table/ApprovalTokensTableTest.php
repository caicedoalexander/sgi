<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\ApprovalTokensTable;
use Cake\I18n\DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Regresión Ola 3: la validación de la tabla debe exigir `token_hash` (no el
 * antiguo `token`). Un mismatch aquí rompería silenciosamente la persistencia
 * (save() devuelve false) en el flujo de aprobación. Pure-unit: newEntity corre
 * validationDefault sin tocar la base de datos.
 */
final class ApprovalTokensTableTest extends TestCase
{
    private function table(): ApprovalTokensTable
    {
        return new ApprovalTokensTable();
    }

    private function validPayload(): array
    {
        return [
            'token_hash' => str_repeat('a', 64),
            'entity_type' => 'employee_novelties',
            'entity_id' => 5,
            'created_by' => 1,
            'expires_at' => new DateTime('+48 hours'),
        ];
    }

    public function testAcceptsEntityWithTokenHash(): void
    {
        $entity = $this->table()->newEntity($this->validPayload());

        $this->assertSame([], $entity->getErrors());
        $this->assertSame(str_repeat('a', 64), $entity->token_hash);
    }

    public function testRequiresTokenHashOnCreate(): void
    {
        $payload = $this->validPayload();
        unset($payload['token_hash']);

        $entity = $this->table()->newEntity($payload);

        $this->assertArrayHasKey('token_hash', $entity->getErrors());
    }

    public function testDoesNotRequireLegacyTokenColumn(): void
    {
        $entity = $this->table()->newEntity($this->validPayload());

        $this->assertArrayNotHasKey('token', $entity->getErrors());
    }
}
