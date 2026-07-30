<?php declare(strict_types=1);

namespace Topdata\TopdataDeduplicatorSW6\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Helper\CliStyle;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:deduplicator:brands',
    description: 'Finds and merges duplicate product brands (manufacturers) safely.'
)]
class DeduplicateBrandsCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityIndexerRegistry $indexerRegistry
    ) {
        parent::__construct();
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cliStyle = new CliStyle($input, $output);
        CliLogger::setCliStyle($this->cliStyle);

        $dryRun = $input->getOption('dry-run');
        $context = Context::createDefaultContext();

        CliLogger::title('Topdata Brand Deduplication Engine');

        if ($dryRun) {
            CliLogger::note('Running in DRY RUN mode. No database modifications will occur.');
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
                $uniqueProductIds = array_values(array_unique($affectedProductIds));
                CliLogger::section('Indexing Cleaned Catalog');
                CliLogger::info(sprintf('Queueing %d updated products for background re-indexing...', count($uniqueProductIds)));

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

    private function processGroup(array $group, bool $dryRun, Context $context, array &$affectedProductIds): bool
    {
        $normalizedName = $group['normalized_name'];
        $defaultLanguageId = Defaults::LANGUAGE_SYSTEM;

        $manufacturers = $this->connection->fetchAllAssociative('
            SELECT m.id, t.name, m.created_at,
                   (SELECT COUNT(*) FROM product p WHERE p.product_manufacturer_id = m.id AND p.version_id = :liveVersionId) as product_count
            FROM product_manufacturer m
            JOIN product_manufacturer_translation t ON t.product_manufacturer_id = m.id AND t.language_id = :languageId
            WHERE LOWER(TRIM(t.name)) = :normalizedName
        ', [
            'languageId' => Uuid::fromHexToBytes($defaultLanguageId),
            'normalizedName' => $normalizedName,
            'liveVersionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)
        ]);

        if (count($manufacturers) <= 1) {
            return false;
        }

        usort($manufacturers, function($a, $b) {
            if ($b['product_count'] !== $a['product_count']) {
                return $b['product_count'] <=> $a['product_count'];
            }
            return strcmp($a['created_at'], $b['created_at']);
        });

        $master = $manufacturers[0];
        $masterHexId = Uuid::fromBytesToHex($master['id']);
        $duplicates = array_slice($manufacturers, 1);

        $masterCreatedAt = (new \DateTime($master['created_at']))->format('Y-m-d');
        CliLogger::write(sprintf('Group: <yellow>%s</yellow>', $master['name']), true);
        CliLogger::write(sprintf('  ↳ KEEP (Master):  [ID: %s] created at %s with <info>%d</info> assigned products', $masterHexId, $masterCreatedAt, $master['product_count']), true);

        foreach ($duplicates as $duplicate) {
            $duplicateHexId = Uuid::fromBytesToHex($duplicate['id']);
            $duplicateCreatedAt = (new \DateTime($duplicate['created_at']))->format('Y-m-d');
            CliLogger::write(sprintf('  ↳ MERGE (Delete): [ID: %s] created at %s (Current assigned products: %d)', $duplicateHexId, $duplicateCreatedAt, $duplicate['product_count']), true);

            if ($dryRun) {
                continue;
            }

            $productRows = $this->connection->fetchAllAssociative('
                SELECT id FROM product 
                WHERE product_manufacturer_id = :duplicateId AND version_id = :liveVersionId
            ', [
                'duplicateId' => $duplicate['id'],
                'liveVersionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)
            ]);

            $groupProductIds = array_map(function($row) {
                return Uuid::fromBytesToHex($row['id']);
            }, $productRows);

            if (!empty($groupProductIds)) {
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

                $affectedProductIds = array_merge($affectedProductIds, $groupProductIds);
            }

            $this->manufacturerRepository->delete([
                ['id' => $duplicateHexId]
            ], $context);
        }

        CliLogger::writeln('');
        return true;
    }
}
