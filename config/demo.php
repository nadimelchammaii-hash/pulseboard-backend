<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Accounts
    |--------------------------------------------------------------------------
    |
    | "Try the demo" creates a temporary, private account with a freshly built
    | sample workspace (StartDemo): fictional teammates, projects, tasks,
    | comments, activity and notifications. Every visitor starts from the same
    | state and nothing they do reaches anyone else. demo:prune deletes the
    | account and everything in it once demo_expires_at has passed.
    |
    */

    'enabled' => (bool) env('DEMO_ENABLED', true),

    // Demo accounts (visitor and fictional teammates) get addresses at this domain.
    // ".invalid" is reserved: it can never receive email.
    'email_domain' => 'demo.pulseboardapp.invalid',

    'lifetime_hours' => (int) env('DEMO_LIFETIME_HOURS', 24),

    // New demos per visitor IP address per hour, and for the whole site per day.
    'starts_per_hour' => (int) env('DEMO_STARTS_PER_HOUR', 3),
    'starts_per_day' => (int) env('DEMO_STARTS_PER_DAY', 200),

    // What a demo account may hold, so a visitor (or a script) cannot fill the
    // database. Tasks and comments count everything in the demo's workspaces,
    // sample data included; deleting something frees room again.
    'limits' => [
        'workspaces' => 2,
        'projects_per_workspace' => 6,
        'tasks' => 100,
        'comments' => 50,
    ],

];
