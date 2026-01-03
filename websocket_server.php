<?php
/**
 * Xpress Feeder Airline - Central WebSocket Server (Render)
 * Monitors live MySQL database for flight and maintenance changes
 * Broadcasts updates to all dashboards in real-time
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
    const DEPT_CARGO       = 'cargo';
    const DEPT_CREW        = 'crew';
    const DEPT_DISPATCH    = 'dispatch';
    const DEPT_ADMIN       = 'admin';
    const DEPT_MAINTENANCE = 'maintenance';

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users   = [];
        $this->subscriptions = [
            'flights'     => [],
            'aircraft'    => [],
            'cargo'       => [],
            'crew'        => [],
            'departments' => []
        ];

        $this->initDatabase();
        $this->logMessage("Central WebSocket Server (Render) initialized with live database monitoring");
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
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            $this->logMessage("✓ Database connected: {$host}/{$name}");
            
            // Initialize last checked timestamps
            $this->lastChecked['flights'] = date('Y-m-d H:i:s', strtotime('-10 seconds'));
            $this->lastChecked['maintenance'] = date('Y-m-d H:i:s', strtotime('-10 seconds'));
            
        } catch (PDOException $e) {
            $this->logMessage("✗ Database connection failed: " . $e->getMessage(), 'ERROR');
            $this->db = null;
        }
    }

    /**
     * Monitor database for changes every second
     */
    public function monitorDatabase() {
        if (!$this->db) return;

        try {
            // Monitor flight_schedule table
            $stmt = $this->db->prepare("
                SELECT * FROM flight_schedule
                WHERE updated_at > :last_checked
                ORDER BY updated_at DESC
                LIMIT 50
            ");
            $stmt->execute(['last_checked' => $this->lastChecked['flights']]);
            
            while ($row = $stmt->fetch()) {
                $this->broadcastFlightUpdate($row);
            }
            
            // Update last checked time for flights
            $this->lastChecked['flights'] = date('Y-m-d H:i:s');

            // Monitor aircraft_mro table
            $stmt = $this->db->prepare("
                SELECT * FROM aircraft_mro
                WHERE updated_at > :last_checked
                ORDER BY updated_at DESC
                LIMIT 50
            ");
            $stmt->execute(['last_checked' => $this->lastChecked['maintenance']]);
            
            while ($row = $stmt->fetch()) {
                $this->broadcastMaintenanceUpdate($row);
            }
            
            // Update last checked time for maintenance
            $this->lastChecked['maintenance'] = date('Y-m-d H:i:s');
            
        } catch (PDOException $e) {
            $this->logMessage("Database monitoring error: " . $e->getMessage(), 'ERROR');
        }
    }

    private function broadcastFlightUpdate($flightData) {
        $message = [
            'type' => 'flight_status_change',
            'flight_id' => $flightData['flight_id'] ?? $flightData['id'],
            'flight_number' => $flightData['flight_number'] ?? null,
            'status' => $flightData['status'] ?? null,
            'aircraft_registration' => $flightData['aircraft_registration'] ?? null,
            'departure_time' => $flightData['departure_time'] ?? null,
            'arrival_time' => $flightData['arrival_time'] ?? null,
            'origin' => $flightData['origin'] ?? null,
            'destination' => $flightData['destination'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $flightData
        ];

        $this->handleFlightUpdate($message);
        $this->logMessage("Flight update broadcast: " . ($message['flight_number'] ?? $message['flight_id']));
    }

    private function broadcastMaintenanceUpdate($mroData) {
        $message = [
            'type' => 'aircraft_maintenance',
            'aircraft_registration' => $mroData['aircraft_registration'] ?? $mroData['registration'],
            'status' => $mroData['status'] ?? 'In Maintenance',
            'maintenance_type' => $mroData['maintenance_type'] ?? null,
            'start_time' => $mroData['start_time'] ?? null,
            'estimated_completion' => $mroData['estimated_completion'] ?? null,
            'notes' => $mroData['notes'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $mroData
        ];

        $this->handleAircraftUpdate($message);
        $this->logMessage("Maintenance update broadcast: " . $message['aircraft_registration']);
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->subscriptions = [];

        $this->logMessage("New connection: {$conn->resourceId}");

        $conn->send(json_encode([
            'type'               => 'connection_established',
            'resource_id'        => $conn->resourceId,
            'server'             => 'Xpress Feeder Central WebSocket (Render)',
            'version'            => '1.0',
            'timestamp'          => date('Y-m-d H:i:s'),
            'active_connections' => count($this->clients),
            'database_status'    => $this->db ? 'connected' : 'disconnected',
            'monitoring_interval'=> '1 second'
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $this->logMessage("Message from {$from->resourceId}: $msg");

        $data = json_decode($msg, true);

        if (!$data) {
            $this->sendError($from, 'Invalid JSON format');
            return;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'auth':
            case 'connect':
                $this->handleAuth($from, $data);
                break;

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

            case 'flight_update':
            case 'flight_status_change':
            case 'flight_departure':
            case 'flight_arrival':
            case 'flight_delay':
                $this->handleFlightUpdate($data);
                break;

            case 'aircraft_status':
            case 'aircraft_location':
            case 'aircraft_maintenance':
                $this->handleAircraftUpdate($data);
                break;

            case 'cargo_update':
                $this->handleCargoUpdate($data);
                break;

            case 'ping':
                $from->send(json_encode([
                    'type'        => 'pong',
                    'timestamp'   => time(),
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

    private function handleAuth(ConnectionInterface $conn, $data) {
        $userId      = $data['user_id'] ?? null;
        $department  = $data['department'] ?? null;
        $userName    = $data['user_name'] ?? $data['username'] ?? 'Unknown User';

        if (!$userId) {
            $this->sendError($conn, 'User ID required');
            return;
        }

        $conn->userId          = $userId;
        $conn->department      = $department;
        $conn->userName        = $userName;
        $conn->authenticatedAt = time();

        if (!isset($this->users[$userId])) {
            $this->users[$userId] = [];
        }
        $this->users[$userId][] = $conn;

        if ($department) {
            $this->subscribeDepartment($conn, ['department' => $department]);
        }

        $this->logMessage("User {$userId} ({$userName}) authenticated for department: {$department}");

        $conn->send(json_encode([
            'type'          => 'auth_success',
            'user_id'       => $userId,
            'department'    => $department,
            'message'       => 'Authentication successful',
            'server_time'   => date('Y-m-d H:i:s'),
            'connection_id' => $conn->resourceId
        ]));

        $this->sendStats($conn);
    }

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
            'type'        => 'subscription_success',
            'subscription'=> 'flight',
            'flight_id'   => $flightId,
            'message'     => "Subscribed to flight {$flightId} updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to flight {$flightId}");
    }

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
            'type'                 => 'subscription_success',
            'subscription'         => 'aircraft',
            'aircraft_registration'=> $aircraftReg,
            'message'              => "Subscribed to aircraft {$aircraftReg} updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to aircraft {$aircraftReg}");
    }

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
            'type'      => 'subscription_success',
            'subscription' => 'cargo',
            'cargo_id'  => $cargoId,
            'message'   => "Subscribed to cargo {$cargoId} updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to cargo {$cargoId}");
    }

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
            'type'       => 'subscription_success',
            'subscription'=> 'department',
            'department' => $department,
            'message'    => "Subscribed to {$department} department updates"
        ]));

        $this->logMessage("Connection {$conn->resourceId} subscribed to department {$department}");
    }

    private function unsubscribe(ConnectionInterface $conn, $data) {
        $subscriptionKey = $data['subscription_key'] ?? null;

        if ($subscriptionKey && isset($conn->subscriptions[$subscriptionKey])) {
            unset($conn->subscriptions[$subscriptionKey]);

            $conn->send(json_encode([
                'type'            => 'unsubscribe_success',
                'subscription_key'=> $subscriptionKey
            ]));
