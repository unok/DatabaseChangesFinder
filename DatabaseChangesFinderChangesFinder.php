<?php /** @noinspection PhpComposerExtensionStubsInspection */

use Carbon\Carbon;

require_once __DIR__ . '/vendor/autoload.php';

class DatabaseChangesFinder
{
    private PDO $connection;
    private string $action;
    private string $transactionKey;

    public function __construct(PDO $connection, string $action, string $transactionKey)
    {
        $this->connection = $connection;
        $this->action = $action;
        $this->transactionKey = $transactionKey;
    }

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        if ($this->action === 'start') {
            $this->runStartProcess();
        } else {
            $this->runEndProcess();
        }
    }

    /**
     * @throws JsonException
     */
    private function runStartProcess(): void
    {
        $static = new DatabaseStatistics($this->connection);
        $this->saveStartStatic($static);
    }

    /**
     * @throws JsonException
     */
    private function runEndProcess(): void
    {
        $newStatistics = new DatabaseStatistics($this->connection);
        $oldStatistics = DatabaseStatistics::createObjectFromJson(file_get_contents($this->getSnapshotFileName()), $this->connection);
        echo $newStatistics->diff($oldStatistics, $newStatistics)->toJson() . PHP_EOL;
    }

    /**
     * @param DatabaseStatistics $static
     * @throws JsonException
     */
    private function saveStartStatic(DatabaseStatistics $static): void
    {
        if (file_exists($this->getSnapshotFileName())) {
            throw new InvalidArgumentException('Snapshot file already exists: ' . $this->getSnapshotFileName());
        }
        file_put_contents($this->getSnapshotFileName(), $static->toJson());
    }

    private function getSnapshotFileName(): string
    {
        return "dcf.{$this->transactionKey}.json";
    }
}

class DatabaseStatisticsDiff
{
    private array $diffs = [];

    /**
     * @return string
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->diffs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param string|array $message
     */
    public function addDiff(string $table, string $key, $message): void
    {
        $this->diffs[$table][$key] = $message;
    }
}

class DatabaseStatistics
{
    private const DISPLAY_NAME = [
        'n_tup_ins' => 'insert_count',
        'n_tup_upd' => 'update_count',
        'n_tup_del' => 'delete_count',
//        'seq_scan' => 'sequence_scan_count',
//        'idx_scan' => 'index_scan_count',
    ];

    private const CRUD_COLUMNS = [
        'created',
        'created_at',
        'created_on',
        'updated',
        'updated_at',
        'updated_on',
        'modified',
        'modified_at',
        'modified_on',
        'deleted',
        'deleted_at',
        'deleted_on',
        'started',
        'finished',
    ];

    private const DO_DATA_DIFF_EXECUTE = [
        'n_tup_ins' => true,
        'n_tup_upd' => true,
        'n_tup_del' => false,
        'seq_scan' => false,
        'idx_scan' => false,
    ];

    private Carbon $targetTimestamp;
    private PDO $connection;

