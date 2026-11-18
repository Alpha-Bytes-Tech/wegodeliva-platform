<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Break-glass reset for an admin who is locked out (docs/runbooks/staging.md).
 *
 * The password is never a command argument, so it does not end up in shell or dashboard
 * command history: it is asked for interactively, or, where commands are not interactive
 * (Laravel Cloud's Commands tab), read from SUPER_ADMIN_PASSWORD with --from-env.
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password
        {email : Email address of the admin account}
        {--from-env : Read the new password from SUPER_ADMIN_PASSWORD instead of prompting}';

    protected $description = 'Set a new password for an admin account and sign it out everywhere';

    public function handle(AuditLogger $audit): int
    {
        $email = User::normaliseEmail((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();
        if ($user === null || ! $user->isAdmin()) {
            $this->error('No admin account has that email address.');

            return self::FAILURE;
        }

        $password = $this->newPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', 'max:72', Password::min(12)->letters()->numbers()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $password, $audit): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
            $audit->record(null, 'admin.password_reset_cli', 'user', $user->uuid);
        });

        $this->info("Password updated for {$email}; all of its sessions were signed out.");
        if ($this->option('from-env')) {
            $this->warn('Now remove SUPER_ADMIN_PASSWORD from the environment variables.');
        }

        return self::SUCCESS;
    }

    private function newPassword(): ?string
    {
        if ($this->option('from-env')) {
            $password = config('wegodeliva.super_admin.password');
            if (! is_string($password) || $password === '') {
                $this->error('SUPER_ADMIN_PASSWORD is not set.');

                return null;
            }

            return $password;
        }

        if (! $this->input->isInteractive()) {
            $this->error('This command asks for the password. Run it interactively, or use --from-env.');

            return null;
        }

        $password = (string) $this->secret('New password (at least 12 characters, letters and numbers)');
        if ($password !== (string) $this->secret('Repeat the new password')) {
            $this->error('The passwords do not match.');

            return null;
        }

        return $password;
    }
}
