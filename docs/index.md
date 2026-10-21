# CakePHP Queue Plugin

- [Introduction](#introduction)
- [Quickstart](#quickstart)
    - [Installing the Plugin](#installing-the-plugin)
    - [Dispatchable Job](#dispatchable-job)
- [Dispatchable Jobs](#dispatchable-jobs)
    - [DispatchableInterface and Trait](#dispatchableinterface-and-trait)
    - [DTO / Object Dispatch](#dto-object-dispatch)
    - [Envelope (Metadata)](#envelope-metadata)
    - [ConfigurableInterface](#configurableinterface)
    - [TaggableInterface](#taggableinterface)
    - [SyncSuppressibleInterface](#syncsuppressibleinterface)
- [Command Bus](#command-bus)
    - [Commands](#commands)
    - [Registering Handlers](#registering-handlers)
    - [Dispatching](#dispatching)
    - [Attribute Discovery](#attribute-discovery)
- [Job Middleware](#job-middleware)
    - [Pipeline](#pipeline)
    - [Built-in Middleware](#built-in-middleware)
    - [MiddlewareJob Base Class](#middlewarejob-base-class)
- [Rate Limiting](#rate-limiting)
    - [RateLimiter](#ratelimiter)
    - [Limits](#limits)
    - [Concurrency Limiter](#concurrency-limiter)
- [Callback Processor](#callback-processor)
    - [Completion Callbacks](#completion-callbacks)
    - [Custom Serializers](#custom-serializers)
- [Sync Mode](#sync-mode)
- [Events](#events)
    - [Plugin Events](#plugin-events)
    - [Data Mutators](#data-mutators)
    - [Application Emitters](#application-emitters)

<a name="introduction"></a>
## Introduction

CakePHP Queue (`crustum/cakephp-queue`, namespace `Crustum\Queue`) wraps [cakephp/queue](https://book.cakephp.org/queue/) with helpers for self-dispatching jobs, static queue configuration, tags, optional **sync (in-process) dispatch**, and dispatch lifecycle events.

Jobs can call `::dispatch()` or `::dispatchLater()` through `DispatchableTrait` with an array payload or a DTO/command object, optionally define their own queue settings with `getQueueConfig()`, and attach tags via `createTags()`. The envelope (tags, `_uniqueId`) travels in message metadata, never inside job data. Before enqueue, `JobDataMutators::prepare()` can inject application fields into job data (for example Speculum's `speculum_uuid`). Every dispatch then fires `Crustum/Queue.Job.pending` then `Crustum/Queue.Job.pushed`. When sync mode handles a job, push is skipped and the job runs in-process via Cake Queue `Processor` (same DI and `Processor.message.*` events as a worker). Applications may register an extra emitter with `JobDispatchEmitters::set()` for their own side effects without replacing those events.
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

You must also configure [cakephp/queue](https://book.cakephp.org/queue/) (`Queue` config + workers) as usual.

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

1. Serializes DTO/command objects at entry (data stays pure)
2. Merges tags (`TaggableInterface` + job class) into metadata
3. Ensures `_uniqueId` in metadata (generated before produce)
4. Runs data mutators
5. Emits pending → `QueueManager::push` → emits pushed
   **or** (sync): pending → pushed (`sync => true`) → `SyncJobRunner` (skip push)

`dispatchLater()` always stays async (delay is never executed in-process).

<a name="dto-object-dispatch"></a>
### DTO / Object Dispatch

Pass an array or a DTO/command object — objects are serialized at entry and never mutated:

```php
// Direct job dispatch (plain DTO):
ExampleJob::dispatch(new ExampleDto(id: 7));

// via the bus (command → handler job):
CommandBus::dispatch(new ExampleCommand(id: 7));

// array payload + explicit DTO class also works:
ExampleJob::dispatch(['id' => 7], ['dtoClass' => ExampleDto::class]);
```

The handler restores the typed object from the pure payload:

```php
public function execute(Message $message): ?string
{
    $dto = $message->getDto(ExampleDto::class);

    return Processor::ACK;
}
```

The expected class always comes from job code, never from the message body.

<a name="envelope-metadata"></a>
### Envelope (Metadata)

Tags and `_uniqueId` live in message `metadata`, never inside job data. `_uniqueId` is generated before produce and is the stable instance id used by monitoring to link dispatch ↔ worker regardless of event order.

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

    public static function createTags(array|object $data): array
    {
        $package = is_array($data) ? ($data['package'] ?? null) : null;

        return is_string($package) ? ['package:' . $package] : [];
    }

    // execute(...)
}
```

Tags from `createTags()` and the job class name are merged uniquely into message metadata.

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
}
```

Commands are plain DTOs hydrated by `DtoManager`. The handler restores them with `$message->getDto(SendWelcomeEmailCommand::class)`.

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

`dispatch()` delegates to the handler job's `::dispatch()`, so sync mode runs the job in-process and async mode pushes to the broker — identical to direct `SendWelcomeEmailJob::dispatch()`. The command object is passed through as-is and serialized once at the handler dispatch; it is never cut to an array.

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
        $command = $message->getDto(SendWelcomeEmailCommand::class);
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

<a name="job-middleware"></a>
## Job Middleware

Per-job execution middleware for queue jobs. A job declares a stack, and each middleware either calls `$next($job, $message)` to run the rest of the chain or returns early to short-circuit it. Skipping returns `ACK` without running the job; deferring re-pushes a delayed copy and `ACK`s the current one; failing returns `REJECT` into the regular failed-job path.

The pipeline is enforced by a processor class, not by the jobs themselves. `Crustum\Queue\Processor\MiddlewareProcessor` extends the Cake Queue `Processor` and overrides a single choke point — `processMessage()`. For every message it resolves the job once (container or plain `new`), reads the stack from `MiddlewareAwareInterface::middleware()`, and runs the shared `MiddlewareRunner` core around the job invocation. Consequences of this shape:

- Enforcement is global: any middleware-aware job gets the pipeline with zero per-job boilerplate. Jobs without middleware behave exactly as before (empty stack falls through to the parent call).
- Worker and sync share it: both paths resolve the same `processor` queue config key, so `SyncJobRunner` needs no changes.
- The resolved instance is the executed instance: middleware configures the same object that runs, so injected dependencies never diverge between the stack and `execute()`.

Custom processors (batch, callback-style, domain-specific) reuse the identical core at their own inner-execution point via `RunsMiddlewareTrait` — one pipeline implementation, every entry point covered. See [Pipeline](#pipeline) for wiring, [Built-in Middleware](#built-in-middleware) for the ports, [MiddlewareJob Base Class](#middlewarejob-base-class) for the ergonomic subclass.

<a name="pipeline"></a>
### Pipeline

Implement `Crustum\Queue\Contract\MiddlewareAwareInterface` on the job and return middleware from `middleware()`. The `Message` argument allows argument-aware, container-resolved stacks:

```php
use Cake\Queue\Job\Message;
use Crustum\Queue\Contract\MiddlewareAwareInterface;
use Crustum\Queue\Contract\MiddlewareInterface;
use Crustum\Queue\Middleware\Skip;

class SendEmailJob implements JobInterface, DispatchableInterface, MiddlewareAwareInterface
{
    use DispatchableTrait;

    /**
     * @return list<MiddlewareInterface>
     */
    public function middleware(Message $message): array
    {
        return [
            Skip::when($message->getArgument('dry_run') === true),
        ];
    }

    // execute(...)
}
```

Enable the pipeline with one config line (worker and sync paths both resolve the same key, so no `SyncJobRunner` changes are needed):

```php
'Queue' => ['default' => [
    // ...
    'processor' => \Crustum\Queue\Processor\MiddlewareProcessor::class,
]],
```

Custom processors reuse the same shared core at their inner-execution point via `RunsMiddlewareTrait` (resolve once, then `runWithMiddleware()`), so middleware also applies there. Custom middleware implements `MiddlewareInterface::handle(object $job, Message $message, callable $next): mixed`.

<a name="built-in-middleware"></a>
### Built-in Middleware

- `Middleware\Skip::when()` / `::unless()` — guard; skips without calling `$next` and returns `ACK`. Sync-safe.
- `Middleware\Release::when()` / `::unless()` — conditional deferral; re-pushes a delayed copy via `QueueManager` (Cake `REQUEUE` is delay-less) and `ACK`s the current one. Sync-unsafe: releasing in sync mode throws a descriptive exception, since there is no "later" in-process.
- `Middleware\FailOnException` — fail-fast policy; takes a truth-test callback or an exception class list. Matched exceptions jump the message `attempts` to the job `static::$maxAttempts`, so the regular failed-job path records them without burning the retry budget. Unmatched exceptions propagate untouched. Sync-safe.
- `Middleware\ThrottlesExceptions` — cross-message failure counting with decay and backoff over `RateLimiter`. Over budget defers; success clears the counter; exceptions support report gates, delete-fast (`REJECT`, no failed row), fail-fast (same sink as `FailOnException`), otherwise hit the counter and defer with backoff. Fluent configuration: `setWhen()`, `addDeleteWhen()`, `addFailWhen()`, `setReport()`, `setKey()`, `useJobKey()`, `setPrefix()`, `setBackoff()`, `setLimiter()`. Deferral paths are sync-unsafe.
- `Middleware\WithoutOverlapping` — execution-time mutex on Cake Lock. The first holder runs (released in `try`/`finally`); contention re-pushes with delay, or drops the job with `disableRelease()`. Fluent configuration: `setReleaseAfter()`, `disableRelease()`, `setExpireAfter()`, `setPrefix()`, `setShared()`, plus the lock config name in the constructor. Use a non-file lock backend (Redis, Memcached) for distributed workers; the File engine is single-server and has no TTL expiry.
- `Middleware\RateLimited` — named-limiter execution gate (see [Rate Limiting](#rate-limiting)). Over budget defers (or drops with `disableRelease()`); under budget hits every limit and runs. Unregistered limiter names and `Unlimited` pass through. Deferral paths are sync-unsafe.

<a name="middlewarejob-base-class"></a>
### MiddlewareJob Base Class

Extend `Crustum\Queue\Job\MiddlewareJob` for new jobs instead of wiring the runner by hand. `final execute()` makes the pipeline wrapper unskippable within the hierarchy; subclasses implement `doExecute()` plus optionally `middleware()`:

```php
use Crustum\Queue\Job\MiddlewareJob;

class SendEmailJob extends MiddlewareJob
{
    protected function doExecute(Message $message): ?string
    {
        // real work, no pipeline boilerplate
        return Processor::ACK;
    }

    public function middleware(Message $message): array
    {
        return [new Skip($message->getArgument('dry_run') === true)];
    }
}
```

Inside `execute()`, `$this` is the container-resolved executing instance, so injected dependencies are shared between `middleware()` and `doExecute()` with no extra discipline. Opt-in by inheritance: jobs implementing `JobInterface` directly bypass the pipeline.

<a name="rate-limiting"></a>
## Rate Limiting

Attempt counters with decay windows over Cake Cache (port of `Illuminate\Cache\RateLimiter` plus `Limiters`). Counters need an atomic `increment` — use Redis, Memcached, or APCu; the File engine is read-modify-write and local-dev only (same rule as the queue `uniqueCache`). Timer TTLs come from the cache config `duration`, so it must cover the longest decay used.

<a name="ratelimiter"></a>
### RateLimiter

```php
use Crustum\Queue\Limiter\RateLimiter;

$limiter = new RateLimiter('limiter_cache_config');

$limiter->hit('emails', 60);                    // record one hit, returns the new count
$limiter->isLimited('emails', 100);             // over budget within the window?
$limiter->getAvailableIn('emails');             // seconds until accessible again
$limiter->getRemaining('emails', 100);          // attempts left
$limiter->getAttempts('emails');                // current count
$limiter->clear('emails');                      // reset count and timer
$limiter->resetAttempts('emails');              // reset count only
$limiter->attempt('emails', 100, $callback, 60); // run unless limited (false when limited)
```

Named limiters are registered with resolvers and read back by name. Resolvers receive the job and the message and return a `Limit`, an `Unlimited`, or a list of `Limit` definitions (duplicate keys get collision-free fallback keys):

```php
$limiter->setLimiter('sending', static fn(object $job, Message $message): Limit => Limit::perMinute(100)->setKey('emails'));
$limiter->hasLimiter('sending');   // check registration
$limiter->getLimiter('sending');   // resolver closure (null when unregistered)
```

<a name="limits"></a>
### Limits

`Crustum\Queue\Limiter\Limit` definitions are built with factories and tuned fluently:

```php
use Crustum\Queue\Limiter\Limit;

Limit::perSecond(60);
Limit::perMinute(100)->setKey('emails');
Limit::perMinutes(5, 100);
Limit::perHour(500);
Limit::perDay(1000);
Limit::none(); // Unlimited — always runs
```

`setKey()` scopes the counter, `setAfterCallback()` / `setResponseCallback()` attach HTTP-layer hooks, `getFallbackKey()` derives a collision-free key for duplicated definitions. `GlobalLimit` is a keyless limit; `Unlimited` is a pass-through with `PHP_INT_MAX` attempts.

<a name="concurrency-limiter"></a>
### Concurrency Limiter

Slot-based concurrency caps over Cake Lock (up to N holders at once, slots carry a TTL so crashed holders stop blocking):

```php
use Crustum\Queue\Limiter\ConcurrencyLimiterBuilder;

(new ConcurrencyLimiterBuilder('lock_config', 'sending'))
    ->setMaxLocks(5)
    ->setReleaseAfter(60)
    ->setTimeout(3)
    ->setSleep(250)
    ->then(
        static fn() => sendBatch(),
        static fn($e) => logBusy(),
    );
```

`then()` runs the callback inside a slot and releases it afterwards (also on exception); on timeout it calls the failure callback or throws `LimiterTimeoutException`.

<a name="callback-processor"></a>
## Callback Processor

`Crustum\Queue\Processor\CallbackProcessor` is a Cake Queue processor with return-is-data semantics: any return other than an explicit `REJECT` (or an explicit requeue `Result`) is success — the message is `ACK`ed. Only a `Throwable` fails the message (requeue plus the `failed()` hook). Poison messages `REJECT` instead of killing the worker. Wire it like any processor:

```php
'Queue' => ['ai' => [
    // ...
    'processor' => \Crustum\Queue\Processor\CallbackProcessor::class,
]],
```

The middleware pipeline runs at its inner-execution point, so `MiddlewareAwareInterface` jobs get middleware here too.

<a name="completion-callbacks"></a>
### Completion Callbacks

`then` / `catch` payload callbacks travel inside the message and run wherever the job runs; a duck-typed `failed(Throwable, array)` hook fires on failure. Callback failures are logged and swallowed — callbacks observe, they never arbitrate the job result.

<a name="custom-serializers"></a>
### Custom Serializers

Packing (dispatch side) and unpacking (worker side) share one format definition in `Processor\QueuedCallback` (`pack` / `unpack` / `unpackList`, no global state). The default `SerializableClosureSerializer` needs `laravel/serializable-closure` (composer `suggest`, not required): without it packing throws a descriptive error and unpacking skips. Jobs opt into a custom serializer statically — no instance is constructed on either side:

```php
use Crustum\Queue\Contract\CallbackSerializerInterface;
use Crustum\Queue\Contract\HasCallbackSerializer;

class MyJob implements JobInterface, HasCallbackSerializer
{
    public static function callbackSerializer(): ?CallbackSerializerInterface
    {
        return new EncryptedSerializer('key');
    }

    // execute(...)
}
```

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

Register callbacks that run after the envelope and **before** pending events + push/sync:

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
- `buildPayload(string $jobClass, array $data, array $config = []): array`

Application emitters must not swallow sync control-flow exceptions if they wrap pending emission; `JobDispatchEmitters` rethrows `SyncDispatchHandledException` after the application pending hook.
