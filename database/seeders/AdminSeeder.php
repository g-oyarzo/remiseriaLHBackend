<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RolPersona;
use App\Models\Administrador;
use App\Models\Cuenta;
use App\Models\Persona;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Corrección de auditoría (HALL-025): antes se creaba la cuenta admin con
     * la contraseña fija 'password'. Si este seeder llegara a correr en
     * producción (por accidente o por un `migrate:fresh --seed`), quedaría
     * una cuenta administradora con una contraseña trivialmente adivinable.
     *
     * Ahora: si ADMIN_PASSWORD está definida en el entorno, se usa esa. Si
     * no, se genera una contraseña aleatoria de 32 caracteres y se imprime
     * UNA sola vez por consola (y solo si la cuenta se está creando por
     * primera vez), para que quien corra el seeder pueda copiarla y
     * guardarla en un gestor de contraseñas.
     */
    public function run(): void
    {
        $persona = Persona::query()->firstOrCreate(
            ['dni' => '00000000'],
            [
                'nombre' => 'Administrador',
                'apellido' => 'Remisería LH',
                'telefono' => '2211234567',
            ]
        );

        Administrador::query()->firstOrCreate(['persona_id' => $persona->id]);

        $cuentaExistente = Cuenta::query()->where('email', 'admin@remiserialh.com')->first();

        if ($cuentaExistente) {
            return;
        }

        $password = (string) (env('ADMIN_PASSWORD') ?: Str::password(32));

        Cuenta::query()->create([
            'persona_id' => $persona->id,
            'email' => 'admin@remiserialh.com',
            'password' => Hash::make($password),
            'rol' => RolPersona::Administrador,
            'email_verified_at' => now(),
        ]);

        if (! env('ADMIN_PASSWORD')) {
            $this->command?->warn('AdminSeeder: se generó una contraseña aleatoria para admin@remiserialh.com');
            $this->command?->warn("Contraseña (guárdala ahora, no se vuelve a mostrar): {$password}");
        }
    }
}