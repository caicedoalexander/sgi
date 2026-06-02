<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel\Support;

use App\ViewModel\Support\SubmitButton;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para SubmitButton — decisión del label de submit (avanzar vs guardar)
 * y escape del label del siguiente estado.
 */
final class SubmitButtonTest extends TestCase
{
    public function testDecideReturnsAdvanceWhenCanAdvanceWithoutErrors(): void
    {
        $html = SubmitButton::decide(true, [], 'Contabilidad');

        $this->assertStringContainsString('Guardar y Avanzar a: Contabilidad', $html);
    }

    public function testDecideReturnsSaveWhenCannotAdvance(): void
    {
        $this->assertStringContainsString('Guardar Cambios', SubmitButton::decide(false, [], 'Contabilidad'));
    }

    public function testDecideReturnsSaveWhenThereAreErrors(): void
    {
        $this->assertStringContainsString('Guardar Cambios', SubmitButton::decide(true, ['falta algo'], 'Contabilidad'));
    }

    public function testDecideReturnsSaveWhenNoNextLabel(): void
    {
        $this->assertStringContainsString('Guardar Cambios', SubmitButton::decide(true, [], null));
    }

    public function testForAdvanceEscapesLabel(): void
    {
        $html = SubmitButton::forAdvance('A & B <x>');

        $this->assertStringContainsString('A &amp; B &lt;x&gt;', $html);
        $this->assertStringNotContainsString('<x>', $html);
    }
}
