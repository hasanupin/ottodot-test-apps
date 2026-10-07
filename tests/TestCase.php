<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum treats a request as a stateful SPA call (session + cookie auth) only when it
        // comes from a configured frontend domain, which it detects via the Referer/Origin header.
        $this->withHeader('Referer', config('app.url'));
    }
}
