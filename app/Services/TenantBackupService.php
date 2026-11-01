<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class TenantBackupService
{
    private int $exportBytes = 0;

    private string $exportDirectory = '';

    public function __construct(private TenantBackupSchema $schema) {}

    public function directory(int $tenantId): string
    {
        $path = storage_path('app/private/tenant-backups/'.$tenantId);
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    public function path(int $tenantId, string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('معرّف النسخة غير صالح.');
        }

        return $this->directory($tenantId).'/'.$id.'.zip';
    }

    /** @return array<string, mixed> */
    public function create(Tenant $tenant, string $kind = 'manual'): array
    {
        return Cache::lock('tenant-backup-'.$tenant->id, 1800)->block(5, fn () => $this->export($tenant, $kind));
    }

    /** @return array<string, mixed> */
    private function export(Tenant $tenant, string $kind): array
    {
        $this->exportBytes = 0;
        $id = (string) Str::uuid();
        $path = $this->path($tenant->id, $id);
        $temporary = $this->directory($tenant->id).'/'.$id;
        File::ensureDirectoryExists($temporary, 0700);
        $this->exportDirectory = $temporary;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('تعذر إنشاء ملف النسخة.');
        }
        $zip->setPassword($this->key());
        $opened = true;
        $tables = $this->schema->tables();
        $manifest = ['format' => 'waslapos-tenant-v1', 'id' => $id, 'kind' => $kind, 'tenant_id' => $tenant->id, 'tenant_name' => $tenant->name,
            'created_at' => now()->toIso8601String(), 'driver' => DB::getDriverName(), 'schema' => $this->fingerprint($tables), 'tables' => [], 'files' => []];
        try {
            $level = DB::transactionLevel();
            DB::transaction(function () use ($tenant, $tables, &$manifest, $temporary, $zip, $level): void {
                if ($level === 0 && DB::getDriverName() === 'pgsql') {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                $manifest['tenant'] = (array) DB::table('tenants')->where('id', $tenant->id)->first();
                foreach ($tables as $name => $spec) {
                    $file = $temporary.'/'.$name.'.jsonl';
                    $stream = fopen($file, 'wb');
                    if (! is_resource($stream)) {
                        throw new RuntimeException('تعذر كتابة بيانات النسخة.');
                    }
                    $count = 0;
                    try {
                        $query = $this->schema->scope($name, $tenant->id, $tables);
                        foreach ($spec['primary'] as $column) {
                            $query->orderBy($name.'.'.$column);
                        }
                        foreach ($query->cursor() as $record) {
                            $row = (array) $record;
                            $this->collectFiles($row, $manifest, $zip);
                            $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                            if (fwrite($stream, $line) !== strlen($line)) {
                                throw new RuntimeException('لا توجد مساحة كافية للنسخة.');
                            }
                            $count++;
                        }
                    } finally {
                        fclose($stream);
                    }
                    $entry = 'tables/'.$name.'.jsonl';
                    $this->addFile($zip, $file, $entry);
                    $manifest['tables'][$name] = ['entry' => $entry, 'count' => $count, 'sha256' => hash_file('sha256', $file)];
                }
            });
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            foreach (['manifest.json' => $json, 'signature' => hash_hmac('sha256', $json, $this->key())] as $entry => $content) {
                if (! $zip->addFromString($entry, $content) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    throw new RuntimeException('تعذر حماية ملف النسخة.');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('تعذر إكمال النسخة.');
            }
            $opened = false;
            if (filesize($path) > 104857600) {
                throw new RuntimeException('حجم النسخة يتجاوز 100 MB.');
            }
            chmod($path, 0600);

            return $manifest;
        } catch (Throwable $error) {
            if ($opened) {
                try {
                    $zip->close();
                } catch (Throwable) {
                    // Keep the original failure and remove the incomplete archive.
                }
            }
            File::delete($path);
            throw $error;
        } finally {
            File::deleteDirectory($temporary);
        }
    }

    /** @return array<string, mixed> */
    public function inspect(string $path, Tenant $tenant): array
    {
        $zip = $this->open($path);
        try {
            $manifest = $this->manifest($zip, $tenant);
            $tables = $this->schema->tables();
            if ($manifest['driver'] !== DB::getDriverName() || $manifest['schema'] !== $this->fingerprint($tables) || array_keys($manifest['tables']) !== array_keys($tables)) {
                throw new RuntimeException('إصدار قاعدة البيانات مختلف عن النسخة. يلزم إصدار متوافق للاستعادة.');
            }
            $allowed = ['manifest.json', 'signature'];
            $identities = [];
            $references = [];
            $referenceColumns = [];
            foreach ($tables as $spec) {
                foreach ($spec['keys'] as $key) {
                    if (isset($tables[$key['foreign_table']])) {
                        $referenceColumns[$key['foreign_table']][json_encode($key['foreign_columns'], JSON_THROW_ON_ERROR)] = $key['foreign_columns'];
                    }
                }
            }
            foreach ($tables as $name => $spec) {
                $metadata = $manifest['tables'][$name];
                if ($metadata['entry'] !== 'tables/'.$name.'.jsonl') {
                    throw new RuntimeException('جدول غير صالح في النسخة.');
                }
                $allowed[] = $metadata['entry'];
                $count = 0;
                foreach ($this->rows($zip, $metadata['entry'], $metadata['sha256']) as $row) {
                    if (array_keys($row) !== $spec['columns'] || (isset($row['tenant_id']) && (int) $row['tenant_id'] !== $tenant->id)) {
                        throw new RuntimeException('سجل لا ينتمي لهذا المتجر أو أعمدة غير متوافقة.');
                    }
                    if ($name === 'users' && $row['type'] !== 'tenant_user') {
                        throw new RuntimeException('لا يمكن استعادة حساب مسؤول المنصة ضمن متجر.');
                    }
                    $identity = $this->identity($row, $spec['primary']);
                    if (isset($identities[$name][$identity])) {
                        throw new RuntimeException('سجلات مكررة في النسخة.');
                    }
                    $identities[$name][$identity] = true;
                    foreach ($referenceColumns[$name] ?? [] as $label => $columns) {
                        $references[$name][$label][$this->identity($row, $columns)] = true;
                    }
                    $count++;
                }
                if ($count !== $metadata['count']) {
                    throw new RuntimeException('عدد سجلات النسخة غير صحيح.');
                }
            }
            foreach ($manifest['files'] as $original => $metadata) {
                if (! $this->safePath($original) || ! preg_match('~^uploads/[a-f0-9]{64}$~D', $metadata['entry'])) {
                    throw new RuntimeException('مسار ملف غير صالح في النسخة.');
                }
                $allowed[] = $metadata['entry'];
                $this->verifyEntry($zip, $metadata['entry'], $metadata['sha256']);
            }
            if ($zip->numFiles !== count($allowed)) {
                throw new RuntimeException('النسخة تحتوي ملفات غير معروفة.');
            }
            foreach ($tables as $name => $spec) {
                foreach ($this->rows($zip, $manifest['tables'][$name]['entry']) as $row) {
                    $this->validateReferences($row, $spec, $tables, $references, $tenant->id);
                }
            }
            $owner = $manifest['tenant']['owner_id'] ?? null;
            if ($owner !== null && ! isset($identities['users'][json_encode([(string) $owner], JSON_THROW_ON_ERROR)])) {
                throw new RuntimeException('حساب مالك المتجر غير موجود في النسخة.');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed> */
    public function restore(string $path, Tenant $tenant): array
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('الاستعادة الآمنة متاحة حالياً على PostgreSQL.');
        }

        return Cache::lock('tenant-backup-'.$tenant->id, 1800)->block(5, function () use ($path, $tenant): array {
            $manifest = $this->inspect($path, $tenant);
            $safety = $this->export($tenant, 'before-restore');
            $tables = $this->schema->tables();
            $order = $this->schema->order($tables);
            $zip = $this->open($path);
            $fileRoot = 'tenant-restores/'.$tenant->id.'/'.Str::uuid();
            $remap = [];
            try {
                foreach ($manifest['files'] as $original => $metadata) {
                    $destination = $fileRoot.'/'.$metadata['sha256'].'.'.(pathinfo($original, PATHINFO_EXTENSION) ?: 'bin');
                    $stream = $zip->getStream($metadata['entry']);
                    if (! is_resource($stream)) {
                        throw new RuntimeException('تعذر قراءة ملف من النسخة.');
                    }
                    try {
                        if (! Storage::disk('public')->put($destination, $stream)) {
                            throw new RuntimeException('تعذر استعادة الملفات المرفوعة.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    $remap[$original] = $destination;
                }
                DB::transaction(function () use ($tenant, $tables, $order, $manifest, $zip, $remap): void {
                    if (DB::getDriverName() === 'pgsql') {
                        $grammar = DB::connection()->getQueryGrammar();
                        $locks = array_map(fn ($table) => $grammar->wrapTable($table), array_keys($tables));
                        DB::statement('LOCK TABLE '.$grammar->wrapTable('tenants').', '.implode(', ', $locks).' IN SHARE ROW EXCLUSIVE MODE');
                    }
                    // Re-check under write locks; no archived ID may replace another tenant's row.
                    $this->inspect($zip->filename, $tenant);
                    $this->assertNoIdentityCollisions($zip, $manifest, $tables, $tenant->id);
                    $this->schema->assertNoExternalReferences($tenant->id, $tables);
                    $guards = $this->schema->suspendGuards();
                    $users = DB::table('users')->where('tenant_id', $tenant->id)->pluck('id');
                    DB::table('password_reset_tokens')->whereIn('email', DB::table('users')->where('tenant_id', $tenant->id)->select('email'))->delete();
                    foreach ($this->rows($zip, $manifest['tables']['users']['entry']) as $user) {
                        DB::table('password_reset_tokens')->where('email', $user['email'])->delete();
                    }
                    DB::table('sessions')->whereIn('user_id', $users)->delete();
                    DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $users)->delete();
                    if (Schema::hasTable('passkeys')) {
                        DB::table('passkeys')->whereIn('user_id', $users)->delete();
                    }
                    // Null optional cycles before removing children and then parents.
                    foreach ($order as $name) {
                        $nulls = $this->nullableLinks($tables[$name], $tables);
                        if ($nulls !== []) {
                            $this->schema->scope($name, $tenant->id, $tables)->update($nulls);
                        }
                    }
                    foreach (array_reverse($order) as $name) {
                        $this->schema->scope($name, $tenant->id, $tables)->delete();
                    }
                    foreach ($order as $name) {
                        $spec = $tables[$name];
                        $batch = [];
                        $size = max(1, min(200, intdiv(900, count($spec['columns']))));
                        foreach ($this->rows($zip, $manifest['tables'][$name]['entry']) as $row) {
                            $row = $this->remap($row, $remap);
                            if ($name === 'users') {
                                $row['remember_token'] = null;
                            }
                            if ($name === 'branch_sync_devices') {
                                $row['is_active'] = false;
                                $row['token_hash'] = hash('sha256', Str::random(64));
                            }
                            $batch[] = array_replace($row, $this->nullableLinks($spec, $tables));
                            if (count($batch) >= $size) {
                                DB::table($name)->insert($batch);
                                $batch = [];
                            }
                        }
                        if ($batch !== []) {
                            DB::table($name)->insert($batch);
                        }
                    }
                    foreach ($order as $name) {
                        $columns = array_keys($this->nullableLinks($tables[$name], $tables));
                        if ($columns === []) {
                            continue;
                        }
                        $batch = [];
                        $size = max(1, min(200, intdiv(900, count($tables[$name]['columns']))));
                        foreach ($this->rows($zip, $manifest['tables'][$name]['entry']) as $row) {
                            $batch[] = $this->remap($row, $remap);
                            if (count($batch) >= $size) {
                                DB::table($name)->upsert($batch, $tables[$name]['primary'], $columns);
                                $batch = [];
                            }
                        }
                        if ($batch !== []) {
                            DB::table($name)->upsert($batch, $tables[$name]['primary'], $columns);
                        }
                    }
                    DB::table('tenants')->where('id', $tenant->id)->update(['name' => $manifest['tenant']['name'], 'phone' => $manifest['tenant']['phone'], 'owner_id' => $manifest['tenant']['owner_id']]);
                    foreach ($guards as $definition) {
                        DB::connection()->getPdo()->exec($definition);
                    }
                    if (DB::getDriverName() === 'pgsql') {
                        foreach ($tables as $name => $spec) {
                            if ($spec['primary'] !== ['id']) {
                                continue;
                            }
                            $sequence = DB::scalar("SELECT pg_get_serial_sequence(?, 'id')", [$name]);
                            if ($sequence) {
                                $wrapped = DB::connection()->getQueryGrammar()->wrapTable($name);
                                DB::select('SELECT setval(CAST(? AS regclass), GREATEST((SELECT COALESCE(MAX(id),0) FROM '.$wrapped.'), nextval(CAST(? AS regclass))), true)', [$sequence, $sequence]);
                            }
                        }
                    }
                });

                request()->attributes->remove('tenant_settings_values_'.$tenant->id);

                return $safety;
            } catch (Throwable $error) {
                Storage::disk('public')->deleteDirectory($fileRoot);
                throw $error;
            } finally {
                $zip->close();
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(Tenant $tenant): array
    {
        $items = [];
        foreach (File::glob($this->directory($tenant->id).'/*.zip') as $path) {
            try {
                $zip = $this->open($path);
                try {
                    $items[] = $this->manifest($zip, $tenant);
                } finally {
                    $zip->close();
                }
            } catch (Throwable) {
                continue;
            }
        }
        usort($items, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return array_slice($items, 0, 20);
    }

    /** @param array<string, mixed> $manifest */
    private function collectFiles(mixed $value, array &$manifest, ZipArchive $zip): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectFiles($item, $manifest, $zip);
            }
        } elseif (is_string($value)) {
            if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $this->collectFiles($decoded, $manifest, $zip);
                }
            } elseif ($this->safePath($value) && str_contains($value, '.') && ! isset($manifest['files'][$value])) {
                $disk = Storage::disk('public');
                $absolute = realpath($disk->path($value));
                $root = realpath($disk->path(''));
                if ($absolute && $root && str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) && is_file($absolute)) {
                    $entry = 'uploads/'.hash('sha256', $value);
                    $copy = $this->exportDirectory.'/file-'.hash('sha256', $value);
                    if (! File::copy($absolute, $copy)) {
                        throw new RuntimeException('تعذر نسخ ملف مرفوع.');
                    }
                    $this->addFile($zip, $copy, $entry);
                    $manifest['files'][$value] = ['entry' => $entry, 'sha256' => hash_file('sha256', $copy)];
                }
            }
        }
    }

    private function addFile(ZipArchive $zip, string $path, string $entry): void
    {
        $size = filesize($path);
        if ($size === false || ($this->exportBytes += $size) > 536870912) {
            throw new RuntimeException('حجم البيانات يتجاوز الحد المسموح للنسخة.');
        }
        if (! $zip->addFile($path, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
            throw new RuntimeException('تعذر إضافة بيانات النسخة وحمايتها.');
        }
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('ملف النسخة غير قابل للقراءة.');
        }
        $zip->setPassword($this->key());
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if ($entry === false) {
                $zip->close();
                throw new RuntimeException('ملف ZIP تالف.');
            }
            $total += $entry['size'];
            if ($total > 536870912 || $zip->numFiles > 20000) {
                $zip->close();
                throw new RuntimeException('حجم النسخة يتجاوز الحد المسموح.');
            }
        }

        return $zip;
    }

    /** @return array<string, mixed> */
    private function manifest(ZipArchive $zip, Tenant $tenant): array
    {
        $stat = $zip->statName('manifest.json');
        if (! $stat || $stat['size'] > 4194304) {
            throw new RuntimeException('ملف النسخة غير صالح.');
        }
        $json = $zip->getFromName('manifest.json');
        $signature = $zip->getFromName('signature', 128);
        if (! is_string($json) || ! is_string($signature) || ! hash_equals(hash_hmac('sha256', $json, $this->key()), $signature)) {
            throw new RuntimeException('توقيع النسخة غير صالح أو أنها من خادم آخر.');
        }
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? '') !== 'waslapos-tenant-v1' || ($manifest['tenant_id'] ?? null) !== $tenant->id || ($manifest['tenant']['id'] ?? null) !== $tenant->id) {
            throw new RuntimeException('هذه النسخة لا تخص المتجر المحدد.');
        }

        return $manifest;
    }

    /** @return Generator<int, array<string, mixed>> */
    private function rows(ZipArchive $zip, string $entry, ?string $hash = null): Generator
    {
        $stream = $zip->getStream($entry);
        if (! is_resource($stream)) {
            throw new RuntimeException('بيانات جدول مفقودة أو تالفة.');
        }
        $digest = hash_init('sha256');
        try {
            while (($line = fgets($stream, 16777217)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('سجل غير صالح في النسخة.');
                }
                hash_update($digest, $line);
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($row)) {
                    throw new RuntimeException('سجل غير صالح في النسخة.');
                }
                yield $row;
            }
            if (! feof($stream) || ($hash !== null && ! hash_equals($hash, hash_final($digest)))) {
                throw new RuntimeException('بيانات النسخة معدلة أو تالفة.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function verifyEntry(ZipArchive $zip, string $entry, string $expected): void
    {
        $stream = $zip->getStream($entry);
        if (! is_resource($stream)) {
            throw new RuntimeException('ملف مرفوع مفقود من النسخة.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            if (! feof($stream) || ! hash_equals($expected, hash_final($hash))) {
                throw new RuntimeException('ملف مرفوع تالف في النسخة.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $row
     * @param  list<string>  $columns
     */
    private function identity(array $row, array $columns): string
    {
        return json_encode(array_map(fn ($column) => (string) $row[$column], $columns), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $spec
     * @param  array<string, array<string, mixed>>  $tables
     * @return array<string, null>
     */
    private function nullableLinks(array $spec, array $tables): array
    {
        $columns = [];
        foreach ($spec['keys'] as $key) {
            if (isset($tables[$key['foreign_table']])) {
                foreach (array_intersect($key['columns'], $spec['nullable']) as $column) {
                    $columns[$column] = null;
                }
            }
        }

        return $columns;
    }

    /** @param array<string, mixed> $row
     * @param  array<string, mixed>  $spec
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<string, array<string, array<string, bool>>>  $identities
     */
    private function validateReferences(array $row, array $spec, array $tables, array $identities, int $tenantId): void
    {
        foreach ($spec['keys'] as $key) {
            $values = array_map(fn ($column) => $row[$column], $key['columns']);
            if (in_array(null, $values, true)) {
                continue;
            }
            $value = $values[0];
            $parent = $key['foreign_table'];
            if ($parent === 'tenants') {
                if ((int) $value !== $tenantId) {
                    throw new RuntimeException('علاقة بمتجر آخر في النسخة.');
                }
            } elseif (isset($tables[$parent])) {
                $identity = json_encode(array_map(fn ($item) => (string) $item, $values), JSON_THROW_ON_ERROR);
                if (isset($identities[$parent][json_encode($key['foreign_columns'], JSON_THROW_ON_ERROR)][$identity])) {
                    continue;
                }
                $query = DB::table($parent);
                foreach ($key['foreign_columns'] as $index => $column) {
                    $query->where($column, $values[$index]);
                }
                $global = in_array('tenant_id', $tables[$parent]['columns'], true)
                    && $query->whereNull('tenant_id')->exists();
                if (! $global) {
                    throw new RuntimeException('مرجع مفقود أو تابع لمتجر آخر: '.$parent);
                }
            } elseif (! DB::table($parent)->where($key['foreign_columns'][0], $value)->exists()) {
                throw new RuntimeException('بيانات مشتركة غير متوافقة مع النسخة.');
            }
        }
    }

    /** @param array<string, mixed> $manifest
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function assertNoIdentityCollisions(ZipArchive $zip, array $manifest, array $tables, int $tenantId): void
    {
        foreach ($tables as $name => $spec) {
            if ($spec['primary'] !== ['id']) {
                continue;
            }
            $batch = [];
            $check = function (array $ids) use ($name, $tenantId, $tables): void {
                $existing = DB::table($name)->whereIn('id', $ids)->pluck('id')->all();
                $owned = $this->schema->scope($name, $tenantId, $tables)->whereIn($name.'.id', $ids)->pluck($name.'.id')->all();
                if (array_diff($existing, $owned) !== []) {
                    throw new RuntimeException('رقم سجل مستخدم خارج المتجر؛ أُلغيت الاستعادة.');
                }
            };
            foreach ($this->rows($zip, $manifest['tables'][$name]['entry']) as $row) {
                $batch[] = $row['id'];
                if (count($batch) === 500) {
                    $check($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $check($batch);
            }
        }
    }

    /** @param array<string, string> $mapping */
    private function remap(mixed $value, array $mapping): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->remap($item, $mapping), $value);
        }
        if (is_string($value)) {
            if (isset($mapping[$value])) {
                return $mapping[$value];
            }
            if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return json_encode($this->remap($decoded, $mapping), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return $value;
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && ! str_contains($path, '..') && ! str_contains($path, ':') && ! str_contains($path, '\\') && ! str_starts_with($path, '/') && ! str_contains($path, "\0");
    }

    /** @param array<string, array<string, mixed>> $tables */
    private function fingerprint(array $tables): string
    {
        return hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR));
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('مفتاح حماية النظام غير مضبوط.');
        }

        return hash_hmac('sha256', 'waslapos-tenant-backup-v1', $key);
    }
}
