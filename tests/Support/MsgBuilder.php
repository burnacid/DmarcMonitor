<?php

namespace Tests\Support;

/**
 * Writes minimal Outlook .msg (OLE compound) files for tests. Streams under
 * 4096 bytes go through the mini stream, larger ones through regular sectors.
 */
class MsgBuilder
{
    private const int SECTOR = 512;

    private const int MINI_SECTOR = 64;

    private const int END = 0xFFFFFFFE;

    private const int FREE = 0xFFFFFFFF;

    /** @var list<string> */
    private array $sectors = [];

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private string $miniStream = '';

    /**
     * @param  list<array{name: string, mimeType: string, content: string}>  $attachments
     */
    public static function build(string $subject, array $attachments = []): string
    {
        return (new self)->assemble($subject, $attachments);
    }

    /**
     * @param  list<array{name: string, mimeType: string, content: string}>  $attachments
     */
    private function assemble(string $subject, array $attachments): string
    {
        $utf16 = fn (string $text): string => mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        // [name, type(1 storage|2 stream), parent index, data]
        $nodes = [
            ['Root Entry', 5, -1, null],
            ['__substg1.0_0037001F', 2, 0, $utf16($subject)],
        ];

        foreach ($attachments as $i => $attachment) {
            $storage = count($nodes);
            $nodes[] = [sprintf('__attach_version1.0_#%08X', $i), 1, 0, null];
            $nodes[] = ['__substg1.0_37010102', 2, $storage, $attachment['content']];
            $nodes[] = ['__substg1.0_3707001F', 2, $storage, $utf16($attachment['name'])];
            $nodes[] = ['__substg1.0_370E001F', 2, $storage, $utf16($attachment['mimeType'])];
        }

        $starts = [];

        foreach ($nodes as $index => [, $type, , $data]) {
            if ($type === 2) {
                $starts[$index] = strlen($data) >= 4096 ? $this->allocate($data) : $this->allocateMini($data);
            }
        }

        $rootStart = $this->allocate($this->miniStream);
        $miniFatStart = $this->miniFat === [] ? self::END : $this->allocate($this->padWords($this->miniFat));
        $miniFatSectors = $this->miniFat === [] ? 0 : (int) ceil(count($this->miniFat) / 128);

        $directory = '';
        $childrenOf = [];

        foreach ($nodes as $index => [, , $parent]) {
            if ($parent >= 0) {
                $childrenOf[$parent][] = $index;
            }
        }

        foreach ($nodes as $index => [$name, $type, , $data]) {
            $siblings = $childrenOf[$nodes[$index][2]] ?? [];
            $position = array_search($index, $siblings, true);
            $right = ($position !== false && isset($siblings[$position + 1])) ? $siblings[$position + 1] : self::FREE;
            $child = $childrenOf[$index][0] ?? self::FREE;
            $start = $type === 5 ? $rootStart : ($starts[$index] ?? self::END);
            $size = $type === 5 ? strlen($this->miniStream) : strlen((string) $data);

            $directory .= $this->entry($name, $type, $right, $child, $start, $size);
        }

        while (strlen($directory) % self::SECTOR !== 0) {
            $directory .= $this->entry('', 0, self::FREE, self::FREE, 0, 0);
        }

        $directoryStart = $this->allocate($directory);

        $fatSectorCount = 1;

        while ($fatSectorCount * 128 < count($this->sectors) + $fatSectorCount) {
            $fatSectorCount++;
        }

        $fatSectorIds = [];

        for ($i = 0; $i < $fatSectorCount; $i++) {
            $fatSectorIds[] = count($this->fat);
            $this->fat[] = 0xFFFFFFFD;
        }

        $fatData = $this->padWords($this->fat);

        $difat = array_pad($fatSectorIds, 109, self::FREE);

        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 16)
            .pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6).str_repeat("\0", 6)
            .pack('VVVVVVVVV', 0, $fatSectorCount, $directoryStart, 0, 4096, $miniFatStart, $miniFatSectors, self::END, 0)
            .pack('V*', ...$difat);

        return str_pad($header, self::SECTOR, "\0").implode('', $this->sectors).$fatData;
    }

    /**
     * @param  list<int>  $words
     */
    private function padWords(array $words): string
    {
        $words = array_pad($words, (int) (ceil(count($words) / 128) * 128), self::FREE);

        return pack('V*', ...$words);
    }

    private function allocate(string $data): int
    {
        if ($data === '') {
            return self::END;
        }

        $count = (int) ceil(strlen($data) / self::SECTOR);
        $first = count($this->fat);

        foreach (str_split(str_pad($data, $count * self::SECTOR, "\0"), self::SECTOR) as $i => $chunk) {
            $this->sectors[] = $chunk;
            $this->fat[] = $i === $count - 1 ? self::END : $first + $i + 1;
        }

        return $first;
    }

    private function allocateMini(string $data): int
    {
        if ($data === '') {
            return self::END;
        }

        $count = (int) ceil(strlen($data) / self::MINI_SECTOR);
        $first = count($this->miniFat);
        $this->miniStream .= str_pad($data, $count * self::MINI_SECTOR, "\0");

        for ($i = 0; $i < $count; $i++) {
            $this->miniFat[] = $i === $count - 1 ? self::END : $first + $i + 1;
        }

        return $first;
    }

    private function entry(string $name, int $type, int $right, int $child, int $start, int $size): string
    {
        $encoded = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');

        return str_pad($encoded, 64, "\0")
            .pack('v', $name === '' ? 0 : strlen($encoded) + 2)
            .pack('CC', $type, 1)
            .pack('VVV', self::FREE, $right, $child)
            .str_repeat("\0", 16 + 4 + 16)
            .pack('VVV', $start, $size, 0);
    }
}
