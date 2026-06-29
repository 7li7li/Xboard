<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\WaitTimeCalculator;

class SystemController extends Controller
{
    public function getSystemStatus()
    {
        $queue = $this->getQueueStatus();
        $data = [
            'schedule' => $this->getScheduleStatus(),
            'horizon' => $queue['healthy'],
            'queue' => $queue,
            'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
        ];
        return $this->success($data);
    }

    public function getQueueWorkload(WorkloadRepository $workload)
    {
        if ($this->getQueueStatus()['driver'] === 'sync') {
            return $this->success([]);
        }

        try {
            $workload = collect($workload->get())->sortBy('name')->values()->toArray();
        } catch (\Throwable) {
            $workload = [];
        }

        return $this->success($workload);
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return false;
        }

        return collect($masters)->doesntContain(function ($master) {
            return $master->status === 'paused';
        });
    }

    protected function getQueueStatus(): array
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver", $connection);

        if ($driver === 'sync') {
            return [
                'healthy' => true,
                'driver' => $driver,
                'connection' => $connection,
                'horizon' => false,
                'message' => 'Queue connection is sync; jobs run inline and Horizon is not required.',
            ];
        }

        try {
            $masters = collect(app(MasterSupervisorRepository::class)->all());
            $pausedMasters = $masters->filter(fn($master) => $master->status === 'paused')->count();
            $processes = $this->totalProcessCount();
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'driver' => $driver,
                'connection' => $connection,
                'horizon' => false,
                'masters' => 0,
                'paused_masters' => 0,
                'processes' => 0,
                'message' => 'Unable to read Horizon status: ' . $e->getMessage(),
            ];
        }

        return [
            'healthy' => $masters->isNotEmpty() && $pausedMasters === 0 && $processes > 0,
            'driver' => $driver,
            'connection' => $connection,
            'horizon' => $masters->isNotEmpty(),
            'masters' => $masters->count(),
            'paused_masters' => $pausedMasters,
            'processes' => $processes,
        ];
    }

    public function getQueueStats()
    {
        $queueStatus = $this->getQueueStatus();

        if ($queueStatus['driver'] === 'sync') {
            return $this->success($this->emptyQueueStats($queueStatus));
        }

        try {
            $data = [
                'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
                'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
                'pausedMasters' => $this->totalPausedMasters(),
                'periods' => [
                    'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                    'recentJobs' => config('horizon.trim.recent'),
                ],
                'processes' => $this->totalProcessCount(),
                'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
                'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
                'recentJobs' => app(JobRepository::class)->countRecent(),
                'status' => $queueStatus['healthy'],
                'queue' => $queueStatus,
                'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
            ];
        } catch (\Throwable $e) {
            $queueStatus['healthy'] = false;
            $queueStatus['message'] = 'Unable to read Horizon metrics: ' . $e->getMessage();

            $data = $this->emptyQueueStats($queueStatus);
        }

        return $this->success($data);
    }

    protected function emptyQueueStats(array $queueStatus): array
    {
        return [
            'failedJobs' => 0,
            'jobsPerMinute' => 0,
            'pausedMasters' => 0,
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => 0,
            'queueWithMaxRuntime' => null,
            'queueWithMaxThroughput' => null,
            'recentJobs' => 0,
            'status' => $queueStatus['healthy'],
            'queue' => $queueStatus,
            'wait' => collect(),
        ];
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    public function getQueueMasters()
    {
        if ($this->getQueueStatus()['driver'] === 'sync') {
            return response()->json([]);
        }

        try {
            $masters = collect(app(MasterSupervisorRepository::class)->all())->keyBy('name')->sortBy('name');
            $supervisors = collect(app(SupervisorRepository::class)->all())->sortBy('name')->groupBy('master');

            $masters = $masters->each(function ($master, $name) use ($supervisors) {
                $master->supervisors = ($supervisors->get($name) ?? collect())
                    ->merge(
                        collect(ProvisioningPlan::get($name)->plan[$master->environment ?? config('horizon.env') ?? config('app.env')] ?? [])
                            ->map(function ($value, $key) use ($name) {
                                return (object) [
                                    'name' => $name . ':' . $key,
                                    'master' => $name,
                                    'status' => 'inactive',
                                    'processes' => [],
                                    'options' => [
                                        'queue' => array_key_exists('queue', $value) && is_array($value['queue'])
                                            ? implode(',', $value['queue'])
                                            : ($value['queue'] ?? ''),
                                        'balance' => $value['balance'] ?? null,
                                    ],
                                ];
                            })
                    )
                    ->unique('name')
                    ->values();
            });
        } catch (\Throwable) {
            $masters = [];
        }

        return response()->json($masters);
    }

    public function getAuditLog(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 10));

        $builder = AdminAuditLog::with('admin:id,email')
            ->orderBy('id', 'DESC')
            ->when($request->input('action'), fn($q, $v) => $q->where('action', $v))
            ->when($request->input('admin_id'), fn($q, $v) => $q->where('admin_id', $v))
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(function ($q) use ($keyword) {
                    $q->where('uri', 'like', '%' . $keyword . '%')
                      ->orWhere('request_data', 'like', '%' . $keyword . '%');
                });
            });

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();

        return response(['data' => $res, 'total' => $total]);
    }

    public function getHorizonFailedJobs(Request $request, JobRepository $jobRepository)
    {
        if ($this->getQueueStatus()['driver'] === 'sync') {
            return response()->json([
                'data' => [],
                'total' => 0,
                'current' => max(1, (int) $request->input('current', 1)),
                'page_size' => max(10, (int) $request->input('page_size', 20)),
            ]);
        }

        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 20));
        $offset = ($current - 1) * $pageSize;

        try {
            $failedJobs = collect($jobRepository->getFailed())
                ->sortByDesc('failed_at')
                ->slice($offset, $pageSize)
                ->values();

            $total = $jobRepository->countFailed();
        } catch (\Throwable) {
            $failedJobs = collect();
            $total = 0;
        }

        return response()->json([
            'data' => $failedJobs,
            'total' => $total,
            'current' => $current,
            'page_size' => $pageSize,
        ]);
    }

}