    private array $statistics = [];


    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
        $this->targetTimestamp = new Carbon();
        $this->loadStatistics();
    }

    private function loadStatistics(): void
    {
        $sql = $this->getSqlForTableStatistics();
        $statement = $this->connection->query($sql);
        $statistics = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($statistics as $statistic) {
            $this->statistics[$statistic['relname']] = $statistic;
        }
    }

    public function getTargetTimestamp(): Carbon
    {
        return $this->targetTimestamp;
    }

    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * @return string
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode(
            ['targetTimestamp' => $this->targetTimestamp, 'statistics' => $this->statistics],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param string $jsonString
     * @param PDO $connection
     * @return DatabaseStatistics
     * @throws JsonException
     */
    public static function createObjectFromJson(string $jsonString, PDO $connection): DatabaseStatistics
    {
        $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        $statistics = new self($connection);
        $statistics->targetTimestamp = Carbon::parse($data['targetTimestamp']);
        $statistics->statistics = $data['statistics'];
        return $statistics;
    }

    public function diff(DatabaseStatistics $oldDS, DatabaseStatistics $newDS): DatabaseStatisticsDiff
    {
        $oldStatistics = $oldDS->getStatistics();
        $newStatistics = $newDS->getStatistics();
        $databaseStatisticsDiff = new DatabaseStatisticsDiff();
        foreach ($oldStatistics as $table => $oldStatistic) {
            if (!isset($newStatistics[$table])) {
                $databaseStatisticsDiff->addDiff($table, 'schema', "Table {$table} has been removed or renamed.");
            }
            $newStatistic = $newStatistics[$table];
            $hasDiff = $this->diffStatisticsDetail(
                $table, $oldStatistic, $newStatistic, $databaseStatisticsDiff);
            if ($hasDiff) {
                $databaseStatisticsDiff->addDiff($table, 'data', $this->getDataDiff($table, $oldDS->getTargetTimestamp()));
            }
        }
        foreach ($newStatistics as $table => $newStatistic) {
            if (isset($oldStatistics[$table])) {
                continue;
            }
            $databaseStatisticsDiff->addDiff($table, 'schema', "Table {$table} has been created.");
        }
        return $databaseStatisticsDiff;
    }

    /**
     * @param string $table
     * @param array $oldStatistic
     * @param array $newStatistic
     * @param DatabaseStatisticsDiff $databaseStatisticsDiff
     * @return bool
     */
    private function diffStatisticsDetail(string $table, array $oldStatistic, array $newStatistic, DatabaseStatisticsDiff $databaseStatisticsDiff): bool
    {
        $hasDiff = false;
        foreach ($oldStatistic as $key => $val) {
            if (preg_match('/^\d+$/', $key)) {
                continue;
            }
            if (array_key_exists($key, self::DISPLAY_NAME) === false) {
                continue;
            }
            if ($val !== $newStatistic[$key]) {
                $valueDiff = ($newStatistic[$key] ?? 0) - $val;
                $displayKey = self::DISPLAY_NAME[$key];
                $databaseStatisticsDiff->addDiff($table, $displayKey, $valueDiff);
                if (self::DO_DATA_DIFF_EXECUTE[$key] === true) {
                    $hasDiff = true;
                }
            }
        }
        return $hasDiff;
    }

    private function getDataDiff(string $table, Carbon $targetTimestamp): array
    {
        $columns = $this->getColumns($table);
        $whereCondition = [];
        foreach (self::CRUD_COLUMNS as $col) {
            if (in_array($col, $columns, true) === false) {
                continue;
            }
            $whereCondition[] = sprintf("%s >= '%s'", $col, $targetTimestamp->format('Y/m/d H:i:s.u'));
        }
        if (count($whereCondition) === 0) {
            return ['dataDiff' => []];
        }
        $sql = $this->getSqlForDataDiff($table, $whereCondition);
        $statement = $this->connection->query($sql);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getColumns(string $table): array
    {
        $sql = $this->getSqlForTableColumns($table);
        $statement = $this->connection->query($sql);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $result[] = $record['column_name'];
        }
        return $result;
    }

    /**
     * @param string $table
     * @param array $whereCondition
     * @return string
     */
    private function getSqlForDataDiff(string $table, array $whereCondition): string
    {
        return "SELECT * FROM {$table} WHERE " . implode(' OR ', $whereCondition);
    }

    /**
     * @param string $table
     * @return string
     */
    private function getSqlForTableColumns(string $table): string
    {
        return "SELECT column_name FROM information_schema.columns WHERE table_name = '{$table}' AND data_type like 'timestamp%'";
    }

    /**
     * @return string
     */
    private function getSqlForTableStatistics(): string
    {
        return 'SELECT * FROM pg_stat_user_tables';
    }

}

// 実行処理
$databaseUrl = getenv('DATABASE_URL');
if (empty($databaseUrl)) {
    echo '環境変数 DATABASE_URL に接続情報を設定してください。';
    exit(1);
}
$USAGE = "php {$argv[0]} start|end transaction_key";
if ($argc !== 3 || !in_array($argv[1], ['start', 'end'])) {
    echo 'USAGE: ' . $USAGE . PHP_EOL;
    exit(1);
}
[$action, $transactionKey] = [$argv[1], $argv[2]];

$urlInformation = parse_url($databaseUrl);
$database = preg_replace('/^\//', '', $urlInformation['path']);
$dsn = sprintf("pgsql:dbname=%s;host=%s;port=%s", $database, $urlInformation['host'], $urlInformation['port']);
$connection = new PDO($dsn, $urlInformation['user'], $urlInformation['pass']);

$makeDiffFromDatabase = new DatabaseChangesFinder($connection, $action, $transactionKey);
try {

    $makeDiffFromDatabase->run();
} catch (InvalidArgumentException $e) {
    echo $e->getMessage() . PHP_EOL;
    exit(1);
} catch (JsonException $e) {
    echo 'JSON 形式に変更できませんでした' . PHP_EOL;
    exit(1);
}