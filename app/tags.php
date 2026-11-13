<?php
declare(strict_types=1);

/**
 * Pure PHP audio metadata reader: ID3v2.2/2.3/2.4 + ID3v1, FLAC, OGG Vorbis,
 * Opus, MP4/M4A, WAV and ADTS AAC. No external libraries, no shell-outs —
 * the server only ships PHP with gd/mbstring/fileinfo.
 *
 * audio_probe() returns:
 *   title, artist, album_artist, album, year, track_no, disc_no, genre,
 *   lyrics, duration_ms, bitrate, sample_rate, channels, format,
 *   cover => ['mime' => string, 'data' => string] | null
 */

function audio_probe(string $path): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $result = [
        'title'        => null,
        'artist'       => null,
        'album_artist' => null,
        'album'        => null,
        'year'         => null,
        'track_no'     => null,
        'disc_no'      => null,
        'genre'        => null,
        'lyrics'       => null,
        'duration_ms'  => 0,
        'bitrate'      => null,
        'sample_rate'  => null,
        'channels'     => null,
        'format'       => $ext !== '' ? $ext : 'audio',
        'cover'        => null,
    ];

    if (!is_file($path) || !is_readable($path)) {
        return $result;
    }

    $size = (int) filesize($path);

    try {
        switch ($ext) {
            case 'mp3':
                tags_probe_mp3($path, $result, $size);
                break;
            case 'flac':
                tags_probe_flac($path, $result, $size);
                break;
            case 'ogg':
            case 'oga':
            case 'opus':
                tags_probe_ogg($path, $result, $size);
                break;
            case 'm4a':
            case 'mp4':
            case 'aac':
                if ($ext === 'aac') {
                    tags_probe_aac($path, $result, $size);
                } else {
                    tags_probe_mp4($path, $result, $size);
                }
                break;
            case 'wav':
                tags_probe_wav($path, $result, $size);
                break;
            default:
                if (tags_has_prefix($path, 'ID3')) {
                    tags_probe_mp3($path, $result, $size);
                }
                break;
        }
    } catch (Throwable $e) {
        // A broken header must never abort a library scan.
    }

    // MP3 frame math reports bps; every other path reports kbps. Normalise.
    if ($result['bitrate'] !== null && $result['bitrate'] >= 10000) {
        $result['bitrate'] = (int) round($result['bitrate'] / 1000);
    }

    if ($result['duration_ms'] > 0 && $result['bitrate'] === null) {
        $result['bitrate'] = (int) round($size * 8 / ($result['duration_ms'] / 1000) / 1000);
    }

    return $result;
}

function tags_has_prefix(string $path, string $magic, int $offset = 0): bool
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    fseek($fh, $offset);
    $buf = fread($fh, strlen($magic));
    fclose($fh);
    return $buf === $magic;
}

function tags_syncsafe(string $bytes): int
{
    $b = [ord($bytes[0]), ord($bytes[1]), ord($bytes[2]), ord($bytes[3])];
    return (($b[0] & 0x7f) << 21) | (($b[1] & 0x7f) << 14) | (($b[2] & 0x7f) << 7) | ($b[3] & 0x7f);
}

function tags_be32(string $bytes): int
{
    return unpack('N', $bytes)[1];
}

function tags_le32(string $bytes): int
{
    $v = unpack('V', $bytes)[1];
    return $v;
}

function tags_le64(string $bytes): int
{
    // 0xFFFFFFFFFFFFFFFF marks an unknown granule position.
    if ($bytes === str_repeat("\xFF", 8)) {
        return -1;
    }

    $lo = unpack('V', substr($bytes, 0, 4))[1];
    $hi = unpack('V', substr($bytes, 4, 4))[1];

    if ($hi > 0x7FFFFFFF) {
        return PHP_INT_MAX;
    }

    return ($hi << 32) | $lo;
}

