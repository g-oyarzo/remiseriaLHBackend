<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\RolPersona;
use App\Http\Controllers\Controller;
use App\Models\Administrador;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Persona;
use App\OpenApi\Schemas\AuthTokenResponse as AuthTokenResponseSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\LoginRequest as LoginRequestSchema;
use App\OpenApi\Schemas\MeResponse as MeResponseSchema;
use App\OpenApi\Schemas\RefreshTokenResponse as RefreshTokenResponseSchema;
use App\OpenApi\Schemas\RegisterRequest as RegisterRequestSchema;
use App\OpenApi\Schemas\SimpleMessageResponse;
use App\OpenApi\Schemas\ValidationErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * Autentica al usuario y devuelve un token Bearer (Sanctum).
     */
    #[OA\Post(
        path: '/auth/login',
        summary: 'Iniciar sesión',
        description: 'Autentica por email/contraseña. Revoca cualquier token previo de la cuenta (se admite una sola sesión activa a la vez) y emite uno nuevo.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: LoginRequestSchema::class),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login exitoso.', content: new OA\JsonContent(ref: AuthTokenResponseSchema::class)),
            new OA\Response(response: 401, description: 'Credenciales inválidas.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
            new OA\Response(response: 500, description: 'Error interno del servidor.', content: new OA\JsonContent(ref: \App\OpenApi\Schemas\ServerErrorResponse::class)),
        ],
    )]
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $cuenta = Cuenta::query()
            ->where('email', $validated['email'])
            ->first();

        // Corrección de auditoría (HALL-014, parte 2): antes, si el email no
        // existía, se retornaba de inmediato sin llamar a Hash::check(). El
        // hasheo de contraseñas (bcrypt) es intencionalmente costoso en CPU,
        // así que un email inexistente respondía notablemente más rápido que
        // uno existente con contraseña incorrecta. Ese delta de tiempo es un
        // canal lateral clásico para enumerar qué emails están registrados,
        // incluso sin que el mensaje de error lo diga explícitamente. Ahora
        // siempre se ejecuta un Hash::check() (contra un hash "señuelo" si
        // la cuenta no existe) para que ambos casos tomen un tiempo similar.
        $hashParaComparar = $cuenta->password ?? self::hashSeñuelo();

        if (! $cuenta || ! Hash::check($validated['password'], $hashParaComparar)) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Revocar tokens anteriores para evitar acumulación (1 sesión activa).
        $cuenta->tokens()->delete();

        $token = $cuenta->createToken(
            name: 'api-token',
            abilities: [$cuenta->rol->value],
        );

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'cuenta' => [
                    'id' => $cuenta->id,
                    'email' => $cuenta->email,
                    'rol' => $cuenta->rol->value,
                    'persona' => $cuenta->persona,
                ],
            ],
        ]);
    }

    /**
     * Hash bcrypt fijo usado únicamente para equiparar el tiempo de
     * respuesta de login() cuando el email no existe (ver HALL-014). Nunca
     * puede coincidir con ninguna contraseña real: no corresponde a ningún
     * texto plano conocido, solo se usa como entrada dummy de Hash::check().
     */
    private static function hashSeñuelo(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }

    /**
     * POST /api/v1/auth/register
     *
     * Registra un nuevo cliente (único rol auto-registrable).
     */
    #[OA\Post(
        path: '/auth/register',
        summary: 'Registrar un nuevo cliente',
        description: 'Único rol auto-registrable. Crea Persona + Cliente + Cuenta en una transacción y devuelve un token Bearer.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: RegisterRequestSchema::class),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Cuenta creada.', content: new OA\JsonContent(ref: AuthTokenResponseSchema::class)),
            new OA\Response(response: 422, description: 'Error de validación (formato inválido, contraseñas no coinciden, o DNI/email ya registrados — mensaje genérico por diseño, ver HALL-014).', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dni' => ['required', 'string', 'max:15'],
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Corrección de auditoría (HALL-014): antes se usaban las reglas
        // unique:personas,dni y unique:cuentas,email directamente en
        // validate(). Laravel genera un mensaje de error distinto para cada
        // una ("El dni ya ha sido registrado." vs "El email ya ha sido
        // registrado."), lo que permite a un atacante enumerar qué DNIs y
        // emails ya existen en el sistema probando registros uno por uno y
        // mirando cuál de los dos campos falla.
        //
        // Ahora se comprueba la existencia de ambos por separado, pero se
        // devuelve el mismo mensaje genérico en ambos campos sin importar
        // cuál (o cuáles) de los dos ya estaba en uso, de forma que la
        // respuesta sea indistinguible entre "el dni ya existe", "el email
        // ya existe" y "ambos ya existen".
        $dniExiste = Persona::query()->where('dni', $validated['dni'])->exists();
        $emailExiste = Cuenta::query()->where('email', $validated['email'])->exists();

        if ($dniExiste || $emailExiste) {
            throw ValidationException::withMessages([
                'dni' => ['No fue posible completar el registro con los datos ingresados.'],
                'email' => ['No fue posible completar el registro con los datos ingresados.'],
            ]);
        }

        try {
            $cuenta = DB::transaction(function () use ($validated): Cuenta {
                $persona = Persona::query()->create([
                    'dni' => $validated['dni'],
                    'nombre' => $validated['nombre'],
                    'apellido' => $validated['apellido'],
                    'telefono' => $validated['telefono'] ?? null,
                ]);

                Cliente::query()->create(['persona_id' => $persona->id]);

                return Cuenta::query()->create([
                    'persona_id' => $persona->id,
                    'email' => $validated['email'],
                    'password' => $validated['password'], // cast 'hashed' en el modelo
                    'rol' => RolPersona::Cliente,
                ]);
            });
        } catch (QueryException $e) {
            // Red de seguridad ante una colisión genuina de concurrencia
            // (dos registros con el mismo dni/email casi simultáneos): los
            // unique() de BD la detectan igual, y acá se traduce a la misma
            // respuesta 422 genérica en lugar de un 500 sin controlar.
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'dni' => ['No fue posible completar el registro con los datos ingresados.'],
                    'email' => ['No fue posible completar el registro con los datos ingresados.'],
                ]);
            }

            throw $e;
        }

        $token = $cuenta->createToken(
            name: 'api-token',
            abilities: [RolPersona::Cliente->value],
        );

        return response()->json([
            'message' => 'Registro exitoso.',
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'cuenta' => [
                    'id' => $cuenta->id,
                    'email' => $cuenta->email,
                    'rol' => $cuenta->rol->value,
                    'persona' => $cuenta->persona,
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revoca el token actual.
     */
    #[OA\Post(
        path: '/auth/logout',
        summary: 'Cerrar sesión',
        description: 'Revoca únicamente el token Bearer usado en esta request (currentAccessToken).',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada.', content: new OA\JsonContent(ref: SimpleMessageResponse::class)),
            new OA\Response(response: 401, description: 'No autenticado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * GET /api/v1/auth/me
     *
     * Devuelve la información del usuario autenticado.
     */
    #[OA\Get(
        path: '/auth/me',
        summary: 'Usuario autenticado',
        description: 'Devuelve la cuenta autenticada junto con su subtipo (cliente/conductor/administrador) según el rol.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(ref: MeResponseSchema::class)),
            new OA\Response(response: 401, description: 'No autenticado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        $cuenta = $request->user();
        $cuenta->load('persona');

        $data = [
            'id' => $cuenta->id,
            'email' => $cuenta->email,
            'rol' => $cuenta->rol->value,
            'persona' => $cuenta->persona,
        ];

        // Agregar subtipo según rol.
        match ($cuenta->rol) {
            RolPersona::Cliente => $data['cliente'] = Cliente::query()->find($cuenta->persona_id),
            RolPersona::Conductor => $data['conductor'] = Conductor::query()
                ->with('vehiculo')
                ->find($cuenta->persona_id),
            RolPersona::Administrador => $data['administrador'] = Administrador::query()->find($cuenta->persona_id),
        };

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/auth/refresh-token
     *
     * Revoca el token actual y emite uno nuevo (rotación de token).
     */
    #[OA\Post(
        path: '/auth/refresh-token',
        summary: 'Rotar token',
        description: 'Revoca el token Bearer actual y emite uno nuevo con las mismas abilities (rol).',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Token renovado.', content: new OA\JsonContent(ref: RefreshTokenResponseSchema::class)),
            new OA\Response(response: 401, description: 'No autenticado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function refreshToken(Request $request): JsonResponse
    {
        $cuenta = $request->user();

        // Revocar token actual.
        $cuenta->currentAccessToken()->delete();

        $token = $cuenta->createToken(
            name: 'api-token',
            abilities: [$cuenta->rol->value],
        );

        return response()->json([
            'message' => 'Token renovado exitosamente.',
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }
}