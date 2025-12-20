<?php
/**
 * Xpress Feeder Airline - Central WebSocket Server
 * Handles real-time notifications for ALL departments
 * Location: /home/xfeed306/public_html/websocket/websocket_server.php
 * 
 * Departments Supported:
 * - Flight Operations (dispatch, fleet timeline, flight tracking)
 * - Cargo Management (shipments, loading, manifests)
 * - Crew Management (assignments, check-ins, schedules)
 * - Admin (system-wide alerts, announcements)
 * 
 * Start Server: php websocket_server.php
 * Port: 8080 (configurable)
 */

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

// Load Composer autoloader
require __DIR__ . '/vendor/autoload.php';

/**
 * Xpress Feeder Central WebSocket Handler
 */
class XpressFeederWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $users;
    protected $subscriptions;
    protected $db;
    
    // Department constants
    const DEPT_FLIGHTOPS = 'flightops';
    const DEPT_CARGO = 'cargo';
    const DEPT_CREW = 'crew';
    const DEPT_DISPATCH = 'dispatch';
    const DEPT_ADMIN = 'admin';
    const DEPT_MAINTENANCE = 'maintenance';

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        $this->subscriptions = [
            'flights' => [],
            'aircraft' => [],
            'cargo' => [],
            'crew' => [],
            'departments' => []
        ];
        
        $this->initDatabase();
        $this->logMessage("Central WebSocket Server initialized for ALL departments");
    }

    /**
     * Initialize database connection
     */
    private function initDatabase() {
        try {
            // Load database configuration
            $configFile = dirname(__FILE__) . '/../config/database.php';
            
            if (file_exists($configFile)) {
                $config = require $configFile;
                $host = $config['host'] ?? 'localhost';
                $dbname = $config['database'] ?? 'xfeed306_flightops';
                $username = $config['username'] ?? 'xfeed306_user';
                $password = $config['password'] ?? '';
            } else {
                // Fallback to default values
                $host = 'localhost';
                $dbname = 'xfeed306_flightops';
                $username = 'xfeed306_user';
                $password = 'your_password'; // UPDATE THIS
            }
            
            $this->db = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            $this->logMessage("Database connection established");
        } catch (PDOException $e) {
            $this->logMessage("Database connection failed: " . $e->getMessage(), 'ERROR');
            $this->db = null;
        }
    }

    /**
     * Handle new connection
     */
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->subscriptions = [];
        
        $this->logMessage("New connection: {$conn->resourceId}");
        
        // Send welcome message
        $conn->send(json_encode([
            'type' => 'connection_established',
            'resource_id' => $conn->resourceId,
            'server' => 'Xpress Feeder Central WebSocket',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'active_connections' => count($this->clients)
        ]));
    }

    /**
     * Handle incoming messages
     */
    public function onMessage(ConnectionInterface $from, $msg) {
        $this->logMessage("Message from {$from->resourceId}: $msg");
        
        $data = json_decode($msg, true);
        
        if (!$data) {
            $this->sendError($from, 'Invalid JSON format');
            return;
        }

        $type = $data['type'] ?? '';

        // Route messages based on type
        switch ($type) {
            // Authentication
            case 'auth':
                $this->handleAuth($from, $data);
                break;

            // Subscription Management
            case 'subscribe_flight':
                $this->subscribeFlight($from, $data);
                break;
            case 'subscribe_aircraft':
                $this->subscribeAircraft($from, $data);
                break;
            case 'subscribe_cargo':
                $this->subscribeCargo($from, $data);
                break;
            case 'subscribe_department':
                $this->subscribeDepartment($from, $data);
                break;
            case 'unsubscribe':
                $this->unsubscribe($from, $data);
                break;

            // Flight Operations
            case 'flight_update':
            case 'flight_status_change':
            case 'flight_departure':
            case 'flight_arrival':
            case 'flight_delay':
                $this->handleFlightUpdate($data);
                break;

            // Aircraft Updates
            case 'aircraft_status':
            case 'aircraft_location':
            case 'aircraft_maintenance':
                $this->handleAircraftUpdate($data);
                break;

            // Cargo Operations
            case 'cargo_update':
            case 'cargo_loaded':
            case 'cargo_unloaded':
            case 'cargo_status_change':
                $this->handleCargoUpdate($data);
                break;

            // Crew Management
            case 'crew_assignment':
            case 'crew_checkin':
            case 'crew_checkout':
            case 'crew_update':
                $this->handleCrewUpdate($data);
                break;

            // Dispatch
            case 'dispatch_update':
            case 'dispatch_alert':
                $this->handleDispatchUpdate($data);
                break;

            // System-wide
            case 'system_alert':
            case 'broadcast_all':
            case 'emergency':
                $this->handleSystemWide($data);
                break;

            // Utility
            case 'ping':
                $from->send(json_encode([
                    'type' => 'pong',
                    'timestamp' => time(),
                    'server_time' => date('Y-m-d H:i:s')
                ]));
                break;

            case 'get_stats':
                $this->sendStats($from);
                break;

            default:
                $this->sendError($from, "Unknown message type: {$type}");
        }
    }

    /**
     * Handle user authentication
     */
    private function handleAuth(ConnectionInterface $conn, $data) {
        $userId = $data['user_id'] ?? null;
        $department = $data['department'] ?? null;
        $sessionToken = $data['session_token'] ?? null;
        $userName = $data['user_name'] ?? 'Unknown User';

        if (!$userId) {
            $this->sendError($conn, 'User ID required');
            return;
        }

        // Store user information on connection
        $conn->userId = $userId;
        $conn->department = $department;
        $conn->userName = $userName;
        $conn->authenticatedAt = time();

        // Add to users tracking
        if (!isset($this->users[$userId])) {
            $this->users[$userId] = [];
        }
        $this->users[$userId][] = $conn;

        // Subscribe to department by default
        if ($department) {
            $this->subscribeDepartment($conn, ['department' => $department]);
        }

        $this->logMessage("User {$userId} ({$userName}) authenticated for department: {$department}");

        $conn->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $userId,
            'department' => $department,
            'message' => 'Authentication successful',
            'server_time' => date('Y-m-d H:i:s'),
            'connection_id' => $conn->resourceId
        ]));

        // Send initial system status
        $this->sendStats($conn);
    }

    /**
     * Subscribe to flight updates
     */
    private function subscribeFlight(ConnectionInterface $conn, $data) {
        $flightId = $data['flight_id'] ?? null;
        
        if (!$flightId) {
            $this->sendError($conn, 'Flight ID required');
            return;
        }

        $subscriptionKey = 'flight_' . $flightId;
        $conn->subscriptions[$subscriptionKey] = true;
        
        if (!isset($this->subscriptions['flights'][$flightId])) {
            $this->subscriptions['flights'][$flightId] = [];
        }
        $this->subscriptions['flights'][$flightId][] = $conn;

        $conn->send(json_encode([
            'type' => 'subscription_success',
            'subscription' => 'flight',
            'flight_id' => $flightId,
            'message' => "Subscribed to flight {$flightId} updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to flight {$flightId}");
    }

    /**
     * Subscribe to aircraft updates
     */
    private function subscribeAircraft(ConnectionInterface $conn, $data) {
        $aircraftReg = $data['aircraft_registration'] ?? null;
        
        if (!$aircraftReg) {
            $this->sendError($conn, 'Aircraft registration required');
            return;
        }

        $subscriptionKey = 'aircraft_' . $aircraftReg;
        $conn->subscriptions[$subscriptionKey] = true;
        
        if (!isset($this->subscriptions['aircraft'][$aircraftReg])) {
            $this->subscriptions['aircraft'][$aircraftReg] = [];
        }
        $this->subscriptions['aircraft'][$aircraftReg][] = $conn;

        $conn->send(json_encode([
            'type' => 'subscription_success',
            'subscription' => 'aircraft',
            'aircraft_registration' => $aircraftReg,
            'message' => "Subscribed to aircraft {$aircraftReg} updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to aircraft {$aircraftReg}");
    }

    /**
     * Subscribe to cargo updates
     */
    private function subscribeCargo(ConnectionInterface $conn, $data) {
        $cargoId = $data['cargo_id'] ?? null;
        
        if (!$cargoId) {
            $this->sendError($conn, 'Cargo ID required');
            return;
        }

        $subscriptionKey = 'cargo_' . $cargoId;
        $conn->subscriptions[$subscriptionKey] = true;
        
        if (!isset($this->subscriptions['cargo'][$cargoId])) {
            $this->subscriptions['cargo'][$cargoId] = [];
        }
        $this->subscriptions['cargo'][$cargoId][] = $conn;

        $conn->send(json_encode([
            'type' => 'subscription_success',
            'subscription' => 'cargo',
            'cargo_id' => $cargoId,
            'message' => "Subscribed to cargo {$cargoId} updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to cargo {$cargoId}");
    }

    /**
     * Subscribe to department updates
     */
    private function subscribeDepartment(ConnectionInterface $conn, $data) {
        $department = $data['department'] ?? null;
        
        if (!$department) {
            $this->sendError($conn, 'Department required');
            return;
        }

        $subscriptionKey = 'dept_' . $department;
        $conn->subscriptions[$subscriptionKey] = true;
        
        if (!isset($this->subscriptions['departments'][$department])) {
            $this->subscriptions['departments'][$department] = [];
        }
        $this->subscriptions['departments'][$department][] = $conn;

        $conn->send(json_encode([
            'type' => 'subscription_success',
            'subscription' => 'department',
            'department' => $department,
            'message' => "Subscribed to {$department} department updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to department {$department}");
    }

    /**
     * Unsubscribe from updates
     */
    private function unsubscribe(ConnectionInterface $conn, $data) {
        $subscriptionKey = $data['subscription_key'] ?? null;
        
        if ($subscriptionKey && isset($conn->subscriptions[$subscriptionKey])) {
            unset($conn->subscriptions[$subscriptionKey]);
            
            $conn->send(json_encode([
                'type' => 'unsubscribe_success',
                'subscription_key' => $subscriptionKey
            ]));
        }
    }

    /**
     * Handle flight updates
     */
    private function handleFlightUpdate($data) {
        $flightId = $data['flight_id'] ?? null;
        
        if (!$flightId) return;

        $data['timestamp'] = date('Y-m-d H:i:s');
        $message = json_encode($data);

        // Send to flight subscribers
        if (isset($this->subscriptions['flights'][$flightId])) {
            foreach ($this->subscriptions['flights'][$flightId] as $conn) {
                $conn->send($message);
            }
        }

        // Send to flight ops department
        $this->sendToDepartment(self::DEPT_FLIGHTOPS, $data);
        $this->sendToDepartment(self::DEPT_DISPATCH, $data);

        $this->logMessage("Flight update broadcast for flight {$flightId}: {$data['type']}");
    }

    /**
     * Handle aircraft updates
     */
    private function handleAircraftUpdate($data) {
        $aircraftReg = $data['aircraft_registration'] ?? null;
        
        if (!$aircraftReg) return;

        $data['timestamp'] = date('Y-m-d H:i:s');
        $message = json_encode($data);

        // Send to aircraft subscribers
        if (isset($this->subscriptions['aircraft'][$aircraftReg])) {
            foreach ($this->subscriptions['aircraft'][$aircraftReg] as $conn) {
                $conn->send($message);
            }
        }

        // Send to relevant departments
        $this->sendToDepartment(self::DEPT_FLIGHTOPS, $data);
        $this->sendToDepartment(self::DEPT_MAINTENANCE, $data);

        $this->logMessage("Aircraft update broadcast for {$aircraftReg}: {$data['type']}");
    }

  
        /**
     * Handle cargo updates
     */
    private function handleCargoUpdate($data) {
        $cargoId = $data['cargo_id'] ?? null;
        
        if (!$cargoId) return;

        $data['timestamp'] = date('Y-m-d H:i:s');
        $message = json_encode($data);

        // Send to cargo subscribers
        if (isset($this->subscriptions['cargo'][$cargoId])) {
            foreach ($this->subscriptions['cargo'][$cargoId] as $conn) {
                $conn->send($message);
            }
        }

        // Send to relevant departments
        $this->sendToDepartment(self::DEPT_CARGO, $data);
        $this->sendToDepartment(self::DEPT_FLIGHTOPS, $data);

        $this->logMessage("Cargo update broadcast for cargo {$cargoId}: {$data['type']}");
    }

    /**
     * Handle crew updates
     */
    private function handleCrewUpdate($data) {
        $crewId = $data['crew_id'] ?? null;
        
        $data['timestamp'] = date('Y-m-d H:i:s');

        // Send to crew department
        $this->sendToDepartment(self::DEPT_CREW, $data);
        $this->sendToDepartment(self::DEPT_FLIGHTOPS, $data);

        $this->logMessage("Crew update broadcast: {$data['type']}");
    }

    /**
     * Handle dispatch updates
     */
    private function handleDispatchUpdate($data) {
        $data['timestamp'] = date('Y-m-d H:i:s');

        // Send to dispatch and flight ops
        $this->sendToDepartment(self::DEPT_DISPATCH, $data);
        $this->sendToDepartment(self::DEPT_FLIGHTOPS, $data);

        $this->logMessage("Dispatch update broadcast: {$data['type']}");
    }

    /**
     * Handle system-wide messages
     */
    private function handleSystemWide($data) {
        $data['timestamp'] = date('Y-m-d H:i:s');
        $data['priority'] = $data['priority'] ?? 'high';
        
        // Broadcast to ALL connected clients
        $this->broadcast($data);

        $this->logMessage("System-wide broadcast: {$data['type']}", 'ALERT');
    }

    /**
     * Send message to specific department
     */
    private function sendToDepartment($department, $data) {
        if (!isset($this->subscriptions['departments'][$department])) {
            return;
        }

        $message = json_encode($data);
        foreach ($this->subscriptions['departments'][$department] as $conn) {
            if ($conn->isConnected ?? true) {
                $conn->send($message);
            }
        }
    }

    /**
     * Send message to specific user (all their connections)
     */
    public function sendToUser($userId, $data) {
        if (!isset($this->users[$userId])) {
            return false;
        }

        $message = json_encode($data);
        foreach ($this->users[$userId] as $conn) {
            $conn->send($message);
        }

        return true;
    }

    /**
     * Broadcast to all connected clients
     */
    public function broadcast($data) {
        $message = json_encode($data);
        $count = 0;

        foreach ($this->clients as $client) {
            $client->send($message);
            $count++;
        }

        $this->logMessage("Broadcasted message to {$count} clients");
    }

    /**
     * Send server statistics
     */
    private function sendStats(ConnectionInterface $conn) {
        $stats = [
            'type' => 'server_stats',
            'total_connections' => count($this->clients),
            'authenticated_users' => count($this->users),
            'subscriptions' => [
                'flights' => count($this->subscriptions['flights']),
                'aircraft' => count($this->subscriptions['aircraft']),
                'cargo' => count($this->subscriptions['cargo']),
                'departments' => array_map(function($dept) {
                    return count($dept);
                }, $this->subscriptions['departments'])
            ],
            'server_uptime' => $this->getUptime(),
            'server_time' => date('Y-m-d H:i:s'),
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
        ];

        $conn->send(json_encode($stats));
    }

    /**
     * Send error message
     */
    private function sendError(ConnectionInterface $conn, $message) {
        $conn->send(json_encode([
            'type' => 'error',
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ]));
    }

    /**
     * Handle connection close
     */
    public function onClose(ConnectionInterface $conn) {
        // Remove from clients
        $this->clients->detach($conn);

        // Remove from users
        if (isset($conn->userId)) {
            $userId = $conn->userId;
            if (isset($this->users[$userId])) {
                $this->users[$userId] = array_filter(
                    $this->users[$userId],
                    function($c) use ($conn) {
                        return $c !== $conn;
                    }
                );
                
                // Remove user entry if no more connections
                if (empty($this->users[$userId])) {
                    unset($this->users[$userId]);
                }
            }
        }

        // Remove from all subscriptions
        foreach ($this->subscriptions as $type => &$subs) {
            foreach ($subs as $key => &$connections) {
                $connections = array_filter(
                    $connections,
                    function($c) use ($conn) {
                        return $c !== $conn;
                    }
                );
            }
        }

        $userName = $conn->userName ?? 'Unknown';
        $userId = $conn->userId ?? 'N/A';
        
        $this->logMessage("Connection {$conn->resourceId} closed (User: {$userName}, ID: {$userId})");
        $this->logMessage("Active connections: " . count($this->clients));
    }

    /**
     * Handle errors
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->logMessage("Error on connection {$conn->resourceId}: {$e->getMessage()}", 'ERROR');
        $conn->close();
    }

    /**
     * Log message with timestamp
     */
    private function logMessage($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
        
        echo $logEntry;
        
        // Also log to file
        $logFile = dirname(__FILE__) . '/logs/websocket.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Get server uptime
     */
    private function getUptime() {
        static $startTime = null;
        if ($startTime === null) {
            $startTime = time();
        }
        
        $uptime = time() - $startTime;
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;
        
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    /**
     * Cleanup stale connections (called periodically)
     */
    public function cleanupStaleConnections() {
        $timeout = 300; // 5 minutes
        $now = time();
        
        foreach ($this->clients as $client) {
            if (isset($client->authenticatedAt)) {
                $lastActivity = $client->authenticatedAt;
                if (($now - $lastActivity) > $timeout) {
                    $this->logMessage("Closing stale connection {$client->resourceId}");
                    $client->close();
                }
            }
        }
    }
}

/**
 * Start the WebSocket Server
 */
try {
    $port = 8080; // Change if needed
    $host = '0.0.0.0'; // Listen on all interfaces
    
    echo "\n";
    echo "========================================================\n";
    echo "    XPRESS FEEDER AIRLINE - CENTRAL WEBSOCKET SERVER    \n";
    echo "========================================================\n";
    echo "  Real-Time Notifications for All Departments\n";
    echo "========================================================\n";
    echo "\n";
    echo "Server Configuration:\n";
    echo "  - Host: {$host}\n";
    echo "  - Port: {$port}\n";
    echo "  - Started: " . date('Y-m-d H:i:s') . "\n";
    echo "\n";
    echo "Supported Departments:\n";
    echo "  ✓ Flight Operations\n";
    echo "  ✓ Cargo Management\n";
    echo "  ✓ Ground Operations\n";
    echo "  ✓ Crew Management\n";
    echo "  ✓ Dispatch\n";
    echo "  ✓ Maintenance\n";
    echo "  ✓ Administration\n";
    echo "\n";
    echo "========================================================\n";
    echo "Server is running... Press Ctrl+C to stop\n";
    echo "========================================================\n\n";

    $websocket = new XpressFeederWebSocket();
    
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($websocket)
        ),
        $port,
        $host
    );

    // Optional: Setup periodic cleanup (requires ReactPHP event loop)
    // $server->loop->addPeriodicTimer(60, function() use ($websocket) {
    //     $websocket->cleanupStaleConnections();
    // });

    $server->run();
    
} catch (Exception $e) {
    echo "\n[ERROR] Failed to start WebSocket server: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}


