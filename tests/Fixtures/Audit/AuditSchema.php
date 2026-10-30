<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table of the audit fixtures, created per test (rolled back with the test transaction).
 */
class AuditSchema
{
    public static function create(): void
    {
        Schema::create('audit_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('published')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->date('published_on')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('cover')->nullable();
            $table->json('attachments')->nullable();
            $table->string('secret')->nullable();
            $table->string('note')->nullable();
            $table->string('token')->nullable();
            $table->text('private')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
