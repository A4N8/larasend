<?php

namespace App\Services;

use App\Jobs\DeliverInboundWebhook;
use App\Jobs\DeliverWebhook;
use App\Models\EmailEvent;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

class WebhookDeliveryService
{
    public function dispatchFor(EmailEvent $event): void
    {
        $email = $event->email;

        if (! $email) {
            return;
        }

        $email->project
            ->webhookEndpoints()
            ->where('status', 'active')
            ->whereJsonContains('events', $event->event_type)
            ->each(function ($endpoint) use ($event): void {
                $this->dispatchJob(new DeliverWebhook($endpoint->id, $event->id));
            });
    }

    public function dispatchJob(DeliverWebhook|DeliverInboundWebhook $job): void
    {
        try {
            dispatch($job);
        } catch (Throwable $exception) {
            (new UniqueLock(app(Repository::class)))->release($job);

            throw $exception;
        }
    }
}
