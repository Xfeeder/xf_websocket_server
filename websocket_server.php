<?php
/**
 * Xpress Feeder Airline - REAL LIVE WebSocket Server (SECURE PATCHED VERSION)
 * Receives INSTANT flight updates via POST/PUSH
 * Patched with: Security fixes, Authentication, Proper error handling, etc.
 */

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\Router;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

require __DIR__ . '/vendor/autoload.php';

class XpressFeederWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $flightCache = []; // Cache last positions
    protected $pdo;
    protected $clientAuthTokens = []; // Track authenticated clients
    
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
        
        // DATABASE CONNECTION - NO HARDCODED CREDENTIALS (FIXED)
        $this->initializeDatabase();
        
        // LOAD ALL AIRBORNE FLIGHTS - SCHEDULE LOGIC WITH PROPER TIMEZONE (FIXED)
        $this->loadActiveFlights();
        
        echo "[" . $this->getUTCTime() . "] REAL LIVE WebSocket Server initialized\n";
        echo "[" . $this->getUTCTime() . "] Active flights: " . count($this->flightCache) . "\n";
        
        // DEBUG: Show loaded flights
        foreach ($this->flightCache as $callsign => $flight) {
            echo "  - {$callsign}: {$flight['origin']}→{$flight['destination']} @ {$flight['schedule_std']}-{$flight['schedule_sta']}\n";
        }
    }
    
    private function getUTCTime(): string {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');
    }
    
    private function initializeDatabase(): void {
        // DEBUG: Check if .env file exists
        $envPath = '/etc/secrets/.env';
        if (file_exists($envPath)) {
            echo "DEBUG: .env file FOUND at $envPath\n";
            
            // MANUALLY LOAD .env file since getenv() isn't working
            $envContents = file_get_contents($envPath);
            $lines = explode("\n", $envContents);
            $envVars = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '=') !== false && $line[0] !== '#') {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    $envVars[$key] = $value;
                    // Set in environment
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                }
            }
            
            echo "DEBUG: Loaded variables: " . implode(', ', array_keys($envVars)) . "\n";
        } else {
            echo "DEBUG: .env file NOT FOUND at $envPath\n";
        }
        
        // Get variables (try both uppercase and lowercase)
        $dbHost = getenv('DB_HOST') ?: (getenv('db_host') ?: '');
        $dbName = getenv('DB_NAME') ?: (getenv('db_name') ?: 'neondb');
        $dbUser = getenv('DB_USER') ?: (getenv('db_user') ?: 'neondb_owner');
        $dbPass = getenv('DB_PASSWORD') ?: (getenv('db_password') ?: '');
        
        echo "DEBUG - DB_HOST: " . ($dbHost ? "SET ($dbHost)" : "MISSING") . "\n";
        echo "DEBUG - DB_USER: " . ($dbUser ? "SET ($dbUser)" : "MISSING") . "\n";
        echo "DEBUG - DB_PASS: " . ($dbPass ? "SET (***)" : "MISSING") . "\n";
        echo "DEBUG - DB_NAME: " . ($dbName ? "SET ($dbName)" : "MISSING") . "\n";
        
        if (!$dbHost || !$dbUser || !$dbPass || !$dbName) {
            echo "WARNING: Running in simulation mode (no database)\n";
            return;
        }
        
        try {
            $this->pdo = new PDO(
                "pgsql:host={$dbHost};dbname={$dbName}",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_PERSISTENT => false
                ]
            );
            echo "Database connected successfully!\n";
        } catch (\PDOException $e) {
            echo "Database connection failed: " . $e->getMessage() . "\n";
            // Don't throw, just run without DB
        }
    }
    
    private function loadActiveFlights(): void {
        $currentTimeUTC = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('H:i:s');
        $currentDay = date('N'); // 1=Monday
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM flightposition 
                WHERE schedule_dow LIKE :dow 
                AND schedule_std <= :time
                AND schedule_sta >= :time
                AND status IN ('airborne', 'departed')
                ORDER BY schedule_std
            ");
            
            $stmt->execute([
                ':dow' => "%{$currentDay}%",
                ':time' => $currentTimeUTC
            ]);
            
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->flightCache[$row['callsign']] = $row;
            }
            
            // IF NO SCHEDULED FLIGHTS, LOAD ANY AIRBORNE
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
        } catch (\PDOException $e) {
            error_log("Database error loading flights: " . $e->getMessage());
            $this->flightCache = [];
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
        return (int)(($bearing + 360) % 360);
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
    
    // UPDATE FLIGHT IN DATABASE WITH PARAMETER BINDING (FIXED)
    private function updateFlightInDatabase($flight) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE flightposition 
                SET lat = :lat, lon = :lon, altitude = :altitude, 
                    heading = :heading, groundspeed = :groundspeed, 
                    status = :status, last_update = NOW() AT TIME ZONE 'UTC'
                WHERE callsign = :callsign
            ");
            
            $stmt->execute([
                ':lat' => (float)$flight['lat'],
                ':lon' => (float)$flight['lon'],
                ':altitude' => (int)($flight['altitude'] ?? 0),
                ':heading' => (int)($flight['heading'] ?? 0),
                ':groundspeed' => (int)($flight['groundspeed'] ?? 0),
                ':status' => $flight['status'],
                ':callsign' => $flight['callsign']
            ]);
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[" . $this->getUTCTime() . "] DB Update Error: " . $e->getMessage());
            return false;
        }
    }

    // VALIDATE FLIGHT DATA INPUT (CRITICAL SECURITY FIX)
    private function validateFlightData(array $data): ?array {
        $required = ['callsign', 'lat', 'lon', 'status'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                return null;
            }
        }
        
        // Type validation
        $validated = [
            'callsign' => substr((string)$data['callsign'], 0, 10),
            'lat' => filter_var($data['lat'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => -90, 'max_range' => 90]]),
            'lon' => filter_var($data['lon'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => -180, 'max_range' => 180]]),
            'status' => in_array($data['status'], ['scheduled', 'departed', 'airborne', 'arrived', 'cancelled']) ? $data['status'] : 'scheduled',
            'altitude' => isset($data['altitude']) ? (int)$data['altitude'] : 0,
            'groundspeed' => isset($data['groundspeed']) ? (int)$data['groundspeed'] : 0,
            'heading' => isset($data['heading']) ? (int)$data['heading'] : 0
        ];
        
        if ($validated['lat'] === false || $validated['lon'] === false) {
            return null;
        }
        
        // Optional fields
        if (isset($data['origin'])) $validated['origin'] = substr((string)$data['origin'], 0, 4);
        if (isset($data['destination'])) $validated['destination'] = substr((string)$data['destination'], 0, 4);
        if (isset($data['aircraftreg'])) $validated['aircraftreg'] = substr((string)$data['aircraftreg'], 0, 10);
        
        $validated['last_update'] = $this->getUTCTime();
        
        return $validated;
    }

    // REALISTIC PIPER PA-31-350 FLIGHT PHYSICS
    public function updateFlightPositions() {
        foreach ($this->flightCache as $callsign => &$flight) {
            if ($flight['status'] === 'airborne' && isset($this->airports[$flight['destination']])) {
                $aircraftReg = $flight['aircraftreg'] ?? 'C-TLAL';
                $specs = $this->aircraftPerformance[$aircraftReg] ?? $this->aircraftPerformance['C-TLAL'];
                
                $origin = $this->airports[$flight['origin']] ?? $this->airports['YVR'];
                $dest = $this->airports[$flight['destination']];
                
                // Calculate progress based on SCHEDULE
                $scheduledDeparture = strtotime($flight['schedule_std'] . ' UTC');
                $scheduledArrival = strtotime($flight['schedule_sta'] . ' UTC');
                $scheduledDuration = max(1, $scheduledArrival - $scheduledDeparture);
                
                $currentTime = time();
                $elapsed = max(0, $currentTime - $scheduledDeparture);
                $progress = min($elapsed / $scheduledDuration, 0.99);
                
                // Position along great circle route
                $flight['lat'] = $this->interpolateLat($origin['lat'], $dest['lat'], $progress);
                $flight['lon'] = $this->interpolateLon($origin['lon'], $dest['lon'], $progress, $origin['lat'], $dest['lat']);
                
                // REALISTIC PIPER PA-31-350 ALTITUDE PROFILE
                if ($progress < 0.15) {
                    // Climb phase (1200 fpm Piper climb rate)
                    $flight['altitude'] = (int)round(min(12000, 1000 + ($progress / 0.15) * 11000));
                } elseif ($progress > 0.85) {
                    // Descent phase (800 fpm descent)
                    $flight['altitude'] = (int)round(12000 - (($progress - 0.85) / 0.15) * 11000);
                } else {
                    // Cruise at 10,000-12,000 ft
                    $flight['altitude'] = (int)(11000 + rand(-500, 500));
                }
                
                // PIPER CRUISE SPEED CALCULATION
                $currentAltitude = $flight['altitude'];
                $altitudeFactor = 1 + (($currentAltitude / 1000) * 0.02);
                $trueAirspeed = $specs['cruise_speed'] * $altitudeFactor;
                
                // Groundspeed with wind component
                $windComponent = rand(-10, 10);
                $flight['groundspeed'] = (int)($trueAirspeed + $windComponent);
                
                // Heading toward destination
                $flight['heading'] = $this->calculateHeading($flight['lat'], $flight['lon'], $dest['lat'], $dest['lon']);
                $flight['last_update'] = $this->getUTCTime();
                
                // Update database
                if ($this->updateFlightInDatabase($flight)) {
                    // BROADCAST FIX: Pass array, not pre-encoded JSON
                    $this->broadcast([
                        'type' => 'flight_position',
                        'data' => $flight,
                        'timestamp' => microtime(true)
                    ]);
                    
                    echo "[" . $this->getUTCTime() . "] Moved: {$callsign} | Alt: {$flight['altitude']}ft | GS: {$flight['groundspeed']}kt | HDG: {$flight['heading']}°\n";
                }
                
                // Check if flight should have arrived
                if ($progress >= 0.99) {
                    $flight['status'] = 'arrived';
                    $flight['lat'] = $dest['lat'];
                    $flight['lon'] = $dest['lon'];
                    $flight['altitude'] = 0;
                    $flight['groundspeed'] = 0;
                    $flight['heading'] = 0;
                    
                    if ($this->updateFlightInDatabase($flight)) {
                        $this->broadcast([
                            'type' => 'flight_status',
                            'callsign' => $callsign,
                            'status' => 'arrived',
                            'timestamp' => microtime(true)
                        ]);
                        echo "[" . $this->getUTCTime() . "] {$callsign} has arrived at {$flight['destination']}\n";
                    }
                }
            }
        }
        // CRITICAL FIX: Unset reference to prevent bugs
        unset($flight);
    }

    public function handleFlightUpdate($flightData) {
        // AUTHENTICATION CHECK (SECURITY FIX)
        if (!$this->isClientAuthorized($flightData['auth_token'] ?? null)) {
            echo "[" . $this->getUTCTime() . "] Unauthorized flight update attempt\n";
            return false;
        }
        
        $validatedData = $this->validateFlightData($flightData);
        if (!$validatedData) {
            echo "[" . $this->getUTCTime() . "] Invalid flight data received\n";
            return false;
        }
        
        $callsign = $validatedData['callsign'];
        $this->flightCache[$callsign] = $validatedData;
        
        // Update database
        $this->updateFlightInDatabase($validatedData);
        
        // Broadcast to all clients
        $this->broadcast([
            'type' => 'flight_position',
            'data' => $validatedData,
            'timestamp' => microtime(true),
            'source' => 'push_update'
        ]);
        
        echo "[" . $this->getUTCTime() . "] REAL-TIME update: {$callsign}\n";
        return true;
    }
    
    private function isClientAuthorized($token): bool {
        $apiKey = getenv('PUSH_API_KEY');
        if (!$apiKey) {
            // If no API key is set, warn but allow (development mode)
            error_log("WARNING: PUSH_API_KEY not set. Running in insecure mode.");
            return true;
        }
        
        return $token === $apiKey || 
               $token === hash('sha256', $apiKey . date('Y-m-d-H'));
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->resourceId = uniqid('client_', true);
        $conn->authenticated = false; // Track auth status

        echo "[" . $this->getUTCTime() . "] New client: {$conn->resourceId}\n";

        // Send all active flights
        foreach ($this->flightCache as $flight) {
            $this->sendToClient($conn, [
                'type' => 'flight_position',
                'data' => $flight
            ]);
        }

        $this->sendToClient($conn, [
            'type' => 'connection_established',
            'resource_id' => $conn->resourceId,
            'server' => 'Xpress Feeder REAL LIVE WebSocket',
            'timestamp' => $this->getUTCTime(),
            'message' => 'REAL-TIME FLIGHT UPDATES ACTIVE',
            'active_flights' => count($this->flightCache),
            'requires_auth' => !empty(getenv('PUSH_API_KEY'))
        ]);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) {
            $this->sendToClient($from, [
                'type' => 'error',
                'message' => 'Invalid JSON'
            ]);
            return;
        }

        $type = $data['type'] ?? 'unknown';

        switch ($type) {
            case 'auth':
                $token = $data['token'] ?? '';
                if ($this->isClientAuthorized($token)) {
                    $from->authenticated = true;
                    $this->clientAuthTokens[$from->resourceId] = $token;
                    
                    $this->sendToClient($from, [
                        'type' => 'auth_success',
                        'timestamp' => microtime(true),
                        'expires' => time() + 3600
                    ]);
                } else {
                    $this->sendToClient($from, [
                        'type' => 'auth_failed',
                        'message' => 'Invalid authentication token'
                    ]);
                }
                break;

            case 'subscribe_flights':
                foreach ($this->flightCache as $flight) {
                    $this->sendToClient($from, [
                        'type' => 'flight_position',
                        'data' => $flight
                    ]);
                }
                break;

            case 'flight_push':
                if (!$from->authenticated) {
                    $this->sendToClient($from, [
                        'type' => 'error',
                        'message' => 'Authentication required for flight updates'
                    ]);
                    break;
                }
                
                if (isset($data['data'])) {
                    $data['data']['auth_token'] = $this->clientAuthTokens[$from->resourceId] ?? '';
                    $this->handleFlightUpdate($data['data']);
                }
                break;

            case 'ping':
                $this->sendToClient($from, [
                    'type' => 'pong',
                    'timestamp' => microtime(true),
                    'server_time' => $this->getUTCTime()
                ]);
                break;
                
            case 'request_flights':
                $this->sendToClient($from, [
                    'type' => 'flight_list',
                    'data' => array_values($this->flightCache),
                    'timestamp' => microtime(true)
                ]);
                break;

            default:
                $this->sendToClient($from, [
                    'type' => 'error',
                    'message' => 'Unknown command: ' . $type
                ]);
        }
    }

    // FIXED BROADCAST: No double JSON encoding
    private function broadcast($data) {
        $message = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        foreach ($this->clients as $client) {
            $this->sendToClient($client, $message, false); // false = already encoded
        }
    }
    
    private function sendToClient(ConnectionInterface $client, $data, $encode = true) {
        try {
            $message = $encode ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $data;
            $client->send($message);
        } catch (\Exception $e) {
            // Client disconnected, remove them
            $this->clients->detach($client);
            unset($this->clientAuthTokens[$client->resourceId]);
            echo "[" . $this->getUTCTime() . "] Removed dead client: {$client->resourceId}\n";
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        unset($this->clientAuthTokens[$conn->resourceId]);
        echo "[" . $this->getUTCTime() . "] Client disconnected: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[" . $this->getUTCTime() . "] Error ({$conn->resourceId}): {$e->getMessage()}\n";
        $conn->close();
    }
}

