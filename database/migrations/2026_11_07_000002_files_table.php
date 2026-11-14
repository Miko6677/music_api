<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->foreignUuid('song_id')->nullable()->constrained();
            $t->string('kind', 10);
            $t->string('file_name');
            $t->unsignedBigInteger('file_size');
            $t->text('file_path');
            $t->string('mime_type', 100);
            $t->char('sha256', 64)->index();
            $t->string('quality', 16)->default('standard')->index();
            $t->string('codec', 40)->default('');
            $t->unsignedInteger('bitrate')->default(0);
            $t->unsignedInteger('sample_rate')->default(0);
            $t->unsignedSmallInteger('bit_depth')->default(0);
            $t->unsignedSmallInteger('channels')->default(0);
            $t->double('duration')->default(0);
            $t->boolean('is_default')->default(false);
            $t->jsonb('metadata')->nullable();
            $t->jsonb('upload_context')->nullable();
            $t->jsonb('upload_draft')->nullable();
            $t->jsonb('publication')->nullable();
            $t->rawIndex("(publication->>'track_key')", 'files_publication_track');
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['song_id', 'deleted_at']);
            $t->index(['user_id', 'created_at']);
            $t->index('upload_context', 'files_upload_context_index', 'gin');

        });
        \Illuminate\Support\Facades\DB::statement('CREATE UNIQUE INDEX files_one_default ON files (song_id) WHERE is_default = true AND deleted_at IS NULL');
        Schema::create('upload_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->foreignUuid('song_id')->nullable()->constrained();
            $t->string('kind', 10);
            $t->string('file_name');
            $t->unsignedBigInteger('file_size');
            $t->char('token_hash', 64);
            $t->char('sha256', 64)->nullable();
            $t->string('tus_id')->nullable()->unique();
            $t->string('status', 20)->default('pending');
            $t->text('error')->nullable();
            $t->uuid('file_id')->nullable();
            $t->jsonb('context')->nullable();
            $t->jsonb('prepared_lyrics')->default('[]');
            $t->timestampTz('expires_at');
            $t->timestampsTz();
            $t->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
        Schema::dropIfExists('files');
    }
};
