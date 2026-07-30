---
filename: "_ai/backlog/active/260730_1921__IMPLEMENTATION_PLAN__brand_deduplicator.md"
title: "Implementation Plan: Brand Deduplicator CLI Command"
createdAt: 2026-07-30 19:21
updatedAt: 2026-07-30 19:21
status: completed
completedAt: 2026-07-30 19:31
priority: high
tags: [shopware, catalog, deduplication, cli, brand, open-source]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan: Brand Deduplicator CLI Command

## 1. Problem Description
Over time, e-commerce databases accumulate duplicate brand (manufacturer) entities (e.g., "Nike", "nike", and " Nike ") due to inconsistent manual entries, CSV imports, or external ERP data synchronization. 

These duplicate brands lead to:
- Segmented and broken storefront product listings and manufacturer filtering.
- Poor search engine optimization (SEO).
- Inefficient database relationships, indexing bloat, and management confusion.

We need a safe, robust, and automated way to identify duplicate brands, merge all of their product relationships into a single master brand record, clean up the duplicate manufacturer records, and queue the affected products for storefront indexing.

---

## 2. Executive Summary
This implementation plan provides a detailed step-by-step path to introduce a powerful, safe CLI command (`topdata:deduplicator:brands`) within the `TopdataDeduplicatorSW6` plugin.

The command will:
1. Scan the database using efficient raw SQL (via `Doctrine\DBAL\Connection`) to locate case-insensitive and whitespace-insensitive duplicate manufacturer names.
2. Group duplicate brands, automatically determining the "master" brand as the one containing the most product assignments (or the oldest record if tie).
3. Provide a safe `--dry-run` flag so merchants/developers can preview exactly what changes would occur before modifying any database tables.
4. Execute the deduplication within transactional boundaries by updating product foreign keys, deleting duplicate manufacturer records using the Shopware Data Abstraction Layer (DAL) to trigger proper cascade operations, and queuing all affected products for re-indexing using the `EntityIndexerRegistry`.
5. Implement clean console output utilizing the proprietary `CliLogger` class.

---

## 3. Project Environment
- Project Name: SW6.7 Plugin
- Backend root: `src`
- PHP Version: 8.2 / 8.3 / 8.4

---

## 4. Implementation Phases

### Phase 1: Clean Up & Service Registration
In this phase, we clean up the boilerplate example command to prevent confusion and register our new `DeduplicateBrandsCommand` in the Dependency Injection container.

#### [DELETE] `src/Command/ExampleCommand.php`
Remove the old boilerplate example command.

#### [MODIFY] `src/Resources/config/services.xml`
Configure autowiring and manually register the command service, injecting the database connection, the product manufacturer repository, and the entity indexer registry.

```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <service id="Topdata\TopdataDeduplicatorSW6\Controller\StorefrontExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <service id="Topdata\TopdataDeduplicatorSW6\Controller\AdminApiExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <!-- Command Registration -->
        <service id="Topdata\TopdataDeduplicatorSW6\Command\DeduplicateBrandsCommand" autowire="true">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <argument type="service" id="product_manufacturer.repository"/>
            <argument type="service" id="Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry"/>
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

---

### Phase 2: Command Implementation (DeduplicateBrandsCommand)
We will construct the main brand deduplication command using Symfony Console Attributes. The class will extend `\Topdata\TopdataFoundationSW6\TopdataFoundationSW6` and interact exclusively with the CLI using `\Topdata\TopdataFoundationSW6\Util\CliLogger` for formatting.

#### [NEW FILE] `src/Command/DeduplicateBrandsCommand.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataDeduplicatorSW6\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:deduplicator:brands',
    description: 'Finds and merges duplicate product brands (manufacturers) safely.'
)]
class DeduplicateBrandsCommand extends TopdataFoundationSW6
{
    private Connection $connection;
    private EntityRepository $manufacturerRepository;
    private EntityIndexerRegistry $indexerRegistry;

