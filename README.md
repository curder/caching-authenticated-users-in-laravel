# 在 Laravel 中缓存已认证用户

在高流量的 Laravel 应用中，为了获得更好的性能提升，可以通过缓存已认证用户的信息来避免频繁的数据库查询。本文将详细介绍如何实现这一功能，以及在实现过程中需要注意的一些关键点。

## 背景

在 Laravel 应用中，每个需要认证的页面请求或 API 请求都会执行类似下面的数据库查询来获取最新的用户信息：

```sql
select *
from `users`
where `id` = 1 limit 1
```

目前，Laravel 并没有提供自动缓存用户对象的功能。这意味着只要用户保持登录状态，每个请求都会执行这个查询。

考虑到用户信息在请求之间不太可能频繁变化，对于高流量应用来说，在数据发生变化之前缓存这些信息是很有意义的。

## Laravel 认证提供者工作原理

默认情况下，Laravel 使用 `EloquentUserProvider` 来管理已认证用户。

这个类包含了许多有用的方法，如：

- `retrieveById`
- `rehashPasswordIfRequired`
- `validateCredentials`

这些方法负责处理与认证相关的用户获取和更新操作。

可以通过以下方式添加新的认证提供者：

```php
Auth::provider('customProvider', function (Application $app, array $config) {
    // 返回自定义提供者
});
```

## 创建自定义认证提供者

让我们创建一个支持缓存的认证提供者。首先，创建一个名为 `CachedEloquentUserProvider` 的类：

```php
namespace App\Auth\Providers;

use Illuminate\Auth\EloquentUserProvider;

class CachedEloquentUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return cache()->remember(
            'user_' . $identifier,
            now()->addHours(2),
            fn () => parent::retrieveById($identifier)
        );
    }
}
```

然后在 `AppServiceProvider` 的 `boot` 方法中注册这个提供者：

```php
use Illuminate\Foundation\Application;

public function boot(): void
{
    Auth::provider('cachedEloquent', function (Application $app, array $config) {
        return new CachedEloquentUserProvider(
            $app['hash'],
            $config['model']
        );
    });
}
```

最后，在 `config/auth.php` 中启用这个新的驱动：

```php
'providers' => [
    'users' => [
        'driver' => 'cachedEloquent',
        'model' => env('AUTH_MODEL', App\Models\User::class),
    ],
],
```

## 缓存配置

为了避免缓存机制反而造成新的数据库查询，建议将缓存驱动配置为 Redis：

```env
SESSION_DRIVER=redis
CACHE_STORE=redis
```

## 处理缓存失效

当用户信息发生变化时，我们需要使缓存失效。可以通过创建一个用户观察者来实现这一点：

```php
php artisan make:observer UserObserver
```

在观察者中添加更新和删除事件的处理：

```php
class UserObserver
{
    public function updated(User $user)
    {
        cache()->forget('user_' . $user->id);
    }

    public function deleted(User $user)
    {
        cache()->forget('user_' . $user->id);
    }
}
```

然后在 User 模型中注册这个观察者：

```php
use App\Observers\UserObserver;

#[ObservedBy(UserObserver::class)]
class User extends Authenticatable
{
    // ...
}
```

## 注意事项

在实现用户缓存时，需要注意以下几点：

1. 使用 `DB` 门面直接更新用户数据不会触发 Eloquent 事件，因此缓存不会自动失效
2. 数据库中的手动更新也不会触发缓存失效
3. 某些不需要立即反映在前端的字段更新（如 `email_verified_at`）也会导致缓存失效
4. 队列任务中的频繁用户更新可能会导致缓存频繁失效，降低缓存的效果

## 总结

缓存认证用户可以显著提升应用性能，但这并不是一个简单的"即插即用"的解决方案。在实施过程中，需要仔细考虑：

- 缓存失效的时机
- 数据一致性的保证
- 缓存更新的频率
- 边缘情况的处理

通过合理的实现和配置，我们可以在保证数据一致性的同时，显著减少数据库查询，提升应用性能。

## 参考资料

- [Caching Authenticated Users in Laravel - Codecourse](https://codecourse.com/articles/caching-authenticated-users-in-laravel)
