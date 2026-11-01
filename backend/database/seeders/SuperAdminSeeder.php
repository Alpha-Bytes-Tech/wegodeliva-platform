<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;

/**
 * Creates the first super admin from SUPER_ADMIN_* environment variables. There is no default
 * password: without the variables, nothing is created.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{name: string|null, email: string|null, phone: string|null, password: string|null} $config */
        $config = config('wegodeliva.super_admin');

        if (empty($config['email']) || empty($config['password']) || empty($config['phone'])) {
            $this->command->warn('SUPER_ADMIN_EMAIL, SUPER_ADMIN_PHONE and SUPER_ADMIN_PASSWORD are not all set; skipping super admin.');

            return;
        }
        if (mb_strlen($config['password']) < 12) {
            $this->command->error('SUPER_ADMIN_PASSWORD must be at least 12 characters; skipping super admin.');

            return;
        }
        $phone = PhoneNumber::normalise($config['phone']);
        if ($phone === null) {
            $this->command->error('SUPER_ADMIN_PHONE is not a valid Nigerian mobile number; skipping super admin.');

            return;
        }

        $email = User::normaliseEmail($config['email']);
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = new User([
                'name' => $config['name'] ?: 'Super Admin',
                'email' => $email,
                'phone' => $phone,
                'password' => $config['password'],
            ]);
            $user->email_verified_at = now();
            $user->save();
            $this->command->info("Super admin {$email} created.");
        }

        $user->assignRole(RoleName::SuperAdmin);
    }
}
