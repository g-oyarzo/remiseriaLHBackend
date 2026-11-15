<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mapas\GuardaDeProveedor;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\ServicioMapasNoDisponible;
use App\ValueObjects\Coordinate;
use Illuminate\Console\Command;

/**
 * Diagnóstico del servicio de mapas: driver activo, consumo de la cuota
 * local y estado del circuit breaker. Con --probar hace UNA consulta real
 * (consume una unidad de cuota del proveedor).
 */
class EstadoMapasCommand extends Command
{
    protected $signature = 'mapas:estado
        {--probar : Hace una consulta real de prueba (consume cuota del proveedor)}';

    protected $description = 'Muestra el proveedor de mapas configurado, el consumo de cuota y el estado del circuito';

    public function handle(ProveedorDistancia $proveedor): int
    {
        $driver = (string) config('services.mapas.driver');
        $this->line("Driver configurado: <info>{$driver}</info> (".class_basename($proveedor).')');

        if (in_array($driver, ['ors', 'google', 'osrm'], true)) {
            $guarda = new GuardaDeProveedor($driver);
            $estado = $guarda->estado();
            $limites = $guarda->limites();

            $this->table(['Período', 'Consultas', 'Límite local'], [
                ['Minuto actual', $estado['minuto'], $limites['minuto'] ?? 'sin límite'],
                ['Día actual', $estado['dia'], $limites['dia'] ?? 'sin límite'],
                ['Mes actual', $estado['mes'], $limites['mes'] ?? 'sin límite'],
            ]);
            $this->line('Circuito: '.($estado['circuito_abierto_hasta'] !== null
                ? "<comment>abierto hasta {$estado['circuito_abierto_hasta']}</comment>"
                : '<info>cerrado</info>')." (fallas consecutivas: {$estado['fallas_consecutivas']})");
        }

        if (! $this->option('probar')) {
            return self::SUCCESS;
        }

        // Dos puntos fijos del centro de La Plata (~3 km de manejo).
        try {
            $ruta = $proveedor->calcularRuta(new Coordinate(-34.9214, -57.9544), new Coordinate(-34.9055, -57.9325));
        } catch (ServicioMapasNoDisponible $e) {
            $this->error('La consulta de prueba falló: '.$e->getMessage().' (el cotizador usaría la contingencia por zonas).');

            return self::FAILURE;
        }

        $this->info("Consulta de prueba correcta: {$ruta->distanciaKm()} km, {$ruta->duracionMinutos()} min (proveedor {$ruta->proveedor}).");

        return self::SUCCESS;
    }
}
