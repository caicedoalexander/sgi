<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline;

use App\Service\Pipeline\FilterResult;
use PHPUnit\Framework\TestCase;

final class FilterResultTest extends TestCase
{
    public function testReadOnlyFieldsExposed(): void
    {
        $r = new FilterResult(patch: ['a' => 1], errors: ['x']);
        $this->assertSame(['a' => 1], $r->patch);
        $this->assertSame(['x'], $r->errors);
    }

    public function testHasErrorsTrueWhenErrorsNonEmpty(): void
    {
        $r = new FilterResult(patch: [], errors: ['e1']);
        $this->assertTrue($r->hasErrors());
    }

    public function testHasErrorsFalseWhenEmpty(): void
    {
        $r = new FilterResult(patch: ['a' => 1], errors: []);
        $this->assertFalse($r->hasErrors());
    }
}
