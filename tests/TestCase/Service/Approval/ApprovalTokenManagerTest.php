<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Approval;

use App\Service\Approval\ApprovalTokenManager;
use App\Service\Approval\ApprovalTokenStore;
use App\Service\Approval\ConsumeContext;
use Cake\Database\Connection;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests del núcleo ApprovalTokenManager con un store mockeado.
 *
 * Verifica la orquestación (generación, delegación a resolve, consumo single-use
 * bajo transacción) con independencia de la tabla concreta.
 */
final class ApprovalTokenManagerTest extends TestCase
{
    private function inlineTransactionalConnection(): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')
            ->willReturnCallback(fn(callable $cb) => $cb());

        return $connection;
    }

    public function testIssueGeneratesHexTokenAndPersistsViaStore(): void
    {
        $captured = null;
        $store = $this->createMock(ApprovalTokenStore::class);
        $store->expects($this->once())
            ->method('put')
            ->willReturnCallback(function (string $token, string $type, int $id, int $by) use (&$captured): void {
                $captured = compact('token', 'type', 'id', 'by');
            });

        $token = (new ApprovalTokenManager())->issue($store, 'invoices', 5, 7, 48);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $captured['token']); // hash, no claro
        $this->assertSame('invoices', $captured['type']);
        $this->assertSame(5, $captured['id']);
        $this->assertSame(7, $captured['by']);
    }

    public function testResolveDelegatesToStoreWithoutLock(): void
    {
        $record = new Entity(['id' => 1]);
        $store = $this->createMock(ApprovalTokenStore::class);
        $store->expects($this->once())->method('findValid')
            ->with(hash('sha256', 'tok'), false)->willReturn($record);

        $this->assertSame($record, (new ApprovalTokenManager())->resolve($store, 'tok'));
    }

    public function testConsumeMarksAndReturnsRecordUnderLock(): void
    {
        $record = new Entity(['entity_type' => 'invoices', 'entity_id' => 5]);
        $context = new ConsumeContext('ok', '1.2.3.4', 'agent', null, 7);
        $store = $this->createMock(ApprovalTokenStore::class);
        $store->method('getConnection')->willReturn($this->inlineTransactionalConnection());
        $store->expects($this->once())->method('findValid')
            ->with(hash('sha256', 'tok'), true)->willReturn($record);
        $store->expects($this->once())->method('markConsumed')->with($record, 'approve', $context)->willReturn(true);

        $result = (new ApprovalTokenManager())->consume($store, 'tok', 'approve', $context);

        $this->assertSame($record, $result);
    }

    public function testConsumeReturnsNullWhenTokenInvalid(): void
    {
        $store = $this->createMock(ApprovalTokenStore::class);
        $store->method('getConnection')->willReturn($this->inlineTransactionalConnection());
        $store->expects($this->once())->method('findValid')
            ->with(hash('sha256', 'tok'), true)->willReturn(null);
        $store->expects($this->never())->method('markConsumed');

        $this->assertNull((new ApprovalTokenManager())->consume($store, 'tok', 'approve', new ConsumeContext()));
    }

    public function testConsumeReturnsNullWhenMarkConsumedFails(): void
    {
        $record = new Entity(['entity_type' => 'invoices', 'entity_id' => 5]);
        $store = $this->createMock(ApprovalTokenStore::class);
        $store->method('getConnection')->willReturn($this->inlineTransactionalConnection());
        $store->method('findValid')->willReturn($record);
        $store->method('markConsumed')->willReturn(false);

        $this->assertNull((new ApprovalTokenManager())->consume($store, 'tok', 'approve', new ConsumeContext()));
    }

    public function testConsumeRunsDomainEffectAfterMarkAndReturnsRecord(): void
    {
        $record = new Entity(['entity_type' => 'invoices', 'entity_id' => 5]);
        $context = new ConsumeContext('ok', '1.2.3.4', 'agent', null, 7);
        $order = [];

        $store = $this->createMock(ApprovalTokenStore::class);
        $store->method('getConnection')->willReturn($this->inlineTransactionalConnection());
        $store->expects($this->once())->method('findValid')
            ->with(hash('sha256', 'tok'), true)->willReturn($record);
        $store->expects($this->once())->method('markConsumed')
            ->willReturnCallback(function () use (&$order): bool {
                $order[] = 'mark';

                return true;
            });

        $effect = function (object $r) use (&$order, $record): bool {
            $order[] = 'effect';
            $this->assertSame($record, $r);

            return true;
        };

        $result = (new ApprovalTokenManager())->consume($store, 'tok', 'approve', $context, $effect);

        $this->assertSame($record, $result);
        $this->assertSame(['mark', 'effect'], $order);
    }

    public function testConsumeReturnsNullWhenDomainEffectFails(): void
    {
        $record = new Entity(['entity_type' => 'invoices', 'entity_id' => 5]);

        $store = $this->createMock(ApprovalTokenStore::class);
        $store->method('getConnection')->willReturn($this->inlineTransactionalConnection());
        $store->method('findValid')->willReturn($record);
        $store->method('markConsumed')->willReturn(true);

        $effect = fn(object $r): bool => false;

        $result = (new ApprovalTokenManager())
            ->consume($store, 'tok', 'approve', new ConsumeContext(), $effect);

        // inlineTransactionalConnection devuelve el `false` del closure directamente;
        // con una conexión real ese `false` provocaría el rollback del markConsumed.
        $this->assertNull($result);
    }

    public function testGenerateSecretReturnsRandomHex64(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ApprovalTokenManager::generateSecret());
        $this->assertNotSame(
            ApprovalTokenManager::generateSecret(),
            ApprovalTokenManager::generateSecret(),
        );
    }

    public function testHashSecretIsDeterministicSha256(): void
    {
        // SHA256 de un valor conocido + determinismo + formato 64-hex.
        $this->assertSame(hash('sha256', 'abc'), ApprovalTokenManager::hashSecret('abc'));
        $this->assertSame(
            ApprovalTokenManager::hashSecret('same'),
            ApprovalTokenManager::hashSecret('same'),
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ApprovalTokenManager::hashSecret('x'));
    }
}
