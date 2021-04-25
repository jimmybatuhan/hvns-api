<x-guest-layout>

    @if (!isset($success) || !$success)
        <p>Customer Record does not exist</p>
    @else
        <form method="POST" action="{{ route('customer-update') }}">
            <div class="mt-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-label for="first_name" value="First Name" />
                        <x-input
                            id="first_name"
                            class="block mt-1 w-full"
                            type="text"
                            name="first_name"
                            value="{{ $customer['first_name'] }}"
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
                            value="{{ $customer['last_name'] }}"
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
                    value="{{ $customer['email'] }}"
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
                    value="{{ $customer['phone'] }}"
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
                            value="{{ $customer['birthday'] }}"
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
                            value="{{ $customer['gender'] }}"
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

            <div class="flex items-center justify-center mt-4">
                <x-input
                    id="shopify_customer_id"
                    type="hidden"
                    name="shopify_customer_id"
                    value="{{ $customer['shopify_customer_id'] }}"
                />
                <x-button class="ml-4 text-center">
                    {{ __('Update') }}
                </x-button>
            </div>
        </form>
    @endif
</x-guest-layout>
