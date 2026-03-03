<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;

    protected $fillable = [
        'phone',
        'name',
        'email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /* =======================
        Custom Email Verification
    ======================== */

    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmail);
    }

    /* =======================
        Relationships
    ======================== */

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function partner()
    {
        return $this->hasOne(Partner::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /* =======================
        Helpers
    ======================== */

    public function isPartner()
    {
        return $this->roles()->where('role_name', 'Partner')->exists();
    }

    public function isAdmin()
    {
        return $this->roles()->whereIn('role_name', ['Admin','Super Admin'])->exists();
    }
}