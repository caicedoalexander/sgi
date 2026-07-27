<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Model\Entity\Provider;
use App\ViewModel\AdvanceLegalizationViewModel;
use PHPUnit\Framework\TestCase;

/**
 * Los 10 flags rol-aware nuevos llegan intactos a build(), que es lo que el
 * template destructura. Sin esto, el gating de la vista es letra muerta.
 */
final class AdvanceLegalizationViewModelFlagsTest extends TestCase
{
    /**
     * El provider es obligatorio: build() lee `$invoice->provider->name` para
     * derivar el beneficiario. Sin él, PHP emite "Attempt to read property on
     * null" — no rompe hoy, pero sí el día que se active failOnWarning.
     */
    private function _invoice(): Invoice
    {
        return new Invoice([
            'id' => 1,
            'invoice_number' => 'ANT-1',
            'amount' => 1000.0,
            'provider_id' => 1,
            'provider' => new Provider(['name' => 'Proveedor de prueba', 'document_number' => '900123456']),
        ]);
    }

    private function _viewModel(bool $value): AdvanceLegalizationViewModel
    {
        return new AdvanceLegalizationViewModel(
            invoice: $this->_invoice(),
            leg: new AdvanceLegalization(['status' => AdvanceConstants::STATUS_CONTABILIDAD]),
            roleName: 'Contabilidad',
            linkedInvoices: [],
            bankingEntities: [],
            surplusPayment: null,
            canOperateCurrentStep: $value,
            canLinkInvoices: $value,
            canUploadRelationDocument: $value,
            canMoveToAprobacion: $value,
            canMarkSigned: $value,
            canReturnToAprobacion: $value,
            canMarkExact: $value,
            canRegisterShortage: $value,
            canRegisterSurplus: $value,
            canConfirmShortage: $value,
        );
    }

    private function _viewModelWithSingleFlag(string $flagName): AdvanceLegalizationViewModel
    {
        $flags = array_fill_keys($this->_flagNames(), false);
        $flags[$flagName] = true;

        // Un único spread de un array con claves-string: PHP no admite `...$flags`
        // después de argumentos con nombre, así que fusionamos todo en un solo array.
        $args = [
            'invoice' => $this->_invoice(),
            'leg' => new AdvanceLegalization(['status' => AdvanceConstants::STATUS_CONTABILIDAD]),
            'roleName' => 'Contabilidad',
            'linkedInvoices' => [],
            'bankingEntities' => [],
            'surplusPayment' => null,
            ...$flags,
        ];

        return new AdvanceLegalizationViewModel(...$args);
    }

    public function testBuildExposesAllTenFlagsAsTrue(): void
    {
        $built = $this->_viewModel(true)->build();

        foreach ($this->_flagNames() as $flag) {
            $this->assertTrue($built[$flag], "El flag {$flag} debería ser true");
        }
    }

    public function testFlagsDefaultToFalse(): void
    {
        $built = $this->_viewModel(false)->build();

        foreach ($this->_flagNames() as $flag) {
            $this->assertFalse($built[$flag], "El flag {$flag} debería ser false");
        }
    }

    /**
     * Cada flag viaja por su propio carril. Con todos los flags al mismo valor,
     * un cross-wire en build() (p. ej. `'canMarkSigned' => $this->canMarkExact`)
     * pasaría desapercibido; activándolos de uno en uno, no.
     */
    public function testEachFlagIsWiredToItsOwnConstructorArgument(): void
    {
        foreach ($this->_flagNames() as $flagUnderTest) {
            $built = $this->_viewModelWithSingleFlag($flagUnderTest)->build();

            foreach ($this->_flagNames() as $flag) {
                $expected = $flag === $flagUnderTest;
                $this->assertSame(
                    $expected,
                    $built[$flag],
                    "Con solo {$flagUnderTest} activo, {$flag} debería ser " . ($expected ? 'true' : 'false'),
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function _flagNames(): array
    {
        return [
            'canOperateCurrentStep',
            'canLinkInvoices',
            'canUploadRelationDocument',
            'canMoveToAprobacion',
            'canMarkSigned',
            'canReturnToAprobacion',
            'canMarkExact',
            'canRegisterShortage',
            'canRegisterSurplus',
            'canConfirmShortage',
        ];
    }
}
