<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class CSVZipExportValidationTest extends TestCaseSymconValidation
{
    public function testValidateCSVZipExport(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateCSVZipExportModule(): void
    {
        $this->validateModule(__DIR__ . '/../CSVZipExport');
    }
}