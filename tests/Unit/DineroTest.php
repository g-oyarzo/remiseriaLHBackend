<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dinero;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DineroTest extends TestCase
{
    /**
     * @return array<string, array{0: string|int|float, 1: int}>
     */
    public static function montos(): array
    {
        return [
            'entero' => [1500, 150000],
            'string con dos decimales' => ['1234.56', 123456],
            'string con un decimal' => ['10.5', 1050],
            'float' => [0.1 + 0.2, 30],
            'cero' => ['0', 0],
            'negativo' => ['-12.34', -1234],
            'máximo decimal(10,2)' => [Dinero::MAXIMO, 9999999999],
        ];
    }

    #[DataProvider('montos')]
    public function test_convierte_a_centavos(string|int|float $monto, int $esperado): void
    {
        $this->assertSame($esperado, Dinero::aCentavos($monto));
    }

    public function test_desde_centavos_siempre_tiene_dos_decimales(): void
    {
        $this->assertSame('1234.56', Dinero::desdeCentavos(123456));
        $this->assertSame('0.05', Dinero::desdeCentavos(5));
        $this->assertSame('10.00', Dinero::desdeCentavos(1000));
        $this->assertSame('-0.50', Dinero::desdeCentavos(-50));
    }

    public function test_rechaza_textos_que_no_son_montos(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinero::aCentavos('12.345');
    }
}
