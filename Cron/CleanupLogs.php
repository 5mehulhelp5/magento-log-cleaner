<?php
declare(strict_types=1);

namespace LR\LogCleaner\Cron;

use LR\LogCleaner\Helper\Data;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron job for cleaning up log files
 *
 * Automatically cleans log files based on configuration settings
 */
class CleanupLogs
{
    /**
     * @var Data
     */
    private $helper;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param Data $helper
     * @param DirectoryList $directoryList
     * @param LoggerInterface $logger
     */
    public function __construct(
        Data $helper,
        DirectoryList $directoryList,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->directoryList = $directoryList;
        $this->logger = $logger;
    }

    /**
     * Execute log cleanup (cron mode)
     *
     * @return void
     */
    public function execute(): void
    {
        $this->executeInternal();
    }

    /**
     * Execute log cleanup with console output (manual mode)
     *
     * @param OutputInterface $output
     * @param bool $isDryRun
     * @return array
     */
    public function executeWithOutput($output, bool $isDryRun = false): array
    {
        return $this->executeInternal($output, $isDryRun);
    }

    /**
     * Internal execute method that handles both cron and console modes
     *
     * @param OutputInterface|null $output
     * @param bool $isDryRun
     * @return array|void
     */
    private function executeInternal($output = null, bool $isDryRun = false)
    {
        if (!$this->helper->isEnabled()) {
            if ($output) {
                return ['success' => false, 'files_processed' => 0, 'entries_removed' => 0];
            }
            return;
        }

        $isConsoleMode = $output !== null;
        $totalFilesProcessed = 0;
        $totalEntriesRemoved = 0;
        $totalEntriesProcessed = 0;

        if (!$isConsoleMode) {
            $this->logger->info('LR_LogCleaner: Starting log cleanup process');
        }

        try {
            $logPath = $this->directoryList->getRoot() . '/var/log';
            $retentionTimestamp = $this->helper->getRetentionTimestamp();

            $result = $this->cleanupLogs($logPath, $retentionTimestamp, $output, $isDryRun);
            $totalFilesProcessed = $result['files_processed'];
            $totalEntriesRemoved = $result['entries_removed'];

            if ($this->helper->isBackupEnabled() && !$isDryRun) {
                $this->cleanupBackups();
            }

            if (!$isConsoleMode) {
                $this->logger->info(
                    sprintf('LR_LogCleaner: Cleanup completed. Files processed: %d, Entries removed: %d',
                        $totalFilesProcessed, $totalEntriesRemoved)
                );
            }

            if ($isConsoleMode) {
                return [
                    'success' => true,
                    'files_processed' => $totalFilesProcessed,
                    'entries_removed' => $totalEntriesRemoved
                ];
            }

        } catch (FileSystemException $e) {
            $error = 'LR_LogCleaner: Failed to access log directory: ' . $e->getMessage();
            if ($isConsoleMode) {
                throw new \Exception($error);
            } else {
                $this->logger->error($error);
            }
        } catch (\Exception $e) {
            $error = 'LR_LogCleaner: Cleanup failed: ' . $e->getMessage();
            if ($isConsoleMode) {
                throw new \Exception($error);
            } else {
                $this->logger->error($error);
            }
        }
    }

    /**
     * Clean up log entries from log files
     *
     * @param string $logPath
     * @param int $retentionTimestamp
     * @param OutputInterface|null $output
     * @param bool $isDryRun
     * @return array
     */
    private function cleanupLogs(string $logPath, int $retentionTimestamp, $output = null, bool $isDryRun = false): array
    {
        $selectedFiles = $this->helper->getLogFiles();
        $processedFiles = 0;
        $totalEntriesRemoved = 0;
        $isConsoleMode = $output !== null;

        if (!is_dir($logPath)) {
            $message = 'LR_LogCleaner: Log directory does not exist: ' . $logPath;
            if ($isConsoleMode) {
                $output->writeln("❌ <fg=red>{$message}</fg=red>");
            } else {
                $this->logger->warning($message);
            }
            return ['files_processed' => 0, 'entries_removed' => 0];
        }

        $iterator = new \DirectoryIterator($logPath);

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'log') {
                continue;
            }

            $relativePath = $file->getFilename();

            if (!$this->shouldCleanFile($relativePath, $selectedFiles)) {
                continue;
            }

