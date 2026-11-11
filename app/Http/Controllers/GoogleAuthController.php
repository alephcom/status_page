<?php

namespace App\Http\Controllers;

use App\Enums\IncidentImpact;
use App\Models\Subscriber;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Sign in with Google" for two audiences, sharing one callback URL:
 *
 * - subscribe: create (or find) a subscription for the Google address. Google
 *   has verified the address, so no confirmation e-mail is needed.
 * - manage: open an existing subscription without waiting for an e-mail.
 * - admin: sign in to the admin panel, only as an existing admin or editor.
 *
 * Subscribers never become Users; signing in as one grants no admin access.
 */
class GoogleAuthController extends Controller
{
    private const INTENTS = ['subscribe', 'manage', 'admin'];

    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /** POST from the subscribe form: remember the chosen components, then go to Google. */
    public function subscribe(Request $request): SymfonyRedirect
    {
        $data = $request->validate([
            'components' => ['array'],
            'components.*' => ['integer', Rule::exists('components', 'id')->where('visibility', 'public')],
            'min_impact' => ['nullable', Rule::enum(IncidentImpact::class)],
        ]);

        $request->session()->put('google.components', array_map('intval', $data['components'] ?? []));
        $request->session()->put('google.min_impact', $data['min_impact'] ?? null);

        return $this->toGoogle($request, 'subscribe');
    }

    /** GET for "manage" and "admin". */
    public function redirect(Request $request, string $intent): SymfonyRedirect
    {
        abort_unless(in_array($intent, ['manage', 'admin'], true), 404);

        return $this->toGoogle($request, $intent);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        $intent = $request->session()->pull('google.intent');
        $components = $request->session()->pull('google.components', []);
        $minImpact = $request->session()->pull('google.min_impact');

        if (! in_array($intent, self::INTENTS, true)) {
            return redirect()->route('status');
        }

        $failed = fn (string $message) => $intent === 'admin'
            ? redirect(Filament::getLoginUrl())->with('google_error', $message)
            : redirect()->route('subscribe')->with('error', $message);

        if ($request->has('error')) {
            return $failed('Google sign-in was cancelled.');
        }

        try {
            $google = $this->provider()->user();
        } catch (InvalidStateException) {
            return $failed('Your Google sign-in expired. Please try again.');
        } catch (Throwable $e) {
            report($e);

            return $failed('Google sign-in failed. Please try again.');
        }

        $email = mb_strtolower((string) $google->getEmail());

        if ($email === '' || ($google->user['email_verified'] ?? true) === false) {
            return $failed('Your Google account has no verified e-mail address.');
        }

        return match ($intent) {
            'admin' => $this->signInToAdmin($request, $email, $failed),
            'manage' => $this->manage($email),
            'subscribe' => $this->subscribeAs($email, $components, $minImpact),
        };
    }

    private function toGoogle(Request $request, string $intent): SymfonyRedirect
    {
        abort_unless(self::enabled(), 404);

        $request->session()->put('google.intent', $intent);

        // Only the e-mail address is needed; "select_account" lets people
        // who are signed in to several Google accounts choose one.
        return $this->provider()->setScopes(['openid', 'email'])->with(['prompt' => 'select_account'])->redirect();
    }

    private function provider(): Provider
    {
        return Socialite::driver('google')->redirectUrl(route('auth.google.callback'));
    }

    private function signInToAdmin(Request $request, string $email, \Closure $failed): RedirectResponse
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            return $failed("{$email} is not an admin or editor of this status page.");
        }

        Auth::guard(Filament::getPanel('admin')->getAuthGuard())->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }

    private function manage(string $email): RedirectResponse
    {
        $subscriber = Subscriber::query()->where('email', $email)->first();

        if (! $subscriber) {
            return redirect()->route('subscribe')->with('error', "There is no subscription for {$email} yet. Subscribe below.");
        }

        // Signing in with Google proves ownership just like the e-mail link.
        if (! $subscriber->isVerified()) {
            $subscriber->forceFill(['verified_at' => now()])->save();
        }

        return redirect()->route('subscription.show', $subscriber);
    }

    /** @param  list<int>  $components */
    private function subscribeAs(string $email, array $components, ?string $minImpact = null): RedirectResponse
    {
        $confirmedNow = false;

        $subscriber = DB::transaction(function () use ($email, $components, $minImpact, &$confirmedNow) {
            $subscriber = Subscriber::query()->firstOrCreate(['email' => $email]);

            // Like the e-mail form, only a not-yet-confirmed subscription takes
            // its preferences from the form; a confirmed one keeps its own.
            if (! $subscriber->isVerified()) {
                $subscriber->components()->sync($components);
                $subscriber->forceFill(['verified_at' => now(), 'min_impact' => IncidentImpact::tryFrom((string) $minImpact)])->save();
                $confirmedNow = true;
            }

            return $subscriber;
        });

        return redirect()->route('subscription.show', $subscriber)->with('status', $confirmedNow
            ? 'You are subscribed. You can change what you get e-mails about below.'
            : 'You were already subscribed. Your preferences are below.');
    }
}
