<?php

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

// Deliberately does not use RefreshDatabase: the application is rebooted mid-test and
// nothing here touches the database.
uses(TestCase::class);

// Production runs `php artisan route:cache`. Once routes are cached Laravel loads the
// compiled table and never executes routes/*.php, so anything registered as a side
// effect of a routes file silently disappears. Channel authorization callbacks used to
// be `require`d from routes/api.php, so in production every private WebSocket channel
// answered 403 while dev and the rest of the test suite (which never cache routes)
// worked fine. This test boots the app the way production does.
test('broadcast channel authorization is registered even when routes are cached', function () {
    // Cache to a throwaway file so a running dev server's real route cache is untouched.
    $cacheFile = sys_get_temp_dir().'/pulseboard-route-cache-test-'.uniqid().'.php';
    putenv("APP_ROUTES_CACHE={$cacheFile}");

    try {
        $this->artisan('route:cache')->assertSuccessful();
        $this->refreshApplication();

        expect(app()->routesAreCached())->toBeTrue();

        $channels = new ReflectionProperty(Broadcaster::class, 'channels');
        $registered = array_keys($channels->getValue(Broadcast::driver()));

        expect($registered)->toContain('App.Models.User.{id}', 'workspace.{workspaceId}', 'project.{projectId}');
    } finally {
        putenv('APP_ROUTES_CACHE');
        @unlink($cacheFile);
    }
});
