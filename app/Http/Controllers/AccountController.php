<?php

namespace App\Http\Controllers;

use App\Enums\IncidentImpact;
use App\Models\Component;
use App\Models\Setting;
use App\Models\Subscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * A signed-in user's own page: their e-mail subscription and password.
 * The subscription is the one for the account's address, and can only be
 * managed once that address is verified.
 */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.show', [
            'siteName' => Setting::get('site_name'),
            'user' => $user,
            'subscriber' => $user->hasVerifiedEmail() ? $user->subscription()?->load('components') : null,
            'components' => Component::query()->enabled()->public()->with('group')->orderBy('order')->orderBy('name')->get(),
        ]);
    }

    public function updateSubscription(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasVerifiedEmail(), 403);

        $data = $request->validate([
            'components' => ['array'],
            'components.*' => ['integer', Rule::exists('components', 'id')->where('visibility', 'public')],
            'min_impact' => ['nullable', Rule::enum(IncidentImpact::class)],
        ]);

        $created = DB::transaction(function () use ($user, $data) {
            $subscriber = Subscriber::query()->firstOrCreate(['email' => mb_strtolower($user->email)]);
            $subscriber->components()->sync($data['components'] ?? []);
            $subscriber->forceFill([
                'min_impact' => $data['min_impact'] ?? null,
                'verified_at' => $subscriber->verified_at ?? now(),
            ])->save();

            return $subscriber->wasRecentlyCreated;
        });

        return back()->with('status', $created ? __('You are subscribed.') : __('Your preferences were saved.'));
    }

    public function destroySubscription(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasVerifiedEmail(), 403);

        $request->user()->subscription()?->delete();

        return back()->with('status', __('You have been unsubscribed.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $request->user()->update(['password' => $request->input('password')]);

        return back()->with('status', __('Your password was changed.'));
    }
}
