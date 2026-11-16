<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use RuntimeException;

/**
 * Una fila del archivo legacy no se puede importar. El mensaje describe el
 * motivo sin incluir el dato personal completo.
 */
final class FilaInvalida extends RuntimeException {}
