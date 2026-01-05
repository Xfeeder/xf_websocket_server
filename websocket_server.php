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
    
    // Airport coordinates for movement calculation
    protected $airports = [
        'YYJ' => ['lat' => 48.646, 'lon' => -123.426],
        'YVR' => ['lat' => 49.194, 'lon' => -123.183],
        'YCD' => ['lat' => 49.052, 'lon' => -123.870],
        'YQQ' => ['lat' => 49.711, 'lon' => -124.887],
        'YBL' => ['lat' => 49.951, 'lon' => -125.271],
        'YPW' => ['lat' => 49.834, 'lon' => -124.500],
        'YPR' => ['lat' => 54.286, 'lon' => -130.445],
        'YZP' => ['lat' => 53.254, 'lon' => -131.814],
        'ZMT' => ['lat' => 54.027, 'lon' => -132.125],
        'YZT' => ['lat' => 50.681, 'lon' => -127.367],
        'ZEL' => ['lat' => 52.185, 'lon' => -128.157],
        'QBC' => ['lat' => 52.387, 'lon' => -126.596],
        'YAZ' => ['lat' => 49.082, 'lon' => -125.772],
        'YHS' => ['lat' => 49.460, 'lon' => -123.719]
    ];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        
        // DATABASE CONNECTION
        $this->pdo = new PDO(
            'pgsql:host=ep-orange-lab-af9er9mv-pooler.c-2.us-west-2.aws.neon.tech;dbname=neondb',
            'neondb_owner',
            'npg_QXNRj7PTlk1A'
        );
        
        // LOAD ONLY ACTIVE FLIGHTS BASED ON SCHEDULE
        $currentTime = date('H:i:s');
        $currentDay = date('N'); // 1=Monday
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM flightposition 
            WHERE schedule_dow LIKE :dow 
            AND schedule_std <= :time 
            AND schedule_sta >= :time
            AND status IN ('airborne', 'departed')
        ");
        
        $stmt->execute([
            ':dow' => "%{$currentDay}%",
            ':time' => $currentTime
        ]);
        
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->flightCache[$row['callsign']] = $row;
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] REAL LIVE WebSocket Server initialized\n";
        echo "[" . date('Y-m-d H:i:s') . "] Active flights: " . count($this->flightCache) . "\n";
    }

    // CALCULATE HEADING TOWARD DESTINATION
    private function calculateHeading($lat1, $lon1, $lat2, $lon2) {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLon = deg2rad($lon2 - $lon1);
        
        $y = sin($dLon) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
        
        $bearing = rad2deg(atan2($y, $x));
        return ($bearing + 360) % 360;
    }

    // MOVE FLIGHT TOWARD DESTINATION
    public function updateFlightPositions() {
        foreach ($this->flightCache as $callsign => &$flight) {
            if ($flight['status'] === 'airborne' && isset($this->airports[$flight['destination']])) {
                $dest = $this->airports[$flight['destination']];
                
                // Calculate heading toward destination
                $flight['heading'] = $this->calculateHeading(
                    $flight['lat'], 
                    $flight['lon'], 
                    $dest['lat'], 
                    $dest['lon']
                );
                
                // Move toward destination (0.01 degrees ~ 1.1 km)
                $step = 0.01;
                $flight['lat'] = $this->moveToward($flight['lat'], $dest['lat'], $step);
                $flight['lon'] = $this->moveToward($flight['lon'], $dest['lon'], $step);
                
                // Update timestamp
                $flight['last_update'] = date('Y-m-d H:i:s');
                
                // Broadcast update
                $this->broadcast(json_encode([
                    'type' => 'flight_position',
                    'data' => $flight
                ]));
                
                echo "[" . date('H:i:s') . "] Moved: {$callsign} Heading: {$flight['heading']}°\n";
            }
        }
    }
    
    private function moveToward($current, $target, $step) {
        $diff = $target - $current;
        if (abs($diff) < $step) return $target;
        return $current + ($diff > 0 ? $step : -$step);
    }

    public function handleFlightUpdate($flightData) {
        $callsign = $flightData['callsign'] ?? 'unknown';
        $this->flightCache[$callsign] = $flightData;
        
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

        echo "[" . date('Y-m-d H:i:s') . "] New client: {$conn->resourceId}\n";

        // Send all active flights
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
            'message' => 'REAL-TIME FLIGHT UPDATES ACTIVE',
            'active_flights' => count($this->flightCache)
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
                foreach ($this->flightCache as $flight) {
                    $from->send(json_encode([
                        'type' => 'flight_position',
                        'data' => $flight
                    ]));
                }
                break;

            case 'flight_push':
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
    echo "XPRESS FEEDER REAL LIVE WEBSOCKET SERVER\n";
    echo "========================================\n";
    echo "Host: {$host}\n";
    echo "Port: {$port}\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo "Mode: ACTUAL REAL LIVE MOVING FLIGHTS\n";
    echo "========================================\n\n";

    $websocket = new XpressFeederWebSocket();

    // Create HTTP server
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($websocket)
        ),
        $port,
        $host
    );

    // UPDATE FLIGHTS EVERY 5 SECONDS WITH CORRECT HEADINGS
    $server->loop->addPeriodicTimer(5, function() use ($websocket) {
        $websocket->updateFlightPositions();
    });

    // HTTP POST handler
    $server->socket->on('connection', function($socket) use ($websocket) {
        $socket->on('data', function($data) use ($websocket, $socket) {
            if (strpos($data, 'POST /push_flight') !== false) {
                $lines = explode("\r\n", $data);
                $body = end($lines);
                $flightData = json_decode($body, true);
                
                if ($flightData) {
                    $websocket->handleFlightUpdate($flightData);
                    
                    $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n" . 
                                json_encode(['status' => 'ok', 'received' => microtime(true)]);
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
