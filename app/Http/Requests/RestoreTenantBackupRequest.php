<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestoreTenantBackupRequest extends FormRequest
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
        $tenant = $this->route('tenant');

        return ['token' => ['required', 'uuid'], 'confirmation' => ['required', 'string', Rule::in($tenant instanceof Tenant ? [$tenant->name] : [])], 'acknowledge' => ['accepted']];
    }
}
