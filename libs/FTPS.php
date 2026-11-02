<?php

declare(strict_types=1);

class FTPS extends FTP
{
    public $connection;

    public function __construct($host, $port)
    {
        $this->connection = ftp_ssl_connect($host, $port, self::TIMEOUT);
        if (!$this->connection) {
            throw new ErrorException('Error: Host/Port is invalid', 10060);
        }
    }
}