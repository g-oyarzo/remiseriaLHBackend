<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasting;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Events\NuevoMensajeViaje;
use App\Events\TestBroadcastingEvent;
use App\Events\UbicacionConductorActualizada;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Viaje;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_broadcasting_event_se_emite_de_forma_sincrona_en_test_channel(): void
    {
        $evento = new TestBroadcastingEvent('hola');

        $this->assertInstanceOf(ShouldBroadcastNow::class, $evento);
        $this->assertSame('test.event', $evento->broadcastAs());

        $canales = $evento->broadcastOn();
        $this->assertCount(1, $canales);
        $this->assertInstanceOf(Channel::class, $canales[0]);
        $this->assertSame('test-channel', $canales[0]->name);
        $this->assertSame('hola', $evento->broadcastWith()['mensaje']);
    }

    public function test_actualizar_ubicacion_dispara_el_evento_de_dominio(): void
    {
        Event::fake([UbicacionConductorActualizada::class]);

        $cuenta = Cuenta::factory()->rol(RolPersona::Conductor)->create();
        Conductor::factory()->enServicio()->create(['persona_id' => $cuenta->persona_id]);

        $response = $this->actingAs($cuenta, 'sanctum')->patchJson('/api/v1/conductor/ubicacion', [
            'lat' => -34.9214,
            'lng' => -57.9544,
        ]);

        $response->assertOk();

        Event::assertDispatched(UbicacionConductorActualizada::class, function (UbicacionConductorActualizada $event) use ($cuenta) {
            return $event->conductorId === $cuenta->persona_id
                && $event->ubicacion->lat === -34.9214
                && $event->ubicacion->lng === -57.9544;
        });
    }

    public function test_enviar_mensaje_dispara_el_evento_de_dominio(): void
    {
        Event::fake([NuevoMensajeViaje::class]);

        $cuentaCliente = Cuenta::factory()->rol(RolPersona::Cliente)->create();
        Cliente::factory()->create(['persona_id' => $cuentaCliente->persona_id]);

        $cuentaConductor = Cuenta::factory()->rol(RolPersona::Conductor)->create();
        Conductor::factory()->create(['persona_id' => $cuentaConductor->persona_id]);

        $viaje = Viaje::factory()->create([
            'cliente_id' => $cuentaCliente->persona_id,
            'conductor_id' => $cuentaConductor->persona_id,
            'estado' => EstadoViaje::EnCurso,
        ]);

        $response = $this->actingAs($cuentaCliente, 'sanctum')
            ->postJson("/api/v1/viajes/{$viaje->id}/mensajes", [
                'contenido' => 'Estoy en la esquina.',
            ]);

        $response->assertCreated();

        Event::assertDispatched(NuevoMensajeViaje::class, fn (NuevoMensajeViaje $event) => $event->mensaje->viaje_id === $viaje->id);
    }

    public function test_endpoint_de_autorizacion_de_canal_de_viaje_permite_al_cliente_del_viaje(): void
    {
        $cuentaCliente = Cuenta::factory()->rol(RolPersona::Cliente)->create();
        Cliente::factory()->create(['persona_id' => $cuentaCliente->persona_id]);

        $viaje = Viaje::factory()->create(['cliente_id' => $cuentaCliente->persona_id]);

        $response = $this->actingAs($cuentaCliente, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'channel_name' => "private-viaje.{$viaje->id}",
                'socket_id' => '1234.1234',
            ]);

        $response->assertOk();
    }

    public function test_endpoint_de_autorizacion_de_canal_de_viaje_rechaza_a_un_tercero(): void
    {
        $cuentaCliente = Cuenta::factory()->rol(RolPersona::Cliente)->create();
        Cliente::factory()->create(['persona_id' => $cuentaCliente->persona_id]);

        $otraCuenta = Cuenta::factory()->rol(RolPersona::Cliente)->create();
        Cliente::factory()->create(['persona_id' => $otraCuenta->persona_id]);

        $viaje = Viaje::factory()->create(['cliente_id' => $cuentaCliente->persona_id]);

        $response = $this->actingAs($otraCuenta, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'channel_name' => "private-viaje.{$viaje->id}",
                'socket_id' => '1234.1234',
            ]);

        $response->assertForbidden();
    }
}
