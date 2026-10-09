<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Trình chạy tác vụ khi PHÁT TRIỂN — chỉ hoạt động khi APP_ENV=local.
 *
 * Container "scheduler" gọi lệnh này mỗi phút. Nếu có file yêu cầu
 * storage/app/dev-runner/request.json, lệnh chạy lần lượt các tác vụ trong
 * danh sách CHO PHÉP bên dưới và ghi kết quả ra:
 *
 *   storage/app/dev-runner/results/<id>.json   trạng thái từng tác vụ
 *   storage/logs/dev-runner/<id>.log           toàn bộ nội dung in ra
 *
 * Ví dụ file yêu cầu:
 *   {"id": "p3-01", "tasks": ["migrate", "seed", "test"], "filter": "QuotationWorkflowTest"}
 *
 * Mục đích: chạy migration / seed / test mà không cần gõ lệnh docker, và để lại
 * kết quả dạng file cho việc rà lỗi. Không nhận lệnh tuỳ ý — chỉ các tác vụ đã
 * khai báo. Trên production lệnh này không được lên lịch và tự thoát.
 */
class DevRunnerCommand extends Command
{
    protected $signature = 'dev:runner';

    protected $description = 'Chạy các tác vụ phát triển được yêu cầu qua storage/app/dev-runner/request.json (chỉ môi trường local)';

    /**
     * Tác vụ được phép và lệnh artisan tương ứng.
     *
     * @var array<string, list<string>>
     */
    private const ARTISAN_TASKS = [
        'migrate' => ['migrate', '--force', '--no-interaction'],
        'seed' => ['db:seed', '--force', '--no-interaction'],
        'queue-restart' => ['queue:restart'],
        'expire-quotations' => ['quotations:expire'],
        'mark-overdue-invoices' => ['invoices:mark-overdue'],
        'send-reminders' => ['reminders:send'],
    ];

    /**
     * Biến môi trường ép cho tiến trình test: luôn dùng SQLite trong bộ nhớ,
     * không bao giờ chạm vào database thật (tests/TestCase.php kiểm tra lại).
     *
     * @var array<string, string>
     */
    private const TEST_ENV = [
        'APP_ENV' => 'testing',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS' => '4',
        'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ];

    public function handle(): int
    {
        if (! app()->environment('local')) {
            return self::SUCCESS;
        }

        $directory = storage_path('app/dev-runner');
        $requestFile = $directory.'/request.json';

        if (! is_file($requestFile)) {
            return self::SUCCESS;
        }

        $request = json_decode((string) file_get_contents($requestFile), true);

        if (! is_array($request)) {
            return self::SUCCESS;
        }

        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($request['id'] ?? ''));

        if ($id === '' || $id === null) {
            return self::SUCCESS;
        }

        $resultFile = "{$directory}/results/{$id}.json";

        // Mỗi yêu cầu chỉ chạy một lần.
        if (is_file($resultFile)) {
            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($resultFile));
        File::ensureDirectoryExists(storage_path('logs/dev-runner'));

        $logFile = storage_path("logs/dev-runner/{$id}.log");
        $tasks = array_values(array_filter((array) ($request['tasks'] ?? []), 'is_string'));
        $filter = preg_replace('/[^A-Za-z0-9_|]/', '', (string) ($request['filter'] ?? ''));

        $result = ['id' => $id, 'status' => 'running', 'started_at' => now()->toDateTimeString(), 'tasks' => []];
        $this->writeResult($resultFile, $result);
        file_put_contents($logFile, '');

        foreach ($tasks as $task) {
            $command = $this->commandFor($task, (string) $filter);

            if ($command === null) {
                $result['tasks'][] = ['task' => $task, 'exit_code' => null, 'error' => 'Tác vụ không được phép'];

                continue;
            }

            file_put_contents($logFile, "\n===== {$task} =====\n", FILE_APPEND);

            $startedAt = microtime(true);

            $process = Process::path(base_path())
                ->timeout(1800)
                ->env($task === 'test' ? self::TEST_ENV : [])
                ->run($command, function (string $type, string $output) use ($logFile): void {
                    file_put_contents($logFile, $output, FILE_APPEND);
                });

            $result['tasks'][] = [
                'task' => $task,
                'exit_code' => $process->exitCode(),
                'seconds' => round(microtime(true) - $startedAt, 1),
            ];

            $this->writeResult($resultFile, $result);

            // Tác vụ trước lỗi thì dừng: seed/test trên database chưa migrate là vô nghĩa.
            if ($process->failed()) {
                break;
            }
        }

        $result['status'] = 'finished';
        $result['finished_at'] = now()->toDateTimeString();
        $this->writeResult($resultFile, $result);

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function commandFor(string $task, string $filter): ?array
    {
        if (isset(self::ARTISAN_TASKS[$task])) {
            return [PHP_BINARY, 'artisan', ...self::ARTISAN_TASKS[$task]];
        }

        if ($task === 'test') {
            $command = [PHP_BINARY, 'vendor/bin/phpunit', '--colors=never'];

            if ($filter !== '') {
                $command[] = '--filter';
                $command[] = $filter;
            }

            return $command;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeResult(string $file, array $result): void
    {
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
