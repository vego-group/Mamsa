<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payout;
use App\Models\User;
use App\Services\PartnerWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give staging a partner who is actually payable, plus one executed payout.
 *
 * Regenerating the ledger from bookings alone loses the only coverage there was
 * for two things that have no other fixture: the 2,000 SAR minimum, and a
 * completed monthly cycle. Without a partner sitting above the floor with a
 * paid payout behind them, neither screen can be exercised at all — which is
 * why this is part of the reseed rather than a nicety after it.
 *
 * Non-production only. Idempotent: re-running tops the partner back up rather
 * than stacking duplicate payouts.
 *
 *   php artisan ledger:seed-payout-scenario
 */
class SeedPayoutScenario extends Command
{
    protected $signature = 'ledger:seed-payout-scenario
        {--phone=+966599000777 : Partner phone to build the scenario on}';

    protected $description = 'STAGING ONLY — a partner above the payout floor with one executed payout';

    /** The floor a balance must clear before a payout may be requested. */
    private const PAYOUT_FLOOR = 2000.00;

    public function handle(PartnerWalletService $wallet): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run on production.');

            return self::FAILURE;
        }

        $partner = $this->partner((string) $this->option('phone'));

        // Earnings first, comfortably clear of the floor, so the payout below
        // leaves a positive remainder rather than a balance of exactly zero —
        // a zero tells you nothing about whether the arithmetic works.
        $wallet->post(
            partnerUserId: $partner->id,
            type: \App\Models\PartnerLedgerEntry::TYPE_EARNING,
            amount: 7500.00,
            refType: 'seed',
            refId: 'payout-scenario',
            refCode: 'SEED-EARN',
            description: 'أرباح تجريبية لسيناريو التحويل',
        );

        $payout = Payout::create([
            'partner_user_id' => $partner->id,
            'reference'       => 'PO-SEED-'.now()->format('Ym'),
            'period_month'    => now()->subMonth()->format('Y-m'),
            'amount'          => 5000.00,
            'bookings_count'  => 3,
            'currency'        => 'SAR',
            'iban_masked'     => 'SA •••• 4321',
            'bank_name'       => 'مصرف الراجحي',
            'status'          => Payout::STATUS_PAID,
            'paid_at'         => now()->subDays(3),
            'bank_reference'  => 'TRX-SEED-0001',
        ]);

        $wallet->recordPayout($payout);

        $balance = (float) DB::table('partner_ledger_entries')
            ->where('partner_user_id', $partner->id)->sum('amount');

        $this->info('Payout scenario ready');
        $this->table(
            ['partner', 'phone', 'earned', 'paid out', 'balance', 'above floor?'],
            [[
                $partner->name,
                $partner->phone,
                '7,500.00',
                number_format((float) $payout->amount, 2),
                number_format($balance, 2),
                $balance >= self::PAYOUT_FLOOR ? 'yes' : 'no',
            ]],
        );

        return self::SUCCESS;
    }

    private function partner(string $phone): User
    {
        foreach (['Individual', 'User'] as $role) {
            \Spatie\Permission\Models\Role::findOrCreate($role, 'web');
        }

        $partner = User::firstOrCreate(
            ['phone' => $phone],
            ['name' => 'شريك سيناريو التحويل', 'is_active' => true],
        );

        if (! $partner->isPartner()) {
            $partner->assignRole('Individual');
        }

        $partner->partnerDetail()->updateOrCreate(
            ['user_id' => $partner->id],
            ['type' => 'individual', 'status' => \App\Models\PartnerDetail::STATUS_APPROVED],
        );

        return $partner;
    }
}
