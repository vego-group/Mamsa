<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Shared fixture + cursor pagination for the wallet-ledger STUB endpoints
 * (partner /wallet/ledger and admin /admin/wallets/{partnerId}/ledger).
 * Entries are newest-first by createdAt; amounts sum to 4310.75 = availableBalance.
 */
final class StubLedger
{
    /** @return list<array<string,mixed>> PartnerLedgerEntry[] — newest first. */
    public static function entries(string $partnerId): array
    {
        return [
            self::row('ple_04', $partnerId, 'earning', 1810.75, 4310.75, 'booking', 'bkg_9003', 'BKG-9003', 'حصة الشريك — حجز BKG-9003', '2026-08-09T16:05:00+03:00'),
            self::row('ple_03', $partnerId, 'payout', -1000.00, 2500.00, 'payout', 'pay_7001', 'PO-2026-07-0018', 'تحويل يوليو', '2026-08-06T09:00:00+03:00'),
            self::row('ple_02', $partnerId, 'earning', 1500.00, 3500.00, 'booking', 'bkg_9002', 'BKG-9002', 'حصة الشريك — حجز BKG-9002', '2026-08-05T11:30:00+03:00'),
            self::row('ple_01', $partnerId, 'earning', 2000.00, 2000.00, 'booking', 'bkg_9001', 'BKG-9001', 'حصة الشريك — حجز BKG-9001', '2026-08-02T09:14:22+03:00'),
        ];
    }

    /**
     * Cursor page — { items, hasMore, nextCursor }. `?limit=` default 20, max 100;
     * `?before=` is an ISO-8601 createdAt cursor (returns entries older than it).
     *
     * @param  list<array<string,mixed>>  $all  newest-first
     * @return array{items: list<array<string,mixed>>, hasMore: bool, nextCursor: ?string}
     */
    public static function page(Request $request, array $all): array
    {
        $limit  = min(100, max(1, (int) $request->query('limit', 20)));
        $before = $request->query('before');

        if ($before !== null && $before !== '') {
            $all = array_values(array_filter($all, static fn (array $e) => $e['createdAt'] < $before));
        }

        $items   = array_slice($all, 0, $limit);
        $hasMore = count($all) > $limit;

        return [
            'items'      => $items,
            'hasMore'    => $hasMore,
            'nextCursor' => $hasMore && $items !== [] ? end($items)['createdAt'] : null,
        ];
    }

    /** @return array<string,mixed> PartnerLedgerEntry — §2.3 (v2.2 name). */
    private static function row(
        string $id, string $partnerId, string $type, float $amount, float $balanceAfter,
        string $refType, string $refId, string $refCode, string $description, string $createdAt,
    ): array {
        return [
            'id'               => $id,
            'partnerId'        => $partnerId,
            'type'             => $type,
            'amount'           => $amount,
            'balanceAfter'     => $balanceAfter,
            'refType'          => $refType,
            'refId'            => $refId,
            'refCode'          => $refCode,
            'description'      => $description,
            'createdAt'        => $createdAt,
            'createdByAdminId' => null,
        ];
    }
}
