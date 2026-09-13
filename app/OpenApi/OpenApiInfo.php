<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Contenedor de los atributos OpenAPI "globales" del documento (info,
 * servidor y esquema de seguridad). No se instancia; swagger-php solo lee
 * los atributos de la clase durante el escaneo (config/l5-swagger.php).
 */
#[OA\Info(
    version: '1.0.0',
    title: 'Remisería LH API',
    description: "API REST de **Remisería LH**: gestión integral de un servicio de remises.\n\n"
        .'Incluye autenticación por roles (Administrador, Cliente, Conductor), solicitud y '
        .'ciclo de vida de viajes, tarifas dinámicas por distancia, pagos, mensajería interna '
        .'por viaje y rastreo GPS de conductores. Los eventos en tiempo real (ubicación de '
        .'conductores y mensajes de chat) se transmiten vía WebSockets (Laravel Reverb) sobre '
        .'los canales privados `conductor.{conductorId}` y `viaje.{viajeId}`; ver '
        .'`POST /broadcasting/auth` para su autorización.',
    contact: new OA\Contact(name: 'Equipo Backend', email: 'backend@remiseria-lh.example'),
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'Servidor de la API (v1)',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Token Bearer emitido por Laravel Sanctum. Se obtiene en `POST /auth/login` o '
        .'`POST /auth/register` (campo `data.token` de la respuesta) y se envía en el header '
        .'`Authorization: Bearer {token}`.',
)]
#[OA\Tag(name: 'Auth', description: 'Registro, login y gestión de la sesión (Sanctum).')]
#[OA\Tag(name: 'Viajes', description: 'Ciclo de vida de un viaje: solicitud, aceptación, inicio, finalización, cancelación y calificación.')]
#[OA\Tag(name: 'Conductor', description: 'Acciones propias del rol conductor: estado de servicio y ubicación GPS.')]
#[OA\Tag(name: 'Mensajes', description: 'Chat interno entre cliente y conductor durante un viaje.')]
#[OA\Tag(name: 'Pagos', description: 'Registro de pagos de viajes finalizados.')]
#[OA\Tag(name: 'Tarifas', description: 'Consulta y configuración de tarifas.')]
#[OA\Tag(name: 'Admin - Clientes', description: 'Consulta de clientes (solo administrador).')]
#[OA\Tag(name: 'Admin - Conductores', description: 'Gestión de conductores (solo administrador).')]
#[OA\Tag(name: 'Admin - Vehículos', description: 'Gestión de vehículos (solo administrador).')]
#[OA\Tag(name: 'Marcas', description: 'Catálogo de marcas de vehículos.')]
final class OpenApiInfo
{
    // Clase vacía: solo actúa como ancla para los atributos globales de arriba.
}