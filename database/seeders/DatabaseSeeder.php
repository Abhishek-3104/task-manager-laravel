<?php

namespace Database\Seeders;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data + the test credentials shared with reviewers.
 *
 *   Admin : admin@taskmanager.test / Admin@123
 *   User  : user@taskmanager.test  / User@1234
 *   User 2: priya@taskmanager.test / User@1234
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->user('Admin', 'admin@taskmanager.test', 'Admin@123', UserRole::Admin);
        $user = $this->user('Rahul Sharma', 'user@taskmanager.test', 'User@1234', UserRole::Member);
        $priya = $this->user('Priya Patel', 'priya@taskmanager.test', 'User@1234', UserRole::Member);

        $website = $this->project($admin, 'Website Redesign', 'Refresh the marketing site with the new brand guidelines.');
        $mobile = $this->project($admin, 'Mobile App MVP', 'First release of the job-portal mobile app.');

        $this->task($website, $admin, $user, 'Design new homepage', TaskPriority::High, TaskStatus::InProgress, 5);
        $this->task($website, $admin, $user, 'Write About Us copy', TaskPriority::Medium, TaskStatus::Todo, 10);
        $this->task($website, $admin, $priya, 'Set up analytics', TaskPriority::Low, TaskStatus::Done, 3);
        // Past due and not done: the overdue sweep (or first status change) marks it OVERDUE.
        $this->task($website, $admin, $user, 'Fix broken contact form', TaskPriority::High, TaskStatus::InProgress, -2);

        $this->task($mobile, $admin, $priya, 'Build login screen', TaskPriority::High, TaskStatus::InProgress, 7);
        $this->task($mobile, $admin, $user, 'Push notification spike', TaskPriority::Medium, TaskStatus::Overdue, -4);
        $this->task($mobile, $admin, $priya, 'App store listing', TaskPriority::Low, TaskStatus::Todo, 14);
    }

    private function user(string $name, string $email, string $password, UserRole $role): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $name, 'password' => $password, 'role' => $role])->save();

        return $user;
    }

    private function project(User $admin, string $name, string $description): Project
    {
        return $admin->createdProjects()->firstOrCreate(['name' => $name], ['description' => $description]);
    }

    private function task(Project $project, User $creator, User $assignee, string $title, TaskPriority $priority, TaskStatus $status, int $dueInDays): void
    {
        $task = Task::firstOrNew(['project_id' => $project->id, 'title' => $title]);
        $task->fill([
            'assigned_to' => $assignee->id,
            'priority' => $priority,
            'due_date' => now()->addDays($dueInDays)->toDateString(),
            'description' => "Demo task for {$project->name}.",
        ]);
        $task->forceFill(['status' => $status, 'created_by' => $creator->id])->save();
    }
}
