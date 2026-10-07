<?php // tests/SaasStatusProbeTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Registry;
use KipSaaS\Tenants;
use PHPUnit\Framework\TestCase;

/**
 * bin/status-probe against a fixture registry and local HTTP stubs: the
 * jsonl log under one exclusive flock (idempotent re-runs, torn trailing
 * lines, the 30-day prune), the pre-aggregated public file (counters only:
 * host names live only in the operator's jsonl), and the lock actually
 * serializing an overlapping run. The probe is a standalone script, so it
 * is exercised as a process, like bin/saas.
 */
final class SaasStatusProbeTest extends TestCase
{
    private const UP_PORT = 8129;
    private const DOWN_HOST = '127.0.0.1:9'; // nothing listens; refusal is immediate

    private string $dir;
    /** @var list<resource> */
    private array $procs = [];
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/saasprobe-' . bin2hex(random_bytes(4));
        $this->dirs[] = $this->dir;
        mkdir($this->dir . '/docroot', 0777, true);
        file_put_contents($this->dir . '/docroot/healthz', 'ok');

        // Registry: the stub host is an active tenant AND the control host
        // (deduped by the probe), a dead host is an active tenant, and a
        // pending tenant is never probed. Insertion order puts the UP host
        // first so a stale success header from one fetch cannot mask the
        // next host's failure.
        $tenants = new Tenants((new Registry('sqlite:' . $this->dir . '/registry.sqlite'))->pdo());
        $up = $tenants->create('up', '127.0.0.1:' . self::UP_PORT, 'standard', 'u@e.test', 'Up', '', '');
        $tenants->setStatus($up, 'verified');
        $tenants->setStatus($up, 'active');
        $down = $tenants->create('down', self::DOWN_HOST, 'standard', 'd@e.test', 'Down', '', '');
        $tenants->setStatus($down, 'verified');
        $tenants->setStatus($down, 'active');
        $pend = $tenants->create('pend', 'pend.example.test', 'standard', 'p@e.test', 'Pend', '', '');
        $tenants->setStatus($pend, 'verified'); // stops before active: never probed

