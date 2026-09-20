<?php

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new class extends Component {
    public bool $isExporting = false;
    public string $message = '';

    /**
     * إنشاء النسخة الاحتياطية وتنزيلها مباشرة
     */
    public function downloadBackup(): ?BinaryFileResponse
    {
        $this->isExporting = true;

        try {
            $timestamp = date('Y-m-d_H-i-s');
            $backupDir = storage_path('app/backups');

            if (!file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            // 1. تصدير قاعدة البيانات (تلقائياً حسب نوعها)
            $sqlFile = $backupDir . "/db_backup_{$timestamp}.sql";
            $this->dumpDatabase($sqlFile);

            // 2. إنشاء ملف ZIP يجمع قاعدة البيانات مع ملفات الـ Uploads
            $zipFile = $backupDir . "/backup_{$timestamp}.zip";
            $zip = new ZipArchive();

            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                // إضافة ملف الـ SQL
                if (file_exists($sqlFile)) {
                    $zip->addFile($sqlFile, "database/db_backup_{$timestamp}.sql");
                }

                // إضافة ملفات الـ Public Uploads (إن وجدت)
                $uploadsPath = public_path('uploads'); // تعديل المسار حسب مشروعك
                if (file_exists($uploadsPath)) {
                    $this->addFolderToZip($uploadsPath, $zip, 'uploads');
                }

                $zip->close();
            }

            // مؤقت: حذف ملف الـ SQL المفرط للحفاظ على المساحة
            if (file_exists($sqlFile)) {
                unlink($sqlFile);
            }

            $this->message = 'تم إنشاء النسخة الاحتياطية بنجاح!';
            $this->isExporting = false;

            // تنزيل الملف للمستخدم لتخزينه على الفلاشة
            return response()->download($zipFile)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            $this->isExporting = false;
            $this->message = 'حدث خطأ أثناء أخذ النسخة الاحتياطية: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * استخراج قاعدة البيانات (PostgreSQL أو MySQL)
     */
    private function dumpDatabase(string $outputFile): void
    {
        $driver = config('database.default');
        $host = config("database.connections.{$driver}.host");
        $port = config("database.connections.{$driver}.port");
        $database = config("database.connections.{$driver}.database");
        $username = config("database.connections.{$driver}.username");
        $password = config("database.connections.{$driver}.password");

        if ($driver === 'mysql') {
            // 1. تحديد مسار MySQL تلقائياً في wamp64
            // يمكنك تغيير التخليص أو رقم الإصدار إذا كان مختلفاً لديك
            $mysqlVersion = 'mysql8.0.31'; // 👈 تحقق من رقم إصدار MySQL في وامب لديك
            $dumpBinary = "C:\\wamp64\\bin\\mysql\\{$mysqlVersion}\\bin\\mysqldump.exe";

            // إذا لم يجد المسار المحدد، يبحث عن أي نسخة mysqldump داخل wamp64
            if (!file_exists($dumpBinary)) {
                $globSearch = glob('C:/wamp64/bin/mysql/mysql*/bin/mysqldump.exe');
                if (!empty($globSearch)) {
                    $dumpBinary = $globSearch[0];
                } else {
                    $dumpBinary = 'mysqldump.exe'; // محاولة أخيرة عبر النظام
                }
            }

            // إعداد أجزاء الأمر
            $passwordParam = !empty($password) ? '-p' . escapeshellarg($password) : '';

            $command = sprintf('%s -h %s -P %s -u %s %s %s > %s', escapeshellarg($dumpBinary), escapeshellarg($host), escapeshellarg($port), escapeshellarg($username), $passwordParam, escapeshellarg($database), escapeshellarg($outputFile));
        } elseif ($driver === 'pgsql') {
            // 2. إذا كنت تستخدم PostgreSQL على WampServer
            $pgVersion = 'postgresql15'; // 👈 رقم إصدار PostgreSQL في حال كنت مجمعه مع وامب
            $dumpBinary = "C:\\wamp64\\bin\\postgresql\\{$pgVersion}\\bin\\pg_dump.exe";

            if (!file_exists($dumpBinary)) {
                $globSearch = glob('C:/wamp64/bin/postgresql/postgresql*/bin/pg_dump.exe');
                if (!empty($globSearch)) {
                    $dumpBinary = $globSearch[0];
                } else {
                    // مسار PostgreSQL الافتراضي على ويندوز إذا تم تثبيته بشكل مستقل
                    $dumpBinary = 'C:\\Program Files\\PostgreSQL\\15\\bin\\pg_dump.exe';
                }
            }

            putenv("PGPASSWORD={$password}");

            $command = sprintf('%s -h %s -p %s -U %s %s > %s', escapeshellarg($dumpBinary), escapeshellarg($host), escapeshellarg($port), escapeshellarg($username), escapeshellarg($database), escapeshellarg($outputFile));
        } else {
            throw new \Exception("نوع قاعدة البيانات ($driver) غير مدعوم.");
        }

        // تنفيذ الأمر مع التقاط أي خطأ ناتج من الويندوز
        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \Exception('فشل تنفيذ الأمر: ' . implode("\n", $output));
        }
    }

    /**
     * دالة مساعدة لإضافة المجلدات داخل ZIP
     */
    private function addFolderToZip(string $folderPath, ZipArchive $zip, string $zipPath = ''): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath), RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $zipPath . '/' . substr($filePath, strlen($folderPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
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
                اضغط على الزر لتوليد ملف مضغوط يحتوي على قاعدة البيانات والملفات المرفوعة لتنزيله ونقله إلى **الفلاشة**.
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