/** Decode an ID3v2 text payload according to its encoding byte. */
function tags_decode_text(int $enc, string $raw): string
{
    switch ($enc) {
        case 0: // ISO-8859-1
            $raw = rtrim($raw, "\0");
            if ($raw === '') {
                return '';
            }
            $out = @mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            return $out !== false ? $out : $raw;
        case 1: // UTF-16 with BOM (some taggers omit the BOM)
            if ($raw === '') {
                return '';
            }
            // Never trim raw bytes here: UTF-16 code units are two bytes, and
            // stripping a trailing NUL breaks the last ASCII character.
            if (!preg_match('/^\xFE\xFF|^\xFF\xFE/', $raw)) {
                $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
                if ($out !== false) {
                    return trim($out, "\0");
                }
            }
            $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
            if ($out !== false) {
                return trim($out, "\0");
            }
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        case 2: // UTF-16BE
            if ($raw === '') {
                return '';
            }
            $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            if ($out !== false) {
                return trim($out, "\0");
            }
            return $raw;
        case 3: // UTF-8
        default:
            $raw = rtrim($raw, "\0");
            if ($raw === '') {
                return '';
            }
            return mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
}

/** Split an ID3v2 string at the first terminator for its encoding. */
function tags_split_term(string $raw, int $enc): array
{
    $term = $enc === 1 || $enc === 2 ? "\0\0" : "\0";
    $pos  = strpos($raw, $term);

    if ($pos === false) {
        return [$raw, ''];
    }

    return [substr($raw, 0, $pos), substr($raw, $pos + strlen($term))];
}

function tags_id3_genre(int $index): ?string
{
    static $genres = [
        'Blues', 'Classic Rock', 'Country', 'Dance', 'Disco', 'Funk', 'Grunge', 'Hip-Hop',
        'Jazz', 'Metal', 'New Age', 'Oldies', 'Other', 'Pop', 'R&B', 'Rap', 'Reggae', 'Rock',
        'Techno', 'Industrial', 'Alternative', 'Ska', 'Death Metal', 'Pranks', 'Soundtrack',
        'Euro-Techno', 'Ambient', 'Trip-Hop', 'Vocal', 'Jazz+Funk', 'Fusion', 'Trance',
        'Classical', 'Instrumental', 'Acid', 'House', 'Game', 'Sound Clip', 'Gospel', 'Noise',
        'Alternative Rock', 'Bass', 'Soul', 'Punk', 'Space', 'Meditative', 'Instrumental Pop',
        'Instrumental Rock', 'Ethnic', 'Gothic', 'Darkwave', 'Techno-Industrial', 'Electronic',
        'Pop-Folk', 'Eurodance', 'Dream', 'Southern Rock', 'Comedy', 'Cult', 'Gangsta',
        'Top 40', 'Christian Rap', 'Pop/Funk', 'Jungle', 'Native American', 'Cabaret',
        'New Wave', 'Psychadelic', 'Rave', 'Showtunes', 'Trailer', 'Lo-Fi', 'Tribal',
        'Acid Punk', 'Acid Jazz', 'Polka', 'Retro', 'Musical', 'Rock & Roll', 'Hard Rock',
    ];

    return $genres[$index] ?? null;
}

/** Normalise a raw genre string ("(17)" / "17" / "Rock" / "Electronica;Dance"). */
function tags_normalize_genre(?string $genre): ?string
{
    $genre = trim((string) $genre);
    if ($genre === '') {
        return null;
    }

    if (preg_match('/^\((\d{1,3})\)(.*)$/', $genre, $m)) {
        $mapped = tags_id3_genre((int) $m[1]);
        $rest   = trim($m[2]);
        return $mapped !== null ? ($rest !== '' ? $mapped . '; ' . $rest : $mapped) : $rest;
    }

    if (preg_match('/^(\d{1,3})$/', $genre, $m)) {
        return tags_id3_genre((int) $m[1]) ?? $genre;
    }

    return $genre;
}

function tags_parse_year(?string $value): ?int
{
    if ($value === null || !preg_match('/(\d{4})/', $value, $m)) {
        return null;
    }
    $year = (int) $m[1];
    return ($year >= 1000 && $year <= 2999) ? $year : null;
}

function tags_parse_fraction(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $parts = explode('/', trim($value));
    $no    = (int) $parts[0];
    return $no > 0 ? $no : null;
}

function tags_sniff_image_mime(string $data): ?string
{
    if (str_starts_with($data, "\xFF\xD8")) {
        return 'image/jpeg';
    }
    if (str_starts_with($data, "\x89PNG")) {
        return 'image/png';
    }
    if (str_starts_with($data, 'GIF8')) {
        return 'image/gif';
    }
    if (strlen($data) >= 12 && str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}

/* -------------------------------------------------------------------------
 * MP3 / ID3
 * ---------------------------------------------------------------------- */

function tags_probe_mp3(string $path, array &$r, int $size): void
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }

    $tagSize = 0;
    $head    = fread($fh, 10);

    if (strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
        $major  = ord($head[3]);
        $flags  = ord($head[5]);
        $tagSize = tags_syncsafe(substr($head, 6, 4));

        if ($flags & 0x10) {
            $tagSize += 10; // footer
        }

        $tagData = tags_read_exact($fh, $tagSize);

        if ($tagData !== '') {
            if ($major >= 3 && $major <= 4 && ($flags & 0x80)) {
                $tagData = str_replace("\xFF\x00", "\xFF", $tagData);
            }
            tags_parse_id3v2($tagData, $major, $r);
        }

        $tagSize += 10;
    } elseif (strlen($head) >= 3 && substr($head, 0, 3) === 'TAG') {
        $tagSize = 0;
    }

    // ID3v1 as fallback / complement.
    if ($size > 128) {
        fseek($fh, $size - 128);
        $v1 = fread($fh, 128);
        if (strlen($v1) === 128 && substr($v1, 0, 3) === 'TAG') {
            tags_parse_id3v1($v1, $r);
        }
    }

    $audio = tags_mp3_find_frame($fh, $tagSize, $size);
    if ($audio !== null) {
        $r['duration_ms'] = (int) round($audio['duration'] * 1000);
        $r['sample_rate'] = $audio['sample_rate'];
        $r['channels']    = $audio['channels'];
        if ($audio['bitrate'] > 0) {
            $r['bitrate'] = $audio['bitrate'];
        }
    }

    fclose($fh);
}

