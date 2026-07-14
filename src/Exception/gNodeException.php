<?php
declare(strict_types=1);

namespace gCore\gNode\Exception;

/**
 * gNodeException - Base exception for gNode client
 *
 * @package gCore\gNode\Exception
 */
class gNodeException extends \Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
