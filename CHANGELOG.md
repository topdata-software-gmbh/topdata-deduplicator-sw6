# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-07-30

### Added
- Created `topdata:deduplicator:brands` CLI command to easily merge duplicate brands/manufacturers.
- Added `--dry-run` flag support to preview candidate duplicates safely before committing modifications.
- Integrated DBAL updates with robust DAL entity deletes to preserve database schema integrity.
- Automated core `product.indexer` queueing for all modified products post-execution.
- Integrated standard `CliLogger` dependency formatting.

## [1.0.0] - 2026-07-30

### Added
- Initial release