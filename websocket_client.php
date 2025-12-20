            'message' => $message,
            'priority' => 'critical',
            'timestamp' => date('Y-m-d H:i:s')
        ], $details);

        return $this->send($notification);
    }

    /**
     * Dispatch alert notification
     */
    public function sendDispatchAlert($message, $flightId = null, $priority = 'medium') {
        $notification = [
            'type' => 'dispatch_alert',
            'message' => $message,
            'priority' => $priority,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($flightId) {
            $notification['flight_id'] = $flightId;
        }

        return $this->send($notification);
    }
}
