<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Select Servers') }}
        </h2>
        <p class="mt-1 text-gray-600 text-sm">
            Choose and configure servers for your applications
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    Hello World - Select Servers Page
                </div>
            </div>
        </div>
    </div>
</x-app-layout>