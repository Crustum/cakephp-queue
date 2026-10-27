# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2]

### Removed

- `CommandMessage` is now a marker interface: `payload()` / `fromPayload()` are gone. Commands are plain DTOs (constructor + readonly props); hydration goes through `DtoManager` / `Message::getDto()`. Existing `payload()` / `fromPayload()` methods on commands are dead code — safe to delete. Optional core hooks still apply when needed: `JsonSerializable` for serialization, static `createFromArray(array, bool)` for hydration.

## [1.4.0]

### Added

- DTO / object dispatch: `DispatchableInterface::dispatch(array|object)` serializes via `Cake\Queue\Dto\DtoManager`, records `dtoClass`; jobs restore with `Message::getDto(Expected::class)`
- Envelope in metadata: tags and `_uniqueId` travel in message `metadata`, never inside job data; generated before produce
- `CommandBus` passes command objects through to the handler job (no array cutting)
- `SyncJobRunner` carries `dtoClass` + `metadata`; event payload is `{id, job, body{class,args}, metadata, dtoClass?}`
- `TaggableInterface::createTags(array|object)`

### Changed

- Requires `cakephp/queue: 3.x-dev` (DTO + metadata body schema)

## [1.3.0]

### Added

- Job middleware pipeline: `Contract\MiddlewareInterface` / `MiddlewareAwareInterface`, shared `MiddlewareRunner` core, `Processor\MiddlewareProcessor` (via the `processor` queue config key) + `RunsMiddlewareTrait`, `Job\MiddlewareJob` base class
- Built-in middleware: `Middleware\Skip`, `Release` (+ `ReleasesJobsTrait`), `FailOnException`, `ThrottlesExceptions`, `WithoutOverlapping` (Cake Lock), `RateLimited`
- `Limiter\RateLimiter` with named limiters (`setLimiter` / `getLimiter` / `hasLimiter`), `Limiter\Limit` / `GlobalLimit` / `Unlimited` definitions, `ConcurrencyLimiter` (+ builder), I18n-backed `Limiter\Time`
- `Processor\CallbackProcessor`: return-is-data lifecycle, `then` / `catch` payload callbacks, duck-typed `failed()` hook; pluggable `Contract\CallbackSerializerInterface` (`HasCallbackSerializer`, `Processor\QueuedCallback`, default `SerializableClosureSerializer`)
- `laravel/serializable-closure` as composer `suggest` (callback packing only)

## [1.2.0]

### Added

- `CommandBus` + `CommandMessage` + `#[Handles]` — dispatch typed command DTOs to handler jobs; `map()` manual registration and `#[Handles]` attribute-driven discovery via `AttributeResolver`


## [1.1.0]

### Added

- Sync mode: `CrustumQueue.sync` / `CRUSTUM_QUEUE_SYNC`, optional `syncOnly` allow-list, `SyncSuppressibleInterface` (no call-site `$overrides['sync']`; listener attached only when sync is on)
- `SyncDispatchListener` + `SyncDispatchHandledException` (internal) + `SyncJobRunner` (Cake Queue `Processor`, DI via `ContainerRegistry`)
- `Event\JobDataMutators` — mutate job data after tags/`_uniqueId` and before pending emit + push (e.g. inject `speculum_uuid`)

## [1.0.0]

Initial release of `crustum/queue` (`Crustum\Queue`).

### Added

- `Job\DispatchableInterface` / `Job\DispatchableTrait`
- `Job\ConfigurableInterface`
- `Job\TaggableInterface`
- `Event\EventDispatcher`, `JobPendingEvent`, `JobPushedEvent`
- `Event\JobDispatchEmitterInterface`, `DefaultJobDispatchEmitter`, `JobDispatchEmitters`
- `QueuePlugin` bootstrap
