<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ServiceResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ServiceResultTest extends TestCase
{
    public function testOkWithoutData(): void
    {
        $result = ServiceResult::ok();
        $this->assertTrue($result->success);
        $this->assertNull($result->data);
        $this->assertSame([], $result->errors);
        $this->assertFalse($result->hasErrors());
        $this->assertNull($result->firstError());
    }

    public function testOkWithData(): void
    {
        $payload = ['id' => 42, 'name' => 'X'];
        $result = ServiceResult::ok($payload);
        $this->assertTrue($result->success);
        $this->assertSame($payload, $result->data);
    }

    public function testFailWithStringWrapsIntoArray(): void
    {
        $result = ServiceResult::fail('algo falló');
        $this->assertFalse($result->success);
        $this->assertSame(['algo falló'], $result->errors);
        $this->assertTrue($result->hasErrors());
        $this->assertSame('algo falló', $result->firstError());
        $this->assertNull($result->data);
    }

    public function testFailWithArrayPreservesAllErrors(): void
    {
        $result = ServiceResult::fail(['e1', 'e2', 'e3']);
        $this->assertFalse($result->success);
        $this->assertSame(['e1', 'e2', 'e3'], $result->errors);
        $this->assertSame('e1', $result->firstError());
    }

    public function testFailWithEmptyArrayIsStillFailButReportsNoErrors(): void
    {
        $result = ServiceResult::fail([]);
        $this->assertFalse($result->success);
        $this->assertFalse($result->hasErrors());
        $this->assertNull($result->firstError());
    }

    public function testReadOnlyFields(): void
    {
        $result = ServiceResult::ok('x');
        $ref = new ReflectionClass($result);
        foreach (['success', 'data', 'errors'] as $prop) {
            $this->assertTrue($ref->getProperty($prop)->isReadOnly(), "Property {$prop} should be readonly");
        }
    }
}
