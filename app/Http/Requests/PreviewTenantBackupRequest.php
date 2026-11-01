<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;

class PreviewTenantBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $tenant = $this->route('tenant');

        return $tenant instanceof Tenant && $user && ($user->isSaaSAdmin() || ($user->isTenantOwner() && $user->tenant_id === $tenant->id)) && $user->can('backup.restore');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['backup' => ['required', 'file', 'extensions:zip', 'max:102400']];
    }
}
