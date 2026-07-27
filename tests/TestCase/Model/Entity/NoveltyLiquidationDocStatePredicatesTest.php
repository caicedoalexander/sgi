<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\NoveltyConstants;
use App\Model\Entity\NoveltyLiquidationDoc;
use PHPUnit\Framework\TestCase;

/**
 * Cubre los predicados de pago del agregado NoveltyLiquidationDoc (fuente única de
 * las reglas estado-solo que componen NoveltyActionPolicy). Puro, sin BD.
 */
final class NoveltyLiquidationDocStatePredicatesTest extends TestCase
{
    private function doc(array $props): NoveltyLiquidationDoc
    {
        return new NoveltyLiquidationDoc($props);
    }

    public function testCanRegisterPaymentRequiresTesoreria(): void
    {
        $this->assertTrue($this->doc(['pipeline_status' => NoveltyConstants::STATUS_TESORERIA])->canRegisterPayment());
        $this->assertFalse($this->doc(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->canRegisterPayment());
        $this->assertFalse($this->doc([])->canRegisterPayment());
    }

    public function testCanAuthorizePaymentRequiresAutorizacionPago(): void
    {
        $this->assertTrue($this->doc(['pipeline_status' => NoveltyConstants::STATUS_AUTORIZACION_PAGO])->canAuthorizePayment());
        $this->assertFalse($this->doc(['pipeline_status' => NoveltyConstants::STATUS_TESORERIA])->canAuthorizePayment());
    }

    public function testCanRejectPaymentIsAliasOfCanAuthorizePayment(): void
    {
        $autorizacion = $this->doc(['pipeline_status' => NoveltyConstants::STATUS_AUTORIZACION_PAGO]);
        $this->assertTrue($autorizacion->canRejectPayment());

        $tesoreria = $this->doc(['pipeline_status' => NoveltyConstants::STATUS_TESORERIA]);
        $this->assertFalse($tesoreria->canRejectPayment());
    }

    public function testCanConfirmPaymentRequiresVerificacionPago(): void
    {
        $this->assertTrue($this->doc(['pipeline_status' => NoveltyConstants::STATUS_VERIFICACION_PAGO])->canConfirmPayment());
        $this->assertFalse($this->doc(['pipeline_status' => NoveltyConstants::STATUS_AUTORIZACION_PAGO])->canConfirmPayment());
    }
}
