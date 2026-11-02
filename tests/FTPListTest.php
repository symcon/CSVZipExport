<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

include_once __DIR__ . '/../libs/FTP.php';

class FTPListTest extends TestCase
{
    public function testUnixList(): void
    {
        $list = FTP::parseList([
            'total 12',
            'drwxrwxrwx   1 root     root             1184 Apr 26 14:57 Public',
            'drwxrws---+  2 admin    users            4096 Jan  3  2024 My Exports',
            '-rw-r--r--   1 admin    users             512 Nov  2 10:00 export.zip',
        ]);
        $this->assertEquals([
            ['size' => '1184', 'mode' => 0x41FF, 'type' => 2, 'filename' => 'Public'],
            ['size' => '4096', 'mode' => 0x41F8, 'type' => 2, 'filename' => 'My Exports'],
            ['size' => '512', 'mode' => 0x61A4, 'type' => 1, 'filename' => 'export.zip'],
        ], $list);
    }

    public function testWindowsList(): void
    {
        $list = FTP::parseList([
            '04-26-23  02:57PM       <DIR>          Public',
            '11-02-2026  10:00       <DIR>          My Exports',
            '11-02-26  10:00AM                  512 export.zip',
        ]);
        $this->assertEquals([
            ['size' => 0, 'type' => 2, 'filename' => 'Public'],
            ['size' => 0, 'type' => 2, 'filename' => 'My Exports'],
            ['size' => 512, 'type' => 1, 'filename' => 'export.zip'],
        ], $list);
    }

    public function testMLSD(): void
    {
        $list = FTP::parseMLSD([
            ['name' => '.', 'type' => 'cdir', 'modify' => '20261102100000'],
            ['name' => '..', 'type' => 'pdir', 'modify' => '20261102100000'],
            ['name' => 'My Exports', 'type' => 'dir', 'modify' => '20261102100000'],
            ['name' => 'export.zip', 'type' => 'file', 'size' => '512', 'modify' => '20261102100000'],
        ]);
        $this->assertEquals([
            ['size' => 0, 'type' => 2, 'filename' => 'My Exports'],
            ['size' => 512, 'type' => 1, 'filename' => 'export.zip'],
        ], $list);
    }
}
