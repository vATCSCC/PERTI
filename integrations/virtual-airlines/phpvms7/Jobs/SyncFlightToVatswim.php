<?php

namespace Modules\Vatswim\Jobs;

use App\Models\Pirep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Vatswim\Services\PirepTransformer;
use Modules\Vatswim\Services\VatswimClient;

/**
 * Queue job to sync flight data to VATSWIM
 */
class SyncFlightToVatswim implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $timeout = 60;

    protected Pirep $pirep;
    protected bool $includeActuals;

    /**
     * Create a new job instance.
     */
    public function __construct(Pirep $pirep, bool $includeActuals = false)
    {
        $this->pirep = $pirep;
        $this->includeActuals = $includeActuals;
        $this->tries = config('vatswim.max_retries', 3);
    }

    /**
     * Execute the job.
     */
    public function handle(VatswimClient $client): void
    {
        $transformer = new PirepTransformer();
        $flightData = $transformer->transform($this->pirep, $this->includeActuals);

        Log::channel(config('vatswim.log_channel', 'stack'))
            ->debug('[VATSWIM] Syncing flight', [
                'pirep_id' => $this->pirep->id,
                'include_actuals' => $this->includeActuals,
                'data' => $flightData
            ]);

        $result = $client->submitFlight($flightData);

        if (!$result || !($result['success'] ?? false)) {
            Log::channel(config('vatswim.log_channel', 'stack'))
                ->warning('[VATSWIM] Flight sync failed', [
                    'pirep_id' => $this->pirep->id,
                    'response' => $result,
                    'attempts' => $this->attempts()
                ]);

            // Retry if configured
            if (config('vatswim.retry_failed', true) && $this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts()); // Exponential backoff
            }
        } else {
            Log::channel(config('vatswim.log_channel', 'stack'))
                ->info('[VATSWIM] Flight sync successful', [
                    'pirep_id' => $this->pirep->id,
                    'processed' => $result['data']['processed'] ?? 0
                ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel(config('vatswim.log_channel', 'stack'))
            ->error('[VATSWIM] Flight sync job failed permanently', [
                'pirep_id' => $this->pirep->id,
                'error' => $exception->getMessage()
            ]);
    }
}
