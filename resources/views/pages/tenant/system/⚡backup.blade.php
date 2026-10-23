<?php

use App\Services\SystemBackupService;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new class extends Component {
    public bool $isExporting = false;
    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSaaSAdmin(), 403);
    }

    public function downloadBackup(SystemBackupService $backups): ?BinaryFileResponse
    {
        abort_unless(auth()->user()?->isSaaSAdmin(), 403);
        $this->authorize('backup.create');
        $this->isExporting = true;
        try {
            $path = $backups->create();
            return response()->download($path, 'system-backup-'.now()->format('Y-m-d-His').'.zip')->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            report($exception);
            $this->message = 'تعذر إنشاء النسخة الاحتياطية. راجع مسؤول النظام.';
            return null;
        } finally {
            $this->isExporting = false;
        }
    }

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>
<flux:main >

    <div class="p-6 bg-white dark:bg-gray-800 rounded-lg shadow-md max-w-md mx-auto">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">النسخ الاحتياطي (Backup)</h2>

        @if ($message)
            <div class="mb-4 p-3 text-sm text-green-700 bg-green-100 rounded-lg dark:bg-green-200 dark:text-green-800">
                {{ $message }}
            </div>
        @endif

        <div class="flex flex-col items-center gap-4">
            <p class="text-sm text-gray-600 dark:text-gray-400 text-center">
                نسخة كاملة للنظام تشمل جميع المتاجر والملفات المرفوعة، متاحة لإدارة النظام فقط.
            </p>

            <button wire:click="downloadBackup" wire:loading.attr="disabled"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow transition duration-150 ease-in-out disabled:opacity-50 flex items-center gap-2">
                <span wire:loading.remove wire:target="downloadBackup">
                    💾 تحميل النسخة الاحتياطية (ZIP)
                </span>
                <span wire:loading wire:target="downloadBackup" class="flex items-center gap-2">
                    ⏳ جاري إنشاء النسخة...
                </span>
            </button>
        </div>
    </div>
</flux:main>
