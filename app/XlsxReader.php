<?php

declare(strict_types=1);

namespace App;

use DomainException;
use ZipArchive;

final class XlsxReader
{
    private const MAX_ENTRY_BYTES = 33554432;

    /** @return array<int,array<int,string>> */
    public static function readFirstSheet(string $path, bool $forceFallback = false): array
    {
        $entries = self::readEntries(
            $path,
            ['xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml'],
            $forceFallback
        );
        $sheetXml = $entries['xl/worksheets/sheet1.xml'] ?? false;
        if ($sheetXml === false) {
            throw new DomainException('Prima foaie din fisier nu poate fi citita.');
        }
        if (!function_exists('simplexml_load_string')) {
            throw new DomainException('Serverul nu are extensia PHP SimpleXML activata.');
        }

        $shared = [];
        $sharedXml = $entries['xl/sharedStrings.xml'] ?? false;
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml !== false) {
                foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
                    $shared[] = implode('', array_map(
                        'strval',
                        $item->xpath('.//*[local-name()="t"]') ?: []
                    ));
                }
            }
        }

        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new DomainException('Fisierul XLSX este deteriorat.');
        }

        $rows = [];
        foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
            $values = [];
            foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/^[A-Z]+/', $reference, $match);
                $index = self::columnIndex($match[0] ?? 'A');
                $type = (string) $cell['t'];
                $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
                $value = (string) ($valueNodes[0] ?? '');
                if ($type === 's') {
                    $value = $shared[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = implode('', array_map(
                        'strval',
                        $cell->xpath('.//*[local-name()="t"]') ?: []
                    ));
                }
                $values[$index] = $value;
            }
            if ($values) {
                $rows[] = array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values);
            }
        }

        return $rows;
    }

    /** @param array<int,string> $names @return array<string,string|false> */
    private static function readEntries(string $path, array $names, bool $forceFallback): array
    {
        if (!$forceFallback && class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new DomainException('Fisierul XLSX nu poate fi deschis.');
            }
            $result = [];
            foreach ($names as $name) {
                $result[$name] = $zip->getFromName($name);
            }
            $zip->close();
            return $result;
        }

        return self::readEntriesWithoutZipExtension($path, $names);
    }

    /** @param array<int,string> $names @return array<string,string|false> */
    private static function readEntriesWithoutZipExtension(string $path, array $names): array
    {
        if (!function_exists('gzinflate')) {
            throw new DomainException('Serverul nu poate decomprima fisiere XLSX.');
        }
        $archive = file_get_contents($path);
        if ($archive === false) {
            throw new DomainException('Fisierul XLSX nu poate fi citit.');
        }
        $endOffset = strrpos($archive, "PK\x05\x06");
        if ($endOffset === false || strlen($archive) < $endOffset + 22) {
            throw new DomainException('Fisierul XLSX nu este o arhiva ZIP valida.');
        }

        $entryCount = self::uint16($archive, $endOffset + 10);
        $centralOffset = self::uint32($archive, $endOffset + 16);
        $wanted = array_fill_keys($names, true);
        $result = array_fill_keys($names, false);
        $offset = $centralOffset;

        for ($index = 0; $index < $entryCount; $index++) {
            if (substr($archive, $offset, 4) !== "PK\x01\x02") {
                throw new DomainException('Structura interna a fisierului XLSX este invalida.');
            }
            $method = self::uint16($archive, $offset + 10);
            $compressedSize = self::uint32($archive, $offset + 20);
            $uncompressedSize = self::uint32($archive, $offset + 24);
            $nameLength = self::uint16($archive, $offset + 28);
            $extraLength = self::uint16($archive, $offset + 30);
            $commentLength = self::uint16($archive, $offset + 32);
            $localOffset = self::uint32($archive, $offset + 42);
            $name = substr($archive, $offset + 46, $nameLength);

            if (isset($wanted[$name])) {
                if ($uncompressedSize > self::MAX_ENTRY_BYTES) {
                    throw new DomainException('Fisierul XLSX contine o foaie prea mare.');
                }
                $result[$name] = self::extractEntry(
                    $archive,
                    $localOffset,
                    $method,
                    $compressedSize,
                    $uncompressedSize
                );
                unset($wanted[$name]);
                if (!$wanted) {
                    break;
                }
            }

            $offset += 46 + $nameLength + $extraLength + $commentLength;
        }

        return $result;
    }

    private static function extractEntry(
        string $archive,
        int $localOffset,
        int $method,
        int $compressedSize,
        int $uncompressedSize
    ): string {
        if (substr($archive, $localOffset, 4) !== "PK\x03\x04") {
            throw new DomainException('Structura interna a fisierului XLSX este invalida.');
        }
        $nameLength = self::uint16($archive, $localOffset + 26);
        $extraLength = self::uint16($archive, $localOffset + 28);
        $dataOffset = $localOffset + 30 + $nameLength + $extraLength;
        $compressed = substr($archive, $dataOffset, $compressedSize);
        if (strlen($compressed) !== $compressedSize) {
            throw new DomainException('Fisierul XLSX este incomplet.');
        }

        if ($method === 0) {
            $content = $compressed;
        } elseif ($method === 8) {
            $content = gzinflate($compressed);
            if ($content === false) {
                throw new DomainException('Fisierul XLSX nu poate fi decomprimat.');
            }
        } else {
            throw new DomainException('Fisierul XLSX foloseste o compresie neacceptata.');
        }

        if (strlen($content) !== $uncompressedSize) {
            throw new DomainException('Fisierul XLSX este incomplet.');
        }
        return $content;
    }

    private static function uint16(string $data, int $offset): int
    {
        return (int) (unpack('vvalue', substr($data, $offset, 2))['value'] ?? 0);
    }

    private static function uint32(string $data, int $offset): int
    {
        return (int) (unpack('Vvalue', substr($data, $offset, 4))['value'] ?? 0);
    }

    private static function columnIndex(string $letters): int
    {
        $result = 0;
        foreach (str_split($letters) as $letter) {
            $result = $result * 26 + (ord($letter) - 64);
        }
        return $result - 1;
    }
}
