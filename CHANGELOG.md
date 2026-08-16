# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