function tags_read_exact($fh, int $length): string
{
    $data = '';
    while (strlen($data) < $length && !feof($fh)) {
        $chunk = fread($fh, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    return $data;
}

function tags_parse_id3v2(string $tag, int $major, array &$r): void
{
    $offset  = 0;
    $length  = strlen($tag);
    $version = $major === 2 ? 2 : ($major === 4 ? 4 : 3);
    $header  = $version === 2 ? 6 : 10;

    while ($offset + $header <= $length) {
        if ($version === 2) {
            $id   = substr($tag, $offset, 3);
            $size = unpack('N', "\0" . substr($tag, $offset + 3, 3))[1];
            $fpos = $offset + 6;
        } else {
            $id   = substr($tag, $offset, 4);
            $raw  = substr($tag, $offset + 4, 4);
            $size = $version === 4
                ? tags_syncsafe($raw)
                : unpack('N', $raw)[1];
            $fpos = $offset + 10;
            $flags = unpack('n', substr($tag, $offset + 8, 2))[1];
        }

        if ($id === '' || trim($id, "\0") === '' || !preg_match('/^[A-Z0-9]{3,4}$/', $id)) {
            break;
        }
        if ($size <= 0 || $fpos + $size > $length) {
            break;
        }

        $data = substr($tag, $fpos, $size);

        if ($version === 4 && isset($flags) && ($flags & 0x02)) {
            $data = str_replace("\xFF\x00", "\xFF", $data);
        }

        tags_apply_id3_frame($id, $data, $r);

        $offset = $fpos + $size;
    }
}

function tags_apply_id3_frame(string $id, string $data, array &$r): void
{
    // Map ID3v2.2 three-letter ids onto the 2.3/2.4 equivalents.
    $id = match ($id) {
        'TT2' => 'TIT2',
        'TP1' => 'TPE1',
        'TP2' => 'TPE2',
        'TAL' => 'TALB',
        'TYE' => 'TYER',
        'TRK' => 'TRCK',
        'TPA' => 'TPOS',
        'TCO' => 'TCON',
        'PIC' => 'APIC',
        'ULT' => 'USLT',
        default => $id,
    };

    if ($id === 'APIC' || $id === 'PIC') {
        if ($r['cover'] !== null) {
            return;
        }

        $enc  = ord($data[0]);
        $rest = substr($data, 1);

        if ($id === 'PIC') {
            $mime = match (strtoupper(substr($rest, 0, 3))) {
                'PNG' => 'image/png',
                'GIF' => 'image/gif',
                default => 'image/jpeg',
            };
            $rest = substr($rest, 3);
        } else {
            [$mime, $rest] = tags_split_term($rest, 0);
            $mime = trim($mime);
        }

        $rest = substr($rest, 1); // picture type byte
        [, $binary] = tags_split_term($rest, $enc);

        if ($binary !== '' && strlen($binary) > 100) {
            $sniffed = tags_sniff_image_mime($binary);
            $r['cover'] = [
                'mime' => $sniffed ?? ($mime !== '' ? $mime : 'image/jpeg'),
                'data' => $binary,
            ];
        }
        return;
    }

    if ($id === 'USLT') {
        $enc  = ord($data[0]);
        $rest = substr($data, 1);
        $rest = substr($rest, 3); // language
        [, $text] = tags_split_term($rest, $enc);
        $text = trim(tags_decode_text($enc, $text));
        if ($text !== '' && ($r['lyrics'] ?? null) === null) {
            $r['lyrics'] = mb_substr($text, 0, 100000);
        }
        return;
    }

    if ($id[0] !== 'T' || strlen($data) < 2) {
        return;
    }

    $enc  = ord($data[0]);
    $text = tags_decode_text($enc, substr($data, 1));

    switch ($id) {
        case 'TIT2':
            $r['title'] = mb_substr(trim($text), 0, 255) ?: $r['title'];
            break;
        case 'TPE1':
            $r['artist'] = mb_substr(trim($text), 0, 255) ?: $r['artist'];
            break;
        case 'TPE2':
            $r['album_artist'] = mb_substr(trim($text), 0, 255) ?: $r['album_artist'];
            break;
        case 'TALB':
            $r['album'] = mb_substr(trim($text), 0, 255) ?: $r['album'];
            break;
        case 'TYER':
        case 'TDRC':
        case 'TDRL':
            $r['year'] = tags_parse_year($text) ?? $r['year'];
            break;
        case 'TRCK':
            $r['track_no'] = tags_parse_fraction($text) ?? $r['track_no'];
            break;
        case 'TPOS':
            $r['disc_no'] = tags_parse_fraction($text) ?? $r['disc_no'];
            break;
        case 'TCON':
            $r['genre'] = tags_normalize_genre($text) ?? $r['genre'];
            break;
    }
}

function tags_parse_id3v1(string $v1, array &$r): void
{
    $cut = static function (string $s): string {
        $s = rtrim($s, "\0 ");
        return trim($s);
    };

    $title  = $cut(substr($v1, 3, 30));
    $artist = $cut(substr($v1, 33, 30));
    $album  = $cut(substr($v1, 63, 30));
    $year   = $cut(substr($v1, 93, 4));
    $genreI = ord($v1[127]);

    if ($r['title'] === null && $title !== '') {
        $r['title'] = mb_substr($title, 0, 255);
    }
    if ($r['artist'] === null && $artist !== '') {
        $r['artist'] = mb_substr($artist, 0, 255);
    }
    if ($r['album'] === null && $album !== '') {
        $r['album'] = mb_substr($album, 0, 255);
    }
    if ($r['year'] === null) {
        $r['year'] = tags_parse_year($year);
    }
    if ($r['genre'] === null && $genreI < 192) {
        $r['genre'] = tags_id3_genre($genreI);
    }
}

function tags_mp3_find_frame($fh, int $start, int $size): ?array
{
    $pos    = $start;
    $end    = min($size, $start + 5 * 1024 * 1024);
    $tables = tags_mp3_tables();

    // Locate the first valid frame header.
    $first = null;
    while ($pos + 4 <= $end) {
        fseek($fh, $pos);
        $buf = fread($fh, 4);
        if (strlen($buf) < 4) {
            return null;
        }
        if (ord($buf[0]) === 0xFF && (ord($buf[1]) & 0xE0) === 0xE0) {
            $hdr = tags_mp3_header($buf, $tables);
            if ($hdr !== null) {
                $first = ['pos' => $pos] + $hdr;
                break;
            }
        }
        $pos++;
    }

    if ($first === null) {
        return null;
    }

    // Xing / VBRI header gives the exact frame count for (V)BR files.
    if ($first['version'] !== 0) {
        $side = $first['version'] === 3
            ? ($first['channels'] === 1 ? 17 : 32)
            : ($first['channels'] === 1 ? 9 : 17);
        $xingPos = $first['pos'] + 4 + ($first['crc'] ? 2 : 0) + $side;

        if ($first['crc']) {
            // side info already accounts for header; crc sits between them
            $xingPos = $first['pos'] + 4 + 2 + $side;
        }

        fseek($fh, $xingPos);
        $tag = fread($fh, 4);
        if ($tag === 'Xing' || $tag === 'Info') {
            $flags = tags_be32(tags_read_exact($fh, 4));
            if ($flags & 0x01) {
                $frames = tags_be32(tags_read_exact($fh, 4));
                if ($frames > 0) {
                    $duration = $frames * $first['samples'] / $first['sample_rate'];
                    return [
                        'duration'    => $duration,
                        'sample_rate' => $first['sample_rate'],
                        'channels'    => $first['channels'],
                        'bitrate'     => (int) round($size * 8 / max($duration, 0.001) / 1000),
                    ];
                }
            }
        }

        // VBRI lives at a fixed offset from the frame start.
        fseek($fh, $first['pos'] + 4 + 32);
        $tag = fread($fh, 4);
        if ($tag === 'VBRI') {
            fseek($fh, $first['pos'] + 4 + 32 + 10 + 2);
            $frames = tags_be32(tags_read_exact($fh, 4));
            if ($frames > 0) {
                $duration = $frames * $first['samples'] / $first['sample_rate'];
                return [
                    'duration'    => $duration,
                    'sample_rate' => $first['sample_rate'],
                    'channels'    => $first['channels'],
                    'bitrate'     => (int) round($size * 8 / max($duration, 0.001) / 1000),
                ];
            }
        }
    }

    // No Xing: walk frame headers, accumulating samples. Cheap (few freads).
    $pos      = $first['pos'];
    $seconds  = 0.0;
    $guard    = 0;
    $bitrates = [];

    while ($pos + 4 <= $size && $guard < 1_000_000) {
        $guard++;
        fseek($fh, $pos);
        $buf = fread($fh, 4);
        if (strlen($buf) < 4) {
            break;
        }

        if (!(ord($buf[0]) === 0xFF && (ord($buf[1]) & 0xE0) === 0xE0)) {
            $pos++;
            continue;
        }

        $hdr = tags_mp3_header($buf, $tables);
        if ($hdr === null) {
            $pos++;
            continue;
        }

        $seconds += $hdr['samples'] / $hdr['sample_rate'];
        $bitrates[] = $hdr['bitrate'];
        $pos += $hdr['frame_length'];

        if (isset($first['sample_rate']) === false) {
            $first['sample_rate'] = $hdr['sample_rate'];
        }
    }

    if ($seconds <= 0) {
        $seconds = 0;
    }

    $avgBitrate = $bitrates !== []
        ? (int) round(array_sum($bitrates) / count($bitrates))
        : 0;

    if ($avgBitrate <= 0 && $seconds > 0) {
        $avgBitrate = (int) round($size * 8 / $seconds / 1000);
    }

    return [
        'duration'    => $seconds,
        'sample_rate' => $first['sample_rate'],
        'channels'    => $first['channels'],
        'bitrate'     => $avgBitrate,
    ];
}

function tags_mp3_header(string $buf, array $tables): ?array
{
    $b1 = ord($buf[1]);
    $b2 = ord($buf[2]);
    $b3 = ord($buf[3]);

    $versionBits = ($b1 >> 3) & 0x03; // 3=1, 2=2, 0=2.5, 1=reserved
    $layerBits   = ($b1 >> 1) & 0x03; // 3=I, 2=II, 1=III
    $bitrateIdx  = ($b2 >> 4) & 0x0F;
    $rateIdx     = ($b2 >> 2) & 0x03;
    $padding     = ($b2 >> 1) & 0x01;
    $channelMode = ($b3 >> 6) & 0x03;

    if ($versionBits === 1 || $layerBits === 0 || $bitrateIdx === 0 || $bitrateIdx === 15 || $rateIdx === 3) {
        return null;
    }

    $layer = [null, 3, 2, 1][$layerBits];

    $sampleRate = match ($versionBits) {
        3 => [44100, 48000, 32000][$rateIdx],
        2 => [22050, 24000, 16000][$rateIdx],
        0 => [11025, 12000, 8000][$rateIdx],
    };

    $brKey = match (true) {
        $versionBits === 3 && $layer === 1 => 'v1l1',
        $versionBits === 3 && $layer === 2 => 'v1l2',
        $versionBits === 3 && $layer === 3 => 'v1l3',
        $versionBits !== 3 && $layer === 1 => 'v2l1',
        default => 'v2l3',
    };

    $bitrate = $tables[$brKey][$bitrateIdx] * 1000;
    if ($bitrate <= 0) {
        return null;
    }

    $samples = match (true) {
        $layer === 1 => 384,
        $layer === 2 => 1152,
        default => $versionBits === 3 ? 1152 : 576,
    };

    if ($layer === 1) {
        $frameLength = (int) (12 * $bitrate / $sampleRate + $padding) * 4;
    } else {
        $frameLength = (int) ($samples / 8 * $bitrate / $sampleRate) + $padding;
    }

    if ($frameLength < 8) {
        return null;
    }

    return [
        'version'      => $versionBits,
        'layer'        => $layer,
        'bitrate'      => $bitrate,
        'sample_rate'  => $sampleRate,
        'channels'     => $channelMode === 3 ? 1 : 2,
        'samples'      => $samples,
        'crc'          => (ord($buf[1]) & 0x01) === 0,
        'frame_length' => $frameLength,
    ];
}

function tags_mp3_tables(): array
{
    return [
        'v1l1' => [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
        'v1l2' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
        'v1l3' => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        'v2l1' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
        'v2l3' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];
}

/* -------------------------------------------------------------------------
 * FLAC
 * ---------------------------------------------------------------------- */

function tags_probe_flac(string $path, array &$r, int $size): void
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }

    if (fread($fh, 4) !== 'fLaC') {
        fclose($fh);
        return;
    }

    $sampleRate = 0;
    $total      = 0;

    for ($i = 0; $i < 16; $i++) {
        $head = tags_read_exact($fh, 4);
        if (strlen($head) < 4) {
            break;
        }

        $isLast = (ord($head[0]) & 0x80) !== 0;
        $type   = ord($head[0]) & 0x7F;
        $len    = (ord($head[1]) << 16) | (ord($head[2]) << 8) | ord($head[3]);
        $data   = tags_read_exact($fh, $len);

        if (strlen($data) < $len) {
            break;
        }

        if ($type === 0 && $len >= 18) {
            $sampleRate = (ord($data[10]) << 12) | (ord($data[11]) << 4) | (ord($data[12]) >> 4);
            $channels   = ((ord($data[12]) >> 1) & 0x07) + 1;
            $hi = (ord($data[13]) & 0x0F);
            $lo = unpack('N', substr($data, 14, 4))[1];
            $total = ($hi << 32) | $lo;

            $r['sample_rate'] = $sampleRate;
            $r['channels']    = $channels;
        } elseif ($type === 4) {
            tags_parse_vorbis_comments($data, $r);
        } elseif ($type === 6) {
            tags_parse_flac_picture($data, $r);
        }

        if ($isLast) {
            break;
        }
    }

    fclose($fh);

    if ($sampleRate > 0 && $total > 0) {
        $r['duration_ms'] = (int) round($total / $sampleRate * 1000);
    }
}

function tags_parse_vorbis_comments(string $data, array &$r): void
{
    $off = 0;
    if (strlen($data) < 4) {
        return;
    }

    $vendorLen = tags_le32(substr($data, $off, 4));
    $off += 4 + $vendorLen;
    if ($off + 4 > strlen($data)) {
        return;
    }

    $count = tags_le32(substr($data, $off, 4));
    $off += 4;

    for ($i = 0; $i < $count && $off + 4 <= strlen($data); $i++) {
        $len = tags_le32(substr($data, $off, 4));
        $off += 4;
        $entry = substr($data, $off, $len);
        $off += $len;

        $eq = strpos($entry, '=');
        if ($eq === false) {
            continue;
        }

        $key   = strtoupper(substr($entry, 0, $eq));
        $value = trim(substr($entry, $eq + 1));

        switch ($key) {
            case 'TITLE':
                $r['title'] = mb_substr($value, 0, 255) ?: $r['title'];
                break;
            case 'ARTIST':
                $r['artist'] = mb_substr($value, 0, 255) ?: $r['artist'];
                break;
            case 'ALBUMARTIST':
            case 'ALBUM ARTIST':
            case 'ENSEMBLE':
                $r['album_artist'] = mb_substr($value, 0, 255) ?: $r['album_artist'];
                break;
            case 'ALBUM':
                $r['album'] = mb_substr($value, 0, 255) ?: $r['album'];
                break;
            case 'DATE':
            case 'YEAR':
                $r['year'] = tags_parse_year($value) ?? $r['year'];
                break;
            case 'TRACKNUMBER':
                $r['track_no'] = tags_parse_fraction($value) ?? $r['track_no'];
                break;
            case 'DISCNUMBER':
                $r['disc_no'] = tags_parse_fraction($value) ?? $r['disc_no'];
                break;
            case 'GENRE':
                $r['genre'] = tags_normalize_genre($value) ?? $r['genre'];
                break;
            case 'LYRICS':
            case 'UNSYNCEDLYRICS':
                if ($value !== '' && ($r['lyrics'] ?? null) === null) {
                    $r['lyrics'] = mb_substr($value, 0, 100000);
                }
                break;
        }
    }
}

function tags_parse_flac_picture(string $data, array &$r): void
{
    if ($r['cover'] !== null || strlen($data) < 32) {
        return;
    }

    $off    = 4;
    $mimeLen = tags_be32(substr($data, $off, 4));
    $off   += 4;
    $mime   = substr($data, $off, $mimeLen);
    $off   += $mimeLen;
    $off   += 4; // description length
    $descLen = tags_be32(substr($data, $off - 4, 4));
    $off   += $descLen;
    $off   += 16; // width, height, depth, colors
    $picLen = tags_be32(substr($data, $off, 4));
    $off   += 4;

    $binary = substr($data, $off, $picLen);
    if ($binary !== '' && strlen($binary) > 100) {
        $r['cover'] = [
            'mime' => tags_sniff_image_mime($binary) ?? ($mime !== '' ? $mime : 'image/jpeg'),
            'data' => $binary,
        ];
    }
}

/* -------------------------------------------------------------------------
 * OGG Vorbis / Opus
 * ---------------------------------------------------------------------- */

function tags_probe_ogg(string $path, array &$r, int $size): void
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }

    $packets  = [];
    $granule  = 0;
    $lastGran = null;
    $pageNo   = 0;

    while (!feof($fh) && $pageNo < 200000) {
        $pageNo++;
        $header = tags_read_exact($fh, 27);
        if (strlen($header) < 27 || substr($header, 0, 4) !== 'OggS') {
            break;
        }

        $granuleRaw = substr($header, 6, 8);
        $granule    = tags_le64($granuleRaw);
        $segCount   = ord($header[26]);
        $segTable   = tags_read_exact($fh, $segCount);
        if (strlen($segTable) < $segCount) {
            break;
        }

        $bodyLen = 0;
        $packetLens = [];
        $current = 0;
        for ($i = 0; $i < $segCount; $i++) {
            $seg = ord($segTable[$i]);
            $bodyLen += $seg;
            $current += $seg;
            if ($seg < 255) {
                $packetLens[] = $current;
                $current = 0;
            }
        }
        if ($current > 0) {
            // Continued packet; body handled loosely — enough for headers.
            $packetLens[] = $current;
        }

        // Read only as much as we still need for the first two packets.
        $need = count($packets) < 2 ? $bodyLen : 0;
        $body = $need > 0 ? tags_read_exact($fh, $need) : '';
        if ($need > 0 && strlen($body) < $need) {
            break;
        }
        if ($need === 0 && $bodyLen > 0) {
            fseek($fh, $bodyLen, SEEK_CUR);
        }

        if ($body !== '' && count($packets) < 2) {
            $off = 0;
            foreach ($packetLens as $len) {
                if (count($packets) >= 2) {
                    break;
                }
                $packets[] = substr($body, $off, $len);
                $off += $len;
            }
        }

        if ($granule >= 0 && $granule !== PHP_INT_MAX) {
            $lastGran = $granule;
        }

        if (isset($packets[0]) && isset($packets[1])) {
            tags_parse_ogg_packets($packets, $r);
            // Keep scanning pages only for the final granule (duration).
        }
    }

    fclose($fh);

    if (isset($packets[0])) {
        tags_parse_ogg_packets($packets, $r);
    }

    if ($lastGran !== null && $lastGran > 0 && ($r['sample_rate'] ?? 0) > 0) {
        $rate = $r['sample_rate'];
        if ($r['format'] === 'opus') {
            $rate = 48000;
        }
        $seconds = $lastGran / $rate;
        $r['duration_ms'] = (int) round($seconds * 1000);
    }

    if ($r['duration_ms'] > 0 && $r['bitrate'] === null) {
        $r['bitrate'] = (int) round($size * 8 / ($r['duration_ms'] / 1000) / 1000);
    }
}