        $config = [
            'env' => 'dev',
            'registry_dsn' => 'sqlite:' . $this->dir . '/registry.sqlite',
            'status_file' => $this->dir . '/status.json',
            'status_probe_url' => 'http://{host}/healthz',
            'nginx' => ['control_host' => '127.0.0.1:' . self::UP_PORT],
        ];
        file_put_contents($this->dir . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");

        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . self::UP_PORT . ' -t '
            . escapeshellarg($this->dir . '/docroot');
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->procs[] = $proc;
        $up1 = false;
        for ($i = 0; $i < 100; $i++) {
            usleep(50000);
            $body = @file_get_contents('http://127.0.0.1:' . self::UP_PORT . '/healthz');
            if ($body === 'ok') { $up1 = true; break; }
        }
        if (!$up1) $this->fail('health stub did not come up');
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) continue;
            $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($ri as $item) {
                $item->isDir() ? @rmdir((string) $item->getPathname()) : @unlink((string) $item->getPathname());
            }
            @rmdir($dir);
        }
    }

    /** @return array{int, string} exit code, combined output */
    private function probe(): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/status-probe')
            . ' ' . escapeshellarg($this->dir . '/config.php') . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    /** @return list<array<string,mixed>> decoded jsonl rows */
    private function logRows(): array
    {
        $rows = [];
        foreach (file($this->dir . '/status.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode((string) $line, true);
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string,mixed> decoded public aggregate */
    private function aggregate(): array
    {
        $decoded = json_decode((string) file_get_contents($this->dir . '/status.json'), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function test_probe_appends_the_log_and_writes_a_count_only_aggregate(): void
    {
        [$code, $out] = $this->probe();
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('probed 2 hosts: 1 ok, 1 down', $out);

        // The operator log carries the per-host truth; the pending tenant is
        // absent and the control host is deduped against the tenant host.
        $rows = $this->logRows();
        self::assertCount(2, $rows);
        $byHost = array_column($rows, null, 'host');
        self::assertArrayHasKey('127.0.0.1:' . self::UP_PORT, $byHost);
        self::assertArrayHasKey(self::DOWN_HOST, $byHost);
        self::assertTrue($byHost['127.0.0.1:' . self::UP_PORT]['ok']);
        self::assertFalse($byHost[self::DOWN_HOST]['ok']);
        foreach ($rows as $row) {
            self::assertArrayHasKey('ts', $row);
            self::assertArrayHasKey('ms', $row);
        }

        // The public aggregate: counters and times, never a host name. The
        // exact key list is the disclosure contract.
        $agg = $this->aggregate();
        self::assertSame(['generated_at', 'overall', 'hosts_down', 'last_incident'], array_keys($agg));
        self::assertSame(2, $agg['overall']['checks']);
        self::assertSame(50.0, (float) $agg['overall']['ok_pct']); // int or float in the file; the reader casts
        self::assertSame(1, $agg['hosts_down']);
        self::assertSame($byHost[self::DOWN_HOST]['ts'], $agg['last_incident']);
        $raw = (string) file_get_contents($this->dir . '/status.json');
        self::assertStringNotContainsString('127.0.0.1', $raw, 'the public file must never carry a host name');

        // Second run: appends its own checks, keeps the old ones exactly
        // once, and re-aggregates over the whole 30-day window.
        [$code, $out] = $this->probe();
        self::assertSame(0, $code, $out);
        self::assertCount(4, $this->logRows(), 'a re-run must not duplicate earlier lines');
        $agg = $this->aggregate();
        self::assertSame(4, $agg['overall']['checks']);
        self::assertSame(50.0, (float) $agg['overall']['ok_pct']);
    }

    public function test_probe_tolerates_a_torn_trailing_line(): void
    {
        // A killed writer can leave a half-flushed last line. The aggregator
        // drops it instead of crashing, and the rewrite clears it out.
        $fresh = ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'host' => 'seed.example.test', 'ok' => true, 'ms' => 5];
        file_put_contents($this->dir . '/status.jsonl',
            json_encode($fresh, JSON_UNESCAPED_SLASHES) . "\n" . '={"ts":"2026-10-0');
        [$code, $out] = $this->probe();
        self::assertSame(0, $code, $out);

        $rows = $this->logRows();
        self::assertCount(3, $rows, 'the torn line must be dropped, the valid line kept, the run appended');
        $agg = $this->aggregate();
        self::assertSame(3, $agg['overall']['checks']);
        self::assertSame(66.67, $agg['overall']['ok_pct']); // seed ok + up ok + down failed
        self::assertSame(1, $agg['hosts_down']);
    }

    public function test_probe_prunes_checks_older_than_the_30_day_window(): void
    {
        $old = ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 31 * 86400), 'host' => 'old.example.test', 'ok' => true, 'ms' => 5];
        $failed = ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400), 'host' => 'seed.example.test', 'ok' => false, 'ms' => 5];
        file_put_contents($this->dir . '/status.jsonl',
            json_encode($old, JSON_UNESCAPED_SLASHES) . "\n" . json_encode($failed, JSON_UNESCAPED_SLASHES) . "\n");
        [$code, $out] = $this->probe();
        self::assertSame(0, $code, $out);

        $raw = (string) file_get_contents($this->dir . '/status.jsonl');
        self::assertStringNotContainsString('old.example.test', $raw, 'checks past the window must be pruned');
        $rows = $this->logRows();
        self::assertCount(3, $rows); // the fresh seed + this run's two
        $agg = $this->aggregate();
        self::assertSame(3, $agg['overall']['checks']);
        // The seed itself was a failed check: only the up host's check
        // passes, and both failing hosts' newest checks stay failures.
        self::assertSame(33.33, (float) $agg['overall']['ok_pct']);
        self::assertSame(2, $agg['hosts_down']);
        // The operator log carries host names; the public file never does.
        self::assertStringContainsString(self::DOWN_HOST, $raw);
        self::assertStringNotContainsString(self::DOWN_HOST, (string) file_get_contents($this->dir . '/status.json'));
        $downRow = $rows[(int) array_search(self::DOWN_HOST, array_column($rows, 'host'), true)];
        self::assertSame($downRow['ts'], $agg['last_incident'], 'the newest failed check is the last incident');
    }

    public function test_the_exclusive_lock_serializes_overlapping_runs(): void
    {
        // An overlapping cron must wait for the lock, then write its checks
        // exactly once: no interleaved lines, no lost run.
        $fp = fopen($this->dir . '/status.jsonl', 'c+');
        flock($fp, LOCK_EX);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/status-probe')
            . ' ' . escapeshellarg($this->dir . '/config.php');
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(400000); // the probe finishes its network pass and parks on the lock
        $status = proc_get_status($proc);
        self::assertTrue($status['running'], 'the probe must be waiting for the lock, not writing around it');
        flock($fp, LOCK_UN);
        fclose($fp);
        $code = proc_close($proc);
        self::assertSame(0, $code);
        self::assertCount(2, $this->logRows(), 'exactly one run of checks may land');
    }

    public function test_probe_refuses_to_run_without_a_config(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/status-probe')
            . ' ' . escapeshellarg($this->dir . '/missing-config.php') . ' 2>&1', $out, $code);
        self::assertSame(1, $code);
        self::assertStringContainsString('no config', implode("\n", $out));
    }
}
