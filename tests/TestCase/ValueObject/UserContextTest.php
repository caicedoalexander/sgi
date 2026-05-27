<?php
declare(strict_types=1);

namespace App\Test\TestCase\ValueObject;

use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UserContextTest extends TestCase
{
    public function testDirectConstruction(): void
    {
        $ctx = new UserContext(roleId: 5, roleName: 'Tesorería');
        $this->assertSame(5, $ctx->roleId);
        $this->assertSame('Tesorería', $ctx->roleName);
    }

    public function testDefaultRoleNameIsEmptyString(): void
    {
        $ctx = new UserContext(roleId: 7);
        $this->assertSame(7, $ctx->roleId);
        $this->assertSame('', $ctx->roleName);
    }

    public function testFromIdentityReadsRoleIdAndName(): void
    {
        $identity = new stdClass();
        $identity->role_id = 3;
        $identity->role = new stdClass();
        $identity->role->name = 'Contabilidad';

        $ctx = UserContext::fromIdentity($identity);
        $this->assertSame(3, $ctx->roleId);
        $this->assertSame('Contabilidad', $ctx->roleName);
    }

    public function testFromIdentityHandlesMissingRoleId(): void
    {
        $identity = new stdClass();
        $ctx = UserContext::fromIdentity($identity);
        $this->assertSame(0, $ctx->roleId);
        $this->assertSame('', $ctx->roleName);
    }

    public function testFromIdentityCastsStringRoleIdToInt(): void
    {
        $identity = new stdClass();
        $identity->role_id = '11';
        $ctx = UserContext::fromIdentity($identity);
        $this->assertSame(11, $ctx->roleId);
    }

    public function testFromIdentityHandlesNullRoleObject(): void
    {
        $identity = new stdClass();
        $identity->role_id = 1;
        $identity->role = null;
        $ctx = UserContext::fromIdentity($identity);
        $this->assertSame(1, $ctx->roleId);
        $this->assertSame('', $ctx->roleName);
    }
}