function tags_parse_ogg_packets(array $packets, array &$r): void
{
    $ident = $packets[0];

    if (str_starts_with($ident, "\x01vorbis")) {
        $r['format'] = 'ogg';
        if (strlen($ident) >= 30) {
            $channels   = ord($ident[11]);
            $rate       = tags_le32(substr($ident, 12, 4));
            $nominal    = tags_le32(substr($ident, 20, 4));
            $r['channels']    = $channels ?: $r['channels'];
            $r['sample_rate'] = $rate ?: $r['sample_rate'];
            if ($nominal > 0) {
                $r['bitrate'] = (int) round(abs($nominal) / 1000);
            }
        }
        if (isset($packets[1]) && str_starts_with($packets[1], "\x03vorbis")) {
            tags_parse_vorbis_comments(substr($packets[1], 7), $r);
        }
        return;
    }

    if (str_starts_with($ident, 'OpusHead')) {
        $r['format'] = 'opus';
        $r['sample_rate'] = 48000;
        if (strlen($ident) >= 19) {
            $r['channels'] = ord($ident[9]);
        }
        if (isset($packets[1]) && str_starts_with($packets[1], 'OpusTags')) {
            tags_parse_vorbis_comments(substr($packets[1], 8), $r);
        }
        return;
    }

    if (str_starts_with($ident, "\x7FFLAC")) {
        $r['format'] = 'ogg';
        if (isset($packets[1]) && substr($packets[1], 0, 4) === "\x00\x00\x00\x22" || (isset($packets[1]) && strlen($packets[1]) > 4)) {
            // OGG-FLAC comment header: 4-byte header + STREAMINFO? Use safe parse.
            $vc = $packets[1];
            if (str_starts_with($vc, "\x7F")) {
                $vc = substr($vc, 9); // magic + version + streams + channels... fallback below
            }
            tags_parse_vorbis_comments($vc, $r);
        }
    }
}

