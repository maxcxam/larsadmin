<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The runs of queued actions (larsadmin 1.4): their progress, result and encrypted payload.
     */
    public function up(): void
    {
        $table = config('larsadmin.actions.runs_table', 'larsadmin_action_runs');

        Schema::connection(config('larsadmin.actions.runs_connection'))->create($table, function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('entity', 100);
            $t->string('action', 100);
            $t->string('action_name');
            $t->string('user_type');
            $t->string('user_id', 64);
            $t->string('guard', 64)->nullable();
            $t->string('locale', 12);
            $t->string('status', 16);
            $t->unsignedInteger('total')->nullable();
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('ran')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->string('level', 16)->nullable();
            $t->text('message')->nullable();
            $t->json('result')->nullable();
            $t->longText('payload')->nullable();
            $t->uuid('audit_batch_id')->nullable();
            $t->timestamp('cancel_requested_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['user_type', 'user_id', 'status']);
            $t->index(['user_type', 'user_id', 'created_at']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(config('larsadmin.actions.runs_connection'))
            ->dropIfExists(config('larsadmin.actions.runs_table', 'larsadmin_action_runs'));
    }
};
