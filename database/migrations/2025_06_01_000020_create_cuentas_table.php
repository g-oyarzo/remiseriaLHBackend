<?php

declare(strict_types=1);

use App\Enums\RolPersona;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('persona_id')
                ->unique()
                ->constrained('personas')
                ->cascadeOnDelete();

            $table->string('email')->unique();
            $table->string('password');
            $table->enum('rol', array_column(RolPersona::cases(), 'value'));
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('rol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas');
    }
};