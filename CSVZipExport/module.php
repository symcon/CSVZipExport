<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';
use phpseclib3\Net\SFTP;

include_once __DIR__ . '/../libs/WebHookModule.php';
include_once __DIR__ . '/../libs/FTP.php';
include_once __DIR__ . '/../libs/FTPS.php';

class CSVZipExport extends WebHookModule
{
    private $archiveID;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'zip/' . $InstanceID);
        $this->archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Properties
        $this->RegisterPropertyString('ExportOption', 'single');
        $this->RegisterPropertyString('SelectedVariables', '[]');
        $this->RegisterPropertyBoolean('ZipFile', true);
        $this->RegisterPropertyInteger('ArchiveVariable', 0);
        $this->RegisterPropertyString('DecimalSeparator', ',');
        $this->RegisterPropertyInteger('AggregationStage', 1);
        $dateFormat = '{"year":%d,"month":%d,"day":%d,"hour":%d,"minute":%d,"second":%d}';
        $this->RegisterPropertyString('AggregationStart', sprintf($dateFormat, date('Y'), date('m'), 1, 0, 0, 0));
        $this->RegisterPropertyString('AggregationEnd', sprintf($dateFormat, date('Y'), date('m'), date('d'), 23, 59, 59));
        // Properties for Mail
        $this->RegisterPropertyInteger('MailInterval', 0);
        $this->RegisterPropertyInteger('SMTPInstance', 0);
        $this->RegisterPropertyBoolean('IntervalStatus', false);
        $this->RegisterPropertyBoolean('SendMail', false);
        $this->RegisterPropertyBoolean('SendFTP', false);
        $this->RegisterPropertyString('MailTime', '{"hour":12,"minute":0,"second":0}');
        // Properties for FTP & co
        $this->RegisterPropertyString('ConnectionType', 'SFTP');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 22);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('TargetDir', '');

        //Timer
        $this->RegisterTimer('DeleteZipTimer', 0, 'CSV_DeleteZip($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MailTimer', 0, 'CSV_SendCyclic($_IPS[\'TARGET\'], \'\');');

        $this->DeleteZip();

    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->setStatus($this->checkInstanceStatus());
        $this->UpdateCyclicInterval();
    }

    /** Version 1.x only sent a single ArchiveVariable per Mail. Keep such a configuration working */
    public function Migrate($JSONData)
    {
        //Never delete this line!
        parent::Migrate($JSONData);

        $data = json_decode($JSONData);
        $configuration = $data->configuration;
        //The ExportOption does not exist in version 1.x
        if (isset($configuration->ExportOption)) {
            return '';
        }
        if ($configuration->IntervalStatus ?? false) {
            $configuration->SendMail = true;
        }
        $archiveVariable = $configuration->ArchiveVariable ?? 0;
        if ($archiveVariable != 0) {
            if (IPS_ModuleExists('{7E122824-E4D6-4FF8-8AA1-2B7BB36D5EC9}')) {
                $configuration->ExportOption = 'mysql';
            } else {
                $configuration->ExportOption = 'single';
                $configuration->SelectedVariables = json_encode([['UserDefinedName' => '', 'SelectedVariable' => $archiveVariable]]);
            }
        }
        return json_encode($data);
    }
    /** Change things in the ConfigurationForm before loading */
    public function GetConfigurationForm()
    {
        //Add options to form
        $jsonForm = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        //* Set visible based on the option
        // json['elements'][1] = FilterRow
        // json['elements'][2] = ArchiveVariable
        // json['elements'][3] = SelectedVariables List
        // json['elements'][4] = Label SingleInfo
        // json['elements'][5] = Label MultiInfo
        // json['elements'][6] = Checkbox Zip
        $option = $this->ReadPropertyString('ExportOption');
        $jsonForm['elements'][1]['visible'] = $option == 'mysql';
        $jsonForm['elements'][2]['visible'] = $option == 'mysql';
        $jsonForm['elements'][3]['visible'] = $option != 'mysql';
        $jsonForm['elements'][4]['visible'] = $option == 'single';
        $jsonForm['elements'][5]['visible'] = $option == 'multi';
        $jsonForm['elements'][6]['value'] = $option != 'multi' ? true : $this->ReadPropertyBoolean('ZipFile');
        $jsonForm['elements'][6]['enabled'] = $option == 'multi';

        //If the module "SyncMySQL" is installed, the logged variables can be selected by their ident
        if (IPS_ModuleExists('{7E122824-E4D6-4FF8-8AA1-2B7BB36D5EC9}')) {
            $jsonForm['elements'][2]['options'] = $this->GetOptions();
        } elseif ($option != 'mysql') {
            //Without SyncMySQL the variables are selected in the list
            $jsonForm['elements'][0]['options'] = array_values(array_filter($jsonForm['elements'][0]['options'], function ($exportOption)
            {
                return $exportOption['value'] != 'mysql';
            }));
        }

        return json_encode($jsonForm);
    }
    // UI Functions
    /** UI Function onChange of the Export Options */
    public function UIChangeExportOption(string $exportOption): void
    {
        $this->UpdateFormField('FilterRow', 'visible', $exportOption == 'mysql');
        $this->UpdateFormField('ArchiveVariable', 'visible', $exportOption == 'mysql');
        $this->UpdateFormField('SelectedVariables', 'visible', $exportOption != 'mysql');
        $this->UpdateFormField('SingleInfo', 'visible', $exportOption == 'single');
        $this->UpdateFormField('ZipFile', 'value', $exportOption != 'multi' ? true : $this->ReadPropertyBoolean('ZipFile'));
        $this->UpdateFormField('ZipFile', 'enabled', $exportOption == 'multi');
        $this->UpdateFormField('MultiInfo', 'visible', $exportOption == 'multi');
    }
    /** UI Function to change the Port of the form based on the connection type, if it is still a default Port */
    public function UIChangePort(string $connectionType, int $port): void
    {
        if ($port == 21 || $port == 22) {
            $this->UpdateFormField('Port', 'value', $connectionType == 'SFTP' ? 22 : 21);
        }
    }
    /*UI Function to select the Connection Dir */
    public function UISelectDir(string $host, int $port, string $username, string $password, string $connectionType)
    {
        $this->UIGoDeeper('/', $host, $port, $username, $password, $connectionType);
    }

    public function UIAssumeDir(string $value, string $host, int $port, string $username, string $password, string $connectionType)
    {
        $connection = $this->createUIConnection($host, $port, $username, $password, $connectionType);
        if ($connection === false) {
            return;
        }
        $connection->chdir($value);
        $this->UpdateFormField('TargetDir', 'value', $connection->pwd());
        $connection->disconnect();
    }

    public function UILoadDir(string $dir, string $host, int $port, string $username, string $password, string $connectionType)
    {
        $connection = $this->createUIConnection($host, $port, $username, $password, $connectionType);
        if ($connection === false) {
            return;
        }
        $dirs = [];
        //Initial is '..' to handle a go up if $dir != '/'
        if ($dir != '' && $dir != '/') {
            array_push($dirs, [
                'SelectedDirectory' => '..',
                'DeeperDir'         => '⬑',
            ]);
        }
        $list = $connection->rawlist($dir);
        foreach ($list as $entry) {
            if ($entry['type'] == 2 &&
                ($entry['filename'] != '.' && $entry['filename'] != '..')
            ) {
                array_push($dirs, [
                    'SelectedDirectory' => $entry['filename'],
                    'DeeperDir'         => '↳'
                ]);
            }
        }
        $this->UpdateFormField('SelectTargetDirectory', 'values', json_encode($dirs));
        //If the root directory is empty
        if ($dirs == []) {
            echo $this->Translate('There are no directories, we assume the root as target');
            $this->UIAssumeDir($dir, $host, $port, $username, $password, $connectionType);
        }

        $connection->disconnect();
    }

    public function UIGoDeeper(string $value, string $host, int $port, string $username, string $password, string $connectionType)
    {
        $connection = $this->createUIConnection($host, $port, $username, $password, $connectionType);
        if ($connection === false) {
            return;
        }
        $connection->chdir($value);
        $this->UILoadDir($connection->pwd(), $host, $port, $username, $password, $connectionType);
        $this->UpdateFormField('CurrentDir', 'value', $connection->pwd());
        $connection->disconnect();
    }

    /** UI Function to test the Connection */
    public function UITestConnection()
    {
        $this->UpdateFormField('ProgressAlert', 'visible', true);
        try {
            $connection = $this->createConnectionEx(
                $this->ReadPropertyString('Host'),
                $this->ReadPropertyInteger('Port'),
                $this->ReadPropertyString('Username'),
                $this->ReadPropertyString('Password'),
                $this->ReadPropertyString('ConnectionType'),
            );
        } catch (\Throwable $th) {
            $this->UpdateFormField('InformationLabel', 'caption', $th->getMessage());
            $this->UpdateFormField('ExportBar', 'visible', false);
            return;
        }

        if ($connection) {
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('Connection is valid'));
            $this->UpdateFormField('ExportBar', 'visible', false);
            $connection->disconnect();
        }
    }
    //*---------------*//

    /** Handle the export via Button. Returns the relative path for the download or false */
    public function UIExport(int $AggregationStage, string $AggregationStart, string $AggregationEnd)
    {
        if (!$this->checkVariables()) {
            $this->SetStatus(201);
            echo $this->Translate('No valid variable is selected');
            return false;
        }
        $this->UpdateFormField('DownloadBar', 'visible', true);
        ini_set('memory_limit', '256M');
        $timeStamp = $this->validTimestamps(true, $AggregationStart, $AggregationEnd);
        $startTimeStamp = $timeStamp[0];
        $endTimeStamp = $timeStamp[1];

        $relativePath = $this->ExportVariables($AggregationStage, $startTimeStamp, $endTimeStamp);
        sleep(1);
        $this->UpdateFormField('DownloadBar', 'visible', false);
        if ($relativePath === false) {
            echo $this->Translate('The export failed');
            return false;
        }
        $this->SetStatus($this->checkInstanceStatus());
        //Reset ZipDeleteTimer
        $this->SetTimerInterval('DeleteZipTimer', 1000 * 60 * 60);

        return $relativePath;
    }

    /** Kept for scripts of version 1.x. Export a single variable with the times of the form. Returns the relative path for the download or false */
    public function UserExport(int $ArchiveVariable, int $AggregationStage, string $AggregationStart, string $AggregationEnd)
    {
        $timeStamp = $this->validTimestamps(true, $AggregationStart, $AggregationEnd);
        $relativePath = $this->Export($ArchiveVariable, $AggregationStage, $timeStamp[0], $timeStamp[1]);
        if ($relativePath !== false) {
            //Reset ZipDeleteTimer
            $this->SetTimerInterval('DeleteZipTimer', 1000 * 60 * 60);
        }
        return $relativePath;
    }

    /** Export the variables of the configuration. Returns the relative path for the download or false */
    public function ExportVariables(int $AggregationStage, int $startTimeStamp, int $endTimeStamp)
    {
        $limit = IPS_GetOption('ArchiveRecordLimit');
        return $this->runExport(function () use ($AggregationStage, $startTimeStamp, $endTimeStamp, $limit)
        {
            return match ($this->ReadPropertyString('ExportOption')) {
                'single' => $this->ExportMultiFile($AggregationStage, $startTimeStamp, $endTimeStamp, $limit),
                'multi'  => $this->ExportSingleFile($AggregationStage, $startTimeStamp, $endTimeStamp, $limit),
                'mysql'  => $this->ExportSingleVariable($this->ReadPropertyInteger('ArchiveVariable'), $AggregationStage, $startTimeStamp, $endTimeStamp, $limit),
            };
        });
    }

    /** Export a single variable to a Zip File like version 1.x. Returns the relative path for the download or false */
    public function Export(int $ArchiveVariable, int $AggregationStage, int $AggregationStart, int $AggregationEnd)
    {
        $limit = IPS_GetOption('ArchiveRecordLimit');
        return $this->runExport(function () use ($ArchiveVariable, $AggregationStage, $AggregationStart, $AggregationEnd, $limit)
        {
            return $this->ExportSingleVariable($ArchiveVariable, $AggregationStage, $AggregationStart, $AggregationEnd, $limit);
        });
    }

    /** Delete the exported (Zip) File */
    public function DeleteZip()
    {
        foreach (['.zip', '.csv'] as $extension) {
            $tmpfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($this->InstanceID, $extension);
            if (file_exists($tmpfile)) {
                unlink($tmpfile);
            }
        }
        $this->SetTimerInterval('DeleteZipTimer', 0);
    }

    /** For SyncMySQL-Module */
    public function UpdateFilter(string $Filter) //For SyncMySQL-Module
    {
        $options = $this->GetOptions($Filter);
        if (count($options) == 0) {
            $options = [[
                'caption' => '-----------------------',
                'value'   => 0
            ]];
        }
        $this->UpdateFormField('ArchiveVariable', 'options', json_encode($options));
        $this->UpdateFormField('ArchiveVariable', 'value', $options[0]['value']);
    }

    /** Kept for scripts of version 1.x */
    public function SendMail()
    {
        $this->SendCyclic('Mail');
    }

    /**
     * Send the export of the last interval per Mail and/or FTP.
     * Called by the timer with an empty $fromForm, by the buttons with "Mail" or "FTP" to only use that method.
     * Symcon ignores default values of parameters, so $fromForm is always required
     */
    public function SendCyclic(string $fromForm)
    {
        $this->UpdateFormField('ProgressAlert', 'visible', true);
        $this->UpdateFormField('InformationLabel', 'caption', '');
        $this->UpdateFormField('ExportBar', 'visible', true);

        try {
            //The timer checks the configuration again, so a failed sending is retried on the next interval.
            //The buttons only need valid variables, their method is checked while sending
            if ($fromForm == '') {
                $status = $this->checkInstanceStatus();
            } else {
                $status = $this->checkVariables() ? 102 : 201;
            }
            if ($status != 102) {
                $this->SetStatus($status);
                $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('Instance is not valid'));
                return;
            }
            $aggregationStage = $this->ReadPropertyInteger('AggregationStage');
            $timeStamp = $this->validTimestamps(false, $this->ReadPropertyString('AggregationStart'), $this->ReadPropertyString('AggregationEnd'));
            $startTimeStamp = $timeStamp[0];
            $endTimeStamp = $timeStamp[1];

            if ($this->ExportVariables($aggregationStage, $startTimeStamp, $endTimeStamp) === false) {
                $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('The export failed'));
                return;
            }
            $filePath = $this->getExportPath();

            $sent = true;
            if ($fromForm == '' ? $this->ReadPropertyBoolean('SendMail') : $fromForm == 'Mail') {
                $sent = $this->sendExportMail($filePath, $startTimeStamp, $endTimeStamp);
            }
            if ($fromForm == '' ? $this->ReadPropertyBoolean('SendFTP') : $fromForm == 'FTP') {
                $sent = $this->sendFTP($filePath) && $sent;
            }
            if ($sent) {
                //A sending via button must not hide an invalid configuration of the cyclic sending
                $this->SetStatus($this->checkInstanceStatus());
                $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('Done'));
            }
        } finally {
            //Clean up
            $this->UpdateFormField('ExportBar', 'visible', false);
            $this->DeleteZip();
            $this->UpdateCyclicInterval();
        }
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData()
    {
        $path = $this->getExportPath();
        if (!file_exists($path)) {
            http_response_code(404);
            echo $this->Translate('The export does not exist anymore. Please export again.');
            return;
        }
        $this->SendDebug('ProcessHookData', $path, 0);
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        if (str_ends_with($path, '.zip')) {
            header('Content-Type: application/zip; charset=utf-8;');
        } else {
            header('Content-Type: text/csv; charset=utf-8;');
        }
        header('Content-Length:' . filesize($path));
        readfile($path);
    }

    /**
     * Send the Zip / Csv File per Email
     */
    private function sendExportMail($absolutePath, $startTimeStamp, $endTimeStamp): bool
    {
        $smtpInstanceID = $this->ReadPropertyInteger('SMTPInstance');

        if (!@IPS_InstanceExists($smtpInstanceID)) {
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate("The selected SMTP-Instance doesn't exist"));
            $this->SetStatus(203);
            return false;
        }
        if (IPS_GetInstance($smtpInstanceID)['ModuleInfo']['ModuleID'] != '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}') {
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('No valid SMTP-Instance selected'));
            $this->SetStatus(202);
            return false;
        }

        $subject = sprintf($this->Translate('Summary of %s (%s to %s)'), IPS_GetName($this->InstanceID), date('d.m.Y H:i:s', $startTimeStamp), date('d.m.Y H:i:s', $endTimeStamp));
        if (!SMTP_SendMailAttachment($smtpInstanceID, $subject, $this->Translate('In the appendix you can find the created CSV-File.'), $absolutePath)) {
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('The mail could not be sent'));
            return false;
        }
        return true;
    }

    /** Send ZIP/ CSV per (S)FTP(S) */
    private function sendFTP($absolutePath): bool
    {
        $semaphore = 'SendCSV' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            $this->SendDebug('Connection', 'An other Connection is running', 0);
            return false;
        }
        $connection = false;
        try {
            //Create Connection
            try {
                $connection = $this->createConnection();
            } catch (\Throwable $th) {
                $this->UpdateFormField('InformationLabel', 'caption', $th->getMessage());
                $this->SetStatus(205);
                return false;
            }

            //Set the base directory on the remote. FTP throws and SFTP returns false if it does not exist
            $baseDir = $this->ReadPropertyString('TargetDir');
            try {
                $dirChanged = $baseDir == '' || $connection->chdir($baseDir);
            } catch (\Throwable $th) {
                $this->SendDebug('TargetDir', $th->getMessage(), 0);
                $dirChanged = false;
            }
            if (!$dirChanged) {
                $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('The target directory does not exist'));
                $this->SetStatus(206);
                return false;
            }

            // transfer file into the target directory
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('Transfer File'));
            try {
                $transferred = $connection->put(basename($absolutePath), $absolutePath, SFTP::SOURCE_LOCAL_FILE);
            } catch (\Throwable $th) {
                $this->SendDebug('Transfer', $th->getMessage(), 0);
                $transferred = false;
            }
            if (!$transferred) {
                $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('The file could not be transferred'));
                return false;
            }
            return true;
        } finally {
            if ($connection !== false) {
                $connection->disconnect();
            }
            IPS_SemaphoreLeave($semaphore);
        }
    }

    /**
     * Export multiple variable in a single file. It is a table [Timestamp, Variable1, ..., Variable n]
     */
    private function ExportSingleFile($level, $start, $end, $limit): bool
    {
        $this->SendDebug('SingleFile', '', 0);

        $content = '';
        $contentFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($this->InstanceID, '.csv');
        $this->SendDebug('path', $contentFile, 0);
        $list = json_decode($this->ReadPropertyString('SelectedVariables'), true);
        $getName = function ($variable) use ($list): string
        {
            foreach ($list as $key => $value) {
                if ($value['SelectedVariable'] == $variable) {
                    return $value['UserDefinedName'] != '' ? $value['UserDefinedName'] : IPS_GetName($variable);
                }
            }return '';
        };
        $listValues = []; // [Timestamp => [id => Variables]]
        $variables = [];
        foreach ($list as $key => $entry) {
            $selectedVariable = $entry['SelectedVariable'];
            if (!IPS_VariableExists($selectedVariable) || in_array($selectedVariable, $variables)) {
                continue;
            }
            $variables[] = $selectedVariable;

            $tempVariableFile = $this->getTempFile($selectedVariable);
            $this->fetchAllValues($tempVariableFile, $selectedVariable, $level, $start, $end, $limit);
            $loggedValues = explode("\n", rtrim(file_get_contents($tempVariableFile)));
            unlink($tempVariableFile);
            foreach ($loggedValues as $value) {
                //No values in the time range
                if ($value == '') {
                    continue;
                }
                $value = explode(';', $value);
                $timestamp = strtotime($value[0]);
                if (!array_key_exists($timestamp, $listValues)) {
                    $listValues[$timestamp] = [];
                }
                $listValues[$timestamp][$selectedVariable] = array_key_exists(1, $value) ? $value[1] : '';
            }
        }
        // sort timestamps
        krsort($listValues);
        // sort List of the Variables
        sort($variables);
        // add the Values to content
        $content .= implode(';', array_merge(['Date'], array_map($getName, $variables))) . "\n";

        foreach ($listValues as $timeStamp => $values) {
            //Keep the columns aligned, if a variable has no value at this timestamp
            $row = [date('d.m.Y H:i:s', $timeStamp)];
            foreach ($variables as $variable) {
                $row[] = $values[$variable] ?? '';
            }
            $content .= implode(';', $row) . "\n";
        }
        $this->SendDebug('Put in File ', $content, 0);
        file_put_contents($contentFile, $content);

        if ($this->ReadPropertyBoolean('ZipFile')) {
            $this->zipFile([[$contentFile, $this->GenerateFileName($this->InstanceID, '.csv')]]);
        }
        return count($variables) > 0;
    }

    /**
     * Export a single Variable to a file that wrapped with a zip Archive
     */
    private function ExportSingleVariable(int $variableID, $AggregationStage, $startTimeStamp, $endTimeStamp, $limit): bool
    {
        $this->SendDebug('Variable', $variableID, 0);
        if (!IPS_VariableExists($variableID)) {
            return false;
        }
        $contentFile = $this->getTempFile($variableID);
        $this->fetchAllValues($contentFile, $variableID, $AggregationStage, $startTimeStamp, $endTimeStamp, $limit);
        $this->zipFile([[$contentFile, $this->GenerateFileName($variableID, '.csv')]]);
        return true;
    }

    /** Exports of the same instance write the same files, so only one export runs at a time */
    private function runExport(callable $export)
    {
        $semaphore = 'CSVExport' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 30000)) {
            $this->SendDebug('Export', 'An other export is running', 0);
            return false;
        }
        try {
            //Delete the previous export, so only the new Zip or Csv File exists
            $this->DeleteZip();
            $exported = $export();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        return $exported ? '/hook/zip/' . $this->InstanceID : false;
    }

    /**
     * Export a selected Variable per File and combine them to a zip Archive
     */
    private function ExportMultiFile($level, $start, $end, $limit): bool
    {
        $this->SendDebug('MultiFile', '', 0);
        $list = json_decode($this->ReadPropertyString('SelectedVariables'), true);
        $exportFiles = [];
        foreach ($list as $key => $entry) {
            $selectedVariable = $entry['SelectedVariable'];
            if (!IPS_VariableExists($selectedVariable)) {
                continue;
            }
            $contentFile = $this->getTempFile($selectedVariable);
            $this->fetchAllValues($contentFile, $selectedVariable, $level, $start, $end, $limit);
            $exportFiles[] = [$contentFile, $this->GenerateFileName($selectedVariable, '.csv', $entry['UserDefinedName'])];
        }
        if (count($exportFiles) == 0) {
            return false;
        }
        $this->zipFile($exportFiles);
        return true;
    }

    /** fetch the Logged Values and returns an array with timestamp and avg
     * @param int $variable Variable ID that should fetched
     * @param int $level AggregationLevel
     * @param int $start Timestamp from the start, 0 = from the beginning
     * @param int $end Timestamp from the end, 0 = till now
     * @param int $limit max fetched data sets
     */
    private function fetchArchiveData(int $variable, int $level, int $start, int $end, $limit): array
    {
        $this->SendDebug('Fetch', $variable . ' ' . $level . ' ' . date('H:i:s d.m.y', $start) . ' ' . date('H:i:s d.m.y', $end), 0);
        // [[Timestamp, Avg]]
        $aggregationValues = [];
        if ($level != 7) {
            $values = AC_GetAggregatedValues($this->archiveID, $variable, $level, $start, $end, $limit);
            foreach ($values as $value) {
                $aggregationValues[] = [
                    'timeStamp' => $value['TimeStamp'],
                    'avg'       => $value['Avg'],
                ];
            }
        }else {
            $values = AC_GetLoggedValues($this->archiveID, $variable, $start, $end, $limit);
            foreach ($values as $value) {
                $aggregationValues[] = [
                    'timeStamp' => $value['TimeStamp'],
                    'avg'       => $value['Value'],
                ];
            }
        }
        $this->SendDebug('LoggedValues', print_r($aggregationValues, true), 0);
        return $aggregationValues;
    }
    /**
     * Fetch the Values even the ones over the 10 000 limit and put them in the given file
     */
    private function fetchAllValues(string $filePath, int $variable, int $level, int $start, int $end, int $limit)
    {
        file_put_contents($filePath, ''); //Create the tempfile
        $separator = $this->ReadPropertyString('DecimalSeparator');
        $loopAgain = true;
        $endElements = [];
        while ($loopAgain) {
            $content = '';
            $loggedValues = $this->fetchArchiveData($variable, $level, $start, $end, $limit);

            $loopAgain = count($loggedValues) == $limit;

            if ($level == 7) {//Protect values to duplicate on limit border
                //endElements are the last element on the previous array
                foreach ($endElements as $element) {
                    array_shift($loggedValues);
                }
            }

            foreach ($loggedValues as $key => $value) {
                $avg = is_numeric($value['avg'])
                    ? str_replace('.', $separator, '' . $value['avg'])
                    : $value['avg'];
                $content .= date('d.m.Y H:i:s', $value['timeStamp']) . ';' . $avg . "\n";
            }
            $this->SendDebug('Content', $content, 0);
            file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);

            if ($loopAgain) {
                $end = end($loggedValues)['timeStamp'];
                if ($level != 7) {
                    $end -= 1;
                } else {
                    //Only logged values can have duplicates on the same timestamp
                    $endElements = array_filter($loggedValues, function ($element) use ($end)
                    {
                        return $element['timeStamp'] == $end;
                    });
                }
            }
        }
    }

    /** validate the start and end Timestamp and trim them if necessary
     * @return array [startTimestamp, endTimestamp]
     */
    private function validTimestamps(bool $type, string $AggregationStart, string $AggregationEnd): array
    {
        $this->SendDebug('Aggregation start', $AggregationStart, 0);
        $jsonToTimestamp = function ($time)
        {
            $time = json_decode($time, true);
            $time = strtotime(sprintf('%02d', $time['day']) . '-' . sprintf('%02d', $time['month']) . '-' . sprintf('%04d', $time['year']) . ' ' . $time['hour'] . ':' . $time['minute'] . ':' . $time['second']);

            return $time;
        };

        if ($type) { //UserExport
            $startTimeStamp = $jsonToTimestamp($AggregationStart);
            $endTimeStamp = $jsonToTimestamp($AggregationEnd);
            $this->SendDebug('StartTimeUser', $startTimeStamp, 0);
        } else { //SendMail/FTP
            switch ($this->ReadPropertyInteger('MailInterval')) {
                case 0: //Hourly
                    $startTimeStamp = time() - 3600 - (time() % 3600); //If it is 9:54, startTimeStamp is 8:00
                    $endTimeStamp = time() - (time() % 3600); //If it is 9:54, endTimeStamp is 9:00
                    break;
                case 1: //Daily
                    $startTimeStamp = strtotime('yesterday');
                    $endTimeStamp = strtotime('today');
                    break;
                case 2: //Weekly
                    $endTimeStamp = strtotime('monday this week'); //On a monday it is today, "previous monday" would be a week earlier
                    $startTimeStamp = strtotime('-1 week', $endTimeStamp);
                    break;
                case 3: //Monthly
                    $startTimeStamp = strtotime('first day of last month 00:00:00');
                    $endTimeStamp = strtotime('first day of this month 00:00:00');
                    break;
                case 4: //Yearly
                    $startTimeStamp = strtotime('first day of January last year 00:00:00');
                    $endTimeStamp = strtotime('first day of January this year 00:00:00');
                    break;
            }
            //The archive includes the end, but it is the first value of the current interval
            $endTimeStamp--;
            $this->SendDebug('StartTimeCyclic', $startTimeStamp, 0);
        }
        return [$startTimeStamp, $endTimeStamp];
    }

    private function GenerateFileName($variableID, $extension = '.zip', $username = '')
    {
        return $variableID . '_' . preg_replace('/[\"\<\>\?\|\\/\:\/]/', '_', ($username == '' ? IPS_GetName($variableID) : $username)) . $extension;
    }

    /** Get all logged variables as options if the syncMySql-Module is available */
    private function GetOptions($filter = '')
    {
        $mysqlSyncIDs = IPS_GetInstanceListByModuleID('{7E122824-E4D6-4FF8-8AA1-2B7BB36D5EC9}');
        $idents = [];
        foreach ($mysqlSyncIDs as $mysqlSyncID) {
            if (IPS_GetInstance($mysqlSyncID)['InstanceStatus'] == IS_ACTIVE) {
                $idents = array_merge(SSQL_GetIdentList($mysqlSyncID));
            }
        }

        $addIdentifier = function ($variableID) use ($idents)
        {
            foreach ($idents as $ident) {
                if ($ident['variableid'] == $variableID) {
                    return $ident['ident'];
                }
            }
            return '';
        };

        $archiveControlID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $aggregationVariables = AC_GetAggregationVariables($archiveControlID, false);
        $options = [];
        foreach ($aggregationVariables as $aggregationVariable) {
            if (IPS_VariableExists($aggregationVariable['VariableID'])) {
                $jsonString['caption'] = $addIdentifier($aggregationVariable['VariableID']) . ' (' . IPS_GetName($aggregationVariable['VariableID']) . ')';
                $jsonString['value'] = $aggregationVariable['VariableID'];

                if ($filter == '' || strpos($jsonString['caption'], $filter) !== false) {
                    $options[] = $jsonString;
                }
            }
        }
        usort($options, function ($a, $b)
        {
            return strcmp($a['caption'], $b['caption']);
        });
        return $options;
    }

    /** Set the Mail interval base on the property MailInterval */
    private function UpdateCyclicInterval()
    {
        if ($this->ReadPropertyBoolean('IntervalStatus')) {
            $mailInterval = $this->ReadPropertyInteger('MailInterval');
            $time = json_decode($this->ReadPropertyString('MailTime'), true);
            $timeString = sprintf('%02d:%02d:%02d', $time['hour'], $time['minute'], $time['second']);
            $date = 0;
            switch ($mailInterval) {
                case 0: // Hourly
                    //With the date, so the next hour after 23:00 is on the next day
                    $date = strtotime(date('Y-m-d H:00:00', strtotime('+1 hour'))) + $time['minute'] * 60 + $time['second'];
                    $this->SendDebug('Next Dispatch (Hourly)', date('d.m.Y H:i:s', $date), 0);
                    break;

                case 1: //Daily
                    $date = strtotime('tomorrow ' . $timeString);
                    $this->SendDebug('Next Dispatch (Daily)', date('d.m.Y H:i:s', $date), 0);
                    break;

                case 2: // Weekly
                    $date = strtotime('next week ' . $timeString);
                    $this->SendDebug('Next Dispatch (Week)', date('d.m.Y H:i:s', $date), 0);
                    break;

                case 3: //Monthly
                    $date = strtotime('first day of next month ' . $timeString);
                    $this->SendDebug('Next Dispatch (Month)', date('d.m.Y H:i:s', $date), 0);
                    break;
                case 4: //Yearly
                    $date = strtotime('first day of January next year ' . $timeString);
                    $this->SendDebug('Next Dispatch (Year)', date('d.m.Y H:i:s', $date), 0);
                    break;
            }
            $difference = $date - time();

            $this->SetTimerInterval('MailTimer', $difference * 1000);
        } else {
            $this->SetTimerInterval('MailTimer', 0);
        }
    }

    /**  Connection for FTP & co */
    private function createConnection()
    {
        return $this->createConnectionEx(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyString('ConnectionType'),
        );
    }

    /** Connection for the UI functions, shows the error message if the connection fails */
    private function createUIConnection(string $host, int $port, string $username, string $password, string $connectionType)
    {
        try {
            return $this->createConnectionEx($host, $port, $username, $password, $connectionType);
        } catch (\Throwable $th) {
            echo $th->getMessage();
            return false;
        }
    }

    /** Connection for FTP & co. Throws an exception with a translated message if the connection fails */
    private function createConnectionEx(string $host, int $port, string $username, string $password, string $connectionType)
    {
        $this->UpdateFormField('ExportBar', 'visible', true);
        $this->UpdateFormField('ExportBar', 'caption', $this->Translate('Wait on connection'));
        if (!in_array($connectionType, ['SFTP', 'FTP', 'FTPS'])) {
            $this->UpdateFormField('ExportBar', 'visible', false);
            throw new Exception($this->Translate('The Connection Type is undefined'));
        }
        //Create Connection
        try {
            $connection = match ($connectionType) {
                'SFTP' => new SFTP($host, $port),
                'FTP'  => new FTP($host, $port),
                'FTPS' => new FTPS($host, $port),
            };
            //SFTP connects on login and throws if the host is not reachable
            $loggedIn = $connection->login($username, $password);
        } catch (\Throwable $th) {
            $this->SendDebug('Connection', $th->getMessage(), 0);
            $this->UpdateFormField('ExportBar', 'visible', false);
            throw new Exception(sprintf($this->Translate('Could not connect to %s:%d'), $host, $port));
        }
        if ($loggedIn === false) {
            $this->UpdateFormField('ExportBar', 'visible', false);
            throw new Exception($this->Translate('Username/Password is invalid'));
        }
        return $connection;
    }

    /** Check if Variables exist base on the Export Option */
    private function checkVariables(): bool
    {
        if ($this->ReadPropertyString('ExportOption') == 'mysql') {
            return IPS_VariableExists($this->ReadPropertyInteger('ArchiveVariable'));
        }
        $list = json_decode($this->ReadPropertyString('SelectedVariables'), true);
        if (count($list) == 0) {
            return false;
        }

        foreach ($list as $key => $entry) {
            if (!IPS_VariableExists($entry['SelectedVariable'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Path of the exported (Zip) File. Only "Single File with multiple Variables" can be exported without zip.
     * CSV_Export always creates a Zip File, so check which file the last export created
     */
    private function getExportPath(): string
    {
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($this->InstanceID);
        return file_exists($zipPath) ? $zipPath : sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($this->InstanceID, '.csv');
    }

    /** Temporary file for the values of a variable, unique per instance */
    private function getTempFile(int $variableID): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ContentTemp' . $this->InstanceID . '_' . $variableID . '.txt';
    }

    /** Zip the files
     * @return string Path of the Zip
     * @param array $files Array of the files that should zipped
     */
    private function zipFile(array $files = []): string
    {
        $this->SendDebug('Zip File', print_r($files, true), 0);

        $name = $this->InstanceID;

        //Generate zip with aggregated values
        $tempfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($name);
        $zip = new ZipArchive();
        //Set file to new Zip File
        if ($zip->open($tempfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $file) {
                $this->SendDebug('File', print_r($file, true), 0);
                $zip->addFile(
                    $file[0], //Path
                    $file[1] // Name
                );
            }
            $zip->close();
        }

        foreach ($files as $file) {
            if (file_exists($file[0])) {
                unlink($file[0]);
            }
        }
        $this->SendDebug('ZipFile Complete', $tempfile, 0);
        return $tempfile;
    }

    /** Check the Status of critical things for the instance */
    private function checkInstanceStatus(): int
    {
        // Look if all Variables are valid
        if (!$this->checkVariables()) {
            return 201;
        }
        // Look if cyclic sending is true, if Mail or FTP/FTPS/SFTP is true
        if ($this->ReadPropertyBoolean('IntervalStatus')) {
            if (!$this->ReadPropertyBoolean('SendMail') && !$this->ReadPropertyBoolean('SendFTP')) {
                return 204;
            }
            // If Mail is true, is the SMTP Instance selected and valid
            if ($this->ReadPropertyBoolean('SendMail')) {
                $smtpInstanceID = $this->ReadPropertyInteger('SMTPInstance');
                if (!@IPS_InstanceExists($smtpInstanceID)) {
                    return 203;
                }
                if (@IPS_GetInstance($smtpInstanceID)['ModuleInfo']['ModuleID'] != '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}') {
                    return 202;
                }
            }
            // If FTP/FTPS/SFTP is true, is a host set? The connection itself is checked while sending
            if ($this->ReadPropertyBoolean('SendFTP') && $this->ReadPropertyString('Host') == '') {
                return 205;
            }
        }
        return 102;
    }

}