/* -------------------------------------------------------------------------
 * MP4 / M4A
 * ---------------------------------------------------------------------- */

function tags_probe_mp4(string $path, array &$r, int $size): void
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }

    $r['format'] = 'm4a';

    $moov = tags_mp4_find_atom($fh, 0, $size, 'moov');
    if ($moov === null) {
        fclose($fh);
        return;
    }

    [$moovStart, $moovLen] = $moov;
    $end = $moovStart + $moovLen;

    $pos = $moovStart;
    while ($pos + 8 <= $end) {
        fseek($fh, $pos);
        $head = tags_read_exact($fh, 8);
        if (strlen($head) < 8) {
            break;
        }
        $atomSize = tags_be32(substr($head, 0, 4));
        $atomType = substr($head, 4, 4);
        $hdrSize  = 8;

        if ($atomSize === 1) {
            $wide = tags_read_exact($fh, 8);
            if (strlen($wide) < 8) {
                break;
            }
            $atomSize = tags_le64_msw($wide);
            $hdrSize  = 16;
        } elseif ($atomSize === 0) {
            $atomSize = $end - $pos;
        }

        if ($atomSize < $hdrSize) {
            break;
        }

        if ($atomType === 'mvhd') {
            $body = tags_read_exact($fh, $atomSize - $hdrSize);
            if ($body !== '') {
                $version = ord($body[0]);
                if ($version === 1 && strlen($body) >= 32) {
                    $timescale = tags_be32(substr($body, 20, 4));
                    $duration  = tags_le64_msw(substr($body, 24, 8));
                } else {
                    $timescale = tags_be32(substr($body, 12, 4));
                    $duration  = tags_be32(substr($body, 16, 4));
                }
                if ($timescale > 0 && $duration > 0) {
                    $r['duration_ms'] = (int) round($duration / $timescale * 1000);
                }
            }
        } elseif ($atomType === 'udta') {
            $udtaEnd = $pos + $atomSize;
            $upos    = $pos + $hdrSize;
            while ($upos + 8 <= $udtaEnd) {
                fseek($fh, $upos);
                $h2 = tags_read_exact($fh, 8);
                if (strlen($h2) < 8) {
                    break;
                }
                $s2 = tags_be32(substr($h2, 0, 4));
                $t2 = substr($h2, 4, 4);
                if ($s2 < 8) {
                    break;
                }
                if ($t2 === 'meta') {
                    // 'meta' has a 4-byte version/flags header before children.
                    $mstart = $upos + 12;
                    $mend   = $upos + $s2;
                    $mpos   = $mstart;
                    while ($mpos + 8 <= $mend) {
                        fseek($fh, $mpos);
                        $h3 = tags_read_exact($fh, 8);
                        if (strlen($h3) < 8) {
                            break;
                        }
                        $s3 = tags_be32(substr($h3, 0, 4));
                        $t3 = substr($h3, 4, 4);
                        if ($s3 < 8) {
                            break;
                        }
                        if ($t3 === 'ilst') {
                            tags_parse_mp4_ilst($fh, $mpos + 8, $mpos + $s3, $r);
                        }
                        $mpos += $s3;
                    }
                }
                $upos += $s2;
            }
        }

        $pos += $atomSize;
    }

    fclose($fh);
}

