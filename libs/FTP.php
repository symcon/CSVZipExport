<?php

declare(strict_types=1);

class FTP
{
    public $connection;

    public function __construct($host, $port)
    {
        $this->connection = ftp_connect($host, $port);
        if (!$this->connection) {
            throw new ErrorException('Error: Host/Port is invalid', 10060);
        }
    }

    public function chdir(string $dir): bool
    {
        $result = @ftp_chdir($this->connection, $dir);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function rawlist(string $dir = '.')
    {
        $result = @ftp_rawlist($this->connection, $dir);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        //Prepare Result to match to the SFTP Structure
        $dump = [];
        foreach ($result as $value) {
            $info = preg_split("/[\s]+/", $value, 9);
            /*
            Array like SFTP:
            [
                    'size' => 1184,
                    'uid' => 0,
                    'gid' => 0,
                    'mode' => 16895,
                    'type' => 2,
                    'atime' => 1683017468,
                    'mtime' => 1682521030,
                    'filename' => 'Public',
            ]
            Same in FTP:
            drwxrwxrwx   1 root     root             1184 Apr 26 14:57 Public
             */
            $type = $info[0][0] == 'd' ? '0100' : '0110';

            $mode = substr($info[0], 1);
            $mode = str_replace('-', '0', $mode);
            $mode = str_replace('r', '1', $mode);
            $mode = str_replace('w', '1', $mode);
            $mode = str_replace('x', '1', $mode);
            $mode = bindec($type . '000' . $mode);

            array_push($dump, [
                'size'     => $info[4],
                'mode'     => $mode,
                'type'     => $type == '0100' ? 2 : 1,
                'filename' => $info[8],
            ]);
        }
        return $dump;
    }

    public function delete(string $path): bool
    {
        $result = @ftp_delete($this->connection, $path);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function is_dir(string $path): bool
    {
        //Found there: https://gist.github.com/Dare-NZ/5523650#file-is_dir-php-L37
        if (@ftp_chdir($this->connection, $path)) {
            // We can only go up, if we are not at the root level
            if ($path != '/') {
                ftp_cdup($this->connection);
            }
            return true;
        } else {
            return false;
        }
    }

    public function pwd()
    {
        $result = @ftp_pwd($this->connection);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function mkdir(string $dir)
    {
        if (!$this->is_dir($dir)) {
            $result = @ftp_mkdir($this->connection, $dir);
            if ($result === false) {
                throw new Exception(error_get_last()['message']);
            }
            return $result;
        }
    }

    public function put(string $remote_file, string $data): bool
    {
        $result = @ftp_put($this->connection, $remote_file, $data);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function filesize(string $path)
    {
        $result = @ftp_size($this->connection, $path);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function filemtime(string $path)
    {
        $result = @ftp_mdtm($this->connection, $path);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function file_exists($path)
    {
        $result = ftp_size($this->connection, $path);
        if ($result == -1) {
            return false;
        } else {
            return true;
        }
    }

    public function login(string $username, string $password)
    {
        $result = @ftp_login($this->connection, $username, $password);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function disconnect()
    {
        $result = @ftp_close($this->connection);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }
}