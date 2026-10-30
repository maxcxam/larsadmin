<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit log table of Larsadmin (config "larsadmin.audit.table" / "larsadmin.audit.connection").
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->string('event', 20);
            $table->string('entity', 100);
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_guard', 64)->nullable();
            $table->string('actor_label')->nullable();
            $table->json('changes')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            // dateTime: no implicit MySQL default or ON UPDATE, no 2038 limit.
            $table->dateTime('created_at');

            $table->index(['auditable_type', 'auditable_id', 'id']);
            $table->index(['entity', 'id']);
            $table->index(['actor_type', 'actor_id', 'id']);
            $table->index('created_at');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    public function getConnection(): ?string
    {
        return config('larsadmin.audit.connection');
    }

    protected function table(): string
    {
        return config('larsadmin.audit.table', 'larsadmin_audit_entries');
    }
};
