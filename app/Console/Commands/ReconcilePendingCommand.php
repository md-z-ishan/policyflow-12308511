<?php

namespace App\Console\Commands;

use App\Enums\PolicyState;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReconcilePendingCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'policyflow:reconcile';

    /**
     * The console command description.
     */
    protected $description = 'Reconcile stale PENDING_CONFIRMATION and NEEDS_RECONCILIATION policy requests with the insurer';

    /**
     * Execute the console command.
     */
    public function handle(IssuanceService $issuanceService): int
    {
        $staleMinutes = (int) config('services.mock_insurer.stale_minutes', 10);
        $threshold = Carbon::now()->subMinutes($staleMinutes);

        $this->info("Scanning for stale PENDING_CONFIRMATION (older than {$staleMinutes}m) and NEEDS_RECONCILIATION requests...");

        $candidates = PolicyRequest::query()
            ->where('state', PolicyState::NEEDS_RECONCILIATION)
            ->orWhere(function ($query) use ($threshold) {
                $query->where('state', PolicyState::PENDING_CONFIRMATION)
                    ->where('updated_at', '<=', $threshold);
            })
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("No pending or orphaned requests requiring reconciliation.");
            return Command::SUCCESS;
        }

        $results = [];
        $reconciledCount = 0;

        foreach ($candidates as $request) {
            $fromState = $request->state->value;
            $reconciled = $issuanceService->reconcile($request);
            $toState = $reconciled->state->value;

            $results[] = [
                'UUID' => $request->uuid,
                'Agent / Client' => "{$request->agent_id} / {$request->client_request_id}",
                'From State' => $fromState,
                'To State' => $toState,
                'Policy Number' => $reconciled->policy_number ?? 'N/A',
            ];

            $reconciledCount++;
        }

        $this->table(['UUID', 'Agent / Client', 'From State', 'To State', 'Policy Number'], $results);
        $this->info("Successfully processed {$reconciledCount} request(s) for reconciliation.");

        return Command::SUCCESS;
    }
}
