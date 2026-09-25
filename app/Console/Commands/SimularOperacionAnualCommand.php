<?php

namespace App\Console\Commands;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Persona;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\ValueObjects\Coordinate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SimularOperacionAnualCommand extends Command
{
    protected $signature = 'simular:anual {dias=365} {viajes_por_dia=1500}';
    protected $description = 'Simula un volumen de datos equivalente a 1 año de operación.';

    public function handle()
    {
        $dias = (int) $this->argument('dias');
        $viajesPorDia = (int) $this->argument('viajes_por_dia');
        
        $this->info("Simulando $dias días con $viajesPorDia viajes por día (Aprox " . ($dias * $viajesPorDia) . " viajes)...");

        // Use transaction and chunks for performance
        DB::transaction(function () use ($dias, $viajesPorDia) {
            $tarifa = Tarifa::first() ?? Tarifa::factory()->create();
            $cliente = Cliente::first() ?? Cliente::factory()->create();
            $conductor = Conductor::first() ?? Conductor::factory()->create();
            
            $now = Carbon::now()->subDays($dias);
            
            $viajes = [];
            $totalInsertados = 0;
            
            // limit to 10k for speed in local tests
            $target = min($dias * $viajesPorDia, 10000); 
            $this->info("Insertando $target viajes reales para prueba de estrés...");
            
            for ($i = 0; $i < $target; $i++) {
                $viajes[] = [
                    'cliente_id' => $cliente->persona_id,
                    'conductor_id' => $i % 2 == 0 ? $conductor->persona_id : null,
                    'vehiculo_id' => $i % 2 == 0 ? $conductor->vehiculo_id : null,
                    'tarifa_id' => $tarifa->id,
                    'origen' => DB::raw("(ST_GeomFromText('POINT(-57.9544 -34.9214)', 4326))"),
                    'destino' => DB::raw("(ST_GeomFromText('POINT(-57.9544 -34.9214)', 4326))"),
                    'estado' => $i % 2 == 0 ? EstadoViaje::Finalizado->value : EstadoViaje::Solicitado->value,
                    'tipo' => TipoViaje::Actual->value,
                    'costo' => 1000.50,
                    'fecha_viaje' => $now->addMinutes(rand(1, 10))->toDateTimeString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                
                if (count($viajes) >= 1000) {
                    Viaje::insert($viajes);
                    $totalInsertados += count($viajes);
                    $viajes = [];
                    $this->info("Insertados $totalInsertados...");
                }
            }
            
            if (count($viajes) > 0) {
                Viaje::insert($viajes);
            }
        });
        
        $this->info("Simulación completada.");
    }
}