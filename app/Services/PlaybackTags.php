<?php

namespace App\Services;

use App\Models;

class PlaybackTags
{
    private array $known = [];

    public function add(string $songId, string $genre): void
    {
        $names = array_values(array_unique(array_filter(array_map('trim', preg_split('~[/,，;；]+~u', $genre)))));
        $rows = [];
        foreach ($names as $name) {
            $id = $this->tagId($name);
            if (!isset($this->known[$id])) {
                Models\Server\Record::at('tag')->upsert([['id' => $id, 'tag_name' => 'genre', 'tag_value' => $name]], ['id'], ['tag_value']);
                $this->known[$id] = true;
            }
            $rows[] = ['media_file_id' => $songId, 'tag_id' => $id];
        }
        if ($rows) {
            Models\Server\Record::at('media_file_tags')->insertOrIgnore($rows);
        }
    }

    private function tagId(string $name): string
    {
        $bytes = array_values(unpack('C*', md5('genre' . "\u{200b}" . mb_strtolower($name) . "\u{200b}", true)));
        $digits = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';
        while ($bytes) {
            $quotient = [];
            $carry = 0;
            foreach ($bytes as $byte) {
                $value = $carry * 256 + $byte;
                $digit = intdiv($value, 62);
                $carry = $value % 62;
                if ($quotient || $digit) {
                    $quotient[] = $digit;
                }
            }
            $result = $digits[$carry] . $result;
            $bytes = $quotient;
        }

        return str_pad($result, 22, '0', STR_PAD_LEFT);
    }

}
