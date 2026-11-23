<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            $excluded = array_merge(['password', 'password_confirmation', 'token', 'secret', 'api_key'], array_keys($request->allFiles()));
            $metadata = array_diff_key($request->all(), array_flip($excluded));
            [$entityType, $entityId] = $this->target($request);
            AuditLog::create(['admin_id' => $request->user()?->id, 'action' => strtolower($request->method()), 'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $request->method().' '.$request->path(), 'metadata' => $metadata, 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip()]);
        }

        return $response;
    }

    /** @return array{0: string, 1: int|null} */
    private function target(Request $request): array
    {
        // "api/v1/admin/products/5/publish" → ["products", 5]
        $segments = explode('/', (string) preg_replace('#^api/v1/admin/?#', '', $request->path()));
        $entityId = null;
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            $key = $parameter instanceof Model ? $parameter->getKey() : $parameter;
            if (is_numeric($key)) {
                $entityId = (int) $key;
                break;
            }
        }

        return [$segments[0] ?? '', $entityId];
    }
}
