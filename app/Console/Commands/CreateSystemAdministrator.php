<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('app:create-system-administrator {email} {--name=System Administrator}')]
#[Description('Create a system administrator without modifying an existing account.')]
class CreateSystemAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $emailArgument = $this->argument('email');
        if (! is_string($emailArgument)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }
        $email = strtolower(trim($emailArgument));

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error('An account already exists with this email. No changes were made.');

            return self::FAILURE;
        }

        $password = config('provisioning.admin_password');
        if (! $password && $this->input->isInteractive()) {
            $password = $this->secret('Choose a strong password for the system administrator');
        }

        $validator = Validator::make([
            'email' => $email,
            'name' => $this->option('name'),
            'password' => $password,
        ], [
            'email' => ['required', 'email', 'max:191'],
            'name' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'email' => $email,
            'name' => $this->option('name'),
            'password' => $password,
            'type' => 'saas_admin',
            'is_active' => true,
            'is_owner' => false,
        ]);
        $this->info('System administrator created.');

        return self::SUCCESS;
    }
}
