<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HasTenant
{
    public static bool $bypassTenantScope = false;

    public static function bootHasTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (static::$bypassTenantScope) {
                return;
            }

            if (auth()->check() && auth()->user()->tenant_id) {
                $builder->where(
                    $builder->getModel()->getTable() . '.tenant_id',
                    auth()->user()->tenant_id,
                );
            }
        });

        static::creating(function (Model $model) {
            if (! $model->tenant_id && auth()->check() && auth()->user()->tenant_id) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    /**
     * Run a callback without tenant scoping. Admin cross-tenant queries only.
     */
    public static function bypassTenantScope(callable $callback): mixed
    {
        static::$bypassTenantScope = true;

        try {
            return $callback();
        } finally {
            static::$bypassTenantScope = false;
        }
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    /**
     * Resolve route model binding without the tenant scope so
     * TenantIsolation middleware can return 403 (not 404) on mismatch.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQueryWithoutScope('tenant')
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }
}
