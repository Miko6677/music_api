<?php

namespace App\Services;

use App\Models;

class PlaybackStatistics
{
    private function table(string $name): Models\Server\Record
    {
        return Models\Server\Record::at($name);
    }

    public function refresh(array $albumIds, array $libraryIds): void
    {
        foreach (array_chunk(array_values(array_unique($albumIds)), 500) as $ids) {
            $stats = $this->table('media_file')->select(['album_id'])->whereIn('album_id', $ids)->where('missing', false)
                ->selectRaw('count(*) AS count, coalesce(sum(duration), 0) AS duration, coalesce(sum(size), 0) AS size')->groupBy('album_id')->get()->keyBy('album_id');
            $tags = $this->table('media_file')->join('media_file_tags', 'media_file_tags.media_file_id', '=', 'media_file.id')->join('tag', 'tag.id', '=', 'media_file_tags.tag_id')
                ->select(['media_file.album_id', 'tag.id', 'tag.tag_value'])->whereIn('media_file.album_id', $ids)->where('media_file.missing', false)->where('tag.tag_name', 'genre')->distinct()->get()->groupBy('album_id');
            $this->table('album_tags')->whereIn('album_id', $ids)->delete();
            $this->table('album_artists')->whereIn('album_id', $ids)->delete();
            $artists = $this->table('media_file')->join('media_file_artists', 'media_file_artists.media_file_id', '=', 'media_file.id')
                ->select(['media_file.album_id', 'media_file_artists.artist_id', 'media_file_artists.role', 'media_file_artists.sub_role'])
                ->whereIn('media_file.album_id', $ids)->where('media_file.missing', false)->where('media_file_artists.role', 'albumartist')->distinct()->get();
            foreach ($artists->chunk(500) as $rows) {
                $this->table('album_artists')->insertOrIgnore($rows->map(fn($row) => $row->getAttributes())->all());
            }
            foreach ($ids as $id) {
                $row = $stats->get($id);
                $genres = $tags->get($id, collect());
                $names = $genres->pluck('tag_value')->sort()->values()->all();
                $tagValues = $genres->map(fn($genre) => ['id' => $genre->id, 'value' => $genre->tag_value])->values()->all();
                $this->table('album')->whereKey($id)->update(['missing' => !$row, 'song_count' => $row?->count ?? 0, 'duration' => $row?->duration ?? 0,
                    'size' => $row?->size ?? 0, 'genre' => implode(' / ', $names), 'tags' => $tagValues ? json_encode(['genre' => $tagValues], JSON_UNESCAPED_UNICODE) : '{}']);
                $this->table('folder')->whereKey('folder-'.$id)->update(['num_audio_files' => $row?->count ?? 0]);
                foreach ($genres->chunk(500) as $rows) {
                    $this->table('album_tags')->insertOrIgnore($rows->map(fn($genre) => ['album_id' => $id, 'tag_id' => $genre->id])->all());
                }
            }
        }
        foreach (array_unique($libraryIds) as $id) {
            $this->library((int)$id);
        }
    }

    private function library(int $id): void
    {
        $canonical = $this->table('media_file')->where('library_id', $id)->where('missing', false)->whereColumn('id', 'pid')
            ->selectRaw('count(*) AS total_songs, coalesce(sum(size), 0) AS total_size, coalesce(sum(duration), 0) AS total_duration')->first()->getAttributes();
        $albums = $this->table('media_file')->where('library_id', $id)->where('missing', false)->distinct()->count('album_id');
        $credits = $this->table('media_file')->join('media_file_artists', 'media_file_artists.media_file_id', '=', 'media_file.id')
            ->where('media_file.library_id', $id)->where('media_file.missing', false);
        $songCredits = (clone $credits)->select(['media_file.pid', 'media_file.size', 'media_file_artists.artist_id'])->distinct();
        $albumCredits = (clone $credits)->select(['media_file.album_id', 'media_file_artists.artist_id'])->distinct();
        $songs = $this->table('media_file')->fromSub($songCredits, 'song_credits')->select(['artist_id'])->selectRaw('count(*) AS songs, sum(size) AS size')->groupBy('artist_id');
        $albumCounts = $this->table('media_file')->fromSub($albumCredits, 'album_credits')->select(['artist_id'])->selectRaw('count(*) AS albums')->groupBy('artist_id');
        $artists = $this->table('media_file')->fromSub($songs, 'song_stats')->joinSub($albumCounts, 'album_stats', 'album_stats.artist_id', '=', 'song_stats.artist_id')
            ->select(['song_stats.artist_id', 'song_stats.songs', 'song_stats.size', 'album_stats.albums'])->get();
        $this->table('library_artist')->where('library_id', $id)->whereNotExists(
            (clone $credits)->select(['media_file_artists.artist_id'])->whereColumn('media_file_artists.artist_id', 'library_artist.artist_id')
        )->delete();
        foreach ($artists->chunk(500) as $rows) {
            $this->table('library_artist')->upsert($rows->map(function ($row) use ($id) {
                $counts = ['m' => (int)$row->songs, 'a' => (int)$row->albums, 's' => (int)$row->size];
                return ['library_id' => $id, 'artist_id' => $row->artist_id, 'stats' => json_encode(['total' => $counts, 'maincredit' => $counts])];
            })->all(), ['library_id', 'artist_id'], ['stats']);
        }
        $tagUsage = $this->table('media_file')->join('media_file_tags', 'media_file_tags.media_file_id', '=', 'media_file.id')
            ->where('media_file.library_id', $id)->where('media_file.missing', false);
        $tags = (clone $tagUsage)->groupBy('media_file_tags.tag_id')
            ->select(['media_file_tags.tag_id'])->selectRaw('count(*) AS media_file_count, count(distinct media_file.album_id) AS album_count')->get();
        $this->table('library_tag')->where('library_id', $id)->whereNotExists(
            $tagUsage->select(['media_file_tags.tag_id'])->whereColumn('media_file_tags.tag_id', 'library_tag.tag_id')
        )->delete();
        foreach ($tags->chunk(500) as $rows) {
            $this->table('library_tag')->upsert($rows->map(fn($row) => [...$row->getAttributes(), 'library_id' => $id])->all(), ['tag_id', 'library_id'], ['album_count', 'media_file_count']);
        }
        $this->table('library')->whereKey($id)->update([...$canonical, 'total_files' => $canonical['total_songs'], 'total_albums' => $albums,
            'total_artists' => $artists->count(), 'total_folders' => $albums, 'updated_at' => now()]);
    }
}
