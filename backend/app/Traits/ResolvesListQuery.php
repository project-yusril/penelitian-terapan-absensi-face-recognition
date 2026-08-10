<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Helper terpusat untuk M-18: clamp pagination dan allowlist sorting agar
 * request tidak dapat memicu memory/DB DoS atau error dari nama kolom arbitrer.
 */
trait ResolvesListQuery
{
    /**
     * Resolve nilai per_page yang aman.
     */
    protected function resolvePerPage(Request $request, int $default = 15, int $max = 100): int
    {
        $raw = $request->get('per_page', $default);

        // Query string dapat mengirim array atau nilai non-numerik.
        if (! is_scalar($raw) || ! is_numeric($raw)) {
            return $default;
        }

        $perPage = (int) $raw;

        if ($perPage < 1) {
            return $default;
        }

        return min($perPage, $max);
    }

    /**
     * Resolve kolom dan arah sorting dari allowlist.
     *
     * @param  array<int, string>  $allowed  Kolom yang boleh digunakan untuk sorting.
     * @return array{0: string, 1: string}  [kolom, arah]
     */
    protected function resolveSort(Request $request, array $allowed, string $defaultColumn, string $defaultDir = 'desc'): array
    {
        $rawColumn = $request->get('sort_by', $defaultColumn);
        $column = is_scalar($rawColumn) ? (string) $rawColumn : $defaultColumn;
        if (! in_array($column, $allowed, true)) {
            $column = $defaultColumn;
        }

        $rawDir = $request->get('sort_dir', $defaultDir);
        $dir = strtolower(is_scalar($rawDir) ? (string) $rawDir : $defaultDir);
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = $defaultDir;
        }

        return [$column, $dir];
    }
}
