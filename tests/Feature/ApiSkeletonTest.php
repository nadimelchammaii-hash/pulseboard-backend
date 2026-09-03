<?php

use Tests\TestCase;

uses(TestCase::class);

test('unauthenticated request to the user endpoint is rejected', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});
