@if (request()->is('admin*'))
    <x-layouts::app.saas-sidebar :title="$title ?? null">
        <flux:main>
            {{ $slot }}
        </flux:main>
    </x-layouts::app.saas-sidebar>
@else
    <x-layouts::app.sidebar :title="$title ?? null">
        <flux:main>
            {{ $slot }}
        </flux:main>
    </x-layouts::app.sidebar>
@endif
