<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues a Sanctum personal access token for the API.
 *
 * This is how the frontend gets its credential during setup. The token is
 * printed exactly once - Sanctum stores only a hash, so it genuinely cannot
 * be recovered afterwards, only replaced.
 */
class IssueApiTokenCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'api:token
                            {name=frontend : A label identifying what the token is for}
                            {--email=api@palm.test : The user the token belongs to}';

    /**
     * @var string
     */
    protected $description = 'Issue a Sanctum API token for the frontend';

    /**
     * Run the command.
     */
    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->argument('name');

        // firstOrCreate keeps the command idempotent: running it twice issues
        // a second token rather than failing on a duplicate user.
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'API Consumer',
                // A random password nobody needs to know. This account exists
                // to own tokens; it never logs in interactively.
                'password' => Hash::make(Str::random(48)),
            ],
        );

        $token = $user->createToken($name);

        $this->newLine();
        $this->components->info('API token created. It is shown only once.');
        $this->newLine();

        $this->line('  <comment>User:</comment>  '.$user->email);
        $this->line('  <comment>Name:</comment>  '.$name);
        $this->newLine();
        $this->line('  <fg=green;options=bold>'.$token->plainTextToken.'</>');
        $this->newLine();

        $this->line('  Add it to <comment>services/frontend/.env.local</comment> as:');
        $this->line('    <fg=cyan>BACKEND_API_TOKEN='.$token->plainTextToken.'</>');
        $this->newLine();
        $this->components->warn('Keep this server-side. It must never be exposed to the browser.');

        return self::SUCCESS;
    }
}
