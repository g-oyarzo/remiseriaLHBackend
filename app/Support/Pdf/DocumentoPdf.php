<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * Generador mínimo de PDF de solo texto (A4, Helvetica, WinAnsiEncoding).
 *
 * Alcanza para comprobantes y reportes tabulares sin agregar una
 * dependencia externa: no interpreta HTML ni carga recursos remotos o
 * fuentes, que son la superficie de ataque habitual de los generadores de
 * PDF basados en HTML. Los textos se convierten a Windows-1252 (cubre el
 * español); los caracteres no representables se reemplazan por "?".
 */
final class DocumentoPdf
{
    private const ANCHO = 595.28;

    private const ALTO = 841.89;

    private const MARGEN = 50.0;

    /** @var list<string> contenido (operadores PDF) de cada página */
    private array $paginas = [];

    private string $actual = '';

    private float $y;

    public function __construct(private readonly string $tituloDocumento = 'Documento')
    {
        $this->nuevaPagina();
    }

    public function titulo(string $texto): self
    {
        return $this->texto($texto, tamano: 16, negrita: true)->espacio(4);
    }

    public function subtitulo(string $texto): self
    {
        return $this->espacio(6)->texto($texto, tamano: 12, negrita: true)->espacio(2);
    }

    public function texto(string $texto, float $tamano = 10, bool $negrita = false, float $x = self::MARGEN): self
    {
        $this->reservar($tamano * 1.45);
        $this->escribir($x, $this->y, $texto, $tamano, $negrita);

        return $this;
    }

    /**
     * Línea "Etiqueta: valor" con la etiqueta en negrita.
     */
    public function par(string $etiqueta, string $valor, float $tamano = 10): self
    {
        $this->reservar($tamano * 1.45);
        $this->escribir(self::MARGEN, $this->y, $etiqueta.':', $tamano, true);
        $this->escribir(self::MARGEN + 150, $this->y, $valor, $tamano, false);

        return $this;
    }

    /**
     * Fila de una tabla. $anchos son las proporciones de cada columna.
     *
     * @param  list<string>  $celdas
     * @param  list<float>  $anchos
     */
    public function fila(array $celdas, array $anchos, bool $negrita = false, float $tamano = 8): self
    {
        $this->reservar($tamano * 1.5);
        $disponible = self::ANCHO - 2 * self::MARGEN;
        $total = array_sum($anchos);
        $x = self::MARGEN;

        foreach ($celdas as $i => $celda) {
            $ancho = $disponible * $anchos[$i] / $total;
            // Truncado aproximado: Helvetica promedia ~0,5 em por carácter.
            $maximo = max(1, (int) floor($ancho / ($tamano * 0.5)) - 1);
            $this->escribir($x, $this->y, mb_strimwidth($celda, 0, $maximo, '…'), $tamano, $negrita);
            $x += $ancho;
        }

        return $this;
    }

    public function separador(): self
    {
        $this->reservar(8);
        $y = $this->y + 3;
        $this->actual .= sprintf("0.6 w %.2F %.2F m %.2F %.2F l S\n", self::MARGEN, $y, self::ANCHO - self::MARGEN, $y);

        return $this;
    }

    public function espacio(float $puntos): self
    {
        $this->reservar($puntos);

        return $this;
    }

    public function generar(): string
    {
        $paginas = [...$this->paginas, $this->actual];
        $objetos = [];

        // 1 catálogo, 2 árbol de páginas, 3 y 4 fuentes, 5 info; luego, por
        // cada página, su objeto Page y su stream de contenido.
        $kids = [];
        foreach ($paginas as $i => $contenido) {
            $kids[] = (6 + 2 * $i).' 0 R';
        }

        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objetos[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($paginas).' >>';
        $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objetos[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objetos[5] = '<< /Title ('.self::escapar($this->tituloDocumento).') /Producer (Remiseria LH) >>';

        foreach ($paginas as $i => $contenido) {
            $pagina = 6 + 2 * $i;
            $objetos[$pagina] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::ANCHO, self::ALTO, $pagina + 1,
            );
            $objetos[$pagina + 1] = '<< /Length '.strlen($contenido)." >>\nstream\n".$contenido.'endstream';
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objetos as $numero => $cuerpo) {
            $offsets[$numero] = strlen($pdf);
            $pdf .= "{$numero} 0 obj\n{$cuerpo}\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objetos) + 1)." /Root 1 0 R /Info 5 0 R >>\nstartxref\n{$inicioXref}\n%%EOF\n";
    }

    private function reservar(float $alto): void
    {
        if ($this->y - $alto < self::MARGEN) {
            $this->nuevaPagina();
        }

        $this->y -= $alto;
    }

    private function nuevaPagina(): void
    {
        if ($this->actual !== '') {
            $this->paginas[] = $this->actual;
        }

        $this->actual = '';
        $this->y = self::ALTO - self::MARGEN;
    }

    private function escribir(float $x, float $y, string $texto, float $tamano, bool $negrita): void
    {
        $this->actual .= sprintf(
            "BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
            $negrita ? 'F2' : 'F1', $tamano, $x, $y, self::escapar($texto),
        );
    }

    /**
     * Convierte a Windows-1252 y escapa los caracteres especiales de los
     * strings literales de PDF.
     */
    private static function escapar(string $texto): string
    {
        $texto = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $texto) ?? '';
        $convertido = mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');

        return strtr($convertido, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