function tags_le64_msw(string $bytes): int
{
    $hi = unpack('N', substr($bytes, 0, 4))[1];
    $lo = unpack('N', substr($bytes, 4, 4))[1];
    return ($hi << 32) | $lo;
}

function tags_mp4_find_atom($fh, int $start, int $end, string $want): ?array
{
    $pos = $start;
    $guard = 0;

    while ($pos + 8 <= $end && $guard < 10000) {
        $guard++;
        fseek($fh, $pos);
        $head = tags_read_exact($fh, 8);
        if (strlen($head) < 8) {
            return null;
        }

        $size = tags_be32(substr($head, 0, 4));
        $type = substr($head, 4, 4);
        $hdr  = 8;

        if ($size === 1) {
            $wide = tags_read_exact($fh, 8);
            if (strlen($wide) < 8) {
                return null;
            }
            $size = tags_le64_msw($wide);
            $hdr  = 16;
        } elseif ($size === 0) {
            $size = $end - $pos;
        }

        if ($size < $hdr) {
            return null;
        }

        if ($type === $want) {
            return [$pos + $hdr, $size - $hdr];
        }

        // Containers worth descending into.
        if (in_array($type, ['moov', 'udta', 'trak', 'mdia', 'minf', 'stbl'], true)) {
            $found = tags_mp4_find_atom($fh, $pos + $hdr, $pos + $size, $want);
            if ($found !== null) {
                return $found;
            }
        }

        $pos += $size;
    }

    return null;
}

