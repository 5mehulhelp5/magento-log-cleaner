<?php
declare(strict_types=1);

namespace LR\LogCleaner\Console\Command;

use LR\LogCleaner\Cron\CleanupLogs;
use LR\LogCleaner\Helper\Data;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Console command for manual log cleanup with rich output
 */
class CleanLogsCommand extends Command
{
    private const DRY_RUN_OPTION = 'dry-run';

    private $cleanupLogs;
    private $helper;

    public function __construct(
        CleanupLogs $cleanupLogs,
        Data $helper,
        string $name = null
    ) {
        $this->cleanupLogs = $cleanupLogs;
        $this->helper = $helper;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('lr:logs:clean')
            ->setDescription('🧹 Clean old log entries from log files')
            ->addOption(
                self::DRY_RUN_OPTION,
                'd',
                InputOption::VALUE_NONE,
                'Preview what would be cleaned without making changes'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = $input->getOption(self::DRY_RUN_OPTION);

        $output->writeln('');
        $output->writeln('🧹 <fg=cyan>LR Log Cleaner</fg=cyan> 🧹');
        $output->writeln('<fg=yellow>=====================================</fg=yellow>');
        $output->writeln('');

        if (!$this->helper->isEnabled()) {
            $output->writeln('❌ <fg=red>Log Cleaner is disabled!</fg=red>');
            $output->writeln('💡 <fg=yellow>Enable in admin: Cell Israel Config > Log Cleaner</fg=yellow>');
            return Cli::RETURN_FAILURE;
        }

        if ($isDryRun) {
            $output->writeln('🔍 <fg=yellow>DRY RUN MODE - No changes will be made</fg=yellow>');
            $output->writeln('');
        }

        $retentionDays = $this->helper->getRetentionDays();
        $cutoffDate = date('Y-m-d H:i:s', $this->helper->getRetentionTimestamp());

        $output->writeln("📅 <fg=cyan>Retention Period:</fg=cyan> {$retentionDays} days");
        $output->writeln("✂️ <fg=cyan>Cutoff Date:</fg=cyan> {$cutoffDate}");
        $output->writeln('');

        try {
            $result = $this->cleanupLogs->executeWithOutput($output, $isDryRun);

            $output->writeln('');
            $output->writeln('<fg=yellow>📊 SUMMARY</fg=yellow>');
            $output->writeln('<fg=yellow>==================</fg=yellow>');
            $output->writeln("📁 Files processed: <fg=green>{$result['files_processed']}</fg=green>");
            $output->writeln("🗑️  Entries removed: <fg=green>{$result['entries_removed']}</fg=green>");

            if ($this->helper->isBackupEnabled() && !$isDryRun) {
                $output->writeln("💾 Backups created: <fg=cyan>var/log/backup/</fg=cyan>");
            }

            if ($isDryRun) {
                $output->writeln('');
                $output->writeln('💡 <fg=yellow>Run without --dry-run to apply changes</fg=yellow>');
            } else {
                $output->writeln('');
                $output->writeln('✅ <fg=green>Log cleanup completed successfully!</fg=green>');
            }

            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('❌ <fg=red>Error: ' . $e->getMessage() . '</fg=red>');
            return Cli::RETURN_FAILURE;
        }
    }
}
