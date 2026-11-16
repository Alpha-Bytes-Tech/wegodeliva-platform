<?php

namespace App\Enums;

/**
 * The client a token is issued for. Each token carries exactly one `app:*` ability, so a rider
 * token cannot call vendor endpoints (spec 14 §14.1), and each app only accepts users holding
 * one of its roles (spec 02 §2.4).
 */
enum ClientApp: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Rider = 'rider';
    case Admin = 'admin';

    /** @return list<RoleName> */
    public function allowedRoles(): array
    {
        return match ($this) {
            self::Customer => [RoleName::Customer],
            self::Vendor => [RoleName::VendorOwner, RoleName::VendorStaff],
            self::Rider => [RoleName::Rider],
            self::Admin => RoleName::adminRoles(),
        };
    }

    public function ability(): string
    {
        return 'app:'.$this->value;
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Customer => 'WeGoDeliva',
            self::Vendor => 'WeGoDeliva Vendor',
            self::Rider => 'WeGoDeliva Rider',
            self::Admin => 'WeGoDeliva Admin',
        };
    }

    /** Token lifetime in minutes (config/wegodeliva.php). */
    public function tokenLifetimeMinutes(): int
    {
        $key = $this === self::Admin ? 'admin' : 'app';

        return (int) config("wegodeliva.tokens.{$key}_lifetime_minutes");
    }

    /** @return list<string> */
    public static function mobileValues(): array
    {
        return [self::Customer->value, self::Vendor->value, self::Rider->value];
    }
}
