<?php

namespace Xfeeder;

use WebSocket\Client;

class NotificationSender
{
    private string $wsUrl;

    /**
     * @param string $wsUrl WebSocket endpoint, e.g.:
     *   - "ws://localhost:8080" for local testing
     *   - "wss://your-render-service.onrender.com" on Render
     */
    public function __construct(string $wsUrl)
    {
        $this->wsUrl = $wsUrl;
    }

    private function send(array $payload): bool
    {
        try {
            $client = new Client($this->wsUrl);
            $client->send(json_encode($payload));
            $client->close();
            return true;
        } catch (\Throwable $e) {
            error_log('NotificationSender WebSocket error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send aircraft status to all subscribers (all dashboards/pages).
     */
    public function sendAircraftStatus(
        string $aircraftRegistration,
        string $status,
        ?string $location = null,
        ?string $notes = null
    ): bool {
        $payload = [
            'type'                  => 'aircraft_status',
            'aircraft_registration' => $aircraftRegistration,
            'status'                => $status,
            'location'              => $location,
            'notes'                 => $notes,
        ];

        return $this->send($payload);
    }

    /**
     * Optional: dispatch alert.
     */
    public function sendDispatchAlert(
        string $message,
        ?string $flightId = null,
        string $priority = 'medium'
    ): bool {
        $payload = [
            'type'      => 'dispatch_alert',
            'message'   => $message,
            'priority'  => $priority,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if ($flightId !== null) {
            $payload['flight_id'] = $flightId;
        }

        return $this->send($payload);
    }
}
