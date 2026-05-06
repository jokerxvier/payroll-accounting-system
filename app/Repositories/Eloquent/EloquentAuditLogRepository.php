<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Pas\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Support\Collection;

final class EloquentAuditLogRepository implements AuditLogRepositoryInterface
{
    public function recentForAuditableTypes(?array $types, int $limit): Collection
    {
        // [] means "match nothing" — short-circuit before issuing a useless
        // `whereIn` against an empty list (whose semantics differ slightly
        // across drivers). null means "no filter".
        if ($types === []) {
            return new Collection;
        }

        $query = AuditLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($types !== null) {
            $query->whereIn('auditable_type', $types);
        }

        return $query->get();
    }
}
