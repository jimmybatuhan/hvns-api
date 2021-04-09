<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        <!-- First Name -->
        <div class="mt-4">
            <x-label for="first_name" value="First Name" />
            <x-input
                id="first_name"
                class="block mt-1 w-full"
                type="text" name="first_name"
                :value="old('first_name')"
                required
            />
            @if ($errors->has('first_name'))
                <div class="mt-3 d-block text-sm text-red-600">
                    {{ $errors->first('first_name') }}
                </div>
            @endif
        </div>

        <!-- Last Name -->
        <div class="mt-4">
            <x-label for="last_name" value="Last Name" />
            <x-input
                id="last_name"
                class="block mt-1 w-full"
                type="text" name="last_name"
                :value="old('last_name')"
                required
            />
            @if ($errors->has('last_name'))
                <div class="mt-3 d-block text-sm text-red-600">
                    {{ $errors->first('last_name') }}
                </div>
            @endif
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-label for="email" :value="__('Email')" />
            <x-input
                id="email"
                class="block mt-1 w-full"
                type="email"
                name="email"
                :value="old('email')"
                required
            />
            @if ($errors->has('email'))
                <div class="mt-3 d-block text-sm text-red-600">
                    {{ $errors->first('email') }}
                </div>
            @endif
        </div>

        <!-- Phone  -->
        <div class="mt-4">
            <x-label for="phone" value="Phone No." />
            <x-input
                id="phone"
                class="block mt-1 w-full"
                type="text"
                name="phone"
                :value="old('phone')"
                required
            />
            @if ($errors->has('phone'))
                <div class="mt-3 d-block text-sm text-red-600">
                    {{ $errors->first('phone') }}
                </div>
            @endif
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-label for="password" :value="__('Password')" />
            <x-input
                id="password"
                class="block mt-1 w-full"
                type="password"
                name="password"
                required autocomplete="new-password"
            />
            @if ($errors->has('password'))
                <div class="mt-3 d-block text-sm text-red-600">
                    {{ $errors->first('password') }}
                </div>
            @endif
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-input
                id="password_confirmation"
                class="block mt-1 w-full"
                type="password"
                name="password_confirmation" required
            />
        </div>

        <div class="flex items-center justify-center mt-4">
            <x-button class="ml-4 text-center">
                {{ __('Register') }}
            </x-button>
        </div>
    </form>
</x-guest-layout>