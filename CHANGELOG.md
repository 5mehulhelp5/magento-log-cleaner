# Changelog

All notable changes to the LR Log Cleaner module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-09-18

### Added
- Automated log entry cleanup with configurable retention periods (default 90 days)
- Intelligent batch processing for large files (>50MB) with chunks of 100 entries
- Multi-point estimation for accurate dry run predictions using beginning, middle, and end sampling
- Real-time memory usage monitoring during batch processing
- Automatic garbage collection every 5 batches for improved memory management
- Early stopping logic that terminates batch processing when no old entries found
- Performance protection with maximum 300 batch limit (30,000 entries) for very large files
- Enhanced progress reporting that distinguishes between "processed" and "removed" entries
- Content-based cleaning that preserves recent entries while removing old ones
- Multi-line log entry support for stack traces, exceptions, and JSON objects
- Advanced date pattern recognition supporting multiple timestamp formats
- Optional compressed backup functionality with gzip compression
- Rich console command interface with dry-run mode (`bin/magento lr:logs:clean`)
- Complete admin configuration panel under Stores > Configuration > Cell Israel Config
- ACL integration with proper admin permissions (`LR_LogCleaner::logcleaner_config`)
- Accurate entry counting system that counts log entries (not lines)
- Comprehensive error handling and logging to system.log
- Dynamic log file discovery in `/var/log/` directory
- Emoji-rich console output with colored feedback
- Stream-based processing for memory optimization
- Automatic processing mode selection (standard vs batch)
- Real-time progress indicators with batch progress tracking
- Backup management with separate retention periods and automatic cleanup
- Console command with dry-run mode for safe testing
- Cron job automation running every 2 hours (0 */2 * * *)
- Selective file processing with multi-select admin interface

### Technical Features
- Magento 2.4.4+ compatibility
- PHP 8.1+ support with strict types and modern features
- Proper dependency injection following Magento 2 standards
- Module dependency management with required modules
- Support for configurable retention periods via admin panel
- Intelligent multi-line entry parsing using advanced regex patterns
- Performance optimization with batch processing threshold at 50MB
- Memory management with real-time monitoring and automatic cleanup
- CLI output that clearly separates "processed" vs "removed" entry counts
- Enhanced error messages with descriptive feedback for troubleshooting
- Admin panel schedule information showing current cron configuration
- Comprehensive logging of all operations with detailed statistics
- Backup creation with timestamp-based naming and gzip compression
- Early termination for performance on very large files
- Multi-format timestamp support including microseconds and timezones

### Security & Safety
- Proper file permission handling and validation
- Safe content operations with rollback capabilities
- ACL-protected admin functionality
- Input validation and sanitization
- Exception handling with proper error logging
- Backup creation before any destructive operations

### Performance Optimizations
- Batch processing eliminates memory issues with large files
- Stream-based file reading instead of loading entire files
- Automatic mode selection based on file size
- Real-time memory monitoring and garbage collection
- Early stopping when no old entries found
- Compressed backups reduce storage requirements by 80-90%
- Efficient regex patterns for date recognition
- Optimized database queries and caching

---

## Release Notes

### Version 1.0.0 Highlights
This initial release provides a comprehensive, production-ready log management solution that intelligently manages Magento log files. Unlike simple file deletion tools, this module parses log content to remove only old entries while preserving recent activity, making it ideal for production environments where log continuity is essential.

**Key Benefits:**
- **Intelligent Processing**: Content-aware cleaning that preserves file structure
- **Performance Optimized**: Handles files of any size through advanced batch processing
- **Production Safe**: Comprehensive backup system and error handling
- **User Friendly**: Rich CLI interface and complete admin panel integration
- **Maintenance Free**: Automated 2-hour cron schedule with performance protection
- **Developer Focused**: Clear progress reporting and detailed logging for troubleshooting