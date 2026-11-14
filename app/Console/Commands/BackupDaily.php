<?php

namespace App\Console\Commands;

use App\Services\OffsiteBackup;
use App\Services\TelegramAlert;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backup:daily')]
#[Description('Upload the daily backup to the backup disk, check the uploaded copy can be restored, and report the result on Telegram.')]
class BackupDaily extends Command
{
    public const DIRECTORY = 'daily-backups';

    public function handle(OffsiteBackup $backup, TelegramAlert $telegram): int
    {
        if (! $backup->isExternal()) {
            $this->warn('Backup disk "'.$backup->diskName().'" is not external storage; set BACKUP_DISK=s3 for daily backups.');
            $telegram->send('⚠️ النسخة الاحتياطية اليومية لم تُنفَّذ: مكان الحفظ غير مضبوط (BACKUP_DISK).');

            return self::FAILURE;
        }

        try {
            $name = $backup->upload(self::DIRECTORY);
            $check = $backup->verify($name);
            $backup->prune(self::DIRECTORY, (int) config('backup.daily_keep'));
        } catch (Throwable $exception) {
            $this->error('Daily backup failed: '.$exception->getMessage());
            $telegram->send("❌ فشلت النسخة الاحتياطية اليومية.\n".$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $size = number_format($check['bytes'] / 1048576, 1);
        $this->info("Daily backup uploaded and verified: {$name} ({$size} MB, {$check['tables']} tables)");
        $telegram->send("✅ تمت النسخة الاحتياطية اليومية ونجح فحص الاستعادة.\n{$name}\nالحجم: {$size} MB — الجداول: {$check['tables']}");

        return self::SUCCESS;
    }
}
