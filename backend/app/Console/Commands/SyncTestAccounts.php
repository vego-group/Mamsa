<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PartnerDetail;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Provision (idempotently) the three scoped test accounts used with the fixed-OTP
 * / simulated-payment test mode: a super-admin, an approved partner, and a plain
 * user. Numbers come from config/test_mode.php (TEST_*_PHONE env vars).
 *
 * Roles are only ever ADDED — an existing real account keeps its name and other
 * roles — so running this against production can never demote or rename a user.
 *
 *   php artisan test-accounts:sync
 */
class SyncTestAccounts extends Command
{
    protected $signature = 'test-accounts:sync';

    protected $description = 'Create/update the three scoped test accounts (super-admin, partner, user)';

    public function handle(): int
    {
        foreach (['SuperAdmin', 'Individual', 'User'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $accounts = (array) config('test_mode.accounts', []);

        $rows = [
            $this->superAdmin($accounts['superadmin'] ?? null),
            $this->partner($accounts['partner'] ?? null),
            $this->user($accounts['user'] ?? null),
        ];

        $code = (string) config('test_mode.code', '');
        $otpOn = (bool) config('test_mode.otp');
        $payOn = (bool) config('test_mode.payments');

        $this->newLine();
        $this->table(['Role', 'Phone (E.164)', 'Dashboard form', 'Login OTP'], array_filter($rows));
        $this->newLine();
        $this->line('  OTP test mode      : '.($otpOn ? '<info>ON</info>' : '<comment>OFF</comment>'));
        $this->line('  Payments test mode : '.($payOn ? '<info>ON</info>' : '<comment>OFF</comment>'));
        $this->line('  Fixed OTP code     : '.($code !== '' ? '<info>set ('.strlen($code).' digits)</info>' : '<comment>UNSET — OTP bypass inert</comment>'));

        if ($otpOn && $code === '') {
            $this->warn('  TEST_OTP_MODE is on but TEST_OTP_CODE is empty — set a private code for the accounts to log in.');
        }

        return self::SUCCESS;
    }

    /** @return array{0:string,1:string,2:string,3:string}|null */
    private function superAdmin(?string $rawPhone): ?array
    {
        if (blank($rawPhone)) {
            return null;
        }

        $phone = PhoneNumber::toE164Ksa($rawPhone);
        $user = User::firstOrCreate(['phone' => $phone], ['name' => 'مشرف تجريبي', 'is_active' => true]);
        $user->forceFill(['is_active' => true])->save();

        if (! $user->hasRole('SuperAdmin')) {
            $user->assignRole('SuperAdmin');
        }

        return ['SuperAdmin', $phone, $this->dashboardForm($phone), $this->otpHint()];
    }

    /** @return array{0:string,1:string,2:string,3:string}|null */
    private function partner(?string $rawPhone): ?array
    {
        if (blank($rawPhone)) {
            return null;
        }

        $phone = PhoneNumber::toE164Ksa($rawPhone);
        $user = User::firstOrCreate(['phone' => $phone], ['name' => 'شريك تجريبي', 'is_active' => true]);
        $user->forceFill(['is_active' => true])->save();

        if (! $user->isPartner()) {
            $user->assignRole('Individual');
        }

        // Approved profile is required for the dashboard to let the partner in.
        $user->partnerDetail()->updateOrCreate(
            ['user_id' => $user->id],
            ['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED],
        );

        return ['Partner', $phone, $this->dashboardForm($phone), $this->otpHint()];
    }

    /** @return array{0:string,1:string,2:string,3:string}|null */
    private function user(?string $rawPhone): ?array
    {
        if (blank($rawPhone)) {
            return null;
        }

        $phone = PhoneNumber::toE164Ksa($rawPhone);
        $user = User::firstOrCreate(['phone' => $phone], ['name' => 'مستخدم تجريبي', 'is_active' => true]);
        $user->forceFill(['is_active' => true])->save();

        if (! $user->roles()->exists()) {
            $user->assignRole('User');
        }

        return ['User', $phone, '—', $this->otpHint()];
    }

    /** The 5XXXXXXXX form the partner/admin login screens expect. */
    private function dashboardForm(string $e164): string
    {
        return str_starts_with($e164, '+966') ? substr($e164, 4) : $e164;
    }

    private function otpHint(): string
    {
        $code = (string) config('test_mode.code', '');

        return $code !== '' ? $code : '(set TEST_OTP_CODE)';
    }
}
