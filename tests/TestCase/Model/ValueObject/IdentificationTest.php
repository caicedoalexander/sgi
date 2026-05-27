<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\ValueObject;

use App\Model\ValueObject\Identification;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentificationTest extends TestCase
{
    public function testConstructionWithValidValues(): void
    {
        $id = new Identification(type: 'CC', number: '1234567');
        $this->assertSame('CC', $id->type);
        $this->assertSame('1234567', $id->number);
    }

    public function testEmptyTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('document_type');
        new Identification(type: '', number: '1');
    }

    public function testEmptyNumberIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('document_number');
        new Identification(type: 'CC', number: '');
    }

    public function testFormattedJoinsWithMiddleDot(): void
    {
        $id = new Identification('CC', '987654321');
        $this->assertSame('CC · 987654321', $id->formatted());
    }

    public function testToStringDelegatesToFormatted(): void
    {
        $id = new Identification('CE', '5');
        $this->assertSame('CE · 5', (string)$id);
    }

    public function testEqualsRequiresBothFieldsMatch(): void
    {
        $a = new Identification('CC', '1');
        $b = new Identification('CC', '1');
        $c = new Identification('CE', '1');
        $d = new Identification('CC', '2');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertFalse($a->equals($d));
    }
}
