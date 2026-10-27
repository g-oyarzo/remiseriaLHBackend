<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Pdf\DocumentoPdf;
use PHPUnit\Framework\TestCase;

class DocumentoPdfTest extends TestCase
{
    public function test_genera_un_pdf_estructuralmente_valido(): void
    {
        $pdf = (new DocumentoPdf('Prueba'))->titulo('Hola')->par('Clave', 'Valor')->generar();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);

        // Cada entrada de la tabla xref apunta exactamente al inicio de su objeto.
        preg_match('/startxref\n(\d+)\n/', $pdf, $m);
        $xref = substr($pdf, (int) $m[1]);
        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $offsets);
        $this->assertNotEmpty($offsets[1]);
        foreach ($offsets[1] as $i => $offset) {
            $this->assertSame(($i + 1).' 0 obj', substr($pdf, (int) $offset, strlen(($i + 1).' 0 obj')));
        }
    }

    public function test_escapa_parentesis_y_barras_y_convierte_a_windows_1252(): void
    {
        $pdf = (new DocumentoPdf)->texto('Pago (QR) \\ Pérez ñandú')->generar();

        $this->assertStringContainsString('(Pago \\(QR\\) \\\\ P'."\xE9".'rez '."\xF1".'and'."\xFA".') Tj', $pdf);
    }

    public function test_los_textos_no_pueden_inyectar_operadores_pdf(): void
    {
        $pdf = (new DocumentoPdf)->texto(") Tj ET\n/F1 99 Tf (x")->generar();

        $this->assertStringContainsString('(\\) Tj ET /F1 99 Tf \\(x) Tj', $pdf);
    }

    public function test_agrega_paginas_cuando_el_contenido_no_entra(): void
    {
        $documento = new DocumentoPdf;
        for ($i = 0; $i < 200; $i++) {
            $documento->fila(["Fila {$i}", 'dato'], [1, 1]);
        }

        $this->assertMatchesRegularExpression('/\/Type \/Pages \/Kids \[[^\]]+\] \/Count ([2-9]|\d{2,})/', $documento->generar());
    }
}
