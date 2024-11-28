<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/WebHookModule.php';
include_once __DIR__ . '/../libs/vendor/autoload.php';
include_once __DIR__ . '/../libs/FTP.php';
include_once __DIR__ . '/../libs/FTPS.php';
use phpseclib3\Net\SFTP;

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
        $this->RegisterPropertyString('MailTime', '{"hour":12,"minute":0,"second":0}');
        // Properties for FTP & co
        $this->RegisterPropertyString('ConnectionType', 'SFTP');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 22);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        //Timer
        $this->RegisterTimer('DeleteZipTimer', 0, 'CSV_DeleteZip($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MailTimer', 0, 'CSV_SendMail($_IPS[\'TARGET\']);');

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

        $this->UpdateMailInterval();
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

        //If the module "SyncMySQL" is installed, get other options
        if (IPS_ModuleExists('{7E122824-E4D6-4FF8-8AA1-2B7BB36D5EC9}') || $this->ReadPropertyString('ExportOption') == 'mysql') {
            $jsonForm['elements'][1]['visible'] = true;
            $jsonForm['elements'][2]['type'] = 'Select';
            $jsonForm['elements'][2]['options'] = $this->GetOptions();
            unset($jsonForm['elements'][2]['requiredLogging']);
        }

        return json_encode($jsonForm);
    }
    // UI Functions
    /** UI Function onChange of the Export Options */
    public function UIChangeExportOption($exportOption): void
    {
        $this->UpdateFormField('FilterRow', 'visible', $exportOption == 'mysql');
        $this->UpdateFormField('ArchiveVariable', 'visible', $exportOption == 'mysql');
        $this->UpdateFormField('SelectedVariables', 'visible', $exportOption != 'mysql');
        $this->UpdateFormField('SingleInfo', 'visible', $exportOption == 'single');
        $this->UpdateFormField('ZipFile', 'value', $exportOption != 'multi' ? true : $this->ReadPropertyBoolean('ZipFile'));
        $this->UpdateFormField('ZipFile', 'enabled', $exportOption == 'multi');
        $this->UpdateFormField('MultiInfo', 'visible', $exportOption == 'multi');
    }
    /*UI Funktion to change the Port base on the connection type */
    public function UIChangePort($value): void
    {
        if ($this->ReadPropertyInteger('Port') == 21 || $this->ReadPropertyInteger('Port') == 22) {
            switch ($value) {
                case 'SFTP':
                    $value = 22;
                    break;
                case 'FTP':
                case 'FTPS':
                    $value = 21;
                    break;
                default:
                    # code...
                    break;
            }
            $this->UpdateFormField('Port', 'value', $value);
        }
    }
    /*UI Function to select the Connection Dir */
    public function UISelectDir(string $host, int $port, string $username, string $password, string $connectionType)
    {
        $this->UIGoDeeper('/', $host, $port, $username, $password, $connectionType);
    }

    public function UIAssumeDir(string $value, string $host, int $port, string $username, string $password, string $connectionType)
    {
        $connection = $this->createConnectionEx($host, $port, $username, $password, $connectionType, true);
        if ($connection === false) {
            return;
        }
        $connection->chdir($value);
        $this->UpdateFormField('TargetDir', 'value', $connection->pwd());
        $connection->disconnect();
    }

    public function UILoadDir(string $dir, string $host, int $port, string $username, string $password, string $connectionType)
    {
        $connection = $this->createConnectionEx($host, $port, $username, $password, $connectionType, true);
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
        $connection = $this->createConnectionEx($host, $port, $username, $password, $connectionType, true);
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
        $connection = $this->createConnectionEx(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyString('ConnectionType'),
            true,
        );
        if ($connection !== false) {
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('Connection is valid'));
            $this->UpdateFormField('Progress', 'visible', false);
            $connection->disconnect();
        }
    }
    //*---------------*//

    /** Handle the export via Button  */
    public function UserExport(int $ArchiveVariable, int $AggregationStage, string $AggregationStart, string $AggregationEnd)
    {
        if (!IPS_VariableExists($ArchiveVariable)) {
            $this->SetStatus(201); //for the instance
            echo $this->Translate('A Variable is not selected');
            return 'javascript:alert("' . $this->Translate('A Variable is not selected') . ' ");'; //for the Browser
        }
        $this->UpdateFormField('ExportBar', 'visible', true);
        ini_set('memory_limit', '256M');
        $timeStamp = $this->validTimestamps(true, $AggregationStart, $AggregationEnd);
        $startTimeStamp = $timeStamp[0];
        $endTimeStamp = $timeStamp[1];

        $relativePath = $this->Export($ArchiveVariable, $AggregationStage, $startTimeStamp, $endTimeStamp);
        if ($relativePath !== false) {
            $this->SetStatus(102);
        }
        sleep(1);
        $this->UpdateFormField('ExportBar', 'visible', false);
        //Reset ZipDeleteTimer
        $this->SetTimerInterval('DeleteZipTimer', 1000 * 60 * 60);

        return $relativePath;
    }

    /** Handle the export */
    public function Export(int $ArchiveVariable, int $AggregationStage, int $startTimeStamp, int $endTimeStamp)
    {
        $this->SendDebug('Export', $this->archiveID, 0);
        $archiveControlID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $limit = IPS_GetOption('ArchiveRecordLimit');

        match ($this->ReadPropertyString('ExportOption')) {
            'single' => $this->ExportMultiFile($AggregationStage, $startTimeStamp, $endTimeStamp, $limit),
            'multi'  => $this->ExportSingleFile($AggregationStage, $startTimeStamp, $endTimeStamp, $limit, $this->ReadPropertyBoolean('ZipFile')),
            'mysql'  => $this->ExportMySQL($AggregationStage, $startTimeStamp, $endTimeStamp, $limit),
        };

        //Return
        return '/hook/zip/' . $this->InstanceID;
    }

    /** Delete the (Zip) File */
    public function DeleteZip()
    {
        $ArchiveVariable = $this->ReadPropertyInteger('ArchiveVariable');
        $tmpfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($ArchiveVariable);
        if (file_exists($tmpfile)) {
            unlink($tmpfile);
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

    /** The function send the export via Email */
    public function SendMail()
    {
        //$archiveVariable = $this->ReadPropertyInteger('ArchiveVariable');
        if (!$this->checkVariables()) {
            echo $this->Translate('A Variable is not selected');
            $this->SetStatus(201);
            return;
        }
        $aggregationStage = $this->ReadPropertyInteger('AggregationStage');
        $timeStamp = $this->validTimestamps(false, $this->ReadPropertyString('AggregationStart'), $this->ReadPropertyString('AggregationEnd'));
        $startTimeStamp = $timeStamp[0];
        $endTimeStamp = $timeStamp[1];

        $smtpInstanceID = $this->ReadPropertyInteger('SMTPInstance');

        if (!@IPS_InstanceExists($smtpInstanceID)) {
            $this->SendDebug('SMTP-Instance', $this->Translate('No valid SMTP-Instance selected'), 0);
            $this->SetStatus(202);
            if (@IPS_GetInstance($smtpInstanceID)['ModuleInfo']['ModuleID'] != '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}') {
                echo $this->Translate("The selected SMTP-Instance doesn't exist");
                $this->SetStatus(203);
            }
            return;
        }

        $relativePath = $this->Export($archiveVariable, $aggregationStage, $startTimeStamp, $endTimeStamp);
        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($this->ReadPropertyInteger('ArchiveVariable'));
        if ($filePath !== false) {
            $this->SetStatus(102);
        }
        $subject = sprintf($this->Translate('Summary of %s (%s to %s)'), IPS_GetName($this->ReadPropertyInteger('ArchiveVariable')), date('d.m.Y H:i:s', $this->ExtractTimestamp('AggregationStart')), date('d.m.Y H:i:s', $this->ExtractTimestamp('AggregationEnd')));
        SMTP_SendMailAttachment($smtpInstanceID, $subject, $this->Translate('In the appendix you can find the created CSV-File.'), $filePath);

        //Clean up
        $this->DeleteZip();
        $this->UpdateMailInterval();
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData()
    {
        //$ArchiveVariable = $this->ReadPropertyInteger('ArchiveVariable');
        $ArchiveVariable = $this->InstanceID; // TODO Change then Option is Mysql
        $zipped = $this->ReadPropertyBoolean('ZipFile');
        $this->SendDebug('Zipped?', $zipped, 0);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . ($zipped ? $this->GenerateFileName($ArchiveVariable) : $this->GenerateFileName($ArchiveVariable, '.csv'));
        if (!file_exists($path)) {
            throw new Exception('File ' . $path . " doesn't Exist", 1);
        }
        $this->SendDebug('ProcessHookData', $path . ' ' . file_exists($path), 0);
        if ($zipped) {
            header('Content-Disposition: attachment; filename="' . $this->GenerateFileName($ArchiveVariable) . '"');
            header('Content-Type: application/zip; charset=utf-8;');
        }else {
            header('Content-Disposition: attachment; filename="' . $this->GenerateFileName($ArchiveVariable, '.csv') . '"');
            header('Content-Type: text/csv; charset=utf-8;');
        }
        header('Content-Length:' . filesize($path));
        readfile($path);
    }

    /**
     * Export multiple variable in a single file. It is a table [Timestamp, Variable1, ..., Variable n]
     */
    //TODO Funktionalität
    private function ExportSingleFile($level, $start, $end, $limit)
    {
        $this->SendDebug('SingleFile', '', 0);

        $content = '';
        $contentFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($this->InstanceID, '.csv');
        file_put_contents($contentFile, ''); //Create the tempfile
        $list = json_decode($this->ReadPropertyString('SelectedVariables'), true);
        $getName = function ($variable) use ($list): String
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
            if (!in_array($entry['SelectedVariable'], $variables)) {
                $variables[] = $entry['SelectedVariable'];
            }
            $loggedValues = $this->fetchArchiveData($entry['SelectedVariable'], $level, $start, $end, $limit);
            foreach ($loggedValues as $key => $value) {
                if (!array_key_exists($value['timeStamp'], $listValues)) {
                    $listValues[$value['timeStamp']] = [];
                }
                $listValues[$value['timeStamp']][$entry['SelectedVariable']] = $value['avg'];
            }
        }
        // sort timestamps
        krsort($listValues);
        // sort List of the Variables
        sort($variables);
        // add the Values to content
        $content .= 'Date;';
        foreach ($variables as $key => $value) {
            $content .= $getName($value) . ';';
        }
        $content .= "\n";

        foreach ($listValues as $timeStamp => $value) {
            ksort($value);
            $content .= date('d.m.Y H:i:s', $timeStamp) . ';' . implode(';', $value) . "\n";
        }
        $this->SendDebug('Put in File ', $content, 0);
        file_put_contents($contentFile, $content);
        $this->SendDebug('Is File exist', file_exists($contentFile), 0);

        if ($this->ReadPropertyBoolean('ZipFile')) {
            $this->zipFile([[$contentFile, $this->GenerateFileName($this->InstanceID, '.csv')]]);
        }else {
            file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->GenerateFileName($this->InstanceID, '.csv'), $content);
            unlink($contentFile);
        }
    }

    /**
     * Export a single Variable to a file that wrapped with a zip Archive
     */
    //TODO Duplicated code with Multi
    private function ExportMySQL($AggregationStage, $startTimeStamp, $endTimeStamp, $limit)
    {
        $this->SendDebug('Mysql', '', 0);
        $contentFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ContentTemp.txt';
        file_put_contents($contentFile, ''); //Create the tempfile
        $separator = $this->ReadPropertyString('DecimalSeparator');
        $loopAgain = true;
        $endElements = [];
        $ArchiveVariable = $this->ReadPropertyInteger('ArchiveVariable');
        while ($loopAgain) {

            $content = '';
            if ($AggregationStage != 7) {
                $loggedValues = AC_GetAggregatedValues($this->archiveID, $ArchiveVariable, $AggregationStage, $startTimeStamp, $endTimeStamp, $limit);
                $loopAgain = count($loggedValues) == $limit;
                for ($j = 0; $j < count($loggedValues); $j++) {
                    $value = is_numeric($loggedValues[$j]['Avg']) ? str_replace('.', $separator, '' . $loggedValues[$j]['Avg']) : $loggedValues[$j]['Avg'];
                    $content .= date('d.m.Y H:i:s', $loggedValues[$j]['TimeStamp']) . ';' . $value . "\n";
                }
            } else {
                $loggedValues = AC_GetLoggedValues($archiveControlID, $ArchiveVariable, $startTimeStamp, $endTimeStamp, $limit);
                $loopAgain = count($loggedValues) == $limit;

                //Protect values to duplicate on limit border
                //endElements are the last element on the previous array
                foreach ($endElements as $element) {
                    array_shift($loggedValues);
                }

                for ($j = 0; $j < count($loggedValues); $j++) {
                    $value = is_numeric($loggedValues[$j]['Value']) ? str_replace('.', $separator, '' . $loggedValues[$j]['Value']) : $loggedValues[$j]['Value'];
                    $content .= date('d.m.Y H:i:s', $loggedValues[$j]['TimeStamp']) . ';' . $value . "\n";
                }
            }
            file_put_contents($contentFile, $content, FILE_APPEND | LOCK_EX);

            if ($loopAgain) {
                $endTimeStamp = end($loggedValues)['TimeStamp'];

                if ($AggregationStage != 7) {
                    $endTimeStamp -= 1;
                } else {
                    //Only logged values can have duplicates on the same timestamp
                    $endElements = array_filter($loggedValues, function ($element) use ($endTimeStamp)
                    {
                        return $element['TimeStamp'] == $endTimeStamp;
                    });
                }
            }
        }
        $this->zipFile([[$contentFile, $this->GenerateFileName($ArchiveVariable, '.csv')]]);
    }

    /**
     * Export a selected Variable per File and combine them to a zip Archive
     */
    //TODO Duplicated Code with MYSQL
    private function ExportMultiFile($level, $start, $end, $limit)
    {
        $this->SendDebug('MultiFile', '', 0);
        IPS_SemaphoreEnter('MultiFileZip', 5000);
        $list = json_decode($this->ReadPropertyString('SelectedVariables'), true);
        $exportFiles = [];
        $separator = $this->ReadPropertyString('DecimalSeparator');
        foreach ($list as $key => $entry) {
            $contentFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ContentTemp' . $entry['SelectedVariable'] . '.txt';
            file_put_contents($contentFile, ''); //Create the tempfile
            $loopAgain = true;
            $endElements = [];
            $selectedVariable = $entry['SelectedVariable'];
            while ($loopAgain) {

                $content = '';
                $loggedValues = $this->fetchArchiveData($selectedVariable, $level, $start, $end, $limit);
                $loopAgain = count($loggedValues) == $limit;

                if ($level == 7) {//Protect values to duplicate on limit border
                    //endElements are the last element on the previous array
                    foreach ($endElements as $element) {
                        array_shift($loggedValues);
                    }
                }

                foreach ($loggedValues as $key => $value) {
                    $value = is_numeric($loggedValues[$key]['avg'])
                        ? str_replace('.', $separator, '' . $loggedValues[$key]['avg'])
                        : $loggedValues[$key]['avg'];
                    $content .= date('d.m.Y H:i:s', $loggedValues[$key]['timeStamp']) . ';' . $value . "\n";
                }

                file_put_contents($contentFile, $content, FILE_APPEND | LOCK_EX);

                if ($loopAgain) {
                    $endTimeStamp = end($loggedValues)['TimeStamp'];

                    if ($AggregationStage != 7) {
                        $endTimeStamp -= 1;
                    } else {
                        //Only logged values can have duplicates on the same timestamp
                        $endElements = array_filter($loggedValues, function ($element) use ($endTimeStamp)
                        {
                            return $element['TimeStamp'] == $endTimeStamp;
                        });
                    }
                }
            }
            $exportFiles[] = [$contentFile, $this->GenerateFileName($selectedVariable, '.csv', $entry['UserDefinedName'])];
        }
        $this->zipFile($exportFiles);
        IPS_SemaphoreLeave('MultiFileZip');
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
        return $aggregationValues;
    }

    /** validate the start and end Timestamp and trim them if necessary
     * @return array [startTimestamp, endTimestamp]
     */
    private function validTimestamps(bool $type, string $AggregationStart, string $AggregationEnd): array
    {
        $jsonToTimestamp = function ($time)
        {
            $time = json_decode($time, true);
            $time = strtotime(sprintf('%02d', $time['day']) . '-' . sprintf('%02d', $time['month']) . '-' . sprintf('%04d', $time['year']) . ' ' . $time['hour'] . ':' . $time['minute'] . ':' . $time['second']);

            return $time;
        };

        if ($type) { //UserExport
            $startTimeStamp = $jsonToTimestamp($AggregationStart);
            $endTimeStamp = $jsonToTimestamp($AggregationEnd);
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
                    $endTimeStamp = strtotime('previous monday');
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
        }
        return [$startTimeStamp, $endTimeStamp];
    }

    private function GenerateFileName($variableID, $extension = '.zip', $username = '')
    {
        return $variableID . '_' . preg_replace('/[\"\<\>\?\|\\/\:\/]/', '_', ($username == '' ? IPS_GetName($variableID) : $username)) . $extension;
    }

    private function ExtractTimestamp($property)
    {
        $timeProperty = json_decode($this->ReadPropertyString($property), true);
        return mktime($timeProperty['hour'], $timeProperty['minute'], $timeProperty['second'], $timeProperty['month'], $timeProperty['day'], $timeProperty['year']);
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
    private function UpdateMailInterval()
    {
        if ($this->ReadPropertyBoolean('IntervalStatus')) {
            $mailInterval = $this->ReadPropertyInteger('MailInterval');
            $time = json_decode($this->ReadPropertyString('MailTime'), true);
            $mailTime = sprintf('%02d:%02d:%02d', $time['hour'], $time['minute'], $time['second']);
            $mailDate = 0;
            switch ($mailInterval) {
                case 0: // Hourly
                    $mailDate = strtotime(date('H', strtotime('+ 1 hour')) . ':' . $time['minute'] . ':' . $time['second']);
                    $this->SendDebug('Next Dispatch (Hourly)', date('d.m.Y H:i:s', $mailDate), 0);
                    break;

                case 1: //Daily
                    $mailDate = strtotime('tomorrow ' . $mailTime);
                    $this->SendDebug('Next Dispatch (Daily)', date('d.m.Y H:i:s', $mailDate), 0);
                    break;

                case 2: // Weekly
                    $mailDate = strtotime('next week ' . $mailTime);
                    $this->SendDebug('Next Dispatch (Week)', date('d.m.Y H:i:s', $mailDate), 0);
                    break;

                case 3: //Monthly
                    $mailDate = strtotime('first day of next month ' . $mailTime);
                    $this->SendDebug('Next Dispatch (Month)', date('d.m.Y H:i:s', $mailDate), 0);
                    break;
                case 4: //Yearly
                    $mailDate = strtotime('first day of January next year' . $mailTime);
                    $this->SendDebug('Next Dispatch (Year)', date('d.m.Y H:i:s', $mailDate), 0);
                    break;
            }
            $difference = $mailDate - time();

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
            false,
        );
    }

    /** Connection for FTP & co */
    private function createConnectionEx(string $host, int $port, string $username, string $password, string $connectionType, bool $showError)
    {
        $this->UpdateFormField('Progress', 'visible', true);
        $this->UpdateFormField('Progress', 'caption', $this->Translate('Wait on connection'));
        //Create Connection
        try {
            switch ($connectionType) {
                case 'SFTP':
                    $connection = new SFTP($host, $port);
                    break;
                case 'FTP':
                    $connection = new FTP($host, $port);
                    break;
                case 'FTPS':
                    $connection = new FTPS($host, $port);
                    break;
                default:
                    if (!$showError) {
                        $this->SetStatus(201);
                    } else {
                        echo $this->Translate('The Connection Type is undefined');
                    }
                    break;
            }
        } catch (\Throwable $th) {
            //Throw than the initial of FTP or FTPS connection failed
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate($th->getMessage()));
            $this->UpdateFormField('Progress', 'visible', false);
            if (!$showError) {
                $this->SetStatus(203);
            } else {
                echo $this->Translate($th->getMessage());
            }
            return false;
        }
        if ($connection->login($username, $password) === false) {
            $this->UpdateFormField('InformationLabel', 'caption', $this->Translate('Username/Password is invalid'));
            $this->UpdateFormField('Progress', 'visible', false);
            if (!$showError) {
                $this->SetStatus(201);
            } else {
                echo $this->Translate('Username/Password is invalid');
            }
            return false;
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

        foreach ($list as $key => $entry) {
            if (!IPS_VariableExists($entry['SelectedVariable'])) {
                return false;
            }
        }
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
                    $file[0],
                    $file[1]
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

}