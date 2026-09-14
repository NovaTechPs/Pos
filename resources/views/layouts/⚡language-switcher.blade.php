<?php

use Livewire\Component;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

new class extends Component {
    public function switchLanguage($locale)
    {
        if (in_array($locale, ['ar', 'en'])) {
            Session::put('locale', $locale);
            App::setLocale($locale);

            return redirect(request()->header('Referer') ?? route('dashboard'));
        }
    }
}; ?>

<div>
    <flux:dropdown position="top" align="start" class="w-full">
        <flux:button icon="language" variant="subtle" class="w-full justify-start">
            {{ app()->getLocale() == 'ar' ? 'العربية' : 'English' }}
        </flux:button>
        <flux:menu>
            <flux:menu.item wire:click="switchLanguage('ar')" class="cursor-pointer">العربية (AR)</flux:menu.item>
            <flux:menu.item wire:click="switchLanguage('en')" class="cursor-pointer">English (EN)</flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
