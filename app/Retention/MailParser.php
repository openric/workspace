<?php

// OpenRiC Workspace - reads a captured e-mail: an .eml file (RFC 5322 with MIME), or an Outlook .msg file (a compound
// file of MAPI properties) (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The .eml reader is Heratio's EmailCaptureService::parseEml (headers with continuation lines, RFC 2047 words,
// base64 and quoted-printable parts), changed to walk nested multiparts and to list each attachment with its name,
// type, size and SHA-256 instead of only counting them. ponytail: the .msg reader reads the compound file format
// ([MS-CFB]) and the few MAPI properties a captured e-mail needs ([MS-OXMSG]): subject, sender, display To and Cc,
// the plain and HTML bodies, the internet message ID, the transport headers, the submit and delivery times, and the
// attachments' names and data. No library was used, since the one that reads .msg needs the bcmath extension this host
// does not have; a library is the upgrade path if a .msg it cannot read turns up. Either reader returns the same shape.

namespace App\Retention;

use InvalidArgumentException;

class MailParser
{
    private const END_OF_CHAIN = 0xFFFFFFFE;

    private const FREE = 0xFFFFFFFF;

    /** @return array{message_id: string, from: string, to: string, cc: string, subject: string, sent_at: ?string, received_at: ?string, body_text: ?string, body_html: ?string, attachments: list<array{name: string, mime_type: string, size_bytes: int, sha256: string}>} */
    public function eml(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
        $headers = $this->headers($head);
        if (! isset($headers['from']) && ! isset($headers['message-id'])) {
            throw new InvalidArgumentException(__('That is not an e-mail message.'));
        }
        $out = ['body_text' => null, 'body_html' => null, 'attachments' => []];
        $this->part($headers, $body, $out);
        $received = $headers['received'] ?? null;
        $received = is_array($received) ? $received[0] : $received;

        return ['message_id' => trim((string) $this->one($headers['message-id'] ?? ''), " <>\t\n"), 'from' => $this->address($headers['from'] ?? ''), 'to' => $this->address($headers['to'] ?? ''),
            'cc' => $this->address($headers['cc'] ?? ''), 'subject' => $this->decodeHeader($this->one($headers['subject'] ?? '')), 'sent_at' => $this->date($this->one($headers['date'] ?? '')),
            'received_at' => $received && preg_match('/;\s*(.+)$/', $received, $m) ? $this->date($m[1]) : null] + $out;
    }

