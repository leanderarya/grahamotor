<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserManagementAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateOwnerCommand extends Command
{
    protected $signature = 'owner:create {--name=} {--email=}';

    protected $description = 'Create an Owner account through the local CLI';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command requires an interactive terminal so the password can be entered privately.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Nama Owner')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email Owner'))));
        $password = $this->secret('Password Owner');

        try {
            Validator::make([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        if (! $this->confirm("Buat akun Owner untuk {$email}?", false)) {
            $this->info('Pembuatan akun dibatalkan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($name, $email, $password): void {
            $owner = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'owner',
            ]);

            UserManagementAudit::create([
                'actor_id' => null,
                'target_user_id' => $owner->id,
                'action' => 'owner_provisioned',
                'metadata' => ['source' => 'local_cli'],
                'created_at' => now(),
            ]);
        });

        $this->info('Akun Owner berhasil dibuat.');

        return self::SUCCESS;
    }
}
