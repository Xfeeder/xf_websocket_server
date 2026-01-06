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
    
    // Airport coordinates with NAMES
    protected $airports = [
        'YYJ' => ['lat' => 48.646, 'lon' => -123.426, 'name' => 'Victoria International Airport'],
        'YVR' => ['lat' => 49.194, 'lon' => -123.183, 'name' => 'Vancouver International Airport'],
        'YCD' => ['lat' => 49.052, 'lon' => -123.870, 'name' => 'Nanaimo Airport'],
        'YQQ' => ['lat' => 49.711, 'lon' => -124.887, 'name' => 'Comox Valley Airport'],
        'YBL' => ['lat' => 49.951, 'lon' => -125.271, 'name' => 'Campbell River Airport'],
        'YPW' => ['lat' => 49.834, 'lon' => -124.500, 'name' => 'Powell River Airport'],
        'YPR' => ['lat' => 54.286, 'lon' => -130.445, 'name' => 'Prince Rupert Airport'],
        'YZP' => ['lat' => 53.254, 'lon' => -131.814, 'name' => 'Sandspit Airport'],
        'ZMT' => ['lat' => 54.027, 'lon' => -132.125, 'name' => 'Masset Airport'],
        'YZT' => ['lat' => 50.681, 'lon' => -127.367, 'name' => 'Port Hardy Airport'],
        'ZEL' => ['lat' => 52.185, 'lon' => -128.157, 'name' => 'Bella Bella Airport'],
        'QBC' => ['lat' => 52.387, 'lon' => -126.596, 'name' => 'Bella Coola Airport'],
        'YAZ' => ['lat' => 49.082, 'lon' => -125.772, 'name' => 'Tofino Airport'],
        'YHS' => ['lat' => 49.460, 'lon' => -123.719, 'name' => 'Sechelt Airport']
    ];
    
    // Piper PA-31-350 Chieftain Freighter Specifications
    protected $aircraftPerformance = [
        'C-TLAL' => [
            'type' => 'Piper PA-31-350 Chieftain Freighter',
            'cruise_speed' => 195, // KTAS at 65% power, 10,000 ft
            'max_speed' => 212, // KTAS
            'stall_speed' => 68, // KIAS flaps down
            'climb_rate' => 1200, // fpm at sea level
            'service_ceiling' => 26000, // ft
            'range' => 830, // nm with reserves
            'fuel_flow' => 32, // gph per engine at 65% power
            'engine' => 'Lycoming TIO-540-J2B (350 hp each)',
            'mtow' => 7000, // lbs
            'usable_fuel' => 204 // US gallons
        ],
        'C-GILA' => [
            'type' => 'Piper PA-31-350 Chieftain Freighter',
            'cruise_speed' => 198, // Slightly different performance
            'max_speed' => 212,
            'stall_speed' => 68,
            'climb_rate' => 1250,
            'service_ceiling' => 26000,
            'range' => 840,
            'fuel_flow' => 31.5,
            'engine' => 'Lycoming TIO-540-J2B (350 hp each)',
            'mtow' => 7000,
            'usable_fuel' => 204
        ]
    ];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        
        // DATABASE CONNECTION WITH ENVIRONMENT VARIABLES - FIXED
        $dbHost = getenv('DB_HOST') ?: 'ep-orange-lab-af9er9mv-pooler.c-2.us-west-2.aws.neon.tech';
        $dbName = getenv('DB_NAME') ?: 'neondb';
        $dbUser = getenv('DB_USER') ?: 'neondb_owner';
        $dbPass = getenv('DB_PASSWORD') ?: 'npg_QXNRj7PTlk1A';

        $this->pdo = new PDO(
            "pgsql:host={$dbHost};dbname={$dbName}",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // LOAD ALL AIRBORNE FLIGHTS - SCHEDULE LOGIC FIXED
        $currentTime = date('H:i:s');
        $currentDay = date('N'); // 1=Monday
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM flightposition 
            WHERE schedule_dow LIKE :dow 
            AND schedule_std <= :time  -- FIXED: Changed FROM BETWEEN
            AND schedule_sta >= :time  -- FIXED: Changed TO <= and >=
            AND status IN ('airborne', 'departed')
            ORDER BY schedule_std
        ");
        
        $stmt->execute([
            ':dow' => "%{$currentDay}%",
            ':time' => $currentTime
        ]);
        
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->flightCache[$row['callsign']] = $row;
        }
        
        // IF NO FLIGHTS FOUND, LOAD SOME FOR TESTING
        if (empty($this->flightCache)) {
            $fallbackStmt = $this->pdo->query("
                SELECT * FROM flightposition 
                WHERE status = 'airborne' 
                LIMIT 5
            ");
            while($row = $fallbackStmt->fetch(PDO::FETCH_ASSOC)) {
                $this->flightCache[$row['callsign']] = $row;
            }
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] REAL LIVE WebSocket Server initialized\n";
        echo "[" . date('Y-m-d H:i:s') . "] Active flights: " . count($this->flightCache) . "\n";
        
        // DEBUG: Show loaded flights
        foreach ($this->flightCache as $callsign => $flight) {
            echo "  - {$callsign}: {$flight['origin']}→{$flight['destination']} @ {$flight['schedule_std']}-{$flight['schedule_sta']}\n";
        }
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
    
    // CALCULATE DISTANCE BETWEEN TWO POINTS (km)
    private function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $R = 6371; // Earth radius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + 
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
             sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }
    
    // GREAT CIRCLE INTERPOLATION
    private function interpolateLat($lat1, $lat2, $f) {
        return $lat1 + ($lat2 - $lat1) * $f;
    }
    
    private function interpolateLon($lon1, $lon2, $f, $lat1, $lat2) {
        $avgLat = ($lat1 + $lat2) / 2;
        $lonFactor = cos(deg2rad($avgLat));
        return $lon1 + ($lon2 - $lon1) * $f * $lonFactor;
    }
    
    // UPDATE FLIGHT IN DATABASE
    private function updateFlightInDatabase($flight) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE flightposition 
                SET lat = :lat, lon = :lon, altitude = :altitude, 
                    heading = :heading, groundspeed = :groundspeed, 
                    status = :status, last_update = NOW()
                WHERE callsign = :callsign
            ");
            
            $stmt->execute([
                ':lat' => (float)$flight['lat'],
                ':lon' => (float)$flight['lon'],
                ':altitude' => (int)$flight['altitude'],
                ':heading' => (int)$flight['heading'],
                ':groundspeed' => (int)$flight['groundspeed'],
                ':status' => $flight['status'],
                ':callsign' => $flight['callsign']
            ]);
            
            return true;
        } catch (Exception $e) {
            echo "[" . date('H:i:s') . "] DB Error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    // REALISTIC PIPER PA-31-350 FLIGHT PHYSICS
    public function updateFlightPositions() {
        foreach ($this->flightCache as $callsign => &$flight) {
            if ($flight['status'] === 'airborne' && isset($this->airports[$flight['destination']])) {
                $aircraftReg = $flight['aircraftreg'];
                $specs = $this->aircraftPerformance[$aircraftReg] ?? $this->aircraftPerformance['C-TLAL'];
                
                $origin = $this->airports[$flight['origin']];
                $dest = $this->airports[$flight['destination']];
                
                // Calculate progress based on SCHEDULE
                $scheduledDeparture = strtotime($flight['schedule_std']);
                $scheduledArrival = strtotime($flight['schedule_sta']);
                $scheduledDuration = max(1, $scheduledArrival - $scheduledDeparture);
                
                $currentTime = time();
                $elapsed = max(0, $currentTime - $scheduledDeparture);
                $progress = min($elapsed / $scheduledDuration, 0.99);
                
                // Position along great circle route
                $flight['lat'] = $this->interpolateLat($origin['lat'], $dest['lat'], $progress);
                $flight['lon'] = $this->interpolateLon($origin['lon'], $dest['lon'], $progress, $origin['lat'], $dest['lat']);
                
                // REALISTIC PIPER PA-31-350 ALTITUDE PROFILE (CAST TO INT)
                if ($progress < 0.15) {
                    // Climb phase (1200 fpm Piper climb rate)
                    $flight['altitude'] = (int)round(min(12000, 1000 + ($progress / 0.15) * 11000));
                } elseif ($progress > 0.85) {
                    // Descent phase (800 fpm descent)
                    $flight['altitude'] = (int)round(12000 - (($progress - 0.85) / 0.15) * 11000);
                } else {
                    // Cruise at 10,000-12,000 ft (optimal for PA-31-350)
                    $flight['altitude'] = (int)(11000 + rand(-500, 500));
                }
                
                // PIPER CRUISE SPEED CALCULATION
                // True airspeed increases ~2% per 1,000 ft altitude
                $currentAltitude = $flight['altitude'];
                $altitudeFactor = 1 + (($currentAltitude / 1000) * 0.02);
                $trueAirspeed = $specs['cruise_speed'] * $altitudeFactor;
                
                // Groundspeed with wind component (CAST TO INT)
                $windComponent = rand(-10, 10);
                $flight['groundspeed'] = (int)($trueAirspeed + $windComponent);
                
                // Heading toward destination (CAST TO INT)
                $flight['heading'] = (int)$this->calculateHeading($flight['lat'], $flight['lon'], $dest['lat'], $dest['lon']);
                $flight['last_update'] = date('Y-m-d H:i:s');
                
                // Update database
                $this->updateFlightInDatabase($flight);
                
                // Broadcast to all clients
                $this->broadcast(json_encode([
                    'type' => 'flight_position',
                    'data' => $flight
                ]));
                
                echo "[" . date('H:i:s') . "] Moved: {$callsign} | Alt: {$flight['altitude']}ft | GS: {$flight['groundspeed']}kt | HDG: {$flight['heading']}°\n";
                
                // Check if flight should have arrived
                if ($progress >= 0.99) {
                    $flight['status'] = 'arrived';
                    $flight['lat'] = $dest['lat'];
                    $flight['lon'] = $dest['lon'];
                    $flight['altitude'] = 0;
                    $flight['groundspeed'] = 0;
                    $flight['heading'] = 0;
                    $this->updateFlightInDatabase($flight);
                    echo "[" . date('H:i:s') . "] {$callsign} has arrived at {$flight['destination']}\n";
                }
            }
        }
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

try {
    // PORT CONFIGURATION WITH VALIDATION - FIXED
    $port = (int)(getenv('PORT') ?: 10000);
    if ($port < 1 || $port > 65535) {
        $port = 10000;
    }
    $host = '0.0.0.0';

    echo "\n========================================\n";
    echo "XPRESS FEEDER REAL LIVE WEBSOCKET SERVER\n";
    echo "========================================\n";
    echo "Host: {$host}\n";
    echo "Port: {$port}\n";
    echo "Database: PostgreSQL/NeonDB\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo "Mode: PIPER PA-31-350 REAL FLIGHT PHYSICS\n";
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

    // UPDATE FLIGHTS EVERY 5 SECONDS WITH PIPER PHYSICS
    $server->loop->addPeriodicTimer(5, function() use ($websocket) {
        $websocket->updateFlightPositions();
    });

    // HTTP POST HANDLER WITH HEALTH CHECK - FIXED
    $server->socket->on('connection', function($socket) use ($websocket) {
        $socket->on('data', function($data) use ($websocket, $socket) {
            // HEALTH CHECK for Render - FIXED
            if (strpos($data, 'GET /health') !== false) {
                $response = "HTTP/1.1 200 OK\r\n";
                $response .= "Content-Type: application/json\r\n\r\n";
                $response .= json_encode([
                    'status' => 'healthy',
                    'server' => 'Xpress Feeder WebSocket',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'active_clients' => count($websocket->clients),
                    'active_flights' => count($websocket->flightCache)
                ]);
                $socket->write($response);
                $socket->end();
                return;
            }
            
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
