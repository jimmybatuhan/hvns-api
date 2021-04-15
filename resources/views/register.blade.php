<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        <div class="mt-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-label for="first_name" value="First Name" />
                    <x-input
                        id="first_name"
                        class="block mt-1 w-full"
                        type="text"
                        name="first_name"
                        :value="old('first_name')"
                        required
                    />
                    @if ($errors->has('first_name'))
                        <div class="mt-3 d-block text-sm text-red-600">
                            {{ $errors->first('first_name') }}
                        </div>
                    @endif
                </div>
                <div>
                    <x-label for="last_name" value="Last Name" />
                    <x-input
                        id="last_name"
                        class="block mt-1 w-full"
                        type="text"
                        name="last_name"
                        :value="old('last_name')"
                        required
                    />
                    @if ($errors->has('last_name'))
                        <div class="mt-3 d-block text-sm text-red-600">
                            {{ $errors->first('last_name') }}
                        </div>
                    @endif
                </div>
            </div>
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
            <x-label for="mobile" value="Mobile No." />
            <x-input
                id="mobile"
                class="block mt-1 w-full"
                type="text"
                name="mobile"
                :value="old('mobile')"
                required
            />
            @if ($errors->has('mobile'))
                <div class="mt-3 d-block text-sm text-red-600">
                    {{ $errors->first('mobile') }}
                </div>
            @endif
        </div>

        <div class="mt-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-label for="birthday" value="Birthday" />
                    <x-input
                        id="birthday"
                        class="block mt-1 w-full"
                        type="text"
                        name="birthday"
                        :value="old('birthday')"
                        required
                    />
                    @if ($errors->has('birthday'))
                        <div class="mt-3 d-block text-sm text-red-600">
                            {{ $errors->first('birthday') }}
                        </div>
                    @endif
                </div>
                <div>
                    <x-label for="gender" value="Gender" />
                    <x-input
                        id="gender"
                        class="block mt-1 w-full"
                        type="text"
                        name="gender"
                        :value="old('gender')"
                        required
                    />
                    @if ($errors->has('gender'))
                        <div class="mt-3 d-block text-sm text-red-600">
                            {{ $errors->first('gender') }}
                        </div>
                    @endif
                </div>
            </div>
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