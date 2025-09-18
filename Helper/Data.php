<?php
declare(strict_types=1);

namespace LR\LogCleaner\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * Log Cleaner Helper
 *
 * Provides configuration access and utility methods for the Log Cleaner module
 */
class Data extends AbstractHelper
{
    private const XML_PATH_ENABLED = 'lr_log_cleaner/general/enabled';
    private const XML_PATH_RETENTION_DAYS = 'lr_log_cleaner/general/retention_days';
    private const XML_PATH_LOG_FILES = 'lr_log_cleaner/general/log_files';
    private const XML_PATH_BACKUP_BEFORE_DELETE = 'lr_log_cleaner/general/backup_before_delete';
    private const XML_PATH_BACKUP_RETENTION_DAYS = 'lr_log_cleaner/general/backup_retention_days';

    // Performance constants
    private const BATCH_PROCESSING_FILE_SIZE_THRESHOLD = 52428800; // 50MB
    private const DEFAULT_BATCH_SIZE = 100; // Entries per batch (increased for better performance)
    private const MAX_BATCHES_FOR_LARGE_FILES = 300; // Maximum batches to process for large files
    private const LARGE_FILE_SAMPLE_SIZE = 2000; // Increased sample size for better estimation accuracy
    private const MEMORY_LIMIT_CHECK_INTERVAL = 5; // Check memory every N batches (more frequent for larger batches)

