<?php
/**
 * Xpress Feeder Airline - REAL LIVE WebSocket Server
 * Receives INSTANT flight updates via POST/PUSH
 */

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require __DIR__ . '/vendor/autoload.php';

class XpressFeederWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $flightCache = []; // Cache last positions
    protected $pdo;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->pdo = new PDO('pgsql:host=ep-orange-lab-af9er9mv-pooler.c-2.us-west-2.aws.neon.tech;dbname=neondb', 'neondb_owner', 'npg_QXNRj7PTlk1A');
        $stmt = $this->pdo->query("SELECT * FROM flightposition");
        while($row = $stmt->fetch()) {
            $this->flightCache[$row['callsign']] = $row;
        }
        echo "[" . date('Y-m-d H:i:s') . "] REAL LIVE WebSocket Server initialized\n";
    }

    public function handleFlightUpdate($flightData) {
        $callsign = $flightData['callsign'] ?? 'unknown';
        $this->flightCache[$callsign] = $flightData;
        $message = ['type' => 'flight_position', 'data' => $flightData, 'timestamp' => microtime(true)];
        $this->broadcast($message);
        echo "[" . date('Y-m-d H:i:s') . "] REAL-TIME update: {$callsign}\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->resourceId = uniqid();
        echo "[" . date('Y-m-d H:i:s') . "] New REAL LIVE client: {$conn->resourceId}\n";
        foreach ($this->flightCache as $flight) {
            $conn->send(json_encode(['type' => 'flight_position', 'data' => $flight]));
        }
        $conn->send(json_encode([
            'type' => 'connection_established',
            'resource_id' => $conn->resourceId,
            'server' => 'Xpress Feeder REAL LIVE WebSocket',
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'REAL-TIME FLIGHT UPDATES ACTIVE'
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) return;
        $type = $data['type'] ?? '';
        switch ($type) {
            case 'auth': $from->send(json_encode(['type' => 'auth_success', 'timestamp' => microtime(true)])); break;
            case 'subscribe_flights':
                foreach ($this->flightCache as $flight) {
                    $from->send(json_encode(['type' => 'flight_position', 'data' => $flight]));
                }
                break;
            case 'flight_push':
                if (isset($data['data'])) {
                    $this->handleFlightUpdate($data['data']);
                }
                break;
            case 'ping': $from->send(json_encode(['type' => 'pong', 'timestamp' => microtime(true), 'server_time' => date('H:i:s')])); break;
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
        echo "[" . date('Y-m-d H:i:s') . "] Client disconnected: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

class FlightPushHandler implements \Ratchet\Http\HttpServerInterface {
    protected $websocket;
    public function __construct(XpressFeederWebSocket $websocket) { $this->websocket = $websocket; }
    public function onOpen(\Ratchet\ConnectionInterface $conn, \Psr\Http\Message\RequestInterface $request = null) { }
    public function onMessage(\Ratchet\ConnectionInterface $from, $msg) { }
    public function onClose(\Ratchet\ConnectionInterface $conn) { }
    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e) { }
}

try {
    $port = getenv('PORT') ? (int)getenv('PORT') : 10000;
    $host = '0.0.0.0';
    echo "\n========================================\n";
    echo "XPRESS FEEDER REAL LIVE WEBSOCKET SERVER\n";
    echo "========================================\n";
    echo "Host: {$host}\n";
    echo "Port: {$port}\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo "Mode: INSTANT REAL-TIME PUSH\n";
    echo "========================================\n\n";
    $websocket = new XpressFeederWebSocket();
    $server = IoServer::factory(new HttpServer(new WsServer($websocket)), $port, $host);
    $server->socket->on('connection', function($socket) use ($websocket) {
        $socket->on('data', function($data) use ($websocket, $socket) {
            if (strpos($data, 'POST /push_flight') !== false) {
                $lines = explode("\r\n", $data);
                $body = end($lines);
                $flightData = json_decode($body, true);
                if ($flightData) {
                    $websocket->handleFlightUpdate($flightData);
                    $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n" . json_encode(['status' => 'ok', 'received' => microtime(true)]);
                    $socket->write($response);
                    $socket->end();
                }
            }
        });
    });
    $server->run();
} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
