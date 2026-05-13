<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pas de build Vite en tests : le manifest.json n'existe pas et
        // toute vue qui utilise @vite() planterait avec une 500.
        $this->withoutVite();
    }
}
