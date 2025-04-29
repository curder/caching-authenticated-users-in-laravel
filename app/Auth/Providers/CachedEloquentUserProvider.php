<?php

namespace App\Auth\Providers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class CachedEloquentUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return cache()->remember('user_'.$identifier, now()->addDay(), function () use ($identifier) {
            return parent::retrieveById($identifier);
        });
    }
}
