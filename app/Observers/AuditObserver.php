<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\BranchProduct;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditObserver
{
    public function created(Model $model): void
    {
        $this->log($model, 'created', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        $previous = Arr::only($model->getPrevious(), array_keys($changes));

        if ($changes === [] || $this->isStockOnlyChange($model, $changes)) {
            return;
        }

        $this->log($model, 'updated', $previous, $changes);
    }

    /**
     * Stock changes from sales, purchases and vouchers are already recorded, with their document and user,
     * in the immutable stock_movements ledger; a second audit row per sold item only slows down saving.
     *
     * @param  array<string, mixed>  $changes
     */
    private function isStockOnlyChange(Model $model, array $changes): bool
    {
        return $model instanceof BranchProduct && array_diff(array_keys($changes), [
            'stock_quantity', 'stock_source_type', 'stock_source_id', 'stock_source_reference',
            'stock_operation_uuid', 'stock_actor_id', 'updated_at',
        ]) === [];
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', $model->getAttributes(), null);
    }

    public function restored(Model $model): void
    {
        $this->log($model, 'restored', null, $model->getAttributes());
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function log(Model $model, string $action, ?array $oldValues, ?array $newValues): void
    {
        $request = request();
        $user = auth()->user();
        $isSystemAdmin = $user?->isSaaSAdmin() ?? false;
        $session = $request->hasSession() ? $request->session() : null;
        $sensitiveKeys = collect(array_merge(array_keys($oldValues ?? []), array_keys($newValues ?? [])))
            ->filter(fn (string $key): bool => Str::contains(Str::lower($key), [
                'password',
                'secret',
                'token',
                'recovery',
                'api_key',
                'private_key',
                'remember',
                'credential',
                'cvv',
                'pin',
                'card_number',
            ]))
            ->all();

        AuditLog::create([
            'tenant_id' => $isSystemAdmin ? null : ($session?->get('active_tenant_id')
                ?: $user?->tenant_id
                ?: $model->getAttribute('tenant_id')
                ?: ($model instanceof Tenant ? $model->getKey() : null)),
            'branch_id' => $isSystemAdmin ? null : ($session?->get('active_branch_id') ?: $user?->branch_id ?: $model->getAttribute('branch_id')),
            'user_id' => $user?->id,
            'action' => $action,
            'module' => class_basename($model),
            'description' => match ($action) {
                'created' => 'تم إنشاء سجل جديد',
                'updated' => 'تم تعديل سجل',
                'deleted' => 'تم حذف سجل',
                'restored' => 'تم استعادة سجل',
                default => 'تم تنفيذ عملية على سجل',
            },
            'subject_type' => get_class($model),
            'subject_id' => $model->getKey(),
            'old_values' => $this->filterValues($oldValues, $sensitiveKeys),
            'new_values' => $this->filterValues($newValues, $sensitiveKeys),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @param  array<int, string>  $sensitiveKeys
     * @return array<string, mixed>|null
     */
    private function filterValues(?array $values, array $sensitiveKeys): ?array
    {
        return $values === null ? null : Arr::except($values, $sensitiveKeys);
    }
}
