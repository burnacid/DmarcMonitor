<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal reader for Outlook .msg files (OLE compound documents), exposing
 * just what DMARC ingestion needs: the subject and the file attachments.
 */
class OutlookMsgReader
{
    private const string SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const int MAX_REGULAR_SECTOR = 0xFFFFFFFA;

    private const int NO_STREAM = 0xFFFFFFFF;

    private const int ENTRY_SIZE = 128;

    private int $sectorSize;

    private int $miniSectorSize;

    private int $miniStreamCutoff;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private string $miniStream = '';

    /** @var list<array{name: string, type: int, left: int, right: int, child: int, start: int, size: int}> */
    private array $entries = [];

    /** @var array<string, int> */
    private array $streams = [];

    /** @var list<string> */
    private array $attachmentStorages = [];

    private function __construct(private readonly string $data)
    {
        if (strlen($data) < 512 || ! str_starts_with($data, self::SIGNATURE)) {
            throw new RuntimeException('Not an Outlook .msg (OLE compound) file.');
        }

        $this->sectorSize = 1 << $this->u16(0x1E);
        $this->miniSectorSize = 1 << $this->u16(0x20);
        $this->miniStreamCutoff = $this->u32(0x38);

        $this->loadFat();
        $this->loadDirectory();
        $this->loadMiniFat();
        $this->walk($this->entries[0]['child'], '');
    }

    public static function fromFile(string $path): self
    {
        $data = file_get_contents($path);

        if ($data === false) {
            throw new RuntimeException("Unable to read [{$path}].");
        }

        return new self($data);
    }

    public function subject(): ?string
    {
        return $this->text('', '0037');
    }

    /**
     * @return list<array{name: string, mimeType: string, content: string}>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->attachmentStorages as $storage) {
            $content = $this->binary($storage, '3701');

            if ($content === null) {
                continue;
            }

            $attachments[] = [
                'name' => $this->text($storage, '3707') ?? $this->text($storage, '3704') ?? '',
                'mimeType' => $this->text($storage, '370E') ?? '',
                'content' => $content,
            ];
        }

        return $attachments;
    }

    private function u16(int $offset, ?string $data = null): int
    {
        return unpack('v', $data ?? $this->data, $offset)[1];
    }

    private function u32(int $offset, ?string $data = null): int
    {
        return unpack('V', $data ?? $this->data, $offset)[1];
    }

    private function sector(int $id): string
    {
        return substr($this->data, ($id + 1) * $this->sectorSize, $this->sectorSize);
    }

    private function loadFat(): void
    {
        $fatSectors = [];

        for ($i = 0; $i < 109; $i++) {
            $fatSectors[] = $this->u32(0x4C + $i * 4);
        }

        $difat = $this->u32(0x44);
        $perDifatSector = intdiv($this->sectorSize, 4) - 1;

        for ($guard = 0; $difat <= self::MAX_REGULAR_SECTOR && $guard < 65536; $guard++) {
            $sector = $this->sector($difat);

            for ($i = 0; $i < $perDifatSector; $i++) {
                $fatSectors[] = $this->u32($i * 4, $sector);
            }

            $difat = $this->u32($perDifatSector * 4, $sector);
        }

        foreach ($fatSectors as $id) {
            if ($id > self::MAX_REGULAR_SECTOR) {
                continue;
            }

            array_push($this->fat, ...array_values(unpack('V*', $this->sector($id))));
        }
    }

    private function readChain(int $start): string
    {
        $out = '';

        for ($guard = 0; $start <= self::MAX_REGULAR_SECTOR && $guard <= count($this->fat); $guard++) {
            $out .= $this->sector($start);
            $start = $this->fat[$start] ?? self::NO_STREAM;
        }

        return $out;
    }

    private function loadDirectory(): void
    {
        $directory = $this->readChain($this->u32(0x30));

        for ($offset = 0; $offset + self::ENTRY_SIZE <= strlen($directory); $offset += self::ENTRY_SIZE) {
            $nameLength = max(0, min(64, $this->u16($offset + 64, $directory) - 2));

            $this->entries[] = [
                'name' => mb_convert_encoding(substr($directory, $offset, $nameLength), 'UTF-8', 'UTF-16LE'),
                'type' => ord($directory[$offset + 66]),
                'left' => $this->u32($offset + 68, $directory),
                'right' => $this->u32($offset + 72, $directory),
                'child' => $this->u32($offset + 76, $directory),
                'start' => $this->u32($offset + 116, $directory),
                'size' => $this->u32($offset + 120, $directory),
            ];
        }

        if ($this->entries === [] || $this->entries[0]['type'] !== 5) {
            throw new RuntimeException('Outlook .msg file has no root directory entry.');
        }
    }

    private function loadMiniFat(): void
    {
        $firstMiniFatSector = $this->u32(0x3C);

        if ($firstMiniFatSector <= self::MAX_REGULAR_SECTOR) {
            $this->miniFat = array_values(unpack('V*', $this->readChain($firstMiniFatSector)));
        }

        $root = $this->entries[0];
        $this->miniStream = substr($this->readChain($root['start']), 0, $root['size']);
    }

    private function walk(int $firstId, string $prefix): void
    {
        $pending = [$firstId];
        $seen = [];

        while ($pending !== []) {
            $id = array_pop($pending);

            if ($id === self::NO_STREAM || ! isset($this->entries[$id]) || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $entry = $this->entries[$id];
            $path = $prefix.$entry['name'];

            if ($entry['type'] === 2) {
                $this->streams[$path] = $id;
            } elseif ($entry['type'] === 1) {
                if (str_starts_with($entry['name'], '__attach_version1.0_#')) {
                    $this->attachmentStorages[] = $path.'/';
                }

                $this->walk($entry['child'], $path.'/');
            }

            $pending[] = $entry['left'];
            $pending[] = $entry['right'];
        }
    }

    private function readStream(int $id): string
    {
        $entry = $this->entries[$id];

        if ($entry['size'] >= $this->miniStreamCutoff) {
            return substr($this->readChain($entry['start']), 0, $entry['size']);
        }

        $out = '';
        $sector = $entry['start'];

        for ($guard = 0; $sector <= self::MAX_REGULAR_SECTOR && $guard <= count($this->miniFat); $guard++) {
            $out .= substr($this->miniStream, $sector * $this->miniSectorSize, $this->miniSectorSize);
            $sector = $this->miniFat[$sector] ?? self::NO_STREAM;
        }

        return substr($out, 0, $entry['size']);
    }

    private function binary(string $storage, string $propertyId): ?string
    {
        $id = $this->streams["{$storage}__substg1.0_{$propertyId}0102"] ?? null;

        return $id === null ? null : $this->readStream($id);
    }

    private function text(string $storage, string $propertyId): ?string
    {
        $unicode = $this->streams["{$storage}__substg1.0_{$propertyId}001F"] ?? null;

        if ($unicode !== null) {
            return rtrim(mb_convert_encoding($this->readStream($unicode), 'UTF-8', 'UTF-16LE'), "\0");
        }

        $ansi = $this->streams["{$storage}__substg1.0_{$propertyId}001E"] ?? null;

        return $ansi === null
            ? null
            : rtrim(mb_convert_encoding($this->readStream($ansi), 'UTF-8', 'Windows-1252'), "\0");
    }
}
