<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\EmployeeNovelty;
use App\Service\Approval\ApprovalTokenManager;
use App\Service\Approval\ApprovalTokenStore;
use App\Service\NotificationService;
use App\Service\NoveltyApprovalService;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests de NoveltyApprovalService con dependencias mockeadas.
 *
 * Usa el ApprovalTokenManager real (es `final`) con un store mockeado, y verifica
 * la emisión del token, la construcción del enlace y el contrato ServiceResult sin
 * tocar la base de datos.
 */
final class NoveltyApprovalServiceTest extends TestCase
{
    private function makeService(
        NotificationService $notification,
        ApprovalTokenStore $store,
        Table $users,
        Table $novelties,
    ): NoveltyApprovalService {
        return new NoveltyApprovalService(
            $notification,
            new ApprovalTokenManager(),
            $store,
            $users,
            $novelties,
        );
    }

    public function testReturnsFailWhenNoApprover(): void
    {
        $store = $this->createMock(ApprovalTokenStore::class);
        $store->expects($this->never())->method('put');

        $service = $this->makeService(
            $this->createMock(NotificationService::class),
            $store,
            $this->createMock(Table::class),
            $this->createMock(Table::class),
        );

        $result = $service->sendApprovalLink(new EmployeeNovelty(['id' => 5]), 7, 'https://sgi.test');

        $this->assertFalse($result->success);
        $this->assertSame('La novedad no tiene un aprobador asignado.', $result->firstError());
    }

    public function testReturnsFailWhenApproverHasNoEmail(): void
    {
        $users = $this->createMock(Table::class);
        $users->method('get')->willReturn(new Entity(['email' => null]));

        $notification = $this->createMock(NotificationService::class);
        $notification->expects($this->never())->method('sendNoveltyApprovalEmail');

        $service = $this->makeService(
            $notification,
            $this->createMock(ApprovalTokenStore::class),
            $users,
            $this->createMock(Table::class),
        );

        $result = $service->sendApprovalLink(new EmployeeNovelty(['id' => 5, 'approver_id' => 7]), 9, 'https://sgi.test');

        $this->assertFalse($result->success);
        $this->assertSame('El aprobador asignado no tiene correo electrónico configurado.', $result->firstError());
    }

    public function testSendsEmailAndReturnsApprovalUrl(): void
    {
        $persistedHash = null;
        $store = $this->createMock(ApprovalTokenStore::class);
        $store->expects($this->once())
            ->method('put')
            ->willReturnCallback(function (string $tokenHash, string $type, int $id, int $by) use (&$persistedHash): void {
                $persistedHash = $tokenHash;
                $this->assertSame('employee_novelties', $type);
                $this->assertSame(5, $id);
                $this->assertSame(9, $by);
            });

        $approver = new Entity(['email' => 'jefe@sgi.test', 'full_name' => 'Jefe']);
        $users = $this->createMock(Table::class);
        $users->method('get')->willReturn($approver);

        $noveltyForEmail = new EmployeeNovelty(['id' => 5]);
        $novelties = $this->createMock(Table::class);
        $novelties->method('get')->willReturn($noveltyForEmail);

        $capturedUrl = null;
        $notification = $this->createMock(NotificationService::class);
        $notification->expects($this->once())
            ->method('sendNoveltyApprovalEmail')
            ->willReturnCallback(function ($a, $n, string $url, ?int $by) use ($approver, $noveltyForEmail, &$capturedUrl): void {
                $this->assertSame($approver, $a);
                $this->assertSame($noveltyForEmail, $n);
                $this->assertSame(9, $by);
                $capturedUrl = $url;
            });

        $service = $this->makeService($notification, $store, $users, $novelties);

        $result = $service->sendApprovalLink(new EmployeeNovelty(['id' => 5, 'approver_id' => 7]), 9, 'https://sgi.test');

        $this->assertTrue($result->success);

        // Ola 3: el secreto en claro viaja en la URL; en el store se persiste su
        // hash SHA256. El enlace devuelto debe contener el secreto cuyo hash es el
        // valor persistido vía put().
        $prefix = 'https://sgi.test/approve/';
        $this->assertStringStartsWith($prefix, $result->data['approvalUrl']);
        $secretInUrl = substr($result->data['approvalUrl'], strlen($prefix));
        $this->assertSame(ApprovalTokenManager::hashSecret($secretInUrl), $persistedHash);
        $this->assertSame($result->data['approvalUrl'], $capturedUrl);
    }

    public function testReturnsFailWhenNotificationThrows(): void
    {
        $users = $this->createMock(Table::class);
        $users->method('get')->willReturn(new Entity(['email' => 'jefe@sgi.test']));

        $novelties = $this->createMock(Table::class);
        $novelties->method('get')->willReturn(new EmployeeNovelty(['id' => 5]));

        $notification = $this->createMock(NotificationService::class);
        $notification->method('sendNoveltyApprovalEmail')
            ->willThrowException(new Exception('SMTP no configurado.'));

        $service = $this->makeService(
            $notification,
            $this->createMock(ApprovalTokenStore::class),
            $users,
            $novelties,
        );

        $result = $service->sendApprovalLink(new EmployeeNovelty(['id' => 5, 'approver_id' => 7]), 9, 'https://sgi.test');

        $this->assertFalse($result->success);
        $this->assertSame('SMTP no configurado.', $result->firstError());
    }
}
