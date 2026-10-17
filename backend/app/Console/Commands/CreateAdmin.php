<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Dropbell Admin} {--generate : Generate a random initial password}';
    protected $description = 'Create an administrator without changing any existing account';
    public function handle(): int
    {
        $email = $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Provide a valid email.'); return self::FAILURE; }
        if (User::where('email', $email)->exists()) { $this->error('That account already exists. No changes made.'); return self::FAILURE; }
        $password = $this->option('generate') ? bin2hex(random_bytes(12)) : $this->secret('Initial password (at least 12 characters)');
        if (strlen($password ?? '') < 12) { $this->error('Use at least 12 characters.'); return self::FAILURE; }
        $user = new User;
        $user->name = $this->option('name'); $user->email = $email; $user->password = Hash::make($password); $user->is_admin = true; $user->save();
        $this->info('Admin created: '.$email);
        if ($this->option('generate')) $this->line('Initial password: '.$password);
        return self::SUCCESS;
    }
}
