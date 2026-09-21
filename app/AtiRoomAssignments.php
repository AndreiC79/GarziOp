<?php

declare(strict_types=1);

final class AtiRoomAssignments
{
    public static function path(): string
    {
        return PROJECT_ROOT . '/storage/config/ati_room_assignments.json';
    }

    public static function all(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Planificarea ATI nu a putut fi citita.');
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !is_array($decoded['assignments'] ?? null)) {
            throw new RuntimeException('Fisierul planificarii ATI nu este valid.');
        }
        return $decoded['assignments'];
    }

    public static function between(string $start, string $end): array
    {
        $all = self::all();
        $result = [];
        $current = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);

        while ($current <= $last) {
            $date = $current->format('Y-m-d');
            $result[$date] = is_array($all[$date] ?? null) ? $all[$date] : [];
            $current = $current->modify('+1 day');
        }

        return $result;
    }

    public static function save(array $assignments, int $userId): void
    {
        ksort($assignments);
        $path = self::path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Directorul pentru planificarea ATI nu a putut fi creat.');
        }

        $payload = [
            'format_version' => 1,
            'updated_at' => date(DATE_ATOM),
            'updated_by' => $userId,
            'assignments' => $assignments,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Planificarea ATI nu a putut fi salvata.');
        }
    }
}
