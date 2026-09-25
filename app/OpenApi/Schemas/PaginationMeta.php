<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Metadatos que agrega Illuminate\Pagination\LengthAwarePaginator al
 * serializar a JSON (los controladores hacen `response()->json($paginator)`
 * directamente, sin envoltorio adicional). Se combina vía `allOf` con la
 * propiedad `data` propia de cada recurso paginado.
 */
#[OA\Schema(
    schema: 'PaginationMeta',
    type: 'object',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'first_page_url', type: 'string', nullable: true),
        new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 5),
        new OA\Property(property: 'last_page_url', type: 'string', nullable: true),
        new OA\Property(
            property: 'links',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'url', type: 'string', nullable: true),
                    new OA\Property(property: 'label', type: 'string'),
                    new OA\Property(property: 'active', type: 'boolean'),
                ],
            ),
        ),
        new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'to', type: 'integer', nullable: true, example: 15),
        new OA\Property(property: 'total', type: 'integer', example: 68),
    ],
)]
final class PaginationMeta
{
}