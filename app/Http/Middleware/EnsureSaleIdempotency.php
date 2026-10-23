<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaleIdempotency
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->filled('operation_uuid')) {
            return $next($request);
        }

        $request->validate(['operation_uuid' => ['required', 'uuid'], 'branch_id' => ['required', 'integer']]);
        $user = $request->user();
        $payload = $this->canonicalize($request->all());
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $next, $user, $hash): Response {
            // Serialize each tenant's keyed financial writes, including the first use of a UUID.
            Tenant::query()->whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('api_sale_operations')
                ->where('tenant_id', $user->tenant_id)
                ->where('operation_uuid', $request->string('operation_uuid')->toString())->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id
                    && (int) $existing->branch_id === $request->integer('branch_id')
                    && hash_equals($existing->request_hash, $hash), 409, 'معرّف العملية مستخدم لطلب مختلف.');

                return response($existing->response_body, $existing->status_code)
                    ->header('Content-Type', 'application/json')->header('Idempotency-Replayed', 'true');
            }

            $response = $next($request);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new HttpResponseException($response);
            }
            DB::table('api_sale_operations')->insert([
                'tenant_id' => $user->tenant_id, 'user_id' => $user->id,
                'branch_id' => $request->integer('branch_id'),
                'operation_uuid' => $request->string('operation_uuid')->toString(),
                'request_hash' => $hash, 'status_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $response;
        });
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
