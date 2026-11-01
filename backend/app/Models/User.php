<?php

namespace App\Models;

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Support\ReferralCode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $phone
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property string|null $password
 * @property UserStatus $status
 * @property string|null $avatar_path
 * @property string $referral_code
 * @property int|null $referred_by_user_id
 * @property int $kyc_tier
 * @property string $locale
 * @property Carbon|null $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /**
     * Only profile fields. Status, roles, verification timestamps and KYC tier are never
     * mass-assignable (legacy-notes: mass assignment and role chosen at sign-up).
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'phone', 'password', 'locale'];

    /** @var list<string> */
    protected $hidden = ['id', 'password', 'remember_token', 'referred_by_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'kyc_tier' => 1,
        'locale' => 'en',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->email = self::normaliseEmail($user->email);
            $user->referral_code ??= ReferralCode::generateUnique();
        });
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'kyc_tier' => 'integer',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<AuthIdentity, $this> */
    public function authIdentities(): HasMany
    {
        return $this->hasMany(AuthIdentity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return array_values($this->roles->pluck('name')->all());
    }

    public function hasRole(RoleName ...$roles): bool
    {
        $names = array_map(fn (RoleName $role) => $role->value, $roles);

        return array_intersect($names, $this->roleNames()) !== [];
    }

    public function assignRole(RoleName $role): void
    {
        $roleId = Role::query()->where('name', $role->value)->value('id');
        $this->roles()->syncWithoutDetaching([$roleId]);
        $this->unsetRelation('roles');
    }

    public function canUseApp(ClientApp $app): bool
    {
        return $this->hasRole(...$app->allowedRoles());
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(...RoleName::adminRoles());
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }
}
