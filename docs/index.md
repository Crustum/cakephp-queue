# CakePHP Queue Plugin

- [Introduction](#introduction)
- [Quickstart](#quickstart)
    - [Installing the Plugin](#installing-the-plugin)
    - [Dispatchable Job](#dispatchable-job)
- [Dispatchable Jobs](#dispatchable-jobs)
    - [DispatchableInterface and Trait](#dispatchableinterface-and-trait)
    - [ConfigurableInterface](#configurableinterface)
    - [TaggableInterface](#taggableinterface)
- [Events](#events)
    - [Plugin Events](#plugin-events)
    - [Host Emitters](#host-emitters)

<a name="introduction"></a>
## Introduction

CakePHP Queue (`crustum/cakephp-queue`, namespace `Crustum\Queue`) wraps [cakephp/queue](https://book.cakephp.org/queue/2/) with helpers for self-dispatching jobs, static queue configuration, tags, and dispatch lifecycle events.

Jobs can call `::dispatch()` or `::dispatchLater()` through `DispatchableTrait`, optionally define their own queue settings with `getQueueConfig()`, and attach tags via `createTags()`. Every dispatch fires `Crustum/Queue.Job.pending` then `Crustum/Queue.Job.pushed`. Applications may register an extra emitter with `JobDispatchEmitters::set()` for their own side effects without replacing those events.
<a name="quickstart"></a>
## Quickstart

<a name="installing-the-plugin"></a>
### Installing the Plugin

Install via Composer:

```bash
composer require crustum/cakephp-queue
```

> [!NOTE]
> This plugin should be registered in your `config/plugins.php` file.

```bash
bin/cake plugin load Crustum/Queue
```

Alternatively, load the plugin in `config/plugins.php`:

```php
'Crustum/Queue' => [
    'bootstrap' => true,
    'routes' => false,
],
```

Or in `Application.php`:

```php
// In src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Crustum/Queue');
}
```

You must also configure [cakephp/queue](https://book.cakephp.org/queue/2/) (`Queue` config + workers) as usual.

<a name="dispatchable-job"></a>
### Dispatchable Job

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Interop\Queue\Processor;

class ExampleJob implements JobInterface, DispatchableInterface
{
    use DispatchableTrait;

    public function execute(Message $message): ?string
    {
        return Processor::ACK;
    }
}

ExampleJob::dispatch(['id' => 1]);
ExampleJob::dispatchLater(['id' => 1], 30);
```

<a name="dispatchable-jobs"></a>
## Dispatchable Jobs

<a name="dispatchableinterface-and-trait"></a>
### DispatchableInterface and Trait

Implement `Crustum\Queue\Job\DispatchableInterface` and use `DispatchableTrait` on a `Cake\Queue\Job\JobInterface` class.

`dispatch()`:

1. Resolves queue options (defaults or `getQueueConfig()`)
2. Merges tags (payload + `TaggableInterface` + job class)
3. Ensures `_uniqueId` on the payload
4. Emits pending → `QueueManager::push` → emits pushed

<a name="configurableinterface"></a>
### ConfigurableInterface

Implement `ConfigurableInterface` and define static configuration on the job:

```php
use Crustum\Queue\Job\ConfigurableInterface;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;

class EmbedJob implements JobInterface, DispatchableInterface, ConfigurableInterface
{
    use DispatchableTrait;

    public static ?int $maxAttempts = 3;

    public static function getQueueConfig(): array
    {
        return [
            'config' => 'default',
            'queue' => 'default',
            'maxAttempts' => self::$maxAttempts ?? 3,
            'retryDelay' => 60,
        ];
    }

    // execute(...)
}
```

Named CakePHP Queue configs must exist under `Configure` `Queue.{name}` and be registered with `QueueManager` (via the Cake/Queue plugin bootstrap).

<a name="taggableinterface"></a>
### TaggableInterface

```php
use Crustum\Queue\Job\TaggableInterface;

class TaggedJob implements JobInterface, DispatchableInterface, TaggableInterface
{
    use DispatchableTrait;

    public static function createTags(array $data): array
    {
        $package = $data['package'] ?? null;

        return is_string($package) ? ['package:' . $package] : [];
    }

    // execute(...)
}
```

Tags from the payload, `createTags()`, and the job class name are merged uniquely into `$data['tags']`.

<a name="events"></a>
## Events

<a name="plugin-events"></a>
### Plugin Events

Every `::dispatch()` always emits:

| Event | When |
|-------|------|
| `Crustum/Queue.Job.pending` | Before `QueueManager::push` |
| `Crustum/Queue.Job.pushed` | After `QueueManager::push` |

Event classes: `JobPendingEvent`, `JobPushedEvent`. Listen via CakePHP `EventManager`:

```php
use Cake\Event\EventManager;

EventManager::instance()->on(
    'Crustum/Queue.Job.pending',
    function ($event): void {
        // $event->getPayload(), getConnection(), getQueue(), …
    },
);
```

Default emission uses `DefaultJobDispatchEmitter` / `EventDispatcher`.

<a name="host-emitters"></a>
### Host Emitters

Register an **additive** host emitter for app-specific side effects. Plugin events still fire first:

```php
use Crustum\Queue\Event\JobDispatchEmitterInterface;
use Crustum\Queue\Event\JobDispatchEmitters;

JobDispatchEmitters::set(new AppJobDispatchEmitter());
```

Order: plugin pending → host pending → push → plugin pushed → host pushed.

`JobDispatchEmitters::set(null)` or `clear()` removes the host emitter only.

Implement `JobDispatchEmitterInterface`:

- `emitPending(string $jobClass, array $data, array $config): void`
- `emitPushed(string $jobClass, array $data, array $config): void`
- `buildPayload(string $jobClass, array $data): array`
