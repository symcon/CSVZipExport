<?php

declare(strict_types=1);
class CSVZipExport extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
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
    }

    public function GetConfigurationForm()
    {
        $jsonForm = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $jsonForm['actions'][0]['options'] = $this->GetOptions();

        return json_encode($jsonForm);
    }

    public function Export($ArchiveVariable, $AggregationStage, $AggregationStart, $AggregationEnd)
    {
        $archiveControlID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $startTimeStamp = 0;
        $endTimeStamp = 0;

        switch ($AggregationStage) {
                case 0:
                case 5:
                case 6:
                    $startTimeStamp = $this->TransferTime($AggregationStart, true, false);
                    $endTimeStamp = $this->TransferTime($AggregationEnd, false, false);
                    break;
                case 1:
                case 2:
                case 3:
                case 4:
                    $startTimeStamp = $this->TransferTime($AggregationStart, true, true);
                    $endTimeStamp = $this->TransferTime($AggregationEnd, false, true);
                    break;
            }

        $this->UpdateFormField('ExportBar', 'visible', true);
        $tempfile = IPS_GetKernelDir() . 'webfront' . DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR . 'CSV_' . $this->InstanceID . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tempfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $loggedValues = AC_GetAggregatedValues($archiveControlID, $ArchiveVariable, $AggregationStage, $startTimeStamp, $endTimeStamp, 0);
            $content = '';
            for ($j = 0; $j < count($loggedValues); $j++) {
                $content .= date('d.m.Y H:i:s', $loggedValues[$j]['TimeStamp']) . ';' . $loggedValues[$j]['Avg'] . "\n";
            }
            $zip->addFromString(IPS_GetName($ArchiveVariable) . '.csv', $content);
            $zip->close();
        }
        sleep(1);
        $this->UpdateFormField('ExportBar', 'visible', false);

        echo "/user/CSV_$this->InstanceID.zip";
    }

    private function TransferTime($JsonTime, bool $Start, bool $ignoreTime)
    {
        $Time = json_decode($JsonTime, true);

        $customTime = '';
        if ($ignoreTime) {
            switch ($Start) {
                    case true:
                        $customTime = '00:00:00';
                        break;
                    case false:
                        $customTime = '23:59:59';
                        break;
                }
        } else {
            $customTime = sprintf('%02d', $Time['hour']) . ':' . sprintf('%02d', $Time['minute']) . ':' . sprintf('%02d', $Time['second']);
        }

        $TimeStamp = strtotime(sprintf('%02d', $Time['day']) . '-' . sprintf('%02d', $Time['month']) . '-' . sprintf('%04d', $Time['year']) . ' ' . $customTime);
        return $TimeStamp;
    }

    private function GetOptions()
    {
        $archiveControlID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $aggregationVariables = AC_GetAggregationVariables($archiveControlID, false);
        $options = [];
        foreach ($aggregationVariables as $aggregationVariable) {
            $jsonString['caption'] = IPS_GetName($aggregationVariable['VariableID']);
            $jsonString['value'] = $aggregationVariable['VariableID'];
            $options[] = $jsonString;
        }
        return $options;
    }
}