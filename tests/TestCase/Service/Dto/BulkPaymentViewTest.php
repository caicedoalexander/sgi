<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Dto;

use App\Service\Dto\BulkPaymentView;
use Cake\ORM\Entity;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BulkPaymentViewTest extends TestCase
{
    public function testFullyPopulatedView(): void
    {
        $bank = new Entity(['name' => 'Bancolombia']);
        $authUser = new Entity(['username' => 'tesorero']);
        $createdBy = new Entity(['username' => 'capturador']);
        $date = new DateTimeImmutable('2026-05-26');

        $view = new BulkPaymentView(
            id: 42,
            banking_entity: $bank,
            amount: 1500000.50,
            payment_date: $date,
            status: 'authorized',
            authorized: true,
            authorized_by_user: $authUser,
            authorized_date: $date,
            created_by_user: $createdBy,
            rejection_reason: null,
        );

        $this->assertSame(42, $view->id);
        $this->assertSame($bank, $view->banking_entity);
        $this->assertSame(1500000.50, $view->amount);
        $this->assertSame($date, $view->payment_date);
        $this->assertSame('authorized', $view->status);
        $this->assertTrue($view->authorized);
        $this->assertSame($authUser, $view->authorized_by_user);
        $this->assertSame($createdBy, $view->created_by_user);
        $this->assertNull($view->rejection_reason);
    }

    public function testAllowsNullablesForUnsetPayment(): void
    {
        $view = new BulkPaymentView(
            id: 1,
            banking_entity: null,
            amount: null,
            payment_date: null,
            status: 'pending',
            authorized: false,
            authorized_by_user: null,
            authorized_date: null,
            created_by_user: null,
            rejection_reason: 'Datos incompletos',
        );

        $this->assertNull($view->banking_entity);
        $this->assertNull($view->amount);
        $this->assertNull($view->payment_date);
        $this->assertFalse($view->authorized);
        $this->assertNull($view->authorized_by_user);
        $this->assertSame('Datos incompletos', $view->rejection_reason);
    }
}
