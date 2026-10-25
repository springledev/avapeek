<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushKeys extends Command
{
    protected $signature = 'avapeek:push-keys';

    protected $description = 'Generate a VAPID key pair for Web Push (printed, not written to any file)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Add both lines to backend/.env and set VAPID_SUBJECT to an operator mailto: or https URL. Keep the private key secret.');

        return self::SUCCESS;
    }
}
