<?php
declare(strict_types=1);

namespace gCore\gNode\Exception;

/**
 * ConfigException - Exception for configuration errors
 *
 * Thrown when:
 * - Required configuration is missing
 * - Credentials cannot be resolved
 * - Invalid configuration values
 *
 * @package gCore\gNode\Exception
 */
class ConfigException extends gNodeException
{
}
