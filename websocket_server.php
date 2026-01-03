<?php
/**
 * Xpress Feeder Airline - Central WebSocket Server (Render)
 * Monitors live MySQL database every 1 second
 */

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require __DIR__ . '/vendor/autoload.php';

class XpressFeederWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $users;
    protected $subscriptions;
    protected $db;
    protected $lastChecked = [];

    const DEPT_FLIGHTOPS   = 'flightops';
    const DEPT_MAINTENANCE = 'maintenance';

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users   = [];
        $this->subscriptions = [
            'flights'     => [],
            'aircraft'    => [],
            'departments' => []
        ];

        $this->initDatabase();
        echo "[" . date('Y-m-d H:i:s') . "] WebSocket Server initialized with 1-second monitoring\n";
    }

    private function initDatabase() {
        try {
            $host = getenv('DB_HOST') ?: 'xfeeder.xyz';
            $name = getenv('DB_NAME') ?: 'xfeed306_flightops';
            $user = getenv('DB_USER') ?: 'xfeed306_admin';
            $pass = getenv('DB_PASS') ?: '(v9CH)}Q4O2cbWCm';
            $port = getenv('DB_PORT') ?: 3306;

            $this->db = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            echo "[" . date('Y-m-d H:i:s') . "] ✓ Database connected: {$host}/{$name}\n";
            
            $this->lastChecked['flights'] = date('Y-m-d H:i:s', strtotime('-5 seconds'));
            $this->lastChecked['maintenance'] = date('Y-m-d H:i:s', strtotime('-5 seconds'));
            
        } catch (PDOException $e) {
            echo "[" . date('Y-m-d H:i:s') . "] ✗ Database error: " . $e->getMessage() . "\n";
            $this->db = null;
        }
    }

    public function monitorDatabase() {
        if (!$this->db) return;

        try {
            // Monitor flights
            $stmt = $this->db->prepare("SELECT * FROM flight_schedule WHERE updated_at > ? LIMIT 20");
            $stmt->execute([$this->lastChecked['flights']]);
            
            while ($row = $stmt->fetch()) {
                $this->broadcastFlightUpdate($row);
            }
            $this->lastChecked['flights'] = date('Y-m-d H:i:s');

            // Monitor maintenance
            $stmt = $this->db->prepare("SELECT * FROM aircraft_mro WHERE updated_at > ? LIMIT 20");
            $stmt->execute([$this->lastChecked['maintenance']]);
            
            while ($row = $stmt->fetch()) {
                $this->broadcastMaintenanceUpdate($row);
            }
            $this->lastChecked['maintenance'] = date('Y-m-d H:i:s');
            
        } catch (PDOException $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Monitor error: " . $e->getMessage() . "\n";
        }
    }

    private function broadcastFlightUpdate($data) {
        $message = [
            'type' => 'flight_status_change',
            'flight_id' => $data['id'] ?? $data['flight_id'],
            'flight_number' => $data['flight_number'] ?? null,
            'status' => $data['status'] ?? null,
            'aircraft_registration' => $data['aircraft_registration'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        $this->broadcast($message);
        echo "[" . date('Y-m-d H:i:s') . "] Flight update: " . ($message['flight_number'] ?? 'unknown') . "\n";
    }

    private function broadcastMaintenanceUpdate($data) {
        $message = [
            'type' => 'aircraft_maintenance',
            'aircraft_registration' => $data['aircraft_registration'] ?? $data['registration'],
            'status' => $data['status'] ?? 'In Maintenance',
            'start_time' => $data['start_time'] ?? null,
            'estimated_completion' => $data['estimated_completion'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        $this->broadcast($message);
        echo "[" . date('Y-m-d H:i:s') . "] Maintenance update: " . $message['aircraft_registration'] . "\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->subscriptions = [];

        echo "[" . date('Y-m-d H:i:s') . "] New connection: {$conn->resourceId}\n";

        $conn->send(json_encode([
            'type' => 'connection_established',
            'resource_id' => $conn->resourceId,
            'server' => 'Xpress Feeder WebSocket (Render)',
            'timestamp' => date('Y-m-d H:i:s'),
            'database_status' => $this->db ? 'connected' : 'disconnected'
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) return;

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'auth':
            case 'connect':
                $from->userId = $data['user_id'] ?? 'guest';
                $from->send(json_encode(['type' => 'auth_success', 'user_id' => $from->userId]));
                break;

            case 'subscribe_department':
                $dept = $data['department'] ?? null;
                if ($dept) {
                    $from->subscriptions['dept_' . $dept] = true;
                    $from->send(json_encode(['type' => 'subscription_success', 'department' => $dept]));
                }
                break;

            case 'subscribe_aircraft':
                $reg = $data['aircraft_registration'] ?? null;
                if ($reg) {
                    $from->subscriptions['aircraft_' . $reg] = true;
                    $from->send(json_encode(['type' => 'subscription_success', 'aircraft_registration' => $reg]));
                }
                break;

            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'timestamp' => time()]));
                break;
        }
    }

    private function broadcast($data) {
        $message = json_encode($data);
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "[" . date('Y-m-d H:i:s') . "] Connection closed: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

try {
    $port = getenv('PORT') ? (int)getenv('PORT') : 10000;
    $host = '0.0.0.0';

    echo "\n========================================\n";
    echo "XPRESS FEEDER WEBSOCKET SERVER (Render)\n";
    echo "========================================\n";
    echo "Host: {$host}\n";
    echo "Port: {$port}\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n\n";

    $websocket = new XpressFeederWebSocket();

    $server = IoServer::factory(
        new HttpServer(
            new WsServer($websocket)
        ),
        $port,
        $host
    );

    $loop = $server->loop;
    $loop->addPeriodicTimer(1, function() use ($websocket) {
        $websocket->monitorDatabase();
    });

    $server->run();

} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
