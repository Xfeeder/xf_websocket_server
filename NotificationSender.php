<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XF WebSocket Test - Xpress Feeder Airline</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #0a0e27;
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-connected { background: #10b981; }
        .status-disconnected { background: #ef4444; }
        .status-connecting { background: #f59e0b; }
        
        .message-log {
            background: #1a1f3a;
            border: 1px solid #2d3748;
            border-radius: 8px;
            padding: 15px;
            height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .message-item {
            padding: 8px;
            margin-bottom: 5px;
            border-left: 3px solid #3b82f6;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 4px;
        }
        .message-timestamp {
            color: #9ca3af;
            font-size: 11px;
        }
        .message-type {
            color: #60a5fa;
            font-weight: 600;
        }
        .card {
            background: #1a1f3a;
            border: 1px solid #2d3748;
        }
        .btn-primary {
            background: #3b82f6;
            border: none;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-success {
            background: #10b981;
            border: none;
        }
        .btn-success:hover {
            background: #059669;
        }
        .btn-danger {
            background: #ef4444;
            border: none;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        h1, h5 {
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row mb-4">
            <div class="col-md-12">
                <h1>🛩️ Xpress Feeder Airline - WebSocket Test Console</h1>
                <p class="text-muted">Real-time notification system testing interface</p>
            </div>
        </div>

        <!-- Connection Status -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Connection Status</h5>
                            <small id="connectionInfo" class="text-muted">Not connected</small>
                        </div>
                        <div>
                            <span id="statusBadge" class="status-badge status-disconnected">DISCONNECTED</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card p-3">
                    <h5>Connection Control</h5>
                    <div class="mb-3">
                        <label class="form-label">User ID</label>
                        <input type="text" id="userId" class="form-control" value="1001" placeholder="Enter User ID">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select id="department" class="form-control">
                            <option value="flightops">Flight Operations</option>
                            <option value="cargo">Cargo Management</option>
                            <option value="crew">Crew Management</option>
                            <option value="dispatch">Dispatch</option>
                            <option value="admin">Administration</option>
                        </select>
                    </div>
                    <button id="connectBtn" class="btn btn-success w-100" onclick="connectWebSocket()">Connect</button>
                    <button id="disconnectBtn" class="btn btn-danger w-100 mt-2" onclick="disconnectWebSocket()" disabled>Disconnect</button>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card p-3">
                    <h5>Subscriptions</h5>
                    <div class="mb-3">
                        <label class="form-label">Flight ID</label>
                        <input type="text" id="flightId" class="form-control" placeholder="e.g., XF123">
                        <button class="btn btn-primary btn-sm mt-2 w-100" onclick="subscribeFlight()">Subscribe to Flight</button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Aircraft Registration</label>
                        <input type="text" id="aircraftReg" class="form-control" placeholder="e.g., N123XF">
                        <button class="btn btn-primary btn-sm mt-2 w-100" onclick="subscribeAircraft()">Subscribe to Aircraft</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card p-3">
                    <h5>Test Actions</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100 mb-2" onclick="sendPing()">Send Ping</button>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100 mb-2" onclick="getStats()">Get Stats</button>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100 mb-2" onclick="simulateFlightUpdate()">Simulate Flight Update</button>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-danger w-100 mb-2" onclick="clearLog()">Clear Log</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Message Log -->
        <div class="row">
            <div class="col-md-12">
                <div class="card p-3">
                    <h5>Message Log</h5>
                    <div id="messageLog" class="message-log"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="websocket-client.js"></script>
    <script>
        let ws = null;

        function connectWebSocket() {
            const userId = document.getElementById('userId').value;
            const department = document.getElementById('department').value;

            if (!userId) {
                alert('Please enter a User ID');
                return;
            }

            logMessage('Connecting...', 'system');
            updateStatus('connecting');

            ws = new XpressFeederWebSocket({
                host: window.location.hostname,
                port: 8080,
                autoReconnect: true
            });

            // Setup event handlers
            ws.on('connection_established', (data) => {
                logMessage('Connection established', 'success', data);
                updateStatus('connected');
                document.getElementById('connectBtn').disabled = true;
                document.getElementById('disconnectBtn').disabled = false;
            });

            ws.on('auth_success', (data) => {
                logMessage('Authenticated successfully', 'success', data);
                document.getElementById('connectionInfo').textContent = 
                    `Connected as User ${data.user_id} (${data.department})`;
            });

            ws.on('flight_update', (data) => {
                logMessage('Flight Update', 'flight', data);
                showNotification('Flight Update', data.message);
            });

            ws.on('flight_departure', (data) => {
                logMessage('Flight Departure', 'flight', data);
                showNotification('Flight Departure', data.message);
            });

            ws.on('flight_arrival', (data) => {
                logMessage('Flight Arrival', 'flight', data);
                showNotification('Flight Arrival', data.message);
            });

            ws.on('aircraft_status', (data) => {
                logMessage('Aircraft Status', 'aircraft',
            ws.on('aircraft_status', (data) => {
                logMessage('Aircraft Status', 'aircraft', data);
            });

            ws.on('cargo_update', (data) => {
                logMessage('Cargo Update', 'cargo', data);
            });

            ws.on('system_alert', (data) => {
                logMessage('System Alert', 'alert', data);
                showNotification('System Alert', data.message, 'warning');
            });

            ws.on('emergency', (data) => {
                logMessage('EMERGENCY', 'emergency', data);
                showNotification('EMERGENCY', data.message, 'error');
            });

            ws.on('server_stats', (data) => {
                logMessage('Server Statistics', 'stats', data);
            });

            ws.on('subscription_success', (data) => {
                logMessage('Subscription Success', 'success', data);
            });

            ws.on('error', (data) => {
                logMessage('Error', 'error', data);
            });

            ws.on('close', (data) => {
                logMessage('Connection closed', 'system');
                updateStatus('disconnected');
                document.getElementById('connectBtn').disabled = false;
                document.getElementById('disconnectBtn').disabled = true;
            });

            ws.on('reconnect', (data) => {
                logMessage(`Reconnecting... (attempt ${data.attempt})`, 'system');
            });

            // Connect
            ws.connect(userId, department, 'Test User ' + userId);
        }

        function disconnectWebSocket() {
            if (ws) {
                ws.disconnect();
                logMessage('Disconnected by user', 'system');
                updateStatus('disconnected');
                document.getElementById('connectBtn').disabled = false;
                document.getElementById('disconnectBtn').disabled = true;
            }
        }

        function subscribeFlight() {
            const flightId = document.getElementById('flightId').value;
            if (!flightId) {
                alert('Please enter a Flight ID');
                return;
            }
            if (ws && ws.isReady()) {
                ws.subscribeFlight(flightId);
                logMessage(`Subscribing to flight: ${flightId}`, 'system');
            } else {
                alert('Please connect first');
            }
        }

        function subscribeAircraft() {
            const aircraftReg = document.getElementById('aircraftReg').value;
            if (!aircraftReg) {
                alert('Please enter an Aircraft Registration');
                return;
            }
            if (ws && ws.isReady()) {
                ws.subscribeAircraft(aircraftReg);
                logMessage(`Subscribing to aircraft: ${aircraftReg}`, 'system');
            } else {
                alert('Please connect first');
            }
        }

        function sendPing() {
            if (ws && ws.isReady()) {
                ws.send({ type: 'ping' });
                logMessage('Ping sent', 'system');
            } else {
                alert('Please connect first');
            }
        }

        function getStats() {
            if (ws && ws.isReady()) {
                ws.getStats();
                logMessage('Requesting server stats...', 'system');
            } else {
                alert('Please connect first');
            }
        }

        function simulateFlightUpdate() {
            if (ws && ws.isReady()) {
                const flightId = document.getElementById('flightId').value || 'XF123';
                ws.send({
                    type: 'flight_update',
                    flight_id: flightId,
                    status: 'En Route',
                    message: `Flight ${flightId} is en route`,
                    altitude: 35000,
                    speed: 450,
                    position: { lat: 40.7128, lon: -74.0060 }
                });
                logMessage('Simulated flight update sent', 'system');
            } else {
                alert('Please connect first');
            }
        }

        function updateStatus(status) {
            const badge = document.getElementById('statusBadge');
            badge.className = 'status-badge';
            
            if (status === 'connected') {
                badge.classList.add('status-connected');
                badge.textContent = 'CONNECTED';
            } else if (status === 'connecting') {
                badge.classList.add('status-connecting');
                badge.textContent = 'CONNECTING...';
            } else {
                badge.classList.add('status-disconnected');
                badge.textContent = 'DISCONNECTED';
            }
        }

        function logMessage(message, type = 'info', data = null) {
            const log = document.getElementById('messageLog');
            const timestamp = new Date().toLocaleTimeString();
            
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message-item';
            
            let typeColor = '#3b82f6';
            if (type === 'success') typeColor = '#10b981';
            if (type === 'error' || type === 'emergency') typeColor = '#ef4444';
            if (type === 'alert') typeColor = '#f59e0b';
            if (type === 'flight') typeColor = '#8b5cf6';
            if (type === 'aircraft') typeColor = '#06b6d4';
            if (type === 'cargo') typeColor = '#14b8a6';
            
            messageDiv.style.borderLeftColor = typeColor;
            
            let html = `
                <div class="message-timestamp">${timestamp}</div>
                <div class="message-type" style="color: ${typeColor}">[${type.toUpperCase()}]</div>
                <div>${message}</div>
            `;
            
            if (data) {
                html += `<pre style="margin-top: 5px; font-size: 11px; color: #9ca3af;">${JSON.stringify(data, null, 2)}</pre>`;
            }
            
            messageDiv.innerHTML = html;
            log.appendChild(messageDiv);
            log.scrollTop = log.scrollHeight;
        }

        function clearLog() {
            document.getElementById('messageLog').innerHTML = '';
            logMessage('Log cleared', 'system');
        }

        function showNotification(title, message, type = 'info') {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(title, {
                    body: message,
                    icon: '/favicon.ico',
                    badge: '/favicon.ico'
                });
            } else if ('Notification' in window && Notification.permission !== 'denied') {
                Notification.requestPermission().then(permission => {
                    if (permission === 'granted') {
                        new Notification(title, {
                            body: message,
                            icon: '/favicon.ico'
                        });
                    }
                });
            }
        }

        // Request notification permission on load
        window.addEventListener('load', () => {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
            logMessage('WebSocket Test Console loaded', 'system');
            logMessage('Ready to connect...', 'system');
        });
    </script>
</body>
</html>
