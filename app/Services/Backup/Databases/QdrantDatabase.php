<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Services\Backup\DTO\DatabaseOperationLog;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Qdrant snapshot backup. Mounted over the Databasement image, which has no
 * native Qdrant type. Refresh these overrides after an image upgrade.
 */
class QdrantDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function listDatabases(): array
    {
        $payload = $this->json('GET', $this->baseUrl().'/collections', 30);
        $names = [];

        foreach ($payload['result']['collections'] ?? [] as $collection) {
            if (is_array($collection) && isset($collection['name']) && is_string($collection['name'])) {
                $names[] = $collection['name'];
            }
        }

        sort($names);

        return $names;
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $collection = (string) ($this->config['database'] ?? '');
        if ($collection === '') {
            throw new \RuntimeException('Qdrant collection name is empty');
        }

        $snapshotsUrl = $this->baseUrl().'/collections/'.rawurlencode($collection).'/snapshots';
        $created = $this->json('POST', $snapshotsUrl, 3600);
        $name = $created['result']['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new \RuntimeException('Qdrant did not return a snapshot name');
        }

        $snapshotUrl = $snapshotsUrl.'/'.rawurlencode($name);
        $deleteWarning = null;

        try {
            sleep(2);
            $this->download($snapshotUrl, $outputPath);
            $size = is_file($outputPath) ? filesize($outputPath) : false;
            if ($size === false || $size === 0) {
                throw new \RuntimeException('Qdrant snapshot download is empty');
            }
        } finally {
            $deleteWarning = $this->deleteQuietly($snapshotUrl);
        }

        return new DatabaseOperationResult(
            log: $deleteWarning === null ? null : new DatabaseOperationLog($deleteWarning, 'warning'),
        );
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        throw new UnsupportedDatabaseTypeException('qdrant');
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        throw new UnsupportedDatabaseTypeException('qdrant');
    }

    public function testConnection(): array
    {
        $start = microtime(true);

        try {
            $payload = $this->json('GET', $this->baseUrl().'/collections', 10);
        } catch (ProcessTimedOutException) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            return [
                'success' => false,
                'message' => 'Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.',
                'details' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'details' => [],
            ];
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $durationMs,
                'output' => json_encode([
                    'dbms' => 'Qdrant',
                    'collections' => count($payload['result']['collections'] ?? []),
                ], JSON_PRETTY_PRINT),
            ],
        ];
    }

    private function baseUrl(): string
    {
        $host = (string) ($this->config['host'] ?? 'qdrant');
        $port = (int) ($this->config['port'] ?? 6333);

        return sprintf('http://%s:%d', $host, $port);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, int $timeout): array
    {
        $body = $this->run($method, $url, $timeout);
        $payload = json_decode($body, true);
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            throw new \RuntimeException('Unexpected Qdrant response');
        }

        return $payload;
    }

    private function download(string $url, string $outputPath): void
    {
        $result = Process::timeout(3600)->run(array_merge(
            ['curl', '-fL', '--retry', '3', '--retry-delay', '2', '-o', $outputPath],
            $this->headers(),
            [$url],
        ));

        if ($result->failed()) {
            $error = trim($result->errorOutput());
            throw new \RuntimeException($error !== '' ? $error : 'Failed to download Qdrant snapshot');
        }
    }

    private function deleteQuietly(string $url): ?string
    {
        try {
            $this->run('DELETE', $url, 60);
        } catch (\Throwable $e) {
            return 'Qdrant snapshot was copied, but deleting it from the server failed: '.$e->getMessage();
        }

        return null;
    }

    private function run(string $method, string $url, int $timeout): string
    {
        $result = Process::timeout($timeout)->run(array_merge(
            ['curl', '-fsS', '--retry', '3', '--retry-delay', '2', '-X', $method],
            $this->headers(),
            [$url],
        ));

        if ($result->failed()) {
            $error = trim($result->errorOutput() ?: $result->output());
            throw new \RuntimeException($error !== '' ? $error : 'Qdrant request failed');
        }

        return $result->output();
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        $key = (string) ($this->config['pass'] ?? '');
        if ($key === '') {
            return [];
        }

        return ['-H', 'api-key: '.$key];
    }
}
