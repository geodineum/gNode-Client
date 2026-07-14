<?php
declare(strict_types=1);

namespace gCore\gNode\Exception;

/**
 * StorageException - Exception for storage errors
 *
 * @package gCore\gNode\Exception
 */
class StorageException extends gNodeException
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "Storage error", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
