<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Xlsx\LibroXlsx;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class LibroXlsxTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function abrir(string $contenido): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'test-xlsx');
        file_put_contents($ruta, $contenido);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));

        $archivos = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            $archivos[$nombre] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($ruta);

        return $archivos;
    }

    public function test_genera_un_paquete_xlsx_con_xml_bien_formado(): void
    {
        $archivos = $this->abrir((new LibroXlsx)->hoja('Resumen', [['a', 1]])->hoja('Detalle', [['b', 2.5]])->generar());

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $parte) {
            $this->assertArrayHasKey($parte, $archivos);
            $this->assertNotFalse(simplexml_load_string($archivos[$parte]), $parte);
        }

        $this->assertStringContainsString('name="Detalle"', $archivos['xl/workbook.xml']);
    }

    public function test_numeros_como_valores_y_textos_como_inline_strings_escapados(): void
    {
        $archivos = $this->abrir((new LibroXlsx)->hoja('H', [
            ['=HYPERLINK("http://x")', '<b>&"', 42, 3.25, null],
        ])->generar());
        $hoja = $archivos['xl/worksheets/sheet1.xml'];

        // Un texto que parece fórmula se guarda como texto, no como <f>.
        $this->assertStringNotContainsString('<f>', $hoja);
        $this->assertStringContainsString('<c r="A1" t="inlineStr"><is><t xml:space="preserve">=HYPERLINK(&quot;http://x&quot;)</t></is></c>', $hoja);
        $this->assertStringContainsString('&lt;b&gt;&amp;&quot;', $hoja);
        $this->assertStringContainsString('<c r="C1"><v>42</v></c>', $hoja);
        $this->assertStringContainsString('<c r="D1"><v>3.25</v></c>', $hoja);
        $this->assertStringNotContainsString('r="E1"', $hoja);
    }

    public function test_nombra_las_columnas_mas_alla_de_la_z(): void
    {
        $fila = array_fill(0, 28, 'x');
        $hoja = $this->abrir((new LibroXlsx)->hoja('H', [$fila])->generar())['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('r="Z1"', $hoja);
        $this->assertStringContainsString('r="AA1"', $hoja);
        $this->assertStringContainsString('r="AB1"', $hoja);
    }
}
