<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
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
        $hasLast  = array_key_exists('last_name', $data);

        if ($hasFirst || $hasLast) {
            $first = trim((string) ($hasFirst ? $data['first_name'] : $this->first_name));
            $last  = trim((string) ($hasLast ? $data['last_name'] : $this->last_name));

            $this->first_name = $first !== '' ? $first : null;
            $this->last_name  = $last !== '' ? $last : null;
            $this->name       = trim($first.' '.$last) ?: $this->name;

            return;
        }

        if (array_key_exists('name', $data) && filled($data['name'])) {
            $name  = trim((string) $data['name']);
            $parts = preg_split('/\s+/', $name, 2) ?: [$name];

            $this->name       = $name;
            $this->first_name = $parts[0] ?? null;
            $this->last_name  = $parts[1] ?? null;
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
        return $this->hasAnyRole(['Admin', 'SuperAdmin']);
    }

    public function isPartner(): bool
    {
        return $this->hasAnyRole(['Individual', 'Company']);
    }
}
