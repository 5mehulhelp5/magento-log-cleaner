<?php
declare(strict_types=1);

namespace LR\LogCleaner\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Exception\FileSystemException;
use Psr\Log\LoggerInterface;

/**
 * Source model for log files selection
 *
 * Dynamically discovers all .log files in the var/log directory
 */
class LogFiles implements OptionSourceInterface
{
    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DriverInterface
     */
    private $driver;

    /**
     * Constructor
     *
     * @param DirectoryList $directoryList
     * @param LoggerInterface $logger
     * @param DriverInterface $driver
     */
    public function __construct(
        DirectoryList $directoryList,
        LoggerInterface $logger,
        DriverInterface $driver
    ) {
        $this->directoryList = $directoryList;
        $this->logger = $logger;
        $this->driver = $driver;
    }

    /**
     * Get options array for log files
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Select Log Files --')],
            ['value' => 'all', 'label' => __('All Log Files')],
        ];

        try {
            $logPath = $this->directoryList->getRoot() . '/var/log';
            $logFiles = $this->getLogFiles($logPath);

            foreach ($logFiles as $logFile) {
                $options[] = [
                    'value' => $logFile,
                    'label' => $logFile,
                ];
            }
        } catch (FileSystemException $e) {
            $this->logger->error('LogCleaner: Failed to read log directory: ' . $e->getMessage());
        }

        return $options;
    }

    /**
     * Get array of log files
     *
     * @param string $logPath
     * @return array
     */
    private function getLogFiles(string $logPath): array
    {
        $logFiles = [];

        if (!$this->driver->isDirectory($logPath)) {
            return $logFiles;
        }

        $iterator = new \DirectoryIterator($logPath);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'log') {
                $logFiles[] = $file->getFilename();
            }
        }

        sort($logFiles);
        return $logFiles;
    }
}