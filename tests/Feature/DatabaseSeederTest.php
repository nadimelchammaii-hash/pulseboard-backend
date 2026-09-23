<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Runs against the isolated sqlite testing database, never the real dev MySQL —
// this only proves the seeder executes cleanly and produces a sane demo dataset,
// it's not something to run against a database anyone is actively using.
test('the database seeder runs cleanly and produces a usable demo workspace', function () {
    $this->seed(DatabaseSeeder::class);

    $owner = User::where('email', 'demo@example.com')->firstOrFail();
    $workspace = $owner->workspaceMembers()->firstOrFail()->workspace;

    expect($workspace->name)->toBe('Acme Engineering');
    expect($workspace->members()->count())->toBe(3);
    expect($workspace->projects()->count())->toBe(2);

    $mobileApp = $workspace->projects()->where('name', 'Mobile App')->firstOrFail();
    expect($mobileApp->tasks()->count())->toBe(9);
    expect($mobileApp->tasks()->where('status', 'done')->count())->toBe(2);

    $boardTask = $mobileApp->tasks()->where('title', 'Build the task board screen')->firstOrFail();
    expect($boardTask->comments()->count())->toBe(2);
});
