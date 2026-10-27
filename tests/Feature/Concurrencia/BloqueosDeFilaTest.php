<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrencia;

use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Models\AsignacionViaje;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Pago;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\Services\Asignacion\AsignadorConductores;
use App\Services\Pagos\RegistroDePagos;
use App\ValueObjects\Coordinate;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrencia real con dos conexiones MySQL: una "transacción competidora"
 * toma el lock de una fila y se verifica que la operación bajo prueba
 * espera ese lock (y termina por timeout) en lugar de leer y escribir sin
 * coordinarse. Es la garantía que evita dobles pagos, dobles asignaciones y
 * transiciones de estado pisadas.
 *
 * Usa DatabaseTruncation (y no RefreshDatabase) porque la segunda conexión
 * necesita ver los datos confirmados.
 */
class BloqueosDeFilaTest extends TestCase
{
    use DatabaseTruncation;

    private Connection $competidora;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql_competidora' => config('database.connections.mysql')]);
        $this->competidora = DB::connection('mysql_competidora');
    }

    protected function tearDown(): void
    {
        if ($this->competidora->transactionLevel() > 0) {
            $this->competidora->rollBack();
        }
        DB::purge('mysql_competidora');

        // DatabaseTruncation limpia al comenzar cada test, no al terminar:
        // sin esto los datos confirmados acá quedarían visibles para las
        // clases de test que usan RefreshDatabase.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    /**
     * Ejecuta $operacion mientras la conexión competidora mantiene un
     * SELECT ... FOR UPDATE sobre $sql, y verifica que la operación quedó
     * esperando el lock (error 1205, lock wait timeout).
     *
     * @param  list<mixed>  $bindings
     */
    private function assertEsperaElLock(string $sql, array $bindings, Closure $operacion): void
    {
        $this->competidora->beginTransaction();
        $this->competidora->select($sql.' FOR UPDATE', $bindings);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $operacion();
            $this->fail('La operación no esperó el lock de la transacción competidora.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('1205', $e->getMessage());
        } finally {
            $this->competidora->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }
    }

    private function conductor(): Conductor
    {
        $cuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);

        return Conductor::factory()
            ->disponibleEn(new Coordinate(-34.9214, -57.9544))
            ->create(['persona_id' => $cuenta->persona_id, 'calificacion' => 4.5]);
    }

    public function test_registrar_un_pago_espera_el_lock_del_viaje(): void
    {
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'costo_final' => '1000.00']);

        $this->assertEsperaElLock('SELECT id FROM viajes WHERE id = ?', [$viaje->id], function () use ($viaje): void {
            app(RegistroDePagos::class)->registrar($viaje->id, MetodoPago::Efectivo, '1000.00', null, $viaje->cliente_id);
        });

        $this->assertSame(0, Pago::query()->count());
    }

    public function test_aceptar_una_oferta_espera_el_lock_del_viaje(): void
    {
        $conductor = $this->conductor();
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Solicitado]);
        AsignacionViaje::factory()->create(['viaje_id' => $viaje->id, 'conductor_id' => $conductor->persona_id]);

        $this->assertEsperaElLock('SELECT id FROM viajes WHERE id = ?', [$viaje->id], function () use ($viaje, $conductor): void {
            app(AsignadorConductores::class)->aceptar($viaje->id, $conductor->persona_id);
        });

        $this->assertSame(EstadoViaje::Solicitado, $viaje->fresh()->estado);
    }

    public function test_ofrecer_un_viaje_espera_el_lock_del_conductor_elegido(): void
    {
        $conductor = $this->conductor();
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Solicitado, 'origen' => new Coordinate(-34.9214, -57.9544)]);

        // Otra transacción está ofreciéndole un viaje a este mismo conductor.
        $this->assertEsperaElLock('SELECT persona_id FROM conductores WHERE persona_id = ?', [$conductor->persona_id], function () use ($viaje): void {
            app(AsignadorConductores::class)->buscarYOfrecer($viaje->id);
        });

        $this->assertSame(0, AsignacionViaje::query()->count());
    }

    public function test_cancelar_espera_el_lock_del_viaje(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        $viaje = Viaje::factory()->create(['cliente_id' => Cliente::factory()->create(['persona_id' => $cliente->persona_id])->persona_id]);

        $this->assertEsperaElLock('SELECT id FROM viajes WHERE id = ?', [$viaje->id], function () use ($cliente, $viaje): void {
            $respuesta = $this->actingAs($cliente)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar");

            throw $respuesta->exception ?? new \RuntimeException('Sin excepción');
        });

        $this->assertSame(EstadoViaje::Solicitado, $viaje->fresh()->estado);
    }

    public function test_actualizar_la_tarifa_espera_el_lock_de_la_tarifa_activa(): void
    {
        $tarifa = Tarifa::factory()->create(['activa' => true]);
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->assertEsperaElLock('SELECT id FROM tarifas WHERE id = ?', [$tarifa->id], function () use ($admin): void {
            $respuesta = $this->actingAs($admin)->putJson('/api/v1/admin/tarifas', ['precio_base' => 2000, 'precio_por_km' => 500]);

            throw $respuesta->exception ?? new \RuntimeException('Sin excepción');
        });

        $this->assertSame(1, Tarifa::query()->count());
    }
}
