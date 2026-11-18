<?php

use App\Services\MusicBrainz;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

test('it maps a MusicBrainz release into album and track draft data', function () {
    Http::fake([
        'musicbrainz.org/ws/2/release/*' => Http::response([
            'id' => '76df3287-6cda-33eb-8e9a-044b5e15ffdd',
            'title' => 'Kind of Blue',
            'artist-credit' => [
                ['name' => 'Miles Davis', 'artist' => ['name' => 'Miles Davis'], 'joinphrase' => ''],
            ],
            'date' => '1959-08-17',
            'country' => 'US',
            'label-info' => [
                ['catalog-number' => 'CL 1355', 'label' => ['name' => 'Columbia']],
            ],
            'media' => [
                [
                    'tracks' => [
                        [
                            'position' => 1,
                            'title' => 'So What',
                            'recording' => ['title' => 'So What'],
                        ],
                    ],
                ],
            ],
        ]),
        'coverartarchive.org/release/*' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://archive.org/details/front.jpg',
                'thumbnails' => ['500' => 'https://archive.org/download/front-500.jpg'],
            ]],
        ]),
    ]);

    $item = app(MusicBrainz::class)->release('76df3287-6cda-33eb-8e9a-044b5e15ffdd');

    expect($item['title'])->toBe('Kind of Blue')
        ->and($item['artist_name'])->toBe('Miles Davis')
        ->and($item['release_year'])->toBe(1959)
        ->and($item['release_country'])->toBe('US')
        ->and($item['label'])->toBe('Columbia')
        ->and($item['catalog_number'])->toBe('CL 1355')
        ->and($item['total_discs'])->toBe(1)
        ->and($item['total_tracks'])->toBe(1)
        ->and($item['tracks'][0])->toMatchArray([
            'disc' => 1,
            'track' => 1,
            'title' => 'So What',
            'artist_name' => 'Miles Davis',
        ])
        ->and($item['cover_preview_url'])->toBe('https://archive.org/download/front-500.jpg');
});

test('it escapes query text and includes the optional artist in release search', function () {
    Http::fake([
        'musicbrainz.org/ws/2/release*' => Http::response([
            'releases' => [[
                'id' => '76df3287-6cda-33eb-8e9a-044b5e15ffdd',
                'title' => 'Kind of Blue',
                'artist-credit' => [['name' => 'Miles Davis', 'joinphrase' => '']],
                'date' => '1959',
                'country' => 'US',
                'track-count' => 5,
                'status' => 'Official',
            ]],
        ]),
    ]);

    $items = app(MusicBrainz::class)->search('Kind of Blue', 'Miles Davis');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray([
            'title' => 'Kind of Blue',
            'artist_name' => 'Miles Davis',
            'track_count' => 5,
        ]);
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'release%3A')
        && str_contains($request->url(), 'artist%3A')
        && str_contains($request->header('User-Agent')[0] ?? '', 'EmosMusicAPI/1.0'));
});

test('it downloads only supported cover art from the archive host', function () {
    config(['contribution.max_cover_size' => 100]);
    Http::fake([
        'coverartarchive.org/release/*' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://archive.org/download/cover.jpg',
                'thumbnails' => ['500' => 'https://archive.org/download/cover-500.jpg'],
            ]],
        ]),
        'archive.org/download/cover.jpg' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $image = app(MusicBrainz::class)->cover('76df3287-6cda-33eb-8e9a-044b5e15ffdd');

    expect($image)->toBe(['body' => 'image-bytes', 'mime_type' => 'image/jpeg']);
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'archive.org/download/cover.jpg'));
});