    public function msg(string $raw): array
    {
        $streams = $this->compoundFile($raw);
        $string = function (string $path, string $id) use ($streams): ?string {
            if (isset($streams[$path.'__substg1.0_'.$id.'001F'])) {
                return mb_convert_encoding($streams[$path.'__substg1.0_'.$id.'001F'], 'UTF-8', 'UTF-16LE');
            }
            if (isset($streams[$path.'__substg1.0_'.$id.'001E'])) {
                return mb_convert_encoding(rtrim($streams[$path.'__substg1.0_'.$id.'001E'], "\0"), 'UTF-8', 'Windows-1252');
            }

            return null;
        };
        $clean = fn (?string $s) => $s === null ? null : rtrim($s, "\0");
        $times = $this->times($streams['__properties_version1.0'] ?? '', 32);
        $headers = $this->headers(str_replace(["\r\n", "\r"], "\n", (string) $clean($string('', '007D'))));
        $html = $streams['__substg1.0_10130102'] ?? $clean($string('', '1013'));
        $attachments = [];
        foreach (array_keys($streams) as $path) {
            if (preg_match('#^(__attach_version1\.0_\#[0-9A-F]{8}/)__substg1\.0_37010102$#', $path, $m)) {
                $data = $streams[$path];
                $name = $clean($string($m[1], '3707')) ?: $clean($string($m[1], '3704')) ?: 'attachment';
                $attachments[] = ['name' => $name, 'mime_type' => $clean($string($m[1], '370E')) ?: 'application/octet-stream', 'size_bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
            }
        }
        $sender = $clean($string('', '0C1F')) ?: $clean($string('', '5D01'));
        $senderName = $clean($string('', '0C1A'));
        $id = $clean($string('', '1035')) ?? $this->one($headers['message-id'] ?? '');
        if (($clean($string('', '0037')) ?? $sender) === null) {
            throw new InvalidArgumentException(__('That is not an Outlook message.'));
        }

        return ['message_id' => trim((string) $id, " <>\t\n"), 'from' => trim(($senderName && $sender && $senderName !== $sender ? $senderName.' <'.$sender.'>' : ($sender ?: (string) $senderName))),
            'to' => (string) $clean($string('', '0E04')), 'cc' => (string) $clean($string('', '0E03')), 'subject' => (string) $clean($string('', '0037')),
            'sent_at' => $times[0x0039] ?? $this->date($this->one($headers['date'] ?? '')), 'received_at' => $times[0x0E06] ?? null, 'body_text' => $clean($string('', '1000')),
            'body_html' => $html !== null ? (mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1252')) : null, 'attachments' => $attachments];
    }

    /** Every stream of a compound file, by its path (storage/stream). */
    private function compoundFile(string $raw): array
    {
        if (strlen($raw) < 512 || substr($raw, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new InvalidArgumentException(__('That is not an Outlook message.'));
        }
        $u16 = fn (int $at) => unpack('v', $raw, $at)[1];
        $u32 = fn (string $s, int $at) => unpack('V', $s, $at)[1];
        $size = 1 << $u16(0x1E);
        $miniSize = 1 << $u16(0x20);
        $cutoff = $u32($raw, 0x38);
        $sector = fn (int $n) => substr($raw, ($n + 1) * $size, $size);
        // The FAT: its sectors listed in the header and the DIFAT chain.
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $s = $u32($raw, 0x4C + 4 * $i);
            if ($s !== self::FREE) {
                $fatSectors[] = $s;
            }
        }
        for ($d = $u32($raw, 0x44), $guard = 0; $d !== self::END_OF_CHAIN && $d !== self::FREE && $guard < 10000; $guard++) {
            $block = $sector($d);
            for ($i = 0; $i < $size / 4 - 1; $i++) {
                $s = $u32($block, 4 * $i);
                if ($s !== self::FREE) {
                    $fatSectors[] = $s;
                }
            }
            $d = $u32($block, $size - 4);
        }
        $fat = [];
        foreach ($fatSectors as $s) {
            $fat = [...$fat, ...array_values(unpack('V*', $sector($s)))];
        }
        $chain = function (int $start, array $table, callable $read) {
            $data = '';
            for ($n = $start, $guard = 0; $n !== self::END_OF_CHAIN && isset($table[$n]) && $guard < 1000000; $n = $table[$n], $guard++) {
                $data .= $read($n);
            }

            return $data;
        };
        $dir = $chain($u32($raw, 0x30), $fat, $sector);
        $miniFat = array_values(unpack('V*', $chain($u32($raw, 0x3C), $fat, $sector) ?: "\xFE\xFF\xFF\xFF"));
        $entries = [];
        for ($at = 0; $at + 128 <= strlen($dir); $at += 128) {
            $nameLength = unpack('v', $dir, $at + 64)[1];
            $entries[] = ['name' => $nameLength > 2 ? mb_convert_encoding(substr($dir, $at, $nameLength - 2), 'UTF-8', 'UTF-16LE') : '', 'type' => ord($dir[$at + 66]),
                'left' => $u32($dir, $at + 68), 'right' => $u32($dir, $at + 72), 'child' => $u32($dir, $at + 76), 'start' => $u32($dir, $at + 116), 'size' => $u32($dir, $at + 120)];
        }
        $ministream = $chain($entries[0]['start'] ?? self::END_OF_CHAIN, $fat, $sector);
        $streams = [];
        $walk = function (int $i, string $path) use (&$walk, &$streams, $entries, $chain, $fat, $sector, $miniFat, $ministream, $miniSize, $cutoff) {
            if ($i === self::FREE || ! isset($entries[$i])) {
                return;
            }
            $e = $entries[$i];
            $walk($e['left'], $path);
            $walk($e['right'], $path);
            if ($e['type'] === 2) {
                $data = $e['size'] < $cutoff ? $chain($e['start'], $miniFat, fn ($n) => substr($ministream, $n * $miniSize, $miniSize)) : $chain($e['start'], $fat, $sector);
                $streams[$path.$e['name']] = substr($data, 0, $e['size']);
            } elseif ($e['type'] === 1) {
                $walk($e['child'], $path.$e['name'].'/');
            }
        };
        $walk($entries[0]['child'] ?? self::FREE, '');

        return $streams;
    }

    /** The FILETIME properties of a property stream: [property id => Y-m-d H:i:s]. */
    private function times(string $stream, int $header): array
    {
        $out = [];
        for ($at = $header; $at + 16 <= strlen($stream); $at += 16) {
            $tag = unpack('V', $stream, $at)[1];
            if (($tag & 0xFFFF) === 0x0040) {
                [, $low, $high] = unpack('V2', $stream, $at + 8);
                $seconds = (int) ((($high * 4294967296) + $low) / 10000000) - 11644473600;
                $out[$tag >> 16] = $seconds > 0 ? gmdate('Y-m-d H:i:s', $seconds) : null;
            }
        }

        return $out;
    }

    private function part(array $headers, string $body, array &$out, int $depth = 0): void
    {
        $type = strtolower($this->one($headers['content-type'] ?? 'text/plain'));
        $disposition = strtolower($this->one($headers['content-disposition'] ?? ''));
        if ($depth < 10 && str_starts_with($type, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $this->one($headers['content-type'] ?? ''), $m)) {
            $segments = explode('--'.$m[1], $body);
            array_shift($segments);
            foreach ($segments as $segment) {
                if (str_starts_with($segment, '--')) {
                    break;
                }
                [$h, $b] = array_pad(explode("\n\n", ltrim($segment, "\n"), 2), 2, '');
                $this->part($this->headers($h), $b, $out, $depth + 1);
            }

            return;
        }
        $name = preg_match('/filename\*?="?([^";]+)"?/i', $disposition.' '.$this->one($headers['content-disposition'] ?? ''), $n) ? $n[1]
            : (preg_match('/name="?([^";]+)"?/i', $this->one($headers['content-type'] ?? ''), $n) ? $n[1] : null);
        if (str_contains($disposition, 'attachment') || ($name && ! str_starts_with($type, 'text/'))) {
            $data = $this->decode($body, $headers, false);
            $out['attachments'][] = ['name' => $this->decodeHeader((string) ($name ?: 'attachment')), 'mime_type' => strtok($type, ';') ?: 'application/octet-stream', 'size_bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
        } elseif (str_starts_with($type, 'text/html')) {
            $out['body_html'] ??= $this->decode($body, $headers);
        } elseif (str_starts_with($type, 'text/')) {
            $out['body_text'] ??= $this->decode($body, $headers);
        }
    }

    private function headers(string $block): array
    {
        $headers = [];
        $current = null;
        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if ($current !== null && preg_match('/^\s/', $line)) {
                is_array($headers[$current]) ? $headers[$current][array_key_last($headers[$current])] .= ' '.trim($line) : $headers[$current] .= ' '.trim($line);

                continue;
            }
            if (! preg_match('/^([!-9;-~]+):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $current = strtolower($m[1]);
            $headers[$current] = isset($headers[$current]) ? [...(array) $headers[$current], $m[2]] : $m[2];
        }

        return $headers;
    }

    private function decode(string $body, array $headers, bool $text = true): string
    {
        $decoded = match (strtolower(trim($this->one($headers['content-transfer-encoding'] ?? '')))) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body)) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        if ($text && ! mb_check_encoding($decoded, 'UTF-8')) {
            $decoded = mb_convert_encoding($decoded, 'UTF-8', preg_match('/charset="?([\w-]+)/i', $this->one($headers['content-type'] ?? ''), $c) ? $c[1] : 'Windows-1252');
        }

        return $text ? rtrim($decoded, "\n") : $decoded;
    }

    private function one(string|array $value): string
    {
        return is_array($value) ? (string) ($value[0] ?? '') : $value;
    }

    private function decodeHeader(string $value): string
    {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim(is_string($decoded) && $decoded !== '' ? $decoded : $value);
    }

    private function address(string|array $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->decodeHeader($this->one($value))));
    }

    private function date(string $value): ?string
    {
        $ts = $value !== '' ? strtotime($value) : false;

        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
}
