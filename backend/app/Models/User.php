<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /**
     * The "phone" of the account that owns platform listings.
     *
     * Not a phone. Every login surface validates the phone it is given — the
     * partner dashboard against ^5\d{8}$, the others against E.164 — so a value
     * with a colon in it cannot even reach the OTP step. That is the point: the
     * account is unreachable by construction, not by a flag someone could flip.
     * It also cannot collide with a real number, and it reads as what it is in
     * any list that shows it.
     */
    public const PLATFORM_PHONE = 'system:mamsa';

    /**
     * The account that owns Mamsa's own listings.
     *
     * `units.user_id` is NOT NULL, so a platform-owned unit has to point at
     * some row. Until 2026-09-19 that row was the ADMIN who created the listing
     * — an employee — which put a staff member's name on the storefront as host
     * and would have attributed platform revenue to them in any query that
     * joined on the owner. Now it points here.
     *
     * Created on first use so a fresh environment never fails a write for want
     * of a seed. No role, inactive, unreachable phone: it exists to be pointed
     * at, never to act.
     */
    public static function platform(): self
    {
        return static::query()->firstOrCreate(
            ['phone' => self::PLATFORM_PHONE],
            ['name' => 'ممسى', 'is_active' => false, 'email' => null],
        );
    }

    /** Is this the platform account rather than a person? */
    public function isPlatform(): bool
    {
        return $this->phone === self::PLATFORM_PHONE;
    }

    /**
     * Where an SMS to this user goes. Null for the platform account: it owns
     * listings, so every "to the unit's owner" notification (review result,
     * cancellation, iCal failure) would otherwise be pushed at its phone —
     * which normalises to "+" and reaches the gateway as a malformed send.
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->isPlatform() ? null : $this->phone;
    }

    /** Same for mail — the column is null anyway; this makes it a rule, not a fact. */
    public function routeNotificationForMail(): ?string
    {
        return $this->isPlatform() ? null : $this->email;
    }

    use HasApiTokens, HasFactory, HasRoles, Notifiable, Prunable;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'phone',
        'email',
        'password',
        'email_verified_at',
        'is_active',
        'invited_at',
        'preferred_locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
        ];
    }

    /* ===================== Name parts ===================== */

    /**
     * Reconcile `name` with `first_name`/`last_name` from a request payload.
     * Parts win when present (name = "first last"); otherwise a bare `name`
     * is naively split so the columns never drift. Nothing set → no-op.
     *
     * @param  array<string, mixed>  $data
     */
    public function fillNameParts(array $data): void
    {
        $hasFirst = array_key_exists('first_name', $data);
        $hasLast = array_key_exists('last_name', $data);

        if ($hasFirst || $hasLast) {
            $first = trim((string) ($hasFirst ? $data['first_name'] : $this->first_name));
            $last = trim((string) ($hasLast ? $data['last_name'] : $this->last_name));

            $this->first_name = $first !== '' ? $first : null;
            $this->last_name = $last !== '' ? $last : null;
            $this->name = trim($first.' '.$last) ?: $this->name;

            return;
        }

        if (array_key_exists('name', $data) && filled($data['name'])) {
            $name = trim((string) $data['name']);
            $parts = preg_split('/\s+/', $name, 2) ?: [$name];

            $this->name = $name;
            $this->first_name = $parts[0] ?? null;
            $this->last_name = $parts[1] ?? null;
        }
    }

    /* ===================== Relations ===================== */

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function partnerDetail(): HasOne
    {
        return $this->hasOne(PartnerDetail::class);
    }

    public function partnerWallet(): HasOne
    {
        return $this->hasOne(PartnerWallet::class, 'partner_user_id');
    }

    public function bankDetail(): HasOne
    {
        return $this->hasOne(BankDetail::class, 'partner_user_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Bookings placed on this partner's units (for admin partner aggregates). */
    public function unitBookings(): HasManyThrough
    {
        return $this->hasManyThrough(Booking::class, Unit::class, 'user_id', 'unit_id', 'id', 'id');
    }

    /** Reviews left on this partner's units (for the partner rating). */
    public function unitReviews(): HasManyThrough
    {
        return $this->hasManyThrough(Review::class, Unit::class, 'user_id', 'unit_id', 'id', 'id');
    }

    public function savedCards(): HasMany
    {
        return $this->hasMany(SavedCard::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /** Units this user has favourited (through the favorites pivot). */
    public function favoriteUnits(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'favorites')->withTimestamps();
    }

    /* ===================== Pruning ===================== */

    /**
     * Abandoned passwordless sign-ins (backend gaps #A): rows created by
     * verify-otp whose profile was never completed. 24h grace lets the user
     * finish registering; the bookings guard keeps any paying customer safe.
     */
    public function prunable(): Builder
    {
        return static::whereNull('name')
            ->where('created_at', '<', now()->subDay())
            ->whereDoesntHave('bookings');
    }

    /**
     * Polymorphic rows have no FK cascade (unlike refresh_tokens, favorites,
     * saved_cards, wallet_transactions) — clean them up explicitly.
     */
    protected function pruning(): void
    {
        $this->tokens()->delete();  // sanctum personal_access_tokens
        $this->roles()->detach();   // spatie model_has_roles
    }

    /* ===================== Helpers ===================== */

    public function isAdmin(): bool
    {
        // 'finance' is an admin-panel role (contract v2.2 §4.2) — it may open an
        // admin session; per-permission authz is enforced separately.
        return $this->hasAnyRole(['Admin', 'SuperAdmin', 'finance']);
    }

    public function isPartner(): bool
    {
        return $this->hasAnyRole(['Individual', 'Company']);
    }
}
