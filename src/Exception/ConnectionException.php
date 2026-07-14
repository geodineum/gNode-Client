<?php
declare(strict_types=1);

namespace gCore\gNode\Exception;

/**
 * ConnectionException - Exception for connection errors
 *
 * @package gCore\gNode\Exception
 */
class ConnectionException extends gNodeException
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "Connection failed", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
