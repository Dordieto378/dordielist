<?php

namespace Tests\Feature;

use App\Mail\AdminNewUserNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InitialAdminSetupTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_11_000001_create_initial_admin_account.php';

    private const PASSWORD = 'Initial-Admin-Password!42';

    protected function setUp(): void
    {
        parent::setUp();

        // Use an isolated schema so these tests never touch the local application's data.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'hashing.bcrypt.rounds' => 4,
            'session.driver' => 'array',
            'cache.default' => 'array',
            'admin.email' => 'initial-admin@example.test',
            'admin.username' => 'initial-admin',
            'admin.password' => self::PASSWORD,
        ]);
        DB::purge('sqlite');

        $schema = require database_path('migrations/2025_08_31_000600_create_roles_users_sessions_cache.php');
        $schema->up();
        Mail::fake();
    }

    public function test_migrate_creates_roles_and_an_active_admin_with_a_working_hashed_password(): void
    {
        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => self::MIGRATION,
            '--force' => true,
        ]));

        $admin = User::with('role')->where('email', 'initial-admin@example.test')->firstOrFail();

        $this->assertSame('Admin', $admin->role->role);
        $this->assertSame('initial-admin', $admin->username);
        $this->assertSame('active', $admin->status);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotSame(self::PASSWORD, $admin->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertDatabaseHas('roles', ['role' => 'Viewer']);
        Mail::assertNothingSent();
    }

    public function test_rerunning_setup_does_not_reset_an_existing_admin_or_duplicate_roles(): void
    {
        $this->runSetup();
        $admin = User::firstOrFail();
        $originalPassword = $admin->password;
        config(['admin.password' => 'Different-Password!42', 'admin.email' => 'other@example.test']);

        $this->runSetup();

        $this->assertSame(1, User::count());
        $this->assertSame(2, Role::count());
        $this->assertSame($originalPassword, $admin->fresh()->password);
        $this->assertSame('initial-admin@example.test', $admin->fresh()->email);
    }

    public function test_an_imported_admin_is_preserved_without_bootstrap_credentials(): void
    {
        $role = Role::firstOrCreate(['role' => 'Admin']);
        $admin = $this->user('imported@example.test', $role, 'banned');
        $originalPassword = $admin->password;
        config(['admin.email' => null, 'admin.password' => null]);

        $this->runSetup();

        $this->assertSame(1, User::count());
        $this->assertSame('banned', $admin->fresh()->status);
        $this->assertSame($originalPassword, $admin->fresh()->password);
        $this->assertDatabaseHas('roles', ['role' => 'Viewer']);
    }

    public function test_missing_bootstrap_credentials_fail_without_creating_accounts(): void
    {
        config(['admin.password' => null]);

        try {
            $this->runSetup();
            $this->fail('Setup should reject missing bootstrap credentials.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_PASSWORD is missing.', $exception->getMessage());
            $this->assertStringNotContainsString('ADMIN_EMAIL', $exception->getMessage());
            $this->assertStringNotContainsString('ADMIN_USERNAME', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Role::count());
    }

    public function test_setup_does_not_promote_an_existing_non_admin_with_the_configured_email(): void
    {
        $role = Role::firstOrCreate(['role' => 'Viewer']);
        $viewer = $this->user('initial-admin@example.test', $role, 'not_active');

        try {
            $this->runSetup();
            $this->fail('Setup should reject an email belonging to a non-admin.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_EMAIL is already used by a non-admin account.', $exception->getMessage());
            $this->assertStringNotContainsString($viewer->email, $exception->getMessage());
            $this->assertStringContainsString('never automatically promoted', $exception->getMessage());
        }

        $this->assertSame('Viewer', $viewer->fresh()->role->role);
        $this->assertSame('not_active', $viewer->fresh()->status);
        $this->assertSame(1, User::count());
    }

    public function test_setup_rejects_a_weak_password_without_exposing_it_in_the_error(): void
    {
        config(['admin.password' => 'weak-secret']);

        try {
            $this->runSetup();
            $this->fail('Setup should reject a weak administrator password.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_PASSWORD', $exception->getMessage());
            $this->assertStringNotContainsString('weak-secret', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
    }

    public function test_setup_reports_all_missing_or_empty_settings(): void
    {
        config(['admin.email' => null, 'admin.username' => '', 'admin.password' => null]);

        try {
            $this->runSetup();
            $this->fail('Setup should identify each missing setting.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_EMAIL is missing.', $exception->getMessage());
            $this->assertStringContainsString('ADMIN_USERNAME is empty.', $exception->getMessage());
            $this->assertStringContainsString('ADMIN_PASSWORD is missing.', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Role::count());
    }

    public function test_setup_identifies_a_username_collision_without_promoting_its_owner(): void
    {
        $role = Role::firstOrCreate(['role' => 'Viewer']);
        $viewer = $this->user('viewer@example.test', $role, 'not_active');
        config(['admin.username' => $viewer->username]);

        try {
            $this->runSetup();
            $this->fail('Setup should reject an occupied username.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_USERNAME is already used by a non-admin account.', $exception->getMessage());
            $this->assertStringNotContainsString($viewer->username, $exception->getMessage());
            $this->assertStringNotContainsString('ADMIN_EMAIL', $exception->getMessage());
        }

        $this->assertSame('Viewer', $viewer->fresh()->role->role);
        $this->assertSame('not_active', $viewer->fresh()->status);
        $this->assertSame(1, User::count());
    }

    public function test_failure_to_notify_one_admin_does_not_prevent_notifying_the_next(): void
    {
        $this->runSetup();
        $adminRole = Role::where('role', 'Admin')->firstOrFail();
        $this->user('second-admin@example.test', $adminRole);
        Log::spy();

        Mail::shouldReceive('to')->once()->with('initial-admin@example.test')
            ->andThrow(new \RuntimeException('SMTP delivery failed'));
        $pendingMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->with(\Mockery::type(AdminNewUserNotification::class));
        Mail::shouldReceive('to')->once()->with('second-admin@example.test')->andReturn($pendingMail);

        $this->post(route('register.post'), [
            'username' => 'new-viewer',
            'email' => 'new-viewer@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'terms' => '1',
        ])->assertOk()->assertViewIs('auth.register_pending');

        Log::shouldHaveReceived('error')->once();
        $this->assertDatabaseHas('users', ['email' => 'new-viewer@example.test', 'status' => 'not_active']);
    }

    public function test_registration_uses_the_viewer_role_and_emails_only_active_admins(): void
    {
        $viewerRole = new Role(['role' => 'Viewer']);
        $viewerRole->role_id = 40;
        $viewerRole->save();
        $adminRole = Role::create(['role' => 'Admin']);
        $firstAdmin = $this->user('first-admin@example.test', $adminRole);
        $secondAdmin = $this->user('second-admin@example.test', $adminRole);
        $this->user('banned-admin@example.test', $adminRole, 'banned');
        $this->user('pending-admin@example.test', $adminRole, 'not_active');
        $this->user('viewer@example.test', $viewerRole);

        $this->post(route('register.post'), [
            'username' => 'new-viewer',
            'email' => 'new-viewer@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'terms' => '1',
        ])->assertOk()->assertViewIs('auth.register_pending');

        $this->assertDatabaseHas('users', [
            'email' => 'new-viewer@example.test',
            'role_id' => 40,
            'status' => 'not_active',
        ]);
        Mail::assertSent(AdminNewUserNotification::class, fn ($mail) => $mail->hasTo($firstAdmin->email));
        Mail::assertSent(AdminNewUserNotification::class, fn ($mail) => $mail->hasTo($secondAdmin->email));
        Mail::assertSentCount(2);
        Mail::assertSent(AdminNewUserNotification::class, function ($mail) {
            $request = \Illuminate\Http\Request::create($mail->confirmUrl);

            return URL::hasValidSignature($request)
                && str_contains($mail->confirmUrl, '/admin/confirm/'.$mail->user->user_id);
        });
    }

    private function runSetup(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    private function user(string $email, Role $role, string $status = 'active'): User
    {
        return User::create([
            'username' => str_replace('@', '-', $email),
            'email' => $email,
            'password' => self::PASSWORD,
            'role_id' => $role->role_id,
            'status' => $status,
        ]);
    }
}
