<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Send the next requests as a browser app on a stateful domain, so Sanctum gives them a session.
     */
    protected function fromSpa(): static
    {
        return $this->withHeader('Origin', 'http://localhost');
    }
}
