<x-guest-layout>
    <div class="text-center mb-7">
        <p class="font-script text-cobalt text-5xl leading-none">Kumusta</p>
        <p class="font-display uppercase tracking-[0.04em] text-navy text-sm mt-1.5">JHS Load &amp; Scheduling</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
        @csrf

        <div>
            <label for="email" class="field-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                   autocomplete="username" placeholder="chair.fil@jhs.test" class="field-input" />
            <x-input-error :messages="$errors->get('email')" class="field-error" />
        </div>

        <div>
            <label for="password" class="field-label">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                   placeholder="••••••••" class="field-input" />
            <x-input-error :messages="$errors->get('password')" class="field-error" />
        </div>

        <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-slate-brand">
            <input id="remember_me" type="checkbox" name="remember"
                   class="rounded border-line text-cobalt focus:ring-electric">
            <span>Remember me</span>
        </label>

        <button type="submit" class="btn-primary w-full mt-1">Sign in</button>

        @if (Route::has('password.request'))
            <a href="{{ route('password.request') }}"
               class="text-center text-sm text-electric hover:underline">
                Forgot your password?
            </a>
        @endif
    </form>
</x-guest-layout>
