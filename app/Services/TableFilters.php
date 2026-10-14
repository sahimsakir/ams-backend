<?php

namespace App\Services;

use Illuminate\Http\Request;

class TableFilters
{
    public static function options(Request $r, array $columns): array
    {
        $r->validate(['filters' => 'nullable|string|max:12000', 'sort' => 'nullable|string', 'direction' => 'nullable|in:asc,desc']);
        $filters = json_decode($r->query('filters', '{}'), true);
        abort_unless(is_array($filters), 422, 'Invalid table filters.');
        foreach ($filters as $key => $f) {
            abort_unless(in_array($key, $columns, true) && is_array($f), 422, 'Unknown filter column.');
            validator($f, ['text' => 'nullable|string|max:200', 'values' => 'nullable|array|max:500', 'values.*' => 'nullable|string|max:500'])->validate();
        }
        $sort = $r->query('sort');
        abort_if($sort && ! in_array($sort, $columns, true), 422, 'Unknown sort column.');

        return [$filters, $sort, $r->query('direction', 'asc')];
    }

    public static function array(Request $r, array $rows, array $columns): array
    {
        [$filters,$sort,$direction] = self::options($r, $columns);
        $facets = [];
        foreach ($columns as $c) {
            $values = array_values(array_unique(array_map(fn ($row) => (string) ($row[$c] ?? ''), $rows)));
            sort($values, SORT_NATURAL | SORT_FLAG_CASE);
            $facets[$c] = array_slice($values, 0, 500);
        }
        $rows = array_values(array_filter($rows, function ($row) use ($filters) {
            foreach ($filters as $c => $f) {
                $v = (string) ($row[$c] ?? '');
                if (! empty($f['text']) && mb_stripos($v, $f['text']) === false) {
                    return false;
                }if (isset($f['values']) && ! in_array($v, $f['values'], true)) {
                    return false;
                }
            }

            return true;
        }));
        if ($sort) {
            usort($rows, function ($a, $b) use ($sort, $direction) {
                $x = $a[$sort] ?? null;
                $y = $b[$sort] ?? null;
                $n = is_numeric($x) && is_numeric($y) ? ((float) $x <=> (float) $y) : strnatcasecmp((string) $x, (string) $y);

                return $direction === 'asc' ? $n : -$n;
            });
        }

        return [$rows, $facets];
    }

    public static function query(Request $r, $query, array $columns)
    {
        [$filters,$sort,$direction] = self::options($r, array_keys($columns));
        $facets = [];
        foreach ($columns as $key => $column) {
            $facets[$key] = (clone $query)->reorder()->select($column)->distinct()->orderBy($column)->limit(500)->pluck($column)->map(fn ($v) => (string) ($v ?? ''))->all();
        }
        foreach ($filters as $key => $f) {
            $column = $columns[$key];
            if (! empty($f['text'])) {
                $text = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($f['text']));
                $query->whereRaw('LOWER(CAST('.$column." AS TEXT)) LIKE ? ESCAPE '\\'", ['%'.$text.'%']);
            }if (isset($f['values'])) {
                $query->whereIn($column, $f['values']);
            }
        }
        $query->reorder()->orderBy($sort ? $columns[$sort] : 'audit_logs.id', $sort ? $direction : 'desc');

        return [$query, $facets];
    }
}
