<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component {
}; ?>

<section class="w-full">
    @include('pages.settings.partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div class="space-y-5">
            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
                <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
            </flux:radio.group>

            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm leading-6 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                ألوان النظام تُدار من إعدادات المتجر وتُطبّق على جميع مستخدميه وفروعه.
                @if (Auth::user()?->hasPermission('store.settings.manage'))
                    <a href="{{ route('store.settings') }}" wire:navigate class="ms-1 font-bold text-indigo-700 underline dark:text-indigo-300">
                        إعدادات المتجر / Store settings
                    </a>
                @endif
            </div>
        </div>
    </x-pages::settings.layout>
</section>
