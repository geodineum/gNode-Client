<?php

namespace gCore\GSD\Exception;

/**
 * GSDException - Base exception for GSD client
 *
 * @package gCore\GSD\Exception
 */
class GSDException extends \Exception
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
