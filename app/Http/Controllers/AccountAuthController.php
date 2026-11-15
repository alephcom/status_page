<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public accounts: register, sign in and out, verify the e-mail address and
 * reset a forgotten password. Accounts created here have no role, so they
 * cannot open the admin panel until an admin gives them one.
 */
class AccountAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('account.login', ['siteName' => Setting::get('site_name')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = self::findByEmail($data['email']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('account'));
    }

    public function showRegister(): View
    {
        return view('account.register', ['siteName' => Setting::get('site_name')]);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $email = mb_strtolower($data['email']);

        if (self::findByEmail($email)) {
            throw ValidationException::withMessages(['email' => __('An account with this address already exists.')]);
        }

        $user = User::create(['name' => $data['name'], 'email' => $email, 'password' => $data['password']]);
        $user->sendEmailVerificationNotification();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('account')->with('status', __('Your account was created. Check your inbox for a link to confirm your address.'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('status');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        // The address is now proven, so a pending e-mail subscription for it is too.
        $subscriber = $request->user()->subscription();
        if ($subscriber && ! $subscriber->isVerified()) {
            $subscriber->forceFill(['verified_at' => now()])->save();
        }

        return redirect()->route('account')->with('status', __('Your e-mail address is confirmed.'));
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', __('A new confirmation link has been sent.'));
    }

    public function showForgot(): View
    {
        return view('account.forgot-password', ['siteName' => Setting::get('site_name')]);
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        if ($user = self::findByEmail($request->input('email'))) {
            Password::sendResetLink(['email' => $user->email]);
        }

        // The same answer either way, so the form does not reveal who has an account.
        return back()->with('status', __('If there is an account for that address, we have e-mailed a link to reset the password.'));
    }

    public function showReset(Request $request, string $token): View
    {
        return view('account.reset-password', [
            'siteName' => Setting::get('site_name'),
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)]);
            // Following a link sent to the address proves it, like the verification link.
            $user->email_verified_at ??= now();
            $user->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('This password reset link is invalid or has expired.')]);
        }

        return redirect()->route('login')->with('status', __('Your password was reset. You can sign in now.'));
    }

    public static function findByEmail(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
    }
}
