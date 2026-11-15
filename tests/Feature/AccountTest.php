<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Component;
use App\Models\Subscriber;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Livewire\Livewire;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private function verifyUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_auth_pages_render_and_header_links_to_sign_in(): void
    {
        $this->get('/')->assertOk()->assertSee(route('login'));
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/abc?email=a@example.com')->assertOk();
    }

    public function test_account_requires_sign_in(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_register_creates_a_user_without_admin_access_and_sends_verification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Pat', 'email' => 'Pat@Example.com',
            'password' => 'long-enough-pw', 'password_confirmation' => 'long-enough-pw',
        ])->assertRedirect(route('account'));

        $user = User::query()->sole();
        $this->assertSame('pat@example.com', $user->email);
        $this->assertNull($user->role);
        $this->assertFalse($user->isStaff());
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get('/admin')->assertForbidden();
    }

    public function test_register_rejects_an_existing_address_in_any_case(): void
    {
        User::factory()->create(['email' => 'pat@example.com']);

        $this->post('/register', [
            'name' => 'Pat', 'email' => 'PAT@example.com',
            'password' => 'long-enough-pw', 'password_confirmation' => 'long-enough-pw',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com', 'password' => Hash::make('secret-password')]);

        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'Pat@Example.com', 'password' => 'secret-password'])->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user);

        $this->get('/login')->assertRedirect(route('account'));

        $this->post('/logout')->assertRedirect(route('status'));
        $this->assertGuest();
    }

    public function test_unverified_users_cannot_manage_a_subscription_for_their_address(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pat@example.com']);
        Subscriber::create(['email' => 'pat@example.com', 'verified_at' => now()]);

        $this->actingAs($user)->get('/account')->assertOk()->assertSee('Send the link again')->assertDontSee('Unsubscribe');
        $this->actingAs($user)->put('/account/subscription', [])->assertForbidden();
        $this->actingAs($user)->delete('/account/subscription')->assertForbidden();

        $this->assertSame(1, Subscriber::count());
    }

    public function test_verifying_the_address_confirms_a_pending_subscription(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pat@example.com']);
        $subscriber = Subscriber::create(['email' => 'pat@example.com']);

        $this->actingAs($user)->get($this->verifyUrl($user))->assertRedirect(route('account'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertTrue($subscriber->fresh()->isVerified());
    }

    public function test_verified_users_subscribe_update_and_unsubscribe(): void
    {
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $user = User::factory()->create(['email' => 'pat@example.com']);

        $this->actingAs($user)->get('/account')->assertOk()->assertSee('You are not subscribed');

        $this->actingAs($user)->put('/account/subscription', ['components' => [$a->id], 'min_impact' => 'major'])->assertRedirect();
        $subscriber = Subscriber::query()->sole();
        $this->assertSame('pat@example.com', $subscriber->email);
        $this->assertTrue($subscriber->isVerified());
        $this->assertSame('major', $subscriber->min_impact->value);
        $this->assertSame([$a->id], $subscriber->components()->pluck('components.id')->all());

        $this->actingAs($user)->put('/account/subscription', ['components' => [$b->id]]);
        $this->assertSame([$b->id], $subscriber->components()->pluck('components.id')->all());
        $this->assertNull($subscriber->fresh()->min_impact);

        $this->actingAs($user)->get('/account')->assertSee('Unsubscribe');
        $this->actingAs($user)->delete('/account/subscription')->assertRedirect();
        $this->assertSame(0, Subscriber::count());
    }

    public function test_change_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'old-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
    }

    public function test_password_reset(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'pat@example.com']);

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'PAT@example.com'])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'pat@example.com',
            'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_google_sign_in_creates_a_verified_account(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        Socialite::fake('google', (new GoogleUser)->map(['email' => 'Pat@Example.com', 'name' => 'Pat'])->setRaw(['email_verified' => true]));

        $this->get('/login')->assertSee(route('auth.google', 'account'));
        $this->get('/auth/google/account')->assertRedirect();
        $this->get('/auth/google/callback')->assertRedirect(route('account'));

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('pat@example.com', $user->email);
        $this->assertNull($user->role);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_admins_can_make_a_user_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('makeAdmin')->table($admin))
            ->callAction(TestAction::make('makeAdmin')->table($user));

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->get('/admin/users')->assertOk();
    }

    public function test_admins_can_change_roles_but_not_their_own(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['role' => UserRole::Editor->value])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(UserRole::Editor, $user->fresh()->role);

        Livewire::test(EditUser::class, ['record' => $admin->id])->assertFormFieldDisabled('role');
    }

    public function test_editors_and_users_cannot_manage_users(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]))->get('/admin/users')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
    }
}
