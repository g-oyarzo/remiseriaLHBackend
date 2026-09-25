<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Marca;
use App\Models\Tarifa;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $marcas = Marca::all();

        if ($marcas->isEmpty()) {
            $marcas = Marca::factory()->count(5)->create();
        }

        // 8 vehículos con un conductor asignado 1 a 1, cada uno con su cuenta de acceso.
        $vehiculos = Vehiculo::factory()->count(8)->recycle($marcas)->create();

        $conductoresActivos = $vehiculos->map(function (Vehiculo $vehiculo): Conductor {
            $conductor = Conductor::factory()
                ->enServicio()
                ->create(['vehiculo_id' => $vehiculo->id]);

            Cuenta::factory()->create([
                'persona_id' => $conductor->persona_id,
                'rol' => RolPersona::Conductor,
            ]);

            return $conductor;
        });

        // Un par de vehículos de reserva, sin conductor asignado todavía.
        Vehiculo::factory()->count(2)->recycle($marcas)->create();

        // 15 clientes con su cuenta de acceso.
        $clientes = Cliente::factory()
            ->count(15)
            ->create()
            ->each(function (Cliente $cliente): void {
                Cuenta::factory()->create([
                    'persona_id' => $cliente->persona_id,
                    'rol' => RolPersona::Cliente,
                ]);
            });

        $tarifa = Tarifa::vigente();

        // Viajes pendientes de asignación.
        Viaje::factory()
            ->count(10)
            ->recycle($clientes)
            ->create(['tarifa_id' => $tarifa?->id]);

        // Viajes finalizados, uno por cada conductor activo, con su pago registrado.
        $conductoresActivos->each(function (Conductor $conductor) use ($clientes, $tarifa): void {
            Viaje::factory()
                ->recycle($clientes)
                ->finalizado()
                ->has(\App\Models\Pago::factory(), 'pago')
                ->create([
                    'tarifa_id' => $tarifa?->id,
                    'conductor_id' => $conductor->persona_id,
                    'vehiculo_id' => $conductor->vehiculo_id,
                ]);
        });
    }
}