<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemHealthService
{
    public function __construct(
        private readonly SmsHealthService $smsHealth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $sms = $this->smsHealth->check();

        return [
            'status' => $this->overallStatus($sms),
            'php' => $this->phpInfo(),
            'laravel' => $this->laravelInfo(),
            'database' => $this->databaseHealth(),
            'cache' => $this->cacheHealth(),
            'queue' => $this->queueHealth(),
            'sms' => $sms,
            'disk' => $this->diskUsage(),
            'uptime' => $this->serverUptime(),
        ];
    }

    /**
     * @param  array<string, mixed>  $sms
     */
    private function overallStatus(array $sms): string
    {
        try {
            DB::select('SELECT 1');
            Cache::store()->get('health-check');
        } catch (\Throwable) {
            return 'degraded';
        }

        // A platform that cannot send a password reset or an order update is not
        // "All Systems Operational", however green the database is.
        if (($sms['status'] ?? null) === 'critical') {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * @return array<string, string>
     */
    private function phpInfo(): array
    {
        return [
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit') ?: 'unknown',
            'max_execution_time' => ini_get('max_execution_time') ?: 'unknown',
            'upload_max_filesize' => ini_get('upload_max_filesize') ?: 'unknown',
            'extensions' => implode(', ', array_intersect(
                ['pdo', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'redis'],
                get_loaded_extensions()
            )),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function laravelInfo(): array
    {
        return [
            'version' => app()->version(),
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug') ? 'ON' : 'OFF',
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'session_driver' => config('session.driver'),
            'broadcast_driver' => config('broadcasting.default'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            $driver = config('database.default');
            $size = null;
            $dbName = config("database.connections.{$driver}.database");

            if ($driver === 'mysql') {
                $result = DB::select('SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = ?', [$dbName]);
                $size = ($result[0]->size_mb ?? '0').' MB';
            }

            return [
                'status' => 'connected',
                'driver' => $driver,
                'latency_ms' => $latency,
                'database' => $dbName,
                'size' => $size,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheHealth(): array
    {
        try {
            $key = 'health-check-'.time();
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            return [
                'status' => $value === 'ok' ? 'connected' : 'error',
                'driver' => config('cache.default'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'driver' => config('cache.default'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queueHealth(): array
    {
        try {
            $failedCount = DB::table('failed_jobs')->count();
            $pendingCount = DB::table('jobs')->count();

            return [
                'driver' => config('queue.default'),
                'pending_jobs' => $pendingCount,
                'failed_jobs' => $failedCount,
                'status' => $failedCount > 10 ? 'warning' : 'healthy',
            ];
        } catch (\Throwable $e) {
            return [
                'driver' => config('queue.default'),
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function diskUsage(): array
    {
        $path = base_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        if ($total === false || $free === false) {
            return ['status' => 'unknown'];
        }

        $used = $total - $free;
        $percentUsed = round(($used / $total) * 100, 1);

        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'free' => $this->formatBytes($free),
            'percent_used' => $percentUsed.'%',
            'status' => $percentUsed > 90 ? 'critical' : ($percentUsed > 75 ? 'warning' : 'healthy'),
        ];
    }

    private function serverUptime(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'N/A (Windows)';
        }

        $uptime = @shell_exec('uptime -p');

        return $uptime ? trim($uptime) : 'unknown';
    }

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