function tags_parse_mp4_ilst($fh, int $start, int $end, array &$r): void
{
    $pos = $start;
    while ($pos + 8 <= $end) {
        fseek($fh, $pos);
        $head = tags_read_exact($fh, 8);
        if (strlen($head) < 8) {
            break;
        }
        $size = tags_be32(substr($head, 0, 4));
        $type = substr($head, 4, 4);
        if ($size < 8 || $pos + $size > $end + 8) {
            break;
        }

        // ©nam style atoms start with byte 0xA9.
        $key = $type;
        if ($type[0] === "\xA9") {
            $key = 'c' . substr($type, 1);
        }

        $bodyStart = $pos + 8;
        $bodyEnd   = $pos + $size;

        if ($type === 'covr' || $key === 'trkn' || $key === 'disk' || $key === 'gnre') {
            tags_parse_mp4_data_atom($fh, $bodyStart, $bodyEnd, $key, $r);
        } else {
            tags_parse_mp4_data_atom($fh, $bodyStart, $bodyEnd, $key, $r);
        }

        $pos += $size;
    }
}

function tags_parse_mp4_data_atom($fh, int $start, int $end, string $key, array &$r): void
{
    $pos = $start;
    while ($pos + 8 <= $end) {
        fseek($fh, $pos);
        $head = tags_read_exact($fh, 8);
        if (strlen($head) < 8) {
            break;
        }
        $size = tags_be32(substr($head, 0, 4));
        $type = substr($head, 4, 4);
        if ($size < 8) {
            break;
        }

        if ($type === 'data' && $pos + $size <= $end + 8) {
            $payload = tags_read_exact($fh, $size - 8);
            if (strlen($payload) >= 8) {
                $dataType = tags_be32(substr($payload, 0, 4));
                $value    = substr($payload, 8);

                switch ($key) {
                    case 'cnam':
                        $r['title'] = mb_substr(trim($value), 0, 255) ?: $r['title'];
                        break;
                    case 'cART':
                        $r['artist'] = mb_substr(trim($value), 0, 255) ?: $r['artist'];
                        break;
                    case 'calb':
                        $r['album'] = mb_substr(trim($value), 0, 255) ?: $r['album'];
                        break;
                    case 'cday':
                        $r['year'] = tags_parse_year($value) ?? $r['year'];
                        break;
                    case 'cgen':
                    case 'gnre':
                        if ($key === 'gnre' && strlen($value) >= 2) {
                            $idx = unpack('n', substr($value, 0, 2))[1];
                            $r['genre'] = tags_id3_genre($idx) ?? $r['genre'];
                        } else {
                            $r['genre'] = tags_normalize_genre($value) ?? $r['genre'];
                        }
                        break;
                    case 'trkn':
                        if (strlen($value) >= 4) {
                            $no = unpack('n', substr($value, 2, 2))[1];
                            if ($no > 0) {
                                $r['track_no'] = $no;
                            }
                        }
                        break;
                    case 'disk':
                        if (strlen($value) >= 4) {
                            $no = unpack('n', substr($value, 2, 2))[1];
                            if ($no > 0) {
                                $r['disc_no'] = $no;
                            }
                        }
                        break;
                    case 'covr':
                        if ($r['cover'] === null && strlen($value) > 100) {
                            $mime = match ($dataType) {
                                13 => 'image/png',
                                14 => 'image/jpeg',
                                default => tags_sniff_image_mime($value),
                            };
                            if ($mime !== null) {
                                $r['cover'] = ['mime' => $mime, 'data' => $value];
                            }
                        }
                        break;
                }
            }
        }

        $pos += $size;
    }
}

