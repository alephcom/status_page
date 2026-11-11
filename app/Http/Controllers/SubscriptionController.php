<?php

namespace App\Http\Controllers;

use App\Enums\IncidentImpact;
use App\Models\Component;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Notifications\VerifySubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Double opt-in e-mail subscriptions. The random token in the link is what
 * proves ownership of the address, so it doubles as the "login" for managing
 * the subscription.
 */
class SubscriptionController extends Controller
{
    public function create(): View
    {
        return view('status.subscribe', [
            'siteName' => Setting::get('site_name'),
            'components' => Component::query()->enabled()->public()->with('group')->orderBy('order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'components' => ['array'],
            'components.*' => ['integer', Rule::exists('components', 'id')->where('visibility', 'public')],
            'min_impact' => ['nullable', Rule::enum(IncidentImpact::class)],
        ]);

        $subscriber = Subscriber::query()->firstOrCreate(['email' => mb_strtolower($data['email'])]);

        // Only an unverified subscription may have its preferences set from
        // this public form; a verified one is managed through its own link.
        if (! $subscriber->isVerified()) {
            $subscriber->components()->sync($data['components'] ?? []);
            $subscriber->update(['min_impact' => $data['min_impact'] ?? null]);
        }

        $subscriber->notify(new VerifySubscription);

        return redirect()->route('subscribe')->with('status', __('Check your inbox for a link to confirm your subscription.'));
    }

    public function verify(Subscriber $subscriber): RedirectResponse
    {
        if (! $subscriber->isVerified()) {
            $subscriber->forceFill(['verified_at' => now()])->save();
        }

        return redirect()->route('subscription.show', $subscriber)->with('status', __('Your subscription is confirmed.'));
    }

    public function show(Subscriber $subscriber): View
    {
        return view('status.subscription', [
            'siteName' => Setting::get('site_name'),
            'subscriber' => $subscriber->load('components'),
            'components' => Component::query()->enabled()->public()->with('group')->orderBy('order')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Subscriber $subscriber): RedirectResponse
    {
        $data = $request->validate([
            'components' => ['array'],
            'components.*' => ['integer', Rule::exists('components', 'id')->where('visibility', 'public')],
            'min_impact' => ['nullable', Rule::enum(IncidentImpact::class)],
        ]);

        $subscriber->components()->sync($data['components'] ?? []);
        $subscriber->update(['min_impact' => $data['min_impact'] ?? null]);

        return back()->with('status', __('Your preferences were saved.'));
    }

    public function destroy(Subscriber $subscriber): RedirectResponse
    {
        $subscriber->delete();

        return redirect()->route('status')->with('status', __('You have been unsubscribed.'));
    }
}
