<?php
declare(strict_types=1);

final class TempSqlite
{
    /** @var string */
    public $path;
    /** @var PDO */
    public $pdo;

    public function __construct(string $schemaSql)
    {
        $this->path = tempnam(sys_get_temp_dir(), 'wcam_db_');
        if ($this->path === false) {
            throw new RuntimeException('Failed to create temp file');
        }
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec($schemaSql);
    }

    public function __destruct()
    {
        if (is_file($this->path)) {
            if (!unlink($this->path)) {
                error_log('TempSqlite: failed to remove ' . $this->path);
            }
        }
    }
}
