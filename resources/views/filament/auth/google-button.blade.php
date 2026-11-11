{{-- Inline styles: Filament ships precompiled CSS without arbitrary Tailwind utilities. --}}
@if (\App\Http\Controllers\GoogleAuthController::enabled())
    <div style="display: flex; flex-direction: column; gap: .75rem;">
        @if (session('google_error'))
            <p style="font-size: .875rem; color: rgb(220 38 38);" role="alert">{{ session('google_error') }}</p>
        @endif
        <x-filament::button tag="a" :href="route('auth.google', 'admin')" color="gray" style="width: 100%;">
            <span style="display: inline-flex; align-items: center; gap: .5rem;">
                <span style="display: inline-flex; width: 1rem; height: 1rem;">@include('status.partials.google-logo')</span>
                Sign in with Google
            </span>
        </x-filament::button>
    </div>
@endif
