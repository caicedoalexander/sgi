<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants;

use App\Constants\RoleConstants;
use PHPUnit\Framework\TestCase;

final class RoleConstantsTest extends TestCase
{
    public function testAdminConstantValue(): void
    {
        $this->assertSame('Administrador', RoleConstants::ADMIN);
    }

    public function testSystemRolesContainsAdmin(): void
    {
        $this->assertContains(RoleConstants::ADMIN, RoleConstants::SYSTEM_ROLES);
    }

    public function testIsSystemTrueForAdmin(): void
    {
        $this->assertTrue(RoleConstants::isSystem('Administrador'));
    }

    public function testIsSystemFalseForRegularRole(): void
    {
        $this->assertFalse(RoleConstants::isSystem('Tesorería'));
        $this->assertFalse(RoleConstants::isSystem('Contabilidad'));
        $this->assertFalse(RoleConstants::isSystem(''));
    }

    public function testIsSystemRequiresExactStrictMatch(): void
    {
        $this->assertFalse(RoleConstants::isSystem('administrador'));
        $this->assertFalse(RoleConstants::isSystem('ADMINISTRADOR'));
        $this->assertFalse(RoleConstants::isSystem(' Administrador '));
    }
}
