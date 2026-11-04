<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Los comprobantes emitidos durante los tests no se escriben en
        // storage/app/private.
        Storage::fake('comprobantes');
    }
}
