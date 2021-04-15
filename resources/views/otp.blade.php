<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        <!-- First Name -->
        <div class="mt-4">
            <x-label for="otp" value="Enter the one-time password sent to your number" />
            <x-input
                id="otp"
                class="block mt-1 w-full"
                type="text" name="otp"
                :value="old('otp')"
                required
            />
            @if ($errors->has('otp'))
                <div class="mt-3 d-block text-sm text-red-600">
                    {{ $errors->first('otp') }}
                </div>
            @endif
        </div>
        <div class="flex items-center justify-center mt-4">
            <x-button class="ml-4 text-center">
                {{ __('Verify OTP') }}
            </x-button>
        </div>
    </form>
</x-guest-layout>