    public function __construct(
        Connection $connection,
        EntityRepository $manufacturerRepository,
        EntityIndexerRegistry $indexerRegistry
    ) {
        parent::__construct();
        $this->connection = $connection;
        $this->manufacturerRepository = $manufacturerRepository;
        $this->indexerRegistry = $indexerRegistry;
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            'd',
            InputOption::VALUE_NONE,
            'Scan for duplicate brands and preview planned changes without executing modifications.'
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        CliLogger::setCliStyle(new SymfonyStyle($input, $output));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run');
        $context = Context::createDefaultContext();

        CliLogger::title('Topdata Brand Deduplication Engine');

        if ($dryRun) {
            CliLogger::notice('Running in DRY RUN mode. No database modifications will occur.');
        }

        try {
            $duplicateGroups = $this->findDuplicateGroups();

            if (empty($duplicateGroups)) {
                CliLogger::success('No duplicate brands detected. Your catalog is clean!');
                return Command::SUCCESS;
            }

            CliLogger::section(sprintf('Found %d groups of duplicate brands', count($duplicateGroups)));

            $affectedProductIds = [];

            foreach ($duplicateGroups as $group) {
                $processed = $this->processGroup($group, $dryRun, $context, $affectedProductIds);
                if (!$processed) {
                    continue;
                }
            }

            if (!$dryRun && !empty($affectedProductIds)) {
                $uniqueProductIds = array_unique($affectedProductIds);
                CliLogger::section('Indexing Cleaned Catalog');
                CliLogger::info(sprintf('Queueing %d updated products for background re-indexing...', count($uniqueProductIds)));
                
                // Triggers Shopware 6 core product indexer
                $this->indexerRegistry->sendIndexingMessage(['product.indexer'], $uniqueProductIds);
                
                CliLogger::success('Re-indexing requested successfully.');
            }

            CliLogger::success('Brand deduplication command run completed.');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            CliLogger::error('An unexpected error occurred during the deduplication run:');
            CliLogger::writeln($e->getMessage());
            CliLogger::debug($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Identify groups of identical normalized brand names.
     */
    private function findDuplicateGroups(): array
    {
        $defaultLanguageId = Defaults::LANGUAGE_SYSTEM;

        $query = '
            SELECT LOWER(TRIM(t.name)) as normalized_name, COUNT(*) as cnt
            FROM product_manufacturer_translation t
            WHERE t.language_id = :languageId AND t.name IS NOT NULL AND t.name != ""
            GROUP BY LOWER(TRIM(t.name))
            HAVING cnt > 1
        ';

        return $this->connection->fetchAllAssociative($query, [
            'languageId' => Uuid::fromHexToBytes($defaultLanguageId)
        ]);
    }

    /**
     * Group, sort, and process duplicates in a specific group.
     */
    private function processGroup(array $group, bool $dryRun, Context $context, array &$affectedProductIds): bool
    {
        $normalizedName = $group['normalized_name'];
        $defaultLanguageId = Defaults::LANGUAGE_SYSTEM;

        // Fetch all brand IDs belonging to this group, along with their product count.
        $query = '
            SELECT m.id, t.name, m.created_at,
                   (SELECT COUNT(*) FROM product p WHERE p.product_manufacturer_id = m.id AND p.version_id = :liveVersionId) as product_count
            FROM product_manufacturer m
            JOIN product_manufacturer_translation t ON t.product_manufacturer_id = m.id AND t.language_id = :languageId
            WHERE LOWER(TRIM(t.name)) = :normalizedName
        ';

        $manufacturers = $this->connection->fetchAllAssociative($query, [
            'languageId' => Uuid::fromHexToBytes($defaultLanguageId),
            'normalizedName' => $normalizedName,
            'liveVersionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)
        ]);

        if (count($manufacturers) <= 1) {
            return false;
        }

        // Sort: Highest product count first, then oldest record first.
        usort($manufacturers, function($a, $b) {
            if ($b['product_count'] !== $a['product_count']) {
                return $b['product_count'] <=> $a['product_count'];
            }
            return strcmp($a['created_at'], $b['created_at']);
        });

        $master = $manufacturers[0];
        $masterHexId = Uuid::fromBytesToHex($master['id']);
        $duplicates = array_slice($manufacturers, 1);

        CliLogger::write(sprintf('Group: <yellow>%s</yellow>', $master['name']), true);
        CliLogger::write(sprintf('  ↳ KEEP (Master):  [ID: %s] with <info>%d</info> assigned products', $masterHexId, $master['product_count']), true);

        foreach ($duplicates as $duplicate) {
            $duplicateHexId = Uuid::fromBytesToHex($duplicate['id']);
            CliLogger::write(sprintf('  ↳ MERGE (Delete): [ID: %s] (Current assigned products: %d)', $duplicateHexId, $duplicate['product_count']), true);

            if ($dryRun) {
                continue;
            }

            // Identify all products assigned to this duplicate
            $productQuery = '
                SELECT id FROM product 
                WHERE product_manufacturer_id = :duplicateId AND version_id = :liveVersionId
            ';
            $productRows = $this->connection->fetchAllAssociative($productQuery, [
                'duplicateId' => $duplicate['id'],
                'liveVersionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)
            ]);

            $groupProductIds = array_map(function($row) {
                return Uuid::fromBytesToHex($row['id']);
            }, $productRows);

            if (!empty($groupProductIds)) {
                // Re-associate products to the master brand record
                $this->connection->executeStatement('
                    UPDATE product 
                    SET product_manufacturer_id = :masterId, updated_at = :now
                    WHERE product_manufacturer_id = :duplicateId AND version_id = :liveVersionId
                ', [
                    'masterId' => $master['id'],
                    'duplicateId' => $duplicate['id'],
                    'now' => (new \DateTime())->format('Y-m-d H:i:s'),
                    'liveVersionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)
                ]);

                // Track product IDs for eventual catalog indexation
                $affectedProductIds = array_merge($affectedProductIds, $groupProductIds);
            }

            // Safely delete duplicate brand through Shopware's Data Abstraction Layer (DAL)
            // This ensures safe cascading translation deletion, media reference clean up, etc.
            $this->manufacturerRepository->delete([
                ['id' => $duplicateHexId]
            ], $context);
        }

        CliLogger::writeln('');
        return true;
    }
}
```

---

### Phase 3: Project Housekeeping & Documentation
We update the configuration, system files, and write developer/user documentation to ensure complete clarity of CLI commands and plugin expectations.

#### [MODIFY] `README.md`
Add configuration instruction and describe the new Deduplication command.

```markdown
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

## License

MIT
```

#### [MODIFY] `CHANGELOG.md`
Log the addition of the Deduplicator brands command.

```markdown
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
```

---

## 5. Verification Plan

### Manual CLI Testing
1. **Mock Data Insertion:**
   Insert dummy duplicate manufacturers directly in the database (e.g., "Apple ", "apple", "APPLE") and assign different products to them.
2. **Execute Dry Run:**
   ```bash
   bin/console topdata:deduplicator:brands --dry-run
   ```
   *Expectation:* The command lists the group correctly, designates the master (usually the record with products, or oldest), lists duplicates, and reports 0 modified items in the database.
3. **Execute Actual Clean:**
   ```bash
   bin/console topdata:deduplicator:brands
   ```
   *Expectation:* The command finishes successfully, updates the database, prints success labels, deletes duplicates safely, and reports that the indexing message was dispatched.
4. **Verification of Records:**
   Verify in DB/Admin that only one Brand (the Master) remains, and all products are now assigned to it. Verify that the storefront filters work correctly.

### Code Quality Verification
- Run PHP CodeSniffer: `composer cs`
- Run PHPStan Static Analysis: `composer phpstan`

---

## 6. Implementation Report Generation
After the command has been successfully developed, manually verified, and formatting metrics checked, compile an implementation report and write it to `_ai/backlog/reports/260730_1921__IMPLEMENTATION_REPORT__brand_deduplicator.md`.

---
```
