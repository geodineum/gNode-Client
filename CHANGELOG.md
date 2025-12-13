# Changelog

## 2.0.0 - 2025-04-28

### Added
- Support for new batch message types (`bc` for batch commands, `br` for batch responses)
- New `executeBatch()` method for efficient batch command execution
- Enhanced batch response handling in ConsumerGroupHandler
- Batch command support in ConsumerGroupHandler with new `sendBatchCommand()` method
- Support for the updated GSD unified stream protocol (v2)

### Changed
- Updated response filtering to recognize 'br' type batch responses
- Modified batch message format according to GSD protocol v2
- Updated README with new message type documentation
- Added detailed batch command and response documentation

### Fixed
- Eliminated processing loop issue by differentiating batch command and response types

## 1.5.0 - 2025-04-15

### Added
- Command name abbreviation support for optimized messaging
- Enhanced protocol support with RESP3 optimization
- Val1Key function integration for protocol operations

### Changed
- Unified stream handling improvements for performance
- Optimized response parsing for reduced protocol overhead

## 1.0.0 - 2025-03-20

### Added
- Initial release of the GSD client library
- Support for geometric service discovery
- Support for consumer group operations
- ValKey/Redis stream communication
- Fallback handling for resilience
- Client capabilities API
- Support for multiple connection modes