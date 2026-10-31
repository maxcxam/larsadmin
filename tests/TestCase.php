<?php

namespace Maxcxam\Larsadmin\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminServiceProvider;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Larsadmin::flushState();
    }

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            LarsadminServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('inertia.pages.paths', [__DIR__.'/../resources/js/Pages']);
        $app['config']->set('inertia.pages.extensions', ['vue']);
    }

    protected function defineDatabaseMigrations(): void
    {
        // The package's publishable migrations (audit log table), loaded in tests only.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The host's standard notifications table (php artisan make:notifications-table).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->boolean('published')->default(false);
            $table->string('status');
            $table->string('subtitle')->default('');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->string('cover')->nullable();
            $table->string('cover_name')->nullable();
            $table->json('attachments')->nullable();
            $table->string('secret')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
