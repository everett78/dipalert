<?php

namespace App\Enums;

enum IsProductPage: string
{
    case NotProcessed = 'NotProcessed';

    case YesViaStore = 'YesViaStore';

    case YesViaAutoCreate = 'YesViaAutoCreate';

    case Maybe = 'Maybe';

    case No = 'No';
}
