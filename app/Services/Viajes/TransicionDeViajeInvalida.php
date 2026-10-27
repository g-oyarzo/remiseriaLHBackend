<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use RuntimeException;

/**
 * Una transición de estado del viaje no está permitida en su estado
 * actual (409). Es una excepción propia para no capturar por error
 * RuntimeException genéricas: QueryException hereda de RuntimeException y
 * su mensaje incluye el SQL.
 */
final class TransicionDeViajeInvalida extends RuntimeException {}
