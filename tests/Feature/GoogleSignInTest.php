<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Component;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\VerifySubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    }

    private function googleUser(string $email, bool $verified = true): void
    {
        Socialite::fake('google', (new GoogleUser)->map(['email' => $email])->setRaw(['email_verified' => $verified]));
    }

    public function test_buttons_are_hidden_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/subscribe')->assertOk()->assertDontSee('Subscribe with Google');
        $this->get('/admin/login')->assertOk()->assertDontSee('Sign in with Google');
        $this->get('/auth/google/manage')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();
    }

    public function test_buttons_show_when_configured(): void
    {
        $this->get('/subscribe')->assertOk()->assertSee('Subscribe with Google')->assertSee(route('auth.google', 'manage'));
        $this->get('/admin/login')->assertOk()->assertSee('Sign in with Google');
    }

    public function test_subscribe_with_google_creates_a_verified_subscription_with_chosen_components(): void
    {
        Notification::fake();
        $a = Component::create(['name' => 'A']);
        Component::create(['name' => 'B']);
        $this->googleUser('Person@Example.com');

        $this->post('/subscribe/google', ['components' => [$a->id]])->assertRedirect();
        $this->assertSame('subscribe', session('google.intent'));

        $response = $this->get('/auth/google/callback?state=x&code=y');

        $subscriber = Subscriber::query()->sole();
        $response->assertRedirect(route('subscription.show', $subscriber))->assertSessionHas('status');
        $this->assertSame('person@example.com', $subscriber->email);
        $this->assertTrue($subscriber->isVerified());
        $this->assertSame([$a->id], $subscriber->components()->pluck('components.id')->all());
        Notification::assertNothingSent();
    }

    public function test_subscribing_again_keeps_a_confirmed_subscriptions_preferences(): void
    {
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $existing = Subscriber::create(['email' => 'p@example.com', 'verified_at' => now()]);
        $existing->components()->attach($a);
        $this->googleUser('p@example.com');

        $this->post('/subscribe/google', ['components' => [$b->id]]);
        $this->get('/auth/google/callback')->assertRedirect(route('subscription.show', $existing));

        $this->assertSame([$a->id], $existing->components()->pluck('components.id')->all());
        $this->assertSame(1, Subscriber::count());
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->googleUser('p@example.com', verified: false);

        $this->post('/subscribe/google');
        $this->get('/auth/google/callback')->assertRedirect(route('subscribe'))->assertSessionHas('error');
        $this->assertSame(0, Subscriber::count());
    }

    public function test_manage_opens_an_existing_subscription_and_confirms_it(): void
    {
        Notification::fake();
        $this->post('/subscribe', ['email' => 'p@example.com']);
        Notification::assertSentTo(Subscriber::query()->sole(), VerifySubscription::class);
        $this->googleUser('p@example.com');

        $this->get('/auth/google/manage')->assertRedirect();
        $this->get('/auth/google/callback')->assertRedirect(route('subscription.show', Subscriber::query()->sole()));
        $this->assertTrue(Subscriber::query()->sole()->isVerified());
    }

    public function test_manage_without_a_subscription_does_not_create_one(): void
    {
        $this->googleUser('nobody@example.com');

        $this->get('/auth/google/manage');
        $this->get('/auth/google/callback')->assertRedirect(route('subscribe'))->assertSessionHas('error');
        $this->assertSame(0, Subscriber::count());
    }

    public function test_staff_sign_in_to_the_admin_panel(): void
    {
        $editor = User::factory()->create(['email' => 'Editor@Example.com', 'role' => UserRole::Editor]);
        $this->googleUser('editor@example.com');

        $this->get('/auth/google/admin');
        $this->get('/auth/google/callback')->assertRedirect('/admin');

        $this->assertAuthenticatedAs($editor);
        $this->get('/admin')->assertOk();
    }

    public function test_unknown_google_accounts_cannot_reach_the_admin_panel(): void
    {
        Subscriber::create(['email' => 'p@example.com', 'verified_at' => now()]);
        $this->googleUser('p@example.com');

        $this->get('/auth/google/admin');
        $this->get('/auth/google/callback')->assertRedirect('/admin/login')->assertSessionHas('google_error');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_subscribing_with_google_never_signs_in_to_the_admin_panel(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'role' => UserRole::Admin]);
        $this->googleUser('admin@example.com');

        $this->post('/subscribe/google');
        $this->get('/auth/google/callback');

        $this->assertGuest();
    }

    public function test_callback_without_a_pending_sign_in_goes_home(): void
    {
        $this->googleUser('p@example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('status'));
        $this->get('/auth/google/subscribe')->assertNotFound();
    }
}
