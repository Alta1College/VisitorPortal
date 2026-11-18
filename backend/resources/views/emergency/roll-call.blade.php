<x-app-layout>
    <x-slot name="header">
        <h1 class="text-4xl font-bold leading-none tracking-tight text-base-content lg:text-5xl">{{ __('Anwesenheitskontrolle') }}</h1>
    </x-slot>

    <livewire:emergency.roll-call-page :roll-call="$rollCall" />
</x-app-layout>
