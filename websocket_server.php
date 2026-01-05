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
    protected $pdo; // ADDED

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        
        // ADDED DATABASE CONNECTION:
        $this->pdo = new PDO(
            'pgsql:host=ep-orange-lab-af9er9mv-pooler.c-2.us-west-2.aws.neon.tech;dbname=neondb',
            'neondb_owner',
            'npg_QXNRj7PTlk1A'
        );
        $stmt = $this->pdo->query("SELECT * FROM flightposition");
        while($row = $stmt->fetch()) {
            $this->flightCache[$row['callsign']] = $row;
        }
        // END ADDED
        
        echo "[" . date('Y-m-d H:i:s') . "] REAL LIVE WebSocket Server initialized\n";
        echo "[" . date('Y-m-d H:i:s') . "] Loaded " . count($this->flightCache) . " flights\n";
    }

    // ADDED: UPDATE FLIGHT POSITIONS
    public function updateFlightPositions() {
        foreach ($this->flightCache as $callsign => &$flight) {
            if ($flight['status'] === 'airborne') {
                // Move flight toward destination
                $flight['lat'] = (float)$flight['lat'] + 0.01;
                $flight['lon'] = (float)$flight['lon'] + 0.01;
                $flight['last_update'] = date('Y-m-d H:i:s');
                
                // Broadcast update
                $this->broadcast(json_encode([
                    'type' => 'flight_position',
                    'data' => $flight
                ]));
                
                echo "[" . date('H:i:s') . "] Moved: {$callsign}\n";
            }
        }
    }

    // NEW: Accept HTTP POST updates from flight sources
    public function handleFlightUpdate($flightData) {
        // Store in cache
        $callsign = $flightData['callsign'] ?? 'unknown';
        $this->flightCache[$callsign] = $flightData;
        
        // Broadcast INSTANTLY to all connected clients
        $message = [
            'type' => 'flight_position',
            'data' => $flightData,
            'timestamp' => microtime(true)
        ];
        
        $this->broadcast($message);
        echo "[" . date('Y-m-d H:i:s') . "] REAL-TIME update: {$callsign}\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->resourceId = uniqid();

        echo "[" . date('Y-m-d H:i:s') . "] New REAL LIVE client: {$conn->resourceId}\n";

        // Send all cached flights immediately
        foreach ($this->flightCache as $flight) {
            $conn->send(json_encode([
                'type' => 'flight_position',
                'data' => $flight
            ]));
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
            case 'auth':
                $from->send(json_encode([
                    'type' => 'auth_success', 
                    'timestamp' => microtime(true)
                ]));
                break;

            case 'subscribe_flights':
                // Send all cached flights
                foreach ($this->flightCache as $flight) {
                    $from->send(json_encode([
                        'type' => 'flight_position',
                        'data' => $flight
                    ]));
                }
                break;

            case 'flight_push':  // DIRECT flight push from source
                if (isset($data['data'])) {
                    $this->handleFlightUpdate($data['data']);
                }
                break;

            case 'ping':
                $from->send(json_encode([
                    'type' => 'pong', 
                    'timestamp' => microtime(true),
                    'server_time' => date('H:i:s')
                ]));
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
        echo "[" . date('Y-m-d H:i:s') . "] Client disconnected: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

// HTTP endpoint to accept flight pushes
class FlightPushHandler implements \Ratchet\Http\HttpServerInterface {
    protected $websocket;

    public function __construct(XpressFeederWebSocket $websocket) {
        $this->websocket = $websocket;
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn, \Psr\Http\Message\RequestInterface $request = null) {
        // Handle WebSocket connections
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg) {
        // Handle WebSocket messages
    }

    public function onClose(\Ratchet\ConnectionInterface $conn) {
        // Handle WebSocket close
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e) {
        // Handle errors
    }
}

try {
    $port = getenv('PORT') ? (int)getenv('PORT') : 10000;
    $host = '0.0.0.0';

    echo "\n========================================\n";
    echo "XPRESS FEEDER REAL LIVE WebSocket Server\n";
    echo "========================================\n";
    echo "Host: {$host}\n";
    echo "Port: {$port}\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo "Mode: INSTANT REAL-TIME MOVING FLIGHTS\n";
    echo "========================================\n\n";

    $websocket = new XpressFeederWebSocket();

    // Create HTTP server that also accepts POST requests
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($websocket)
        ),
        $port,
        $host
    );

    // ADDED: UPDATE FLIGHTS EVERY 5 SECONDS
    $server->loop->addPeriodicTimer(5, function() use ($websocket) {
        $websocket->updateFlightPositions();
    });

    // Add HTTP POST handler for flight pushes
    $server->socket->on('connection', function($socket) use ($websocket) {
        $socket->on('data', function($data) use ($websocket, $socket) {
            // Check if this is an HTTP POST request
            if (strpos($data, 'POST /push_flight') !== false) {
                // Parse flight data from POST body
                $lines = explode("\r\n", $data);
                $body = end($lines);
                $flightData = json_decode($body, true);
                
                if ($flightData) {
                    $websocket->handleFlightUpdate($flightData);
                    
                    // Send HTTP response
                    $response = "HTTP/1.1 200 OK\r\n";
                    $response .= "Content-Type: application/json\r\n";
                    $response .= "\r\n";
                    $response .= json_encode(['status' => 'ok', 'received' => microtime(true)]);
                    
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
