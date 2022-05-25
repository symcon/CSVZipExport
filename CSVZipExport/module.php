<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/WebHookModule.php';

class CSVZipExport extends WebHookModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'zip/' . $InstanceID);
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Timer
        $this->RegisterTimer('DeleteZipTimer', 0, 'CSV_DeleteZip($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MailTimer', 0, 'CSV_SendMail($_IPS[\'TARGET\']);');

        $this->DeleteZip();

        // Properties
        $this->RegisterPropertyInteger('ArchiveVariable', 0);
        $this->RegisterPropertyInteger('AggregationStage', 1);
        $dateFormat = '{"year":%d,"month":%d,"day":%d,"hour":%d,"minute":%d,"second":%d}';
        $this->RegisterPropertyString('AggregationStart', sprintf($dateFormat, date('Y'), date('m'), 1, 0, 0, 0));
        $this->RegisterPropertyString('AggregationEnd', sprintf($dateFormat, date('Y'), date('m'), date('d'), 23, 59, 59));
        $this->RegisterPropertyInteger('MailInterval', 0);
        $this->RegisterPropertyInteger('SMTPInstance', 0);
        $this->RegisterPropertyBoolean('IntervalStatus', false);
        $this->RegisterPropertyString('MailTime', '{"hour":12,"minute":0,"second":0}');
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

    public function GetConfigurationForm()
    {
        //Add options to form
        $jsonForm = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        //If the module "SyncMySQL" is install, get other options
        if (IPS_ModuleExists('{7E122824-E4D6-4FF8-8AA1-2B7BB36D5EC9}')) {
            $jsonForm['elements'][0]['visible'] = true;
            $jsonForm['elements'][1]['type'] = 'Select';
            $jsonForm['elements'][1]['options'] = $this->GetOptions();
            unset($jsonForm['elements'][1]['requiredLogging']);
        }

        return json_encode($jsonForm);
    }

    public function UserExport(int $ArchiveVariable, int $AggregationStage, string $AggregationStart, string $AggregationEnd)
    {
        if (!IPS_VariableExists($ArchiveVariable)) {
            return 'javascript:alert("' . $this->Translate('Variable is not selected') . ' ");';
        }
        $this->UpdateFormField('ExportBar', 'visible', true);
        $relativePath = $this->Export($ArchiveVariable, $AggregationStage, $AggregationStart, $AggregationEnd);
        sleep(1);
        $this->UpdateFormField('ExportBar', 'visible', false);
        //Reset ZipDeleteTimer
        $this->SetTimerInterval('DeleteZipTimer', 1000 * 60 * 60);

        $this->SendDebug('relativer Pfad', $relativePath, 0);

        return $relativePath;
    }

    public function Export(int $ArchiveVariable, int $AggregationStage, string $AggregationStart, string $AggregationEnd)
    {
        $archiveControlID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $startTimeStamp = 0;
        $endTimeStamp = 0;

        switch ($AggregationStage) {
                case 0: //Hourly
                case 5: //1-Minute
                case 6: //5-Minute
                case 7: //raw datas
                    $startTimeStamp = $this->TransferTime($AggregationStart, true, false);
                    $endTimeStamp = $this->TransferTime($AggregationEnd, false, false);
                    break;
                case 1: //Daily
                case 2: //Weekly
                case 3: //Monthly
                case 4: //Yearly
                    $startTimeStamp = $this->TransferTime($AggregationStart, true, true);
                    $endTimeStamp = $this->TransferTime($AggregationEnd, false, true);
                    break;
            }

        //Generate zip with aggregated values
        $name = preg_replace('/[\"\<\>\?\|\\/\:\/]/', '_', IPS_GetName($ArchiveVariable));
        $this->SendDebug('name', $name, 0);
        $tempfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'CSV_' . $ArchiveVariable . '_' . $name . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tempfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $content = '';
            if ($AggregationStage != 7) {
                $loggedValues = AC_GetAggregatedValues($archiveControlID, $ArchiveVariable, $AggregationStage, $startTimeStamp, $endTimeStamp, 0);
                for ($j = 0; $j < count($loggedValues); $j++) {
                    $content .= date('d.m.Y H:i:s', $loggedValues[$j]['TimeStamp']) . ';' . $loggedValues[$j]['Avg'] . "\n";
                }
            } else {
                $loggedValues = AC_GetLoggedValues($archiveControlID, $ArchiveVariable, $startTimeStamp, $endTimeStamp, 0);
                for ($j = 0; $j < count($loggedValues); $j++) {
                    $content .= date('d.m.Y H:i:s', $loggedValues[$j]['TimeStamp']) . ';' . $loggedValues[$j]['Value'] . "\n";
                }
            }
            $zip->addFromString($ArchiveVariable . '_' . $name . '.csv', $content);
            $zip->close();
        }

        //Return
        return '/hook/zip/' . $this->InstanceID;
    }

    public function DeleteZip()
    {
        $tempfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'CSV_' . $ArchiveVariable . '_' . preg_replace('/[\"\<\>\?\|\\/\:\/]/', '_', IPS_GetName($ArchiveVariable)) . '.zip';
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        $this->SetTimerInterval('DeleteZipTimer', 0);
    }

    public function UpdateFilter(string $Filter)
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

    public function SendMail()
    {
        $archiveVariable = $this->ReadPropertyInteger('ArchiveVariable');
        if (!IPS_VariableExists($archiveVariable)) {
            echo $this->Translate('Variable is not selected');
            return;
        }
        $aggregationStage = $this->ReadPropertyInteger('AggregationStage');
        $aggregationStart = $this->ReadPropertyString('AggregationStart');
        $aggregationEnd = $this->ReadPropertyString('AggregationEnd');
        $smtpInstanceID = $this->ReadPropertyInteger('SMTPInstance');
        if (!$this->ValidateInstance($smtpInstanceID)) {
            echo $this->Translate("The selected SMTP-Instance doesn't exist");
            return;
        }
        $relativePath = $this->Export($archiveVariable, $aggregationStage, $aggregationStart, $aggregationEnd);
        $subject = sprintf($this->Translate('Summary of %s (%s to %s)'), IPS_GetName($this->ReadPropertyInteger('ArchiveVariable')), date('d.m.Y H:i:s', $this->ExtractTimestamp('AggregationStart')), date('d.m.Y H:i:s', $this->ExtractTimestamp('AggregationEnd')));
        SMTP_SendMailAttachment($smtpInstanceID, $subject, $this->Translate('In the appendix you can find the created CSV-File.'), IPS_GetKernelDir() . 'webfront' . $relativePath);
        $this->DeleteZip();
        $this->UpdateMailInterval();
    }

    public function UpdateInstanceError(int $SMTPInstanceID)
    {
        if ($this->ValidateInstance($SMTPInstanceID)) {
            $this->UpdateFormField('SMTPInstanceError', 'caption', '');
        } else {
            $this->UpdateFormField('SMTPInstanceError', 'caption', $this->Translate('No valid SMTP-Instance selected'));
        }
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData()
    {
        $ArchiveVariable = $this->ReadPropertyInteger('ArchiveVariable');
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'CSV_' . $ArchiveVariable . '_' . preg_replace('/[\"\<\>\?\|\\/\:\/]/', '_', IPS_GetName($ArchiveVariable)) . '.zip';
        header('Content-Disposition: attachment; filename="' . 'CSV_' . $ArchiveVariable . '_' . preg_replace('/[\"\<\>\?\|\\/\:\/]/', '_', IPS_GetName($ArchiveVariable)) . '.zip');
        header('Content-Type: application/zip; charset=utf-8;');
        header('Content-Length:' . filesize($path));
        readfile($path);
    }

    //Transfer json string to timestamp
    private function TransferTime($jsonTime, bool $start, bool $ignoreTime)
    {
        $time = json_decode($jsonTime, true);

        $customTime = '';
        if ($ignoreTime) {
            switch ($start) {
                    case true:
                        $customTime = '00:00:00';
                        break;
                    case false:
                        $customTime = '23:59:59';
                        break;
                }
        } else {
            $customTime = sprintf('%02d:%02d:%02d', $time['hour'], $time['minute'], $time['second']);
        }

        return strtotime(sprintf('%02d', $time['day']) . '-' . sprintf('%02d', $time['month']) . '-' . sprintf('%04d', $time['year']) . ' ' . $customTime);
    }

    private function ExtractTimestamp($property)
    {
        $timeProperty = json_decode($this->ReadPropertyString($property), true);
        return mktime($timeProperty['hour'], $timeProperty['minute'], $timeProperty['second'], $timeProperty['month'], $timeProperty['day'], $timeProperty['year']);
    }

    //Get all logged variables as options
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

    private function ValidateInstance($smtpInstanceID)
    {
        if (IPS_InstanceExists($smtpInstanceID)) {
            $this->SendDebug('SMTP-Instance', $this->Translate('No valid SMTP-Instance selected'), 0);
            if (IPS_GetInstance($smtpInstanceID)['ModuleInfo']['ModuleID'] == '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}') {
                return true;
            }
        }
        return false;
    }

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
            }
            $difference = $mailDate - time();

            $this->SetTimerInterval('MailTimer', $difference * 1000);
        } else {
            $this->SetTimerInterval('MailTimer', 0);
        }
    }
}
