<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\Asset;

use App\Constants\Domain\Asset\AssetStatus;
use App\Constants\Domain\Asset\MovementType;
use PHPUnit\Framework\TestCase;

final class AssetStatusTest extends TestCase
{
    public function testLabelReturnsHumanReadableSpanish(): void
    {
        $this->assertSame('Disponible', AssetStatus::DISPONIBLE->label());
        $this->assertSame('Dado de baja', AssetStatus::DADO_DE_BAJA->label());
    }

    public function testIsTerminalOnlyForDadoDeBaja(): void
    {
        $this->assertTrue(AssetStatus::DADO_DE_BAJA->isTerminal());
        $this->assertFalse(AssetStatus::DISPONIBLE->isTerminal());
    }

    public function testValuesReturnsAllSlugs(): void
    {
        $this->assertSame(
            ['disponible', 'asignado', 'prestado', 'en_reparacion', 'dado_de_baja'],
            AssetStatus::values(),
        );
    }

    public function testMovementTypeRequiresActa(): void
    {
        $this->assertTrue(MovementType::ENTREGA->requiresActa());
        $this->assertTrue(MovementType::BAJA->requiresActa());
        $this->assertFalse(MovementType::INGRESO->requiresActa());
        $this->assertFalse(MovementType::TRASLADO->requiresActa());
        $this->assertFalse(MovementType::AJUSTE->requiresActa());
    }
}
