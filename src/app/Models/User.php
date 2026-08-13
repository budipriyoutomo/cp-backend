<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;


class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $table = 'users';
    // `pin_lookup` is deliberately NOT fillable: it is derived from `pin` by the
    // mutator below, so it can never drift from the PIN it indexes.
    protected $fillable = [
        'name', 'email', 'password', 'role', 'departemen', 'outlet', 'module_app', 'pin'
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'pin',
        'pin_lookup',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'outlet' => 'array',
        'module_app' => 'array',
    ];

    /**
     * Blind index for the PIN.
     *
     * The PIN itself is stored as a bcrypt hash, which cannot be looked up. A
     * keyed HMAC of the same PIN gives an indexable, deterministic value that
     * still reveals nothing without APP_KEY — so login stays a single indexed
     * query instead of a bcrypt check against every user.
     */
    public static function pinLookup(string $pin): string
    {
        return hash_hmac('sha256', trim($pin), (string) config('app.key'));
    }

    /**
     * Writing a PIN always writes both columns. There is no code path that can
     * store a plaintext PIN or leave the blind index stale — which is why `pin`
     * uses a mutator here instead of the `hashed` cast.
     */
    public function setPinAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['pin']        = null;
            $this->attributes['pin_lookup'] = null;

            return;
        }

        $pin = trim((string) $value);

        $this->attributes['pin']        = Hash::make($pin);
        $this->attributes['pin_lookup'] = static::pinLookup($pin);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * NOTE: the PIN is deliberately absent. A JWT payload is only base64
     * encoded, not encrypted, so anything put here is readable by whoever
     * holds the token.
     */
    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
            'departemen' => $this->departemen,
            'outlet' => $this->outlet,
            'module_app' => $this->module_app,
        ];
    }

    public function scopeAdminOperationCmms($query,$outlet)
    {
        return $query->where('role', 'admin')
            ->where('departemen', 'Operation')
            ->whereJsonContains('outlet', strtolower($outlet)) 
            ->whereJsonContains('module_app', 'cmms');
    }

}
