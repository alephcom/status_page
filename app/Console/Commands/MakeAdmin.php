<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('statuspage:make-admin {email?} {--name=} {--password=}')]
#[Description('Create an admin user, or promote an existing user to admin')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email') ?? text('Email', required: true);
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $user->update(['role' => UserRole::Admin]);
            $this->info("{$email} is now an admin.");

            return self::SUCCESS;
        }

        User::create([
            'email' => $email,
            'name' => $this->option('name') ?? text('Name', default: strstr($email, '@', true) ?: $email),
            'password' => $this->option('password') ?? password('Password', required: true),
            'role' => UserRole::Admin,
        ]);

        $this->info("Admin {$email} created. Sign in at ".url('/admin'));

        return self::SUCCESS;
    }
}