    /**
     * Constructor
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * Check if log cleaner is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get retention period in days
     *
     * @param int|null $storeId
     * @return int
     */
    public function getRetentionDays(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_RETENTION_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get selected log files for cleanup
     *
     * @param int|null $storeId
     * @return array
     */
    public function getLogFiles(?int $storeId = null): array
    {
        $logFiles = $this->scopeConfig->getValue(
            self::XML_PATH_LOG_FILES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($logFiles)) {
            return [];
        }

        return explode(',', $logFiles);
    }

    /**
     * Check if backup before delete is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isBackupEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BACKUP_BEFORE_DELETE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get backup retention period in days
     *
     * @param int|null $storeId
     * @return int
     */
    public function getBackupRetentionDays(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_BACKUP_RETENTION_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get retention timestamp (current time minus retention days)
     *
     * @param int|null $storeId
     * @return int
     */
    public function getRetentionTimestamp(?int $storeId = null): int
    {
        $retentionDays = $this->getRetentionDays($storeId);
        return time() - ($retentionDays * 24 * 60 * 60);
    }

    /**
     * Get backup retention timestamp
     *
     * @param int|null $storeId
     * @return int
     */
    public function getBackupRetentionTimestamp(?int $storeId = null): int
    {
        $retentionDays = $this->getBackupRetentionDays($storeId);
        if ($retentionDays <= 0) {
            return 0;
        }
        return time() - ($retentionDays * 24 * 60 * 60);
    }

    /**
     * Get supported log date patterns
     *
     * @return array
     */
    public function getLogDatePatterns(): array
    {
        return [
            // [2025-09-17T09:35:19.378887+00:00] pattern with microseconds
            '/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:[+-]\d{2}:\d{2})?\]/',
            // [2025-09-15T07:16:56.930479+00:00] pattern (system.log, debug.log, payment.log)
            '/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/',
            // 2025-09-01T06:09:00+00:00 pattern (admin_orders.log, other logs)
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/',
            // 2025-01-01 pattern (simple date format)
            '/^(\d{4}-\d{2}-\d{2})/',
        ];
    }

    /**
     * Extract date from log line
     *
     * @param string $line
     * @return string|null
     */
    public function extractDateFromLogLine(string $line): ?string
    {
        $patterns = $this->getLogDatePatterns();

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Check if log line is older than retention period
     *
     * @param string $line
     * @param int $retentionTimestamp
     * @return bool
     */
    public function isLogLineOld(string $line, int $retentionTimestamp): bool
    {
        $dateString = $this->extractDateFromLogLine($line);

        if (!$dateString) {
            return false; // Keep lines without date patterns
        }

        try {
            $lineTimestamp = strtotime($dateString);
            if ($lineTimestamp === false) {
                return false; // Keep lines with invalid dates
            }

            return $lineTimestamp < $retentionTimestamp;
        } catch (\Exception $e) {
            return false; // Keep lines that can't be parsed
        }
    }

    /**
     * Clean log content by removing old entries
     *
     * @param string $logContent
     * @param int $retentionTimestamp
     * @return string
     */
    public function cleanLogContent(string $logContent, int $retentionTimestamp): string
    {
        $lines = explode("\n", $logContent);
        $cleanedLines = [];
        $currentEntry = [];
        $keepCurrentEntry = true;

        foreach ($lines as $line) {
            $dateFromLine = $this->extractDateFromLogLine($line);

            if ($dateFromLine !== null) {
                // This is a new log entry starting line
                if (!empty($currentEntry) && $keepCurrentEntry) {
                    $cleanedLines = array_merge($cleanedLines, $currentEntry);
                }

                $currentEntry = [$line];
                $keepCurrentEntry = !$this->isLogLineOld($line, $retentionTimestamp);
            } else {
                // This is a continuation line of the current entry
                $currentEntry[] = $line;
            }
        }

        // Add the last entry if it should be kept
        if (!empty($currentEntry) && $keepCurrentEntry) {
            $cleanedLines = array_merge($cleanedLines, $currentEntry);
        }

        return implode("\n", $cleanedLines);
    }

    /**
     * Clean log content in batches for better performance with limits for large files
     *
     * @param string $filePath
     * @param int $retentionTimestamp
     * @param int $batchSize
     * @param OutputInterface|null $output
     * @return array
     */
    public function cleanLogFileInBatches(string $filePath, int $retentionTimestamp, int $batchSize = 100, $output = null): array
    {
        $tempFile = $filePath . '.tmp';
        $tempHandle = fopen($tempFile, 'w');
        $fileHandle = fopen($filePath, 'r');

        if (!$fileHandle || !$tempHandle) {
            throw new \Exception('Cannot open file for batch processing: ' . $filePath);
        }

        $currentEntry = [];
        $keepCurrentEntry = true;
        $processedEntries = 0;
        $removedEntries = 0;
        $totalEntries = 0;
        $batchCount = 0;
        $maxBatches = $this->getMaxBatchesForFile($filePath);
        $memoryCheckInterval = self::MEMORY_LIMIT_CHECK_INTERVAL;

        while (($line = fgets($fileHandle)) !== false) {
            $line = rtrim($line, "\r\n");
            $dateFromLine = $this->extractDateFromLogLine($line);

            if ($dateFromLine !== null) {
                // Process previous entry
                if (!empty($currentEntry)) {
                    $totalEntries++;
                    if ($keepCurrentEntry) {
                        fwrite($tempHandle, implode("\n", $currentEntry) . "\n");
                    } else {
                        $removedEntries++;
                    }
                    $processedEntries++;

                    // Progress update every batch
                    if ($processedEntries % $batchSize === 0) {
                        $batchCount++;
                        if ($output) {
                            $memoryUsage = $this->formatBytes(memory_get_usage(true));
                            $output->writeln("📦 <fg=cyan>Batch {$batchCount}/{$maxBatches}:</fg=cyan> {$processedEntries} processed, {$removedEntries} removed <fg=yellow>({$memoryUsage} memory)</fg=yellow>");
                        }

                        // Check if we've reached the maximum batch limit for large files
                        if ($batchCount >= $maxBatches) {
                            if ($output) {
                                $output->writeln("⚠️  <fg=yellow>Reached maximum batch limit ({$maxBatches}) for performance. Processed {$processedEntries} total entries.</fg=yellow>");
                            }
                            break;
                        }

                        // Memory management every few batches
                        if ($batchCount % $memoryCheckInterval === 0) {
                            $this->performMemoryCleanup($output);

                            // Check memory usage and warn if getting high
                            $memoryUsage = memory_get_usage(true);
                            $memoryLimit = $this->getMemoryLimitBytes();
                            if ($memoryLimit > 0 && $memoryUsage > ($memoryLimit * 0.8)) {
                                if ($output) {
                                    $output->writeln("⚠️  <fg=yellow>High memory usage detected. Consider reducing batch size.</fg=yellow>");
                                }
                            }
                        }

                        // Force memory cleanup
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }
                }

                // Start new entry
                $currentEntry = [$line];
                $keepCurrentEntry = !$this->isLogLineOld($line, $retentionTimestamp);
            } else {
                // This is a continuation line of the current entry
                $currentEntry[] = $line;
            }
        }

        // Process the last entry
        if (!empty($currentEntry)) {
            $totalEntries++;
            if ($keepCurrentEntry) {
                fwrite($tempHandle, implode("\n", $currentEntry) . "\n");
            } else {
                $removedEntries++;
            }
            $processedEntries++;
        }

        fclose($fileHandle);
        fclose($tempHandle);

        // Replace original file with cleaned version
        if (!rename($tempFile, $filePath)) {
            unlink($tempFile);
            throw new \Exception('Failed to replace original file with cleaned version: ' . $filePath);
        }

        // Final cleanup
        $this->performMemoryCleanup($output);

        return [
            'total_entries' => $totalEntries,
            'removed_entries' => $removedEntries,
            'processed_entries' => $processedEntries,
            'batches_processed' => $batchCount,
            'max_batches_limit' => $maxBatches,
            'stopped_early' => $batchCount >= $maxBatches
        ];
    }

    /**
     * Count actual log entries in content (not just lines)
     *
     * @param string $logContent
     * @return int
     */
    public function countLogEntries(string $logContent): int
    {
        if (empty($logContent)) {
            return 0;
        }

        $lines = explode("\n", $logContent);
        $entryCount = 0;

        foreach ($lines as $line) {
            // Count lines that start with a date pattern as entries
            if ($this->extractDateFromLogLine($line) !== null) {
                $entryCount++;
            }
        }

        return $entryCount;
    }

    /**
     * Check if file should use batch processing based on size
     *
     * @param string $filePath
     * @return bool
     */
    public function shouldUseBatchProcessing(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        return filesize($filePath) > self::BATCH_PROCESSING_FILE_SIZE_THRESHOLD;
    }

    /**
     * Get batch size for processing
     *
     * @return int
     */
    public function getBatchSize(): int
    {
        return self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Get file size threshold for batch processing
     *
     * @return int
     */
    public function getBatchProcessingThreshold(): int
    {
        return self::BATCH_PROCESSING_FILE_SIZE_THRESHOLD;
    }

    /**
     * Get maximum number of batches to process for a given file
     *
     * @param string $filePath
     * @return int
     */
    public function getMaxBatchesForFile(string $filePath): int
    {
        if (!file_exists($filePath)) {
            return self::MAX_BATCHES_FOR_LARGE_FILES;
        }

        $fileSize = filesize($filePath);

        // For files larger than 50MB, limit to 300 batches for performance
        if ($fileSize > self::BATCH_PROCESSING_FILE_SIZE_THRESHOLD) {
            return self::MAX_BATCHES_FOR_LARGE_FILES;
        }

        // For smaller files, allow unlimited batches (will process entire file)
        return PHP_INT_MAX;
    }

    /**
     * Perform memory cleanup and optimization
     *
     * @param OutputInterface|null $output
     * @return void
     */
    private function performMemoryCleanup($output = null): void
    {
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            $collected = gc_collect_cycles();
            if ($output && $collected > 0) {
                $output->writeln("🧹 <fg=blue>Memory cleanup: freed {$collected} objects</fg=blue>");
            }
        }

        // Clear any potential memory leaks
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }

    /**
     * Get memory limit in bytes
     *
     * @return int
     */
    private function getMemoryLimitBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return 0; // No limit
        }

        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $memoryLimit = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $memoryLimit *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $memoryLimit *= 1024 * 1024;
                break;
            case 'k':
                $memoryLimit *= 1024;
                break;
        }

        return $memoryLimit;
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Fast estimation for large files without loading into memory
     * Uses multiple sampling points for better accuracy
     *
     * @param string $filePath
     * @param int $retentionTimestamp
     * @return int
     */
    public function estimateRemovalsFast(string $filePath, int $retentionTimestamp): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }

