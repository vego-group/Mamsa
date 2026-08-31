<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-aware SQL fragments — MySQL in production, sqlite under test.
 *
 * These exist because a raw MySQL-only function does not fail loudly in a test
 * suite: the endpoint throws, the request 500s, and a surface that nobody wrote
 * a test for is simply never exercised. Six controllers reached production that
 * way. Equivalent helpers already lived on MapsSpec, but as `protected` methods
 * on an AdminPanel trait they were unreachable from the Api\V1 and Dashboard
 * controllers that needed exactly the same thing — so those hand-rolled the raw
 * form instead. One definition, callable from anywhere, is the fix.
 *
 * MapsSpec now delegates here, so there is a single implementation of each.
 */
final class Sql
{
    private static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * The key that makes a multi-unit building ONE row.
     *
     * `COALESCE(unit_group_id, id)` is the obvious form and it is wrong twice:
     * it mixes a 26-char ULID with a bigint, and — worse — any expression that
     * collapses to a constant for ungrouped rows would fold every standalone
     * unit in the catalogue into a single listing. So an ungrouped unit gets a
     * key of its OWN id, prefixed so it can never collide with a real ULID.
     *
     * Concatenation is the part that differs: MySQL has CONCAT, sqlite has `||`.
     */
    public static function groupKey(string $groupCol, string $idCol): string
    {
        return self::isSqlite()
            ? "COALESCE({$groupCol}, 'u' || {$idCol})"
            : "COALESCE({$groupCol}, CONCAT('u', {$idCol}))";
    }

    /** 'YYYY-MM' bucket for a date/datetime column. */
    public static function ym(string $col): string
    {
        return self::isSqlite()
            ? "strftime('%Y-%m', {$col})"
            : "DATE_FORMAT({$col}, '%Y-%m')";
    }

    /** SUM of nights between two date columns, 0 when there are none. */
    public static function sumNights(string $end = 'end_date', string $start = 'start_date'): string
    {
        return self::isSqlite()
            ? "COALESCE(SUM(julianday({$end}) - julianday({$start})), 0)"
            : "COALESCE(SUM(DATEDIFF({$end}, {$start})), 0)";
    }

    /** AVG nights between two date columns. */
    public static function avgDays(string $end = 'end_date', string $start = 'start_date'): string
    {
        return self::isSqlite()
            ? "AVG(julianday({$end}) - julianday({$start}))"
            : "AVG(DATEDIFF({$end}, {$start}))";
    }

    /**
     * Day of week, 1 = Sunday … 7 = Saturday (MySQL's DAYOFWEEK numbering).
     *
     * sqlite's strftime('%w') is 0-based, so it is shifted to match rather than
     * leaving the caller to branch — the callers index a Sun..Sat array by this
     * value, and an off-by-one here silently relabels every day.
     */
    public static function dayOfWeek(string $col): string
    {
        return self::isSqlite()
            ? "(CAST(strftime('%w', {$col}) AS INTEGER) + 1)"
            : "DAYOFWEEK({$col})";
    }

    /**
     * AVG hours between two datetime columns.
     *
     * MINUTE/60, never TIMESTAMPDIFF(HOUR, …): the HOUR unit TRUNCATES, so it
     * reports 14 where the real gap is 14.2. That is a wrong number, not merely
     * a portable one — an SLA readout built on it under-states every duration.
     */
    public static function avgHours(string $start, string $end): string
    {
        return self::isSqlite()
            ? "AVG((julianday({$end}) - julianday({$start})) * 24)"
            : "AVG(TIMESTAMPDIFF(MINUTE, {$start}, {$end}) / 60)";
    }
}
