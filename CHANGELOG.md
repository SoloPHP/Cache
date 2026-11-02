# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-02

This release contains breaking changes. Read the [upgrade guide](UPGRADE.md) before updating.

### Added

- [Documentation](https://solophp.github.io/Cache/) with a getting-started guide, an API reference and a description of each adapter's behavior.
- `ErrorMode` enum with `Throw` and `Fail` cases, accepted by both constructors and `setMode()`.
- `AbstractAdapter` for custom adapters: it provides the error mode, `has()` and the batch methods on top of `get()`, `set()`, `delete()` and `clear()`.
- `setMultiple()` accepts integer indexes, which stand for their decimal string keys.
- `RedisAdapter::setMultiple()` with a TTL sends all `SETEX` commands in one pipeline.
- `FileAdapter::gc()` also removes temporary files left behind by writers that crashed.

### Changed

- PHP 8.3 or newer is required.
- Both adapters implement `Psr\SimpleCache\CacheInterface` directly, and `CacheAdapterInterface` extends it. `Cache` accepts any PSR-16 implementation and is a readonly class.
- `ErrorMode` replaces the `MODE_THROW` and `MODE_FAIL` integer constants.
- Keys follow PSR-16: letters, digits, `_`, `.` and `-`. A `:` or a trailing newline raises `InvalidArgumentException`.
- Batch methods validate every key before any entry changes.
- In `ErrorMode::Throw`, every storage failure raises `CacheException`, including Redis server errors such as `WRONGTYPE` and values that cannot be serialized without loss, such as resources and closures.
- `FileAdapter` names entries `solo-<sha256>.cache` and stores them with an expiry header. Each write goes to a temporary file that replaces the entry with `rename()` under a pool lock, so readers never see a partly written value.
- `FileAdapter` creates its directory with mode `0700` and entries with mode `0600`.
- `FileAdapter` reads no longer delete expired or corrupt entries. Schedule `gc()`, which reads entry headers only and never restores a value.
- `RedisAdapter` checks the client instead of changing its options. A native prefix, a serializer, compression, or a client inside a transaction or pipeline raises `InvalidArgumentException`.
- `RedisAdapter` rejects an empty prefix and adds a trailing `:` to a prefix without one.
- `RedisAdapter::has()` reads the entry, so it returns `false` for a payload that cannot be restored, like `get()`.

### Fixed

- `FileAdapter` returns a stored `null` as a hit. 1.x treated the entry as corrupt and deleted it.
- `FileAdapter::clear()` removes only the pool's own files. 1.x deleted every file in the directory and could misread a directory name containing glob characters.
- A corrupt payload or a class that no longer exists is a miss. In 1.x, `RedisAdapter` returned `false` for a corrupt payload, and both adapters returned an incomplete object for a missing class.
- `RedisAdapter::clear()` matches its prefix literally and skips nested namespaces, so a prefix containing `*`, `?` or `[` no longer clears other pools.
- A Redis TTL too long for the server is capped instead of failing.

## [1.2.0] - 2026-03-13

### Added

- `RedisAdapter` accepts a key prefix as its third constructor argument. The default stays `cache:`.

### Fixed

- `RedisAdapter` serializes values itself, so a stored `false` is returned as a hit.

## [1.1.0] - 2025-12-12

### Added

- Keys may contain `:` and `-`.

## [1.0.0] - 2025-10-22

Initial release.

[2.0.0]: https://github.com/SoloPHP/Cache/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/SoloPHP/Cache/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/SoloPHP/Cache/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SoloPHP/Cache/releases/tag/v1.0.0
