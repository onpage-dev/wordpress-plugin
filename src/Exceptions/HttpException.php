<?php



namespace OnPage\Exceptions;



class HttpException extends \Exception
{
    public function __construct(
        string $message,
        public int $status_code = 500,
        public string $error_code = 'onpage_api_error',
    )
    {
        parent::__construct($message, 0);
    }
}
