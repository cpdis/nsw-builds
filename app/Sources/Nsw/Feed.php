<?php

namespace App\Sources\Nsw;

enum Feed: string
{
    case DA = 'da';
    case CDC = 'cdc';
    case CC = 'cc';
    case OC = 'oc';

    public function endpoint(): string
    {
        return match ($this) {
            self::DA => 'OnlineDA',
            self::CDC => 'OnlineCDC',
            self::CC => 'OnlineCC',
            self::OC => 'OC',
        };
    }

    public function eventType(): string
    {
        return match ($this) {
            self::DA => 'da_lodged',
            self::CDC => 'cdc_issued',
            self::CC => 'cc_issued',
            self::OC => 'oc_issued',
        };
    }
}
