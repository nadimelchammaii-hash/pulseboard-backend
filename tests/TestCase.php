<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only starts the session for requests it recognizes as coming
        // from the SPA (matched against SANCTUM_STATEFUL_DOMAINS via Referer/Origin),
        // exactly like a real browser request would.
        $this->withHeader('Referer', 'http://localhost:3000');

        // The testing CACHE_STORE is "array", which lives for the whole PHP process,
        // not per-test — without this, rate-limit counters (and any cached reads)
        // from one test would bleed into the next.
        Cache::flush();
    }
}