            try {
                $filePath = $file->getPathname();
                $fileSize = filesize($filePath);
                $useBatchProcessing = $this->helper->shouldUseBatchProcessing($filePath);

                if ($isConsoleMode) {
                    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                    $processingMode = $useBatchProcessing ? 'batch' : 'standard';
                    $output->writeln("📁 <fg=cyan>Processing:</fg=cyan> {$relativePath} <fg=yellow>({$fileSizeMB}MB, {$processingMode} mode)</fg=yellow>");
                }

                if ($useBatchProcessing) {
                    // Use batch processing for large files
                    if ($isConsoleMode) {
                        $output->writeln("⚡ <fg=magenta>Large file detected - using batch processing...</fg=magenta>");
                    }

                    if ($this->helper->isBackupEnabled() && !$isDryRun) {
                        $originalContent = file_get_contents($filePath);
                        $this->createBackup($filePath, $relativePath, $originalContent);
                    }

                    if (!$isDryRun) {
                        $result = $this->helper->cleanLogFileInBatches($filePath, $retentionTimestamp, $this->helper->getBatchSize(), $output);
                        $removedEntries = $result['removed_entries'];
                        $totalEntries = $result['total_entries'];
                        $batchesProcessed = $result['batches_processed'];
                        $maxBatches = $result['max_batches_limit'];
                        $stoppedEarly = $result['stopped_early'] ?? false;

                        if ($removedEntries > 0) {
                            $processedFiles++;
                            $totalEntriesRemoved += $removedEntries;

                            if ($isConsoleMode) {
                                $statusMsg = $stoppedEarly ? ' <fg=yellow>(stopped early for performance)</fg=yellow>' : '';
                                $output->writeln("✅ <fg=green>Batch cleaned:</fg=green> {$relativePath} <fg=yellow>(processed {$totalEntries}, removed {$removedEntries}, {$batchesProcessed} batches)</fg=yellow>{$statusMsg}");
                            } else {
                                $this->logger->info(
                                    sprintf('LR_LogCleaner: Batch cleaned log file: %s (processed %d entries, removed %d old entries, %d batches processed)',
                                        $relativePath, $totalEntries, $removedEntries, $batchesProcessed)
                                );
                            }
                        } else {
                            if ($isConsoleMode) {
                                $processedMsg = $stoppedEarly ? " (processed {$totalEntries} entries, stopped early)" : " (processed {$totalEntries} entries)";
                                $output->writeln("ℹ️  <fg=blue>No old entries:</fg=blue> {$relativePath}<fg=yellow>{$processedMsg}</fg=yellow>");
                            } else {
                                $this->logger->info(sprintf('LR_LogCleaner: No old entries found in: %s (processed %d entries)', $relativePath, $totalEntries));
                            }
                        }
                    } else {
                        // Dry run for batch processing - use fast estimation without loading full file
                        $estimatedRemovals = $this->helper->estimateRemovalsFast($filePath, $retentionTimestamp);
                        $maxBatches = $this->helper->getMaxBatchesForFile($filePath);

                        if ($estimatedRemovals > 0) {
                            $processedFiles++;
                            $totalEntriesRemoved += $estimatedRemovals;
                            $batchInfo = $maxBatches < PHP_INT_MAX ? " (max {$maxBatches} batches)" : '';
                            $output->writeln("🔍 <fg=yellow>Would batch clean:</fg=yellow> {$relativePath} <fg=yellow>(~{$estimatedRemovals} entries estimated{$batchInfo})</fg=yellow>");
                        } else {
                            $output->writeln("ℹ️  <fg=blue>No old entries:</fg=blue> {$relativePath}");
                        }
                    }
                } else {
                    // Use standard processing for smaller files
                    $originalContent = file_get_contents($filePath);

                    if ($originalContent === false) {
                        $this->logger->warning(
                            sprintf('LR_LogCleaner: Could not read log file: %s', $relativePath)
                        );
                        continue;
                    }

                    $cleanedContent = $this->helper->cleanLogContent($originalContent, $retentionTimestamp);

                    $originalEntries = $this->helper->countLogEntries($originalContent);
                    $cleanedEntries = $this->helper->countLogEntries($cleanedContent);
                    $removedEntries = $originalEntries - $cleanedEntries;

                    if ($removedEntries > 0) {
                        if ($this->helper->isBackupEnabled() && !$isDryRun) {
                            $this->createBackup($filePath, $relativePath, $originalContent);
                        }

                        if (!$isDryRun && file_put_contents($filePath, $cleanedContent) !== false) {
                            $processedFiles++;
                            $totalEntriesRemoved += $removedEntries;

                            if ($isConsoleMode) {
                                $output->writeln("✅ <fg=green>Cleaned:</fg=green> {$relativePath} <fg=yellow>(-{$removedEntries} entries)</fg=yellow>");
                            } else {
                                $this->logger->info(
                                    sprintf('LR_LogCleaner: Cleaned log file: %s (removed %d old entries)', $relativePath, $removedEntries)
                                );
                            }
                        } elseif ($isDryRun) {
                            $processedFiles++;
                            $totalEntriesRemoved += $removedEntries;
                            if ($isConsoleMode) {
                                $output->writeln("🔍 <fg=yellow>Would clean:</fg=yellow> {$relativePath} <fg=yellow>(-{$removedEntries} entries)</fg=yellow>");
                            }
                        } else {
                            if ($isConsoleMode) {
                                $output->writeln("❌ <fg=red>Failed to write:</fg=red> {$relativePath}");
                            } else {
                                $this->logger->error(sprintf('LR_LogCleaner: Failed to write cleaned content to: %s', $relativePath));
                            }
                        }
                    } else {
                        if ($isConsoleMode) {
                            $output->writeln("ℹ️  <fg=blue>No old entries:</fg=blue> {$relativePath}");
                        } else {
                            $this->logger->info(sprintf('LR_LogCleaner: No old entries found in: %s', $relativePath));
                        }
                    }
                }
            } catch (\Exception $e) {
                if ($isConsoleMode) {
                    $output->writeln("❌ <fg=red>Error processing:</fg=red> {$relativePath} - {$e->getMessage()}");
                } else {
                    $this->logger->error(sprintf('LR_LogCleaner: Failed to process log file %s: %s', $relativePath, $e->getMessage()));
                }
            }
        }

        return ['files_processed' => $processedFiles, 'entries_removed' => $totalEntriesRemoved];
    }

    /**
     * Check if file should be cleaned
     *
     * @param string $relativePath
     * @param array $selectedFiles
     * @return bool
     */
    private function shouldCleanFile(string $relativePath, array $selectedFiles): bool
    {
        if (empty($selectedFiles) || in_array('all', $selectedFiles)) {
            return true;
        }

        return in_array($relativePath, $selectedFiles);
    }

    /**
     * Create backup of log file content before cleaning
     *
     * @param string $filePath
     * @param string $relativePath
     * @param string $originalContent
     * @return void
     * @throws FileSystemException
     */
    private function createBackup(string $filePath, string $relativePath, string $originalContent): void
    {
        $logPath = $this->directoryList->getRoot() . '/var/log';
        $backupDir = $logPath . DIRECTORY_SEPARATOR . 'backup';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupFileName = str_replace(['/', '\\'], '_', $relativePath);
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $timestamp . '_' . $backupFileName . '.gz';

        $compressedContent = gzencode($originalContent);

        if (file_put_contents($backupPath, $compressedContent) === false) {
            throw new \Exception('Failed to create backup file: ' . $backupPath);
        }

        $this->logger->info(
            sprintf('LR_LogCleaner: Created backup: %s', basename($backupPath))
        );
    }

    /**
     * Clean up old backup files
     *
     * @return void
     */
    private function cleanupBackups(): void
    {
        $backupRetentionTimestamp = $this->helper->getBackupRetentionTimestamp();

        if ($backupRetentionTimestamp <= 0) {
            return;
        }

        try {
            $logPath = $this->directoryList->getRoot() . '/var/log';
            $backupDir = $logPath . DIRECTORY_SEPARATOR . 'backup';

            if (!is_dir($backupDir)) {
                return;
            }

            $iterator = new \DirectoryIterator($backupDir);
            $deletedBackups = 0;

            foreach ($iterator as $file) {
                if ($file->isDot() || !$file->isFile()) {
                    continue;
                }

                if ($file->getMTime() < $backupRetentionTimestamp) {
                    unlink($file->getPathname());
                    $deletedBackups++;

                    $this->logger->info(
                        sprintf('LR_LogCleaner: Deleted old backup: %s', $file->getFilename())
                    );
                }
            }

            if ($deletedBackups > 0) {
                $this->logger->info(
                    sprintf('LR_LogCleaner: Deleted %d old backup files', $deletedBackups)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('LR_LogCleaner: Backup cleanup failed: ' . $e->getMessage());
        }
    }

}