/* -------------------------------------------------------------------------
 * WAV / ADTS AAC
 * ---------------------------------------------------------------------- */

function tags_probe_wav(string $path, array &$r, int $size): void
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }

    $head = tags_read_exact($fh, 12);
    if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
        fclose($fh);
        return;
    }

    $r['format'] = 'wav';
    $byteRate    = 0;
    $dataSize    = 0;
    $pos         = 12;

    while ($pos + 8 <= $size) {
        fseek($fh, $pos);
        $chunk = tags_read_exact($fh, 8);
        if (strlen($chunk) < 8) {
            break;
        }
        $id   = substr($chunk, 0, 4);
        $len  = tags_le32(substr($chunk, 4, 4));
        $body = tags_read_exact($fh, $len);

        if ($id === 'fmt ' && strlen($body) >= 16) {
            $r['channels']    = unpack('v', substr($body, 2, 2))[1];
            $r['sample_rate'] = tags_le32(substr($body, 4, 4));
            $byteRate         = tags_le32(substr($body, 8, 4));
            $bits             = unpack('v', substr($body, 14, 2))[1];
            if ($r['channels'] > 0 && $r['sample_rate'] > 0 && $byteRate > 0) {
                $r['bitrate'] = (int) round($byteRate * 8 / 1000);
            }
        } elseif ($id === 'data') {
            $dataSize = $len;
            break;
        }

        $pos += 8 + $len + ($len % 2);
    }

    fclose($fh);

    if ($byteRate > 0 && $dataSize > 0) {
        $r['duration_ms'] = (int) round($dataSize / $byteRate * 1000);
    }
}

function tags_probe_aac(string $path, array &$r, int $size): void
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }

    $r['format'] = 'aac';

    static $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];

    $pos      = 0;
    $frames   = 0;
    $sampleRate = 0;
    $channels = 0;

    while ($pos + 7 <= $size && $frames < 1_000_000) {
        fseek($fh, $pos);
        $buf = tags_read_exact($fh, 7);
        if (strlen($buf) < 7) {
            break;
        }

        if (ord($buf[0]) !== 0xFF || (ord($buf[1]) & 0xF6) !== 0xF0) {
            $pos++;
            continue;
        }

        $rateIdx  = (ord($buf[2]) >> 2) & 0x0F;
        $chanCfg  = ((ord($buf[2]) & 0x01) << 2) | ((ord($buf[3]) >> 6) & 0x03);
        $frameLen = ((ord($buf[3]) & 0x03) << 11) | (ord($buf[4]) << 3) | (ord($buf[5]) >> 5);

        if ($frameLen < 7) {
            $pos++;
            continue;
        }

        if ($frames === 0) {
            $sampleRate = $rates[$rateIdx] ?? 44100;
            $channels   = max(1, min(7, $chanCfg));
        }

        $frames++;
        $pos += $frameLen;
    }

    fclose($fh);

    if ($frames > 0 && $sampleRate > 0) {
        $r['duration_ms']  = (int) round($frames * 1024 / $sampleRate * 1000);
        $r['sample_rate']  = $sampleRate;
        $r['channels']     = $channels;
    }
}
