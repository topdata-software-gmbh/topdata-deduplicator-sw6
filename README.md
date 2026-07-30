# Topdata Deduplicator SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Installation

1. Download the plugin
2. Upload to your Shopware 6 installation
3. Install and activate the plugin

## CLI Commands

### Deduplicate Brands (Manufacturers)
Identifies and merges duplicate product manufacturer records (brands) in the database based on case-insensitive and spacing differences. It updates all product references to the designated master record, deletes duplicate database entries safely via DAL, and schedules catalog re-indexing.

**Dry Run (Recommended first step):**
```bash
bin/console topdata:deduplicator:brands --dry-run
```

**Execute Merge:**
```bash
bin/console topdata:deduplicator:brands
```

## Requirements

- Shopware 6.7.*
- PHP 8.2+
- `topdata/topdata-foundation-sw6` (provides CLI base classes and logging)

## License

MIT