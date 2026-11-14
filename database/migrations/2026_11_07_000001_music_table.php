<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('recording_version', 100)->default('');
            $t->foreignId('user_id')->constrained();
            $t->timestampsTz();
        });
        Schema::create('libraries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('playback_id')->unique();
            $t->string('name')->unique();
            $t->text('description')->default('');
            $t->text('image_url')->nullable();
            $t->uuid('cover_id')->nullable();
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        foreach (['artists', 'albums', 'songs'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->uuid('id')->primary();
                $t->string('name');
                $t->text('description')->default('');
                $t->string('todb_id', 30)->nullable()->unique();
                $t->jsonb('source_data')->nullable();
                $t->timestampTz('metadata_updated_at')->nullable();
                $t->text('image_url')->nullable();
                $t->uuid('cover_id')->nullable();
                $t->foreignId('user_id')->constrained();
                $t->unsignedInteger('version')->default(1);
                if ($name !== 'artists') {
                    $t->date('release_date')->nullable();
                    $t->string('genre', 100)->default('');
                }
                if ($name === 'songs') {
                    $t->foreignUuid('recording_id')->index()->constrained();
                    $t->foreignUuid('library_id')->index()->constrained('libraries');
                    $t->string('recording_version', 100)->default('');
                }
                if ($name === 'albums') {
                    $t->unsignedSmallInteger('release_year')->nullable();
                    $t->string('edition', 100)->default('');
                    $t->string('release_country', 100)->default('');
                    $t->string('label', 255)->default('');
                    $t->string('catalog_number', 100)->default('');
                    $t->uuid('musicbrainz_id')->nullable()->unique();
                    $t->unsignedInteger('total_discs')->nullable();
                    $t->unsignedInteger('total_tracks')->nullable();
                    $t->jsonb('artwork')->default('[]');
                }
                $t->timestampsTz();
                $t->softDeletesTz();
                $t->rawIndex('"name" gin_trgm_ops', $name . '_name_search')->algorithm('gin');
                $t->index(['created_at', 'id']);
            });
        }
        Schema::create('album_song', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('album_id')->constrained();
            $t->foreignUuid('song_id')->constrained();
            $t->unsignedInteger('disc_number');
            $t->unsignedInteger('track_number');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['album_id', 'song_id']);
            $t->unique(['album_id', 'disc_number', 'track_number']);
            $t->index('song_id');
        });
        foreach (['song', 'album'] as $kind) {
            Schema::create($kind . '_artist', function (Blueprint $t) use ($kind) {
                $t->foreignUuid($kind . '_id')->constrained($kind . 's');
                $t->foreignUuid('artist_id')->constrained('artists');
                $t->primary([$kind . '_id', 'artist_id']);
            });
        }
        Schema::create('song_lyrics', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('song_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->uuid('source_file_id')->nullable()->index();
            $t->string('name', 100);
            $t->string('format', 16);
            $t->string('kind', 20)->default('main');
            $t->string('language', 35)->default('und');
            $t->string('source', 500)->default('');
            $t->string('source_format', 16)->default('');
            $t->jsonb('source_data')->nullable();
            $t->text('content');
            $t->text('lrc')->default('');
            $t->jsonb('structured')->default('[]');
            $t->boolean('is_default')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestampsTz();
            $t->index(['song_id', 'sort_order']);
        });
        \Illuminate\Support\Facades\DB::statement('CREATE UNIQUE INDEX song_lyrics_one_default ON song_lyrics (song_id) WHERE is_default = true');
        Schema::create('favorites', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained();
            $t->foreignUuid('song_id')->constrained();
            $t->timestampsTz();
            $t->primary(['user_id', 'song_id']);
        });
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('user_id')->constrained();
            $t->string('action', 40);
            $t->string('entity_type', 20);
            $t->string('entity_id', 40);
            $t->string('title');
            $t->text('reason')->nullable();
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->string('playback_state', 16)->nullable()->index();
            $t->jsonb('playback_snapshot')->nullable();
            $t->boolean('is_private')->default(false);
            $t->unsignedBigInteger('restored_from')->nullable()->unique();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id', 'id']);
            $t->index(['user_id', 'id']);
        });
        Schema::create('inbox_messages', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('user_id')->constrained();
            $t->string('title');
            $t->text('body');
            $t->string('link')->nullable();
            $t->timestampTz('read_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'id']);
        });
        Schema::create('sync_states', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->unsignedBigInteger('revision')->default(0);
            $t->unsignedBigInteger('synced_revision')->default(0);
            $t->timestampTz('synced_at')->nullable();
            $t->text('error')->nullable();
            $t->timestampsTz();
        });
        \Illuminate\Support\Facades\DB::statement('CREATE INDEX sync_states_pending ON sync_states (id) WHERE revision > synced_revision');
    }

    public function down(): void
    {
        foreach (['sync_states', 'inbox_messages', 'activity_logs', 'favorites', 'song_lyrics', 'album_artist', 'song_artist', 'album_song', 'songs', 'albums', 'artists', 'libraries', 'recordings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
