<?php

namespace Modules\Vatswim\Listeners;

use App\Events\PirepFiled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Vatswim\Jobs\SyncFlightToVatswim;
use Modules\Vatswim\Services\PirepTransformer;
use Modules\Vatswim\Services\VatswimClient;

/**
 * Handle PIREP filed event
 *
 * Submits flight data to VATSWIM when a pilot files a PIREP.
 * This provides T1-T4 predictions (airline estimates).
 */
class PirepFiledListener implements ShouldQueue
{
    public string $queue = 'vatswim';

    protected VatswimClient $client;
    protected PirepTransformer $transformer;

    public function __construct()
    {
        $this->queue = config('vatswim.queue_name', 'vatswim');
    }

    /**
     * Handle the event.
     */
    public function handle(PirepFiled $event): void
    {
        if (!config('vatswim.sync_pirep_filed', true)) {
            return;
        }

        $pirep = $event->pirep;

        Log::channel(config('vatswim.log_channel', 'stack'))
            ->info('[VATSWIM] PIREP filed', [
                'pirep_id' => $pirep->id,
                'callsign' => $pirep->callsign ?? ($pirep->airline?->icao . $pirep->flight_number)
            ]);

        // Dispatch job to sync flight
        if (config('vatswim.use_queue', true)) {
            SyncFlightToVatswim::dispatch($pirep, false)
                ->onQueue(config('vatswim.queue_name', 'vatswim'));
        } else {
            // Sync immediately
            $this->syncFlight($pirep);
        }
    }

    /**
     * Sync flight immediately (non-queued)
     */
    protected function syncFlight($pirep): void
    {
        $client = app('vatswim');
        $transformer = new PirepTransformer();

        $flightData = $transformer->transform($pirep, false);
        $result = $client->submitFlight($flightData);

        if ($result && ($result['success'] ?? false)) {
            Log::channel(config('vatswim.log_channel', 'stack'))
                ->info('[VATSWIM] PIREP filed sync successful', [
                    'pirep_id' => $pirep->id
                ]);
        } else {
            Log::channel(config('vatswim.log_channel', 'stack'))
                ->warning('[VATSWIM] PIREP filed sync failed', [
                    'pirep_id' => $pirep->id,
                    'response' => $result
                ]);
        }
    }
}
