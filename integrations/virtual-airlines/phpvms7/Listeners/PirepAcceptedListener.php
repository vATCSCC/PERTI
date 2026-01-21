<?php

namespace Modules\Vatswim\Listeners;

use App\Events\PirepAccepted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Vatswim\Jobs\SyncFlightToVatswim;
use Modules\Vatswim\Services\PirepTransformer;

/**
 * Handle PIREP accepted event
 *
 * Submits final flight data with actual OOOI times to VATSWIM
 * when a PIREP is accepted/approved by VA staff.
 * This provides T11-T14 actuals (confirmed OOOI times).
 */
class PirepAcceptedListener implements ShouldQueue
{
    public string $queue = 'vatswim';

    public function __construct()
    {
        $this->queue = config('vatswim.queue_name', 'vatswim');
    }

    /**
     * Handle the event.
     */
    public function handle(PirepAccepted $event): void
    {
        if (!config('vatswim.sync_pirep_accepted', true)) {
            return;
        }

        $pirep = $event->pirep;

        Log::channel(config('vatswim.log_channel', 'stack'))
            ->info('[VATSWIM] PIREP accepted', [
                'pirep_id' => $pirep->id,
                'callsign' => $pirep->callsign ?? ($pirep->airline?->icao . $pirep->flight_number)
            ]);

        // Dispatch job to sync flight with actual times
        if (config('vatswim.use_queue', true)) {
            SyncFlightToVatswim::dispatch($pirep, true)
                ->onQueue(config('vatswim.queue_name', 'vatswim'));
        } else {
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

        // Include actual OOOI times for accepted PIREPs
        $flightData = $transformer->transform($pirep, true);
        $result = $client->submitFlight($flightData);

        if ($result && ($result['success'] ?? false)) {
            Log::channel(config('vatswim.log_channel', 'stack'))
                ->info('[VATSWIM] PIREP accepted sync successful', [
                    'pirep_id' => $pirep->id
                ]);
        } else {
            Log::channel(config('vatswim.log_channel', 'stack'))
                ->warning('[VATSWIM] PIREP accepted sync failed', [
                    'pirep_id' => $pirep->id,
                    'response' => $result
                ]);
        }
    }
}
