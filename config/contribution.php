<?php

return [
    'max_file_size'    => 1024 * 1024 * 1024,
    'max_cover_size'   => 20 * 1024 * 1024,
    'chunk_size'       => 16 * 1024 * 1024,
    'max_chunk_size'   => 90 * 1024 * 1024,
    'audio_extensions' => ['mp3', 'm4a', 'aac', 'flac', 'ogg', 'opus', 'wav', 'wma', 'aif', 'aiff', 'ape', 'wv', 'alac'],
    'cover_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    'quality_labels'   => ['standard' => '标准', 'high' => '高品质', 'lossless' => '无损', 'hires' => 'Hi-Res'],
    'notice'           => [
        'version' => '2026-11-14',
        'text'    => <<<'TEXT'
这是一个由社区共同维护的音乐服资源库。

欢迎上传歌曲、封面，共同完善资源。

请在上传时核对歌曲、音乐人、专辑等。同一首歌曲可以添加多种音质；现场、翻唱、混音和重制版请分别建立录音版本。
TEXT,
    ],
];
