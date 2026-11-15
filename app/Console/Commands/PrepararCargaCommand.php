<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EstadoConductor;
use App\Enums\EstadoVehiculo;
use App\Enums\RolPersona;
use App\Models\Administrador;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Marca;
use App\Models\Persona;
use App\Models\Tarifa;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\ValueObjects\Coordinate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos sintéticos para las pruebas de carga (deploy/carga): N conductores
 * en servicio, M clientes y un administrador observador, con tokens de
 * Sanctum. Imprime las credenciales en JSON por la salida estándar.
 *
 * Todos los registros usan el dominio de e-mail @carga.invalid y se borran
 * con --limpiar. Se niega a correr con APP_ENV=production.
 */
class PrepararCargaCommand extends Command
{
    private const DOMINIO = 'carga.invalid';

    protected $signature = 'carga:preparar
        {--conductores=200 : Cantidad de conductores sintéticos}
        {--clientes=20 : Cantidad de clientes sintéticos}
        {--limpiar : Borra los datos sintéticos y termina}';

    protected $description = 'Crea (o borra) datos sintéticos para pruebas de carga. Nunca en producción.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('carga:preparar no se ejecuta en producción.');

            return self::FAILURE;
        }

        $this->limpiar();

        if ($this->option('limpiar')) {
            $this->info('Datos sintéticos de carga eliminados.');

            return self::SUCCESS;
        }

        $conductores = max(1, (int) $this->option('conductores'));
        $clientes = max(0, (int) $this->option('clientes'));

        if (Tarifa::vigente() === null) {
            Tarifa::query()->create(['precio_base' => '1500.00', 'precio_por_km' => '350.00']);
        }

        $marca = Marca::query()->firstOrCreate(['nombre' => 'Carga']);
        // Centro de La Plata; cada conductor arranca en un punto distinto.
        $centro = new Coordinate(-34.9214, -57.9544);

        $salida = ['conductores' => [], 'clientes' => [], 'administrador' => null];

        DB::transaction(function () use ($conductores, $clientes, $marca, $centro, &$salida): void {
            for ($i = 1; $i <= $conductores; $i++) {
                $cuenta = $this->cuenta("conductor{$i}", RolPersona::Conductor);
                $vehiculo = Vehiculo::query()->create([
                    'marca_id' => $marca->id,
                    'modelo' => 'Carga',
                    'patente' => sprintf('CG%05d', $i),
                    'color' => 'Blanco',
                    'anio' => 2020,
                    'estado' => EstadoVehiculo::Operando,
                ]);
                $inicio = new Coordinate($centro->lat + ($i % 20) * 0.002, $centro->lng + intdiv($i, 20) * 0.002);
                Conductor::query()->create([
                    'persona_id' => $cuenta->persona_id,
                    'cuil' => sprintf('20%08d9', 90_000_000 + $i),
                    'fecha_nacimiento' => '1990-01-01',
                    'domicilio_localidad' => 'La Plata',
                    'domicilio_calle' => 'Calle',
                    'domicilio_numero' => (string) $i,
                    'estado' => EstadoConductor::Activo,
                    'en_servicio' => true,
                    'vehiculo_id' => $vehiculo->id,
                ]);
                $salida['conductores'][] = [
                    'id' => $cuenta->persona_id,
                    'token' => $cuenta->createToken('carga')->plainTextToken,
                    'lat' => $inicio->lat,
                    'lng' => $inicio->lng,
                ];
            }

            for ($i = 1; $i <= $clientes; $i++) {
                $cuenta = $this->cuenta("cliente{$i}", RolPersona::Cliente);
                Cliente::query()->create(['persona_id' => $cuenta->persona_id]);
                $salida['clientes'][] = ['id' => $cuenta->persona_id, 'token' => $cuenta->createToken('carga')->plainTextToken];
            }

            $admin = $this->cuenta('observador', RolPersona::Administrador);
            Administrador::query()->create(['persona_id' => $admin->persona_id]);
            $salida['administrador'] = ['id' => $admin->persona_id, 'token' => $admin->createToken('carga')->plainTextToken];
        });

        $this->line(json_encode($salida, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function cuenta(string $alias, RolPersona $rol): Cuenta
    {
        $persona = Persona::query()->create([
            'dni' => 'C'.substr(hash('crc32b', $alias), 0, 8).Str::upper(Str::random(4)),
            'nombre' => 'Carga',
            'apellido' => $alias,
        ]);

        return Cuenta::query()->create([
            'persona_id' => $persona->id,
            'email' => "{$alias}@".self::DOMINIO,
            'password' => Str::random(40),
            'rol' => $rol,
        ]);
    }

    private function limpiar(): void
    {
        $personas = Cuenta::query()->where('email', 'like', '%@'.self::DOMINIO)->pluck('persona_id');

        if ($personas->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($personas): void {
            DB::table('personal_access_tokens')->where('tokenable_type', Cuenta::class)
                ->whereIn('tokenable_id', Cuenta::query()->whereIn('persona_id', $personas)->pluck('id'))->delete();

            // Los viajes nunca se borran (claves foráneas restrict): las
            // personas sintéticas con viajes quedan retenidas, fuera de
            // servicio y con sus valores únicos liberados.
            $conViajes = Viaje::query()->whereIn('cliente_id', $personas)->pluck('cliente_id')
                ->merge(Viaje::query()->whereIn('conductor_id', $personas)->pluck('conductor_id'))
                ->unique();

            foreach ($conViajes as $personaId) {
                Cuenta::query()->where('persona_id', $personaId)->update(['email' => "retenida-{$personaId}@carga-retenida.invalid"]);
                Conductor::query()->whereKey($personaId)->update(['en_servicio' => false, 'cuil' => sprintf('99%09d', $personaId)]);
                Vehiculo::query()->whereIn('id', Conductor::query()->whereKey($personaId)->pluck('vehiculo_id'))
                    ->update(['patente' => sprintf('RT%08d', $personaId)]);
            }

            $personas = $personas->diff($conViajes)->values();
            $vehiculos = Conductor::query()->whereIn('persona_id', $personas)->pluck('vehiculo_id')->filter();

            Conductor::query()->whereIn('persona_id', $personas)->delete();
            Cliente::query()->whereIn('persona_id', $personas)->delete();
            Administrador::query()->whereIn('persona_id', $personas)->delete();
            Vehiculo::query()->whereIn('id', $vehiculos)->delete();
            Cuenta::query()->whereIn('persona_id', $personas)->delete();
            Persona::query()->whereIn('id', $personas)->delete();
        });
    }
}
