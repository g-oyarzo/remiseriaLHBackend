<?php

declare(strict_types=1);

namespace App\Support\Xlsx;

use RuntimeException;
use ZipArchive;

/**
 * Escritor mínimo de planillas .xlsx (Office Open XML) sin dependencias
 * externas: hojas con filas de texto o números, sin estilos ni fórmulas.
 * Requiere la extensión zip de PHP (incluida en la imagen de Sail).
 *
 * Los textos se escriben como "inlineStr", nunca como fórmulas: un valor
 * que empiece con "=" no se evalúa al abrir el archivo.
 */
final class LibroXlsx
{
    /** @var array<string, list<list<string|int|float|null>>> */
    private array $hojas = [];

    /**
     * @param  list<list<string|int|float|null>>  $filas
     */
    public function hoja(string $nombre, array $filas): self
    {
        // Excel limita los nombres de hoja a 31 caracteres y prohíbe []:*?/\
        $nombre = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $nombre) ?? 'Hoja', 0, 31);
        $this->hojas[$nombre] = $filas;

        return $this;
    }

    public function generar(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión zip de PHP es necesaria para exportar a Excel.');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');

        try {
            $zip = new ZipArchive;
            if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo Excel.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>');
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());

            $i = 1;
            foreach ($this->hojas as $filas) {
                $zip->addFromString("xl/worksheets/sheet{$i}.xml", $this->hojaXml($filas));
                $i++;
            }

            $zip->close();

            return (string) file_get_contents($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    private function contentTypes(): string
    {
        $hojas = '';
        for ($i = 1; $i <= count($this->hojas); $i++) {
            $hojas .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$hojas
            .'</Types>';
    }

    private function workbook(): string
    {
        $hojas = '';
        $i = 1;
        foreach (array_keys($this->hojas) as $nombre) {
            $hojas .= '<sheet name="'.self::xml($nombre).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $i++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$hojas.'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        for ($i = 1; $i <= count($this->hojas); $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    /**
     * @param  list<list<string|int|float|null>>  $filas
     */
    private function hojaXml(array $filas): string
    {
        $xml = '';
        foreach ($filas as $r => $fila) {
            $celdas = '';
            foreach (array_values($fila) as $c => $valor) {
                $ref = self::columna($c).($r + 1);

                if ($valor === null || $valor === '') {
                    continue;
                }

                $celdas .= is_int($valor) || is_float($valor)
                    ? '<c r="'.$ref.'"><v>'.$valor.'</v></c>'
                    : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string) $valor).'</t></is></c>';
            }
            $xml .= '<row r="'.($r + 1).'">'.$celdas.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private static function columna(int $indice): string
    {
        $letras = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26).$letras;
        }

        return $letras;
    }

    private static function xml(string $texto): string
    {
        // Caracteres de control no permitidos en XML 1.0.
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto) ?? '';

        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
