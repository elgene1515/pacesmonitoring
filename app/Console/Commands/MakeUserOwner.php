<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promote an existing account to the platform owner. Owners are never created
 * through sign-up: register normally, then run this once.
 */
class MakeUserOwner extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:make-owner {email : Email of an existing user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flag an existing user as the platform owner (manages every organization, plans and subscriptions).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        if ($user->isOwner()) {
            $this->info("{$user->name} ({$user->email}) is already an owner.");

            return self::SUCCESS;
        }

        $user->role = Role::Owner;
        $user->organization_id = null;
        $user->branch_id = null;
        $user->current_school_year_id = null;
        $user->save();

        $this->info("{$user->name} ({$user->email}) is now the platform owner.");

        return self::SUCCESS;
    }
}
