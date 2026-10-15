<x-layouts::tenant :title="__('Dashboard')">
    <style>
        @keyframes dashboard-float {
            0%, 100% { transform: translate3d(0, 0, 0) rotate(0deg); }
            50% { transform: translate3d(0, -14px, 0) rotate(4deg); }
        }

        @keyframes dashboard-orbit {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes dashboard-glow {
            0%, 100% { opacity: .45; }
            50% { opacity: .85; }
        }

        .dashboard-float { animation: dashboard-float 7s ease-in-out infinite; }
        .dashboard-orbit { animation: dashboard-orbit 24s linear infinite; }
        .dashboard-glow { animation: dashboard-glow 5s ease-in-out infinite; }
        .dashboard-stat-card { transform: perspective(1000px) translateZ(0); transition: transform .35s ease, box-shadow .35s ease; }
        .dashboard-stat-card:hover { transform: perspective(1000px) translateY(-6px) rotateX(2deg) translateZ(12px); }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-float, .dashboard-orbit, .dashboard-glow { animation: none; }
            .dashboard-stat-card { transition: none; }
        }
    </style>

    <flux:main>
        <div class="min-h-[calc(100vh-5rem)] overflow-hidden bg-slate-50 px-4 py-6 dark:bg-zinc-950 sm:px-6 lg:px-8">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
                <section class="relative isolate overflow-hidden rounded-[2rem] bg-gradient-to-br from-indigo-950 via-indigo-800 to-violet-700 px-6 py-8 text-white shadow-2xl shadow-indigo-950/20 sm:px-10 sm:py-10 lg:px-12">
                    <div class="absolute -end-16 -top-28 size-80 rounded-full bg-fuchsia-400/20 blur-3xl dashboard-glow"></div>
                    <div class="absolute -bottom-32 start-1/3 size-72 rounded-full bg-cyan-300/20 blur-3xl dashboard-glow"></div>

                    <div class="dashboard-orbit pointer-events-none absolute -end-16 top-1/2 hidden size-80 -translate-y-1/2 rounded-full border border-white/10 lg:block">
                        <div class="absolute start-8 top-12 size-5 rounded-full bg-cyan-200 shadow-[0_0_32px_8px_rgba(103,232,249,.6)]"></div>
                    </div>

                    <div class="relative grid items-center gap-10 lg:grid-cols-[1fr_auto]">
                        <div class="max-w-2xl">
                            <span class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold tracking-wide text-indigo-100 backdrop-blur">
                                <span class="size-2 rounded-full bg-emerald-300 shadow-[0_0_12px_rgba(110,231,183,.9)]"></span>
                                {{ __('Your store at a glance') }}
                            </span>

                            <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">
                                {{ __('Welcome to your dashboard') }}
                            </h1>
                            <p class="mt-4 max-w-xl text-sm leading-7 text-indigo-100/85 sm:text-base">
                                {{ __('A clear view of today’s activity, with your most important tools close at hand.') }}
                            </p>

                            <div class="mt-7 flex flex-wrap gap-3">
                                @can('pos.view')
                                    <a href="{{ route('tenant.pos') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-indigo-800 shadow-lg shadow-indigo-950/20 transition hover:-translate-y-0.5 hover:bg-indigo-50">
                                        <flux:icon.calculator class="size-4" />
                                        {{ __('Open point of sale') }}
                                    </a>
                                @endcan

                                @can('sales_invoices.view')
                                    <a href="{{ route('tenant.sale-invoices') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-5 py-3 text-sm font-semibold text-white backdrop-blur transition hover:-translate-y-0.5 hover:bg-white/15">
                                        <flux:icon.receipt-percent class="size-4" />
                                        {{ __('Browse invoices') }}
                                    </a>
                                @endcan
                            </div>
                        </div>

                        <div class="dashboard-float relative mx-auto hidden h-56 w-56 place-items-center sm:grid lg:me-8">
                            <div class="absolute inset-4 rotate-12 rounded-[2.5rem] border border-white/20 bg-white/5 shadow-2xl shadow-black/20 backdrop-blur-sm"></div>
                            <div class="absolute inset-9 -rotate-12 rounded-[2rem] border border-cyan-100/30 bg-gradient-to-br from-cyan-200/25 to-indigo-300/10 shadow-xl backdrop-blur-md"></div>
                            <div class="relative grid size-28 rotate-6 place-items-center rounded-[1.8rem] border border-white/30 bg-gradient-to-br from-white/30 to-white/5 shadow-[0_25px_60px_rgba(8,10,50,.4)] backdrop-blur-xl">
                                <flux:icon.chart-bar-square class="size-12 text-cyan-100 drop-shadow-lg" />
                            </div>
                            <span class="absolute end-3 top-7 size-4 rounded-full bg-amber-300 shadow-[0_0_28px_8px_rgba(252,211,77,.45)]"></span>
                            <span class="absolute bottom-7 start-3 size-3 rounded-full bg-fuchsia-300 shadow-[0_0_24px_7px_rgba(240,171,252,.5)]"></span>
                        </div>
                    </div>

                    <div class="pointer-events-none absolute -bottom-3 end-12 h-1 w-40 rounded-full bg-gradient-to-r from-transparent via-cyan-200/70 to-transparent blur-sm"></div>
                </section>

                <section aria-label="{{ __('Today at a glance') }}" class="grid auto-rows-fr gap-4 md:grid-cols-3">
                    <article class="dashboard-stat-card group relative isolate overflow-hidden rounded-2xl border border-indigo-100 bg-white p-5 shadow-sm shadow-indigo-950/5 dark:border-indigo-900/60 dark:bg-zinc-900 sm:p-6">
                        <div class="absolute -end-8 -top-10 -z-10 size-32 rounded-full bg-indigo-100/80 blur-2xl transition group-hover:bg-indigo-200 dark:bg-indigo-500/10"></div>
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-500 dark:text-zinc-400">{{ __('Total sales today') }}</p>
                                <p class="mt-3 font-mono text-3xl font-black tracking-tight text-slate-900 dark:text-white">₪0.00</p>
                            </div>
                            <span class="grid size-12 place-items-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-600/25 transition group-hover:rotate-6 group-hover:scale-110">
                                <flux:icon.banknotes class="size-6" />
                            </span>
                        </div>
                        <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-indigo-50 dark:bg-indigo-950">
                            <div class="h-full w-2/3 rounded-full bg-gradient-to-r from-indigo-500 to-violet-400"></div>
                        </div>
                    </article>

                    <article class="dashboard-stat-card group relative isolate overflow-hidden rounded-2xl border border-emerald-100 bg-white p-5 shadow-sm shadow-emerald-950/5 dark:border-emerald-900/60 dark:bg-zinc-900 sm:p-6">
                        <div class="absolute -end-8 -top-10 -z-10 size-32 rounded-full bg-emerald-100/80 blur-2xl transition group-hover:bg-emerald-200 dark:bg-emerald-500/10"></div>
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-500 dark:text-zinc-400">{{ __('Invoices today') }}</p>
                                <p class="mt-3 font-mono text-3xl font-black tracking-tight text-slate-900 dark:text-white">0</p>
                            </div>
                            <span class="grid size-12 place-items-center rounded-2xl bg-emerald-500 text-white shadow-lg shadow-emerald-500/25 transition group-hover:-rotate-6 group-hover:scale-110">
                                <flux:icon.receipt-percent class="size-6" />
                            </span>
                        </div>
                        <div class="mt-5 flex items-center gap-1.5">
                            <span class="h-2 w-8 rounded-full bg-emerald-500"></span>
                            <span class="h-2 w-5 rounded-full bg-emerald-300"></span>
                            <span class="h-2 w-3 rounded-full bg-emerald-200 dark:bg-emerald-900"></span>
                            <span class="ms-auto text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ __('Live today') }}</span>
                        </div>
                    </article>

                    <article class="dashboard-stat-card group relative isolate overflow-hidden rounded-2xl border border-amber-100 bg-white p-5 shadow-sm shadow-amber-950/5 dark:border-amber-900/60 dark:bg-zinc-900 sm:p-6">
                        <div class="absolute -end-8 -top-10 -z-10 size-32 rounded-full bg-amber-100/80 blur-2xl transition group-hover:bg-amber-200 dark:bg-amber-500/10"></div>
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-500 dark:text-zinc-400">{{ __('Register status') }}</p>
                                <div class="mt-3 flex items-center gap-2.5">
                                    <span class="relative flex size-3">
                                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-40 motion-reduce:animate-none"></span>
                                        <span class="relative inline-flex size-3 rounded-full bg-emerald-500"></span>
                                    </span>
                                    <span class="text-xl font-black tracking-tight text-slate-900 dark:text-white">{{ __('Ready for sales') }}</span>
                                </div>
                            </div>
                            <span class="grid size-12 place-items-center rounded-2xl bg-amber-400 text-amber-950 shadow-lg shadow-amber-400/25 transition group-hover:rotate-6 group-hover:scale-110">
                                <flux:icon.sparkles class="size-6" />
                            </span>
                        </div>
                        <div class="mt-5 flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-zinc-400">
                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">{{ __('System active') }}</span>
                            <span>{{ __('Everything is ready') }}</span>
                        </div>
                    </article>
                </section>

                <section class="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                    <div class="absolute -bottom-20 -end-12 size-52 rounded-full border-[24px] border-violet-50 dark:border-violet-950/40"></div>
                    <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[.2em] text-violet-600 dark:text-violet-400">{{ __('Your workspace') }}</p>
                            <h2 class="mt-2 text-xl font-black text-slate-900 dark:text-white">{{ __('Everything in one place') }}</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 dark:text-zinc-400">{{ __('Use the navigation to manage your store, inventory, team, and reports.') }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2" aria-hidden="true">
                            <span class="size-3 rounded-full bg-indigo-500"></span>
                            <span class="size-5 rounded-full bg-cyan-400"></span>
                            <span class="size-8 rounded-full bg-emerald-400"></span>
                            <span class="size-5 rounded-full bg-amber-300"></span>
                            <span class="size-3 rounded-full bg-fuchsia-400"></span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </flux:main>
</x-layouts::tenant>
