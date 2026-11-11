<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statuspage:mcp-token {email : An existing admin or editor} {--name=mcp : Label for the token} {--revoke : Revoke the user\'s tokens with this name instead}')]
#[Description('Issue (or revoke) a bearer token for the MCP endpoint at /mcp')]
class McpToken extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user || $user->role === null) {
            $this->error('No admin or editor with that e-mail.');

            return self::FAILURE;
        }

        $name = (string) $this->option('name');

        if ($this->option('revoke')) {
            $count = $user->tokens()->where('name', $name)->delete();
            $this->info("Revoked {$count} token(s) named \"{$name}\".");

            return self::SUCCESS;
        }

        $token = $user->createToken($name)->plainTextToken;

        $this->info("Token for {$user->email} ({$user->role->value}). It is shown only once:");
        $this->line($token);
        $this->newLine();
        $this->line('Endpoint: '.url('/mcp').'  Header: Authorization: Bearer <token>');

        return self::SUCCESS;
    }
}
