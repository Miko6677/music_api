<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::ensureExtensionExists('pg_trgm');
        Schema::create('users', function (Blueprint $t) {
            $t->id()->startingValue(1000);
            $t->string('emos_id')->unique();
            $t->string('username');
            $t->text('avatar')->nullable();
            $t->string('pseudonym', 60)->nullable()->unique();
            $t->string('role', 10)->default('user');
            $t->string('quality', 16)->default('high');
            $t->string('server_username', 60)->nullable()->unique();
            $t->unsignedBigInteger('size_upload')->default(0);
            $t->string('notice_version')->nullable();
            $t->timestampTz('notice_accepted_at')->nullable();
            $t->rememberToken();
            $t->timestampsTz();
            $t->rawIndex('"username" gin_trgm_ops', 'users_username_search_index')->algorithm('gin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