        $fileHandle = fopen($filePath, 'r');
        if (!$fileHandle) {
            return 0;
        }

        $fileSize = filesize($filePath);
        $maxSampleLines = self::LARGE_FILE_SAMPLE_SIZE;
        $sampleIntervals = 3; // Take samples from beginning, middle, and end

        // Sample from multiple points in the file for better accuracy
        $samplePositions = [
            0, // Beginning
            (int)($fileSize * 0.5), // Middle
            (int)($fileSize * 0.8)  // Near end
        ];

        $totalRemovedInAllSamples = 0;
        $totalEntriesInAllSamples = 0;
        $validSamples = 0;
        $totalLinesInAllSamples = 0;

        foreach ($samplePositions as $position) {
            fseek($fileHandle, $position);

            // Skip partial line if not at beginning
            if ($position > 0) {
                fgets($fileHandle); // Skip potentially partial line
            }

            $sampleRemovedEntries = 0;
            $sampleTotalEntries = 0;
            $sampleLinesRead = 0;
            $maxLinesThisSample = (int)($maxSampleLines / $sampleIntervals);

            // Read sample from this position
            while (($line = fgets($fileHandle)) !== false && $sampleLinesRead < $maxLinesThisSample) {
                $sampleLinesRead++;
                $trimmedLine = rtrim($line, "\r\n");

                if ($this->extractDateFromLogLine($trimmedLine) !== null) {
                    $sampleTotalEntries++;
                    if ($this->isLogLineOld($trimmedLine, $retentionTimestamp)) {
                        $sampleRemovedEntries++;
                    }
                }
            }

            if ($sampleTotalEntries > 0) {
                $totalRemovedInAllSamples += $sampleRemovedEntries;
                $totalEntriesInAllSamples += $sampleTotalEntries;
                $totalLinesInAllSamples += $sampleLinesRead;
                $validSamples++;
            }
        }