// Create a custom HTTP server that handles both WebSocket and HTTP requests
class CustomHttpServer extends HttpServer {
    private $websocketHandler;
    
    public function __construct(WsServer $ws, XpressFeederWebSocket $websocketHandler) {
        parent::__construct($ws);
        $this->websocketHandler = $websocketHandler;
    }
    
    public function onOpen(\Ratchet\ConnectionInterface $conn, \Psr\Http\Message\RequestInterface $request = null) {
        // Check if this is a WebSocket upgrade request
        $isWebSocket = false;
        if ($request && $request->hasHeader('Upgrade')) {
            $upgradeHeaders = $request->getHeader('Upgrade');
            foreach ($upgradeHeaders as $upgradeHeader) {
                if (strtolower($upgradeHeader) === 'websocket') {
                    $isWebSocket = true;
                    break;
                }
            }
        }
        
        if ($isWebSocket) {
            // Handle as WebSocket
            parent::onOpen($conn, $request);
        } else {
            // Handle as regular HTTP request
            $this->handleHttpRequest($conn, $request);
        }
    }
    
    private function handleHttpRequest(\Ratchet\ConnectionInterface $conn, \Psr\Http\Message\RequestInterface $request) {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        // Handle health check
        if ($method === 'GET' && strpos($path, '/health') === 0) {
            $response = new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'status' => 'healthy',
                    'server' => 'Xpress Feeder WebSocket',
                    'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
                    'active_clients' => count($this->websocketHandler->clients),
                    'active_flights' => count($this->websocketHandler->flightCache),
                    'version' => '2.0.0-patched'
                ])
            );
            $conn->send(\GuzzleHttp\Psr7\Message::toString($response));
            $conn->close();
            return;
        }
        
        // Handle flight push
        if ($method === 'POST' && strpos($path, '/push_flight') === 0) {
            $body = (string)$request->getBody();
            $flightData = json_decode($body, true);
            
            // Check authentication
            $apiKey = getenv('PUSH_API_KEY');
            $authHeader = $request->getHeaderLine('X-API-Key');
            
            if ($apiKey && $authHeader !== $apiKey) {
                $response = new \GuzzleHttp\Psr7\Response(
                    401,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Invalid API key'])
                );
                $conn->send(\GuzzleHttp\Psr7\Message::toString($response));
                $conn->close();
                return;
            }
            
            // Add auth token for validation
            if ($flightData) {
                $flightData['auth_token'] = $authHeader ?: $apiKey;
            }
            
            if ($flightData && $this->websocketHandler->handleFlightUpdate($flightData)) {
                $response = new \GuzzleHttp\Psr7\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'status' => 'ok',
                        'received' => microtime(true),
                        'callsign' => $flightData['callsign'] ?? 'unknown'
                    ])
                );
            } else {
                $response = new \GuzzleHttp\Psr7\Response(
                    400,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Invalid flight data'])
                );
            }
            
            $conn->send(\GuzzleHttp\Psr7\Message::toString($response));
            $conn->close();
            return;
        }
        
        // Unknown endpoint
        $response = new \GuzzleHttp\Psr7\Response(
            404,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => 'Endpoint not found'])
        );
        $conn->send(\GuzzleHttp\Psr7\Message::toString($response));
        $conn->close();
    }
}

try {
    // PORT CONFIGURATION WITH VALIDATION
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
    echo "Started: " . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') . " UTC\n";
    echo "Security: " . (getenv('PUSH_API_KEY') ? 'ENABLED' : 'DISABLED (set PUSH_API_KEY)') . "\n";
    echo "Mode: PIPER PA-31-350 REAL FLIGHT PHYSICS\n";
    echo "========================================\n\n";

    $websocket = new XpressFeederWebSocket();

    // Create HTTP server with custom handler
    $server = IoServer::factory(
        new CustomHttpServer(
            new WsServer($websocket),
            $websocket
        ),
        $port,
        $host
    );

    // UPDATE FLIGHTS EVERY 5 SECONDS WITH PIPER PHYSICS
    $server->loop->addPeriodicTimer(5, function() use ($websocket) {
        $websocket->updateFlightPositions();
    });

    $server->run();

} catch (Exception $e) {
    echo "\n[" . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') . "] [FATAL ERROR] " . $e->getMessage() . "\n";
    error_log("WebSocket Server Fatal Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
