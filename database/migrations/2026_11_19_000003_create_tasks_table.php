<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // Plain strings (validated by PHP enums) keep the schema portable
            // and let the Django service read/write the same values.
            $table->string('status', 20)->default('TODO');
            $table->string('priority', 10)->default('MEDIUM');
            $table->date('due_date');
            $table->timestamps();

            // Overdue sweep: WHERE due_date < ? AND status NOT IN (...)
            $table->index(['status', 'due_date']);
            // "My tasks" listing for members.
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
