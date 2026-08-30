<?php

declare(strict_types=1);

namespace App\Domains\Audit\Enums;

enum ActorType: string
{
    case User = 'USER';
    case System = 'SYSTEM';
    case Job = 'JOB';
    case Webhook = 'WEBHOOK';
}
