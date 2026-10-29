<tr>@foreach ($columns as $key => $label)<td>{{ \App\Services\ReportingService::formatCell($key, $row->{$key} ?? null) }}</td>@endforeach</tr>
