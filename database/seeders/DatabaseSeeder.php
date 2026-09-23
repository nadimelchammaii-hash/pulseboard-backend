<?php

namespace Database\Seeders;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a realistic demo workspace so a fresh `migrate:fresh --seed` has
     * something to look at immediately, instead of an empty account. Deliberately
     * does not fabricate Activity/Notification rows — those are a byproduct of
     * real actions and denormalize data at write time, so hand-crafting them here
     * would risk drifting from the shape the app itself actually produces.
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Demo Owner',
            'email' => 'demo@example.com',
        ]);

        $colleague = User::factory()->create([
            'name' => 'Demo Colleague',
            'email' => 'colleague@example.com',
        ]);

        $plainMember = User::factory()->create([
            'name' => 'Demo Member',
            'email' => 'member@example.com',
        ]);

        $workspace = Workspace::factory()->create(['name' => 'Acme Engineering']);
        $workspace->members()->create(['user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);
        $workspace->members()->create(['user_id' => $colleague->id, 'role' => WorkspaceRole::Admin]);
        $workspace->members()->create(['user_id' => $plainMember->id, 'role' => WorkspaceRole::Member]);

        $mobileApp = $workspace->projects()->create(['name' => 'Mobile App', 'slug' => 'mobile-app']);
        $mobileApp->members()->create(['user_id' => $owner->id]);
        $mobileApp->members()->create(['user_id' => $colleague->id]);
        $mobileApp->members()->create(['user_id' => $plainMember->id]);

        $websiteRelaunch = $workspace->projects()->create(['name' => 'Website Relaunch', 'slug' => 'website-relaunch']);
        $websiteRelaunch->members()->create(['user_id' => $owner->id]);
        $websiteRelaunch->members()->create(['user_id' => $colleague->id]);

        $demoTasks = [
            ['title' => 'Design the onboarding flow', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'assignee_id' => $colleague->id],
            ['title' => 'Set up push notifications', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'assignee_id' => $owner->id],
            ['title' => 'Build the task board screen', 'status' => TaskStatus::InProgress, 'priority' => TaskPriority::Urgent, 'assignee_id' => $owner->id],
            ['title' => 'Wire up real-time updates', 'status' => TaskStatus::InProgress, 'priority' => TaskPriority::High, 'assignee_id' => $colleague->id],
            ['title' => 'Fix flaky login test on CI', 'status' => TaskStatus::Review, 'priority' => TaskPriority::Medium, 'assignee_id' => $plainMember->id],
            ['title' => 'Audit color contrast for dark mode', 'status' => TaskStatus::Review, 'priority' => TaskPriority::Low, 'assignee_id' => null],
            ['title' => 'Add offline draft support for comments', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'assignee_id' => null],
            ['title' => 'Investigate battery drain on Android', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'assignee_id' => $plainMember->id],
            ['title' => 'Write release notes for v1.2', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Low, 'assignee_id' => null],
        ];

        foreach (collect($demoTasks)->groupBy('status') as $status => $tasksInStatus) {
            foreach ($tasksInStatus->values() as $position => $taskData) {
                $mobileApp->tasks()->create([
                    'title' => $taskData['title'],
                    'status' => $taskData['status'],
                    'priority' => $taskData['priority'],
                    'assignee_id' => $taskData['assignee_id'],
                    'position' => $position,
                    'created_by' => $owner->id,
                ]);
            }
        }

        $boardTask = $mobileApp->tasks()->where('title', 'Build the task board screen')->firstOrFail();
        $boardTask->comments()->create([
            'user_id' => $colleague->id,
            'body' => "Hey {$owner->name}, I think we should virtualize the column lists once we pass ~200 cards.",
        ]);
        $boardTask->comments()->create([
            'user_id' => $owner->id,
            'body' => 'Good call — filed it as a follow-up, not blocking this one.',
        ]);
    }
}
