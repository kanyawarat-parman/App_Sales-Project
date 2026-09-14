<?php
class Database {
    private string $host;
    private string $dbname;
    private string $user;
    private string $pass;
    private string $charset = 'utf8mb4';
    private ?PDO $conn = null;


    // local  dev
    public function __construct() {
        // getenv() ไม่ใช่ $_ENV — เครื่องนี้ตั้ง php.ini variables_order="GPCS" (ไม่มี "E")
        // ทำให้ $_ENV ไม่ถูก populate จาก environment variable จริงเลย
        $this->host   = getenv('DB_HOST') ?: 'localhost';
        $this->dbname = getenv('DB_NAME') ?: 'appsalesproject_db';
        $this->user   = getenv('DB_USER') ?: 'root';
        $this->pass   = getenv('DB_PASS') ?: 'root';
    }



    // on production 
    // public function __construct() {
    //     // getenv() ไม่ใช่ $_ENV — เครื่องนี้ตั้ง php.ini variables_order="GPCS" (ไม่มี "E")
    //     // ทำให้ $_ENV ไม่ถูก populate จาก environment variable จริงเลย
    //     $this->host   = getenv('DB_HOST') ?: 'localhost';
    //     $this->dbname = getenv('DB_NAME') ?: 'appsalesproject_db';
    //     $this->user   = getenv('DB_USER') ?: 'usr_saleproject';
    //     $this->pass   = getenv('DB_PASS') ?: 'Q1j?J#gdd6G4alwt';
    // }




    public function getConnection(): PDO {
        if ($this->conn) return $this->conn;
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $this->conn = new PDO($dsn, $this->user, $this->pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
        return $this->conn;
    }
}