        fclose($fileHandle);

        // If no valid samples found, no removals expected
        if ($totalEntriesInAllSamples === 0 || $validSamples === 0) {
            return 0;
        }

        // If no old entries found in any sample, no removals expected
        if ($totalRemovedInAllSamples === 0) {
            return 0;
        }

        // Calculate more accurate estimation based on multiple samples
        $totalFileLines = $this->getFileLineCount($filePath);
        $avgEntryToLineRatio = $totalEntriesInAllSamples / $totalLinesInAllSamples;
        $estimatedTotalEntries = (int) round($totalFileLines * $avgEntryToLineRatio);

        $removalRatio = $totalRemovedInAllSamples / $totalEntriesInAllSamples;

        // Apply batch limit constraint to estimation
        $maxBatches = $this->getMaxBatchesForFile($filePath);
        $maxProcessableEntries = $maxBatches * $this->getBatchSize();
        $estimatedRemovals = (int) round($estimatedTotalEntries * $removalRatio);

        // If we'll hit the batch limit, adjust estimation accordingly
        if ($estimatedTotalEntries > $maxProcessableEntries) {
            $processingRatio = $maxProcessableEntries / $estimatedTotalEntries;
            $estimatedRemovals = (int) round($estimatedRemovals * $processingRatio);
        }

        return $estimatedRemovals;
    }

    /**
     * Get approximate line count for large files efficiently
     * Uses improved sampling for better accuracy
     *
     * @param string $filePath
     * @return int
     */
    private function getFileLineCount(string $filePath): int
    {
        $fileHandle = fopen($filePath, 'r');
        if (!$fileHandle) {
            return 0;
        }

        $sampleSize = 100000; // Increased sample size for better accuracy (100KB)
        $bytesRead = 0;
        $sampleLines = 0;

        // Count lines in sample
        while (($line = fgets($fileHandle)) !== false && $bytesRead < $sampleSize) {
            $bytesRead += strlen($line);
            $sampleLines++;
        }

        fclose($fileHandle);

        if ($sampleLines === 0 || $bytesRead === 0) {
            return 0;
        }

        // Estimate total lines based on file size and sample
        $fileSize = filesize($filePath);
        $avgLineLength = $bytesRead / $sampleLines;
        return (int) round($fileSize / $avgLineLength);
    }
}