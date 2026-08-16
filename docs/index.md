# CakePHP Queue Plugin

- [Introduction](#introduction)
- [Quickstart](#quickstart)
    - [Installing the Plugin](#installing-the-plugin)
    - [Dispatchable Job](#dispatchable-job)
- [Dispatchable Jobs](#dispatchable-jobs)
    - [DispatchableInterface and Trait](#dispatchableinterface-and-trait)
    - [ConfigurableInterface](#configurableinterface)
    - [TaggableInterface](#taggableinterface)
    - [SyncSuppressibleInterface](#syncsuppressibleinterface)
- [Command Bus](#command-bus)
    - [Commands](#commands)
    - [Registering Handlers](#registering-handlers)
    - [Dispatching](#dispatching)
    - [Attribute Discovery](#attribute-discovery)
- [Sync Mode](#sync-mode)
- [Events](#events)
    - [Plugin Events](#plugin-events)
    - [Data Mutators](#data-mutators)
    - [Application Emitters](#application-emitters)

<a name="introduction"></a>
## Introduction

CakePHP Queue (`crustum/cakephp-queue`, namespace `Crustum\Queue`) wraps [cakephp/queue](https://book.cakephp.org/queue/2/) with helpers for self-dispatching jobs, static queue configuration, tags, optional **sync (in-process) dispatch**, and dispatch lifecycle events.

Jobs can call `::dispatch()` or `::dispatchLater()` through `DispatchableTrait`, optionally define their own queue settings with `getQueueConfig()`, and attach tags via `createTags()`. Before enqueue, `JobDataMutators::prepare()` can inject application fields into job data (for example Speculum’s `speculum_uuid`). Every dispatch then fires `Crustum/Queue.Job.pending` then `Crustum/Queue.Job.pushed`. When sync mode handles a job, push is skipped and the job runs in-process via Cake Queue `Processor` (same DI and `Processor.message.*` events as a worker). Applications may register an extra emitter with `JobDispatchEmitters::set()` for their own side effects without replacing those events.
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

Publish optional plugin config (requires `crustum/plugin-manifest` in the application):

```bash
bin/cake manifest install --plugin Crustum/Queue
```

That copies `config/crustum_queue.php` into the application. Sync defaults:

```php
// config/crustum_queue.php
'CrustumQueue' => [
    'sync' => filter_var(env('CRUSTUM_QUEUE_SYNC', false), FILTER_VALIDATE_BOOLEAN),
    'syncOnly' => [
        // \App\Job\CriticalPathJob::class,
    ],
],
```

The plugin bootstrap loads `CONFIG/crustum_queue.php` when present, otherwise the plugin’s own `config/crustum_queue.php`.

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
4. Runs data mutators
5. Emits pending → `QueueManager::push` → emits pushed  
   **or** (sync): pending → pushed (`sync => true`) → `SyncJobRunner` (skip push)

`dispatchLater()` always stays async (delay is never executed in-process).

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

<a name="syncsuppressibleinterface"></a>
### SyncSuppressibleInterface

When global sync is on, implement this to keep a job on the broker:

```php
use Crustum\Queue\Job\SyncSuppressibleInterface;

class HeavyReportJob implements JobInterface, DispatchableInterface, SyncSuppressibleInterface
{
    use DispatchableTrait;

    public static function suppressSync(array $data = []): bool
    {
        return true;
    }

    // execute(...)
}
```

<a name="command-bus"></a>
## Command Bus

The plugin provides a `CommandBus` facade for dispatching typed command objects to handler jobs. Commands are lightweight DTOs that carry the intent and payload; the bus maps a command class to its handler job class and delegates to the handler's static dispatch — sync/async is decided by `CrustumQueue` as usual.

The command DTO is a **leaf**: it implements `CommandMessage` and knows nothing about jobs, the queue, or the bus. Dependencies flow **downward only** — app code → command → bus → job → queue, and nothing points back up. So:

- **App code never imports job classes.** It depends only on the command DTO and the bus, so swapping a handler job for another (or a different queue backend) does not touch callers.
- **Commands are plain data.** They are safe to construct, serialize, and pass around anywhere; they carry the *intent*, not the execution.
- **The bus owns the mapping.** The command does not need to know which job runs it, and the job's `#[Handles]` attribute keeps the command class itself dependency-free.

<a name="commands"></a>
### Commands

Implement `Crustum\Queue\CommandMessage` on a DTO to make it dispatchable:

```php
use Crustum\Queue\CommandMessage;

class SendWelcomeEmailCommand implements CommandMessage
{
    public function __construct(
        public readonly int $userId,
        public readonly string $locale = 'en',
    ) {
    }

    public function payload(): array
    {
        return ['user_id' => $this->userId, 'locale' => $this->locale];
    }

    public static function fromPayload(array $data): static
    {
        return new self(
            userId: (int)($data['user_id'] ?? 0),
            locale: (string)($data['locale'] ?? 'en'),
        );
    }
}
```

`payload()` produces the JSON-safe body for the queue; `fromPayload()` rebuilds the command in the handler's `execute()`.

<a name="registering-handlers"></a>
### Registering Handlers

Register a command→job pair either manually or via attributes:

```php
use Crustum\Queue\CommandBus;

CommandBus::map(SendWelcomeEmailCommand::class, SendWelcomeEmailJob::class);
```

<a name="dispatching"></a>
### Dispatching

```php
CommandBus::dispatch(new SendWelcomeEmailCommand(userId: 42));
CommandBus::dispatchLater(new SendWelcomeEmailCommand(userId: 43, locale: 'fr'), 30);
```

`dispatch()` delegates to the handler job's `::dispatch()`, so sync mode runs the job in-process and async mode pushes to the broker — identical to direct `SendWelcomeEmailJob::dispatch()`.

Both accept queue `$overrides` (e.g. `['sync' => true]`) that are forwarded to the handler's static dispatch. Dispatching a command with no registered handler throws a `RuntimeException` (never a bare undefined-key notice).

<a name="attribute-discovery"></a>
### Attribute Discovery

Instead of a manual map, mark the handler job with `#[Handles]` and discover all handlers from your Job folders:

```php
use Crustum\Queue\CommandMessage;
use Crustum\Queue\Handles;

#[Handles(SendWelcomeEmailCommand::class)]
class SendWelcomeEmailJob implements JobInterface, DispatchableInterface
{
    use DispatchableTrait;

    public function execute(Message $message): ?string
    {
        $command = SendWelcomeEmailCommand::fromPayload($message->getArgument());
        // $command->userId, $command->locale …
        return Processor::ACK;
    }
}
```

Then build the map in bootstrap:

```php
CommandBus::registerFromAttributes();
```

Discovery scans the app's Job folder (and each loaded plugin's Job folder) via the attribute resolver (`crustum/cakephp-attribute-resolver`); vendor is excluded. `registerFromAttributes()` is idempotent — calling it twice rebuilds the same map. Manual `map()` calls remain available as a fallback or for cases outside the scanned scope. The scan is customizable via `$paths`, `$basePath`, and `$excludePaths` (defaults: `['Job/*.php']`, `ROOT/src`, `['vendor', 'tests', 'build', 'tmp']`).

<a name="sync-mode"></a>
## Sync Mode

Application-wide in-process execution without a worker. Default is **off** (async).

| Control | Role |
|---------|------|
| `CrustumQueue.sync` / `CRUSTUM_QUEUE_SYNC` | Master switch — when false, `SyncDispatchListener` is not attached |
| `CrustumQueue.syncOnly` | Optional allow-list of job classes (empty = all eligible) |
| `SyncSuppressibleInterface` | Per-job hard opt-out |

Resolution order: suppress → global off → allow-list miss → sync.

Lifecycle under sync: **pending → pushed (`sync => true`) → SyncJobRunner** (`Processor.message.*`, app DI via `ContainerRegistry`). Reject / requeue / exceptions surface to the caller (no auto-reenqueue). Unique / delay / expires are no-ops in sync; delayed dispatch stays async. Other pending listeners (decorators, emitters) still run whether sync is on or off.

Do not catch `SyncDispatchHandledException` in application code — it is an internal signal absorbed by `DispatchableTrait`.

<a name="events"></a>
## Events

<a name="plugin-events"></a>
### Plugin Events

Every `::dispatch()` always emits:

| Event | When |
|-------|------|
| `Crustum/Queue.Job.pending` | Before accept (push or sync) |
| `Crustum/Queue.Job.pushed` | After accept — after push, or before sync execute (`options.sync` / `$config['sync']`) |

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

<a name="data-mutators"></a>
### Data Mutators

Register callbacks that run after tags/`_uniqueId` and **before** pending events + push/sync:

```php
use Crustum\Queue\Event\JobDataMutators;

JobDataMutators::register(function (string $jobClass, array $data, array $config): array {
    $data['speculum_uuid'] = $uuid;

    return $data;
});
```

`JobDataMutators::clear()` removes all mutators (tests).

Dispatch order: **data mutators** → plugin pending → application pending → (async push **or** sync: pushed then runner). On sync, application pending still runs before the trait catches the internal signal.

<a name="application-emitters"></a>
### Application Emitters

Register an **additive** application emitter for app-specific side effects. Plugin events still fire first:

```php
use Crustum\Queue\Event\JobDispatchEmitterInterface;
use Crustum\Queue\Event\JobDispatchEmitters;

JobDispatchEmitters::set(new AppJobDispatchEmitter());
```

`JobDispatchEmitters::set(null)` or `clear()` removes the application emitter only.

Implement `JobDispatchEmitterInterface`:

- `emitPending(string $jobClass, array $data, array $config): void`
- `emitPushed(string $jobClass, array $data, array $config): void`
- `buildPayload(string $jobClass, array $data): array`

Application emitters must not swallow sync control-flow exceptions if they wrap pending emission; `JobDispatchEmitters` rethrows `SyncDispatchHandledException` after the application pending hook.
