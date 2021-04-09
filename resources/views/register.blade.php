<x-guest-layout>
    <!-- Validation Errors -->
    <x-auth-validation-errors class="mb-4" :errors="$errors" />
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <!-- First Name -->
        <div>
            <x-label for="first_name" value="First Name" />
            <x-input
                id="first_name"
                class="block mt-1 w-full"
                type="text" name="first_name"
                :value="old('first_name')"
                required autofocus
            />
        </div>

        <!-- Last Name -->
        <div class="mt-4">
            <x-label for="last_name" value="Last Name" />
            <x-input
                id="last_name"
                class="block mt-1 w-full"
                type="text" name="last_name"
                :value="old('last_name')"
                required autofocus
            />
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

        <div class="flex items-center justify-end mt-4">
            <x-button class="ml-4">
                {{ __('Register') }}
            </x-button>
        </div>
    </form>
</x-guest-layout>