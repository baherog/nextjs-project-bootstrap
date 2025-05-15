<?php
require_once 'config.php';
require_once 'db.php';

class RealtimeMonitor {
    private $db;
    private $ami;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->connectAMI();
    }

    private function connectAMI() {
        try {
            $this->ami = fsockopen(AMI_HOST, AMI_PORT);
            if (!$this->ami) {
                throw new Exception("Could not connect to Asterisk Manager Interface");
            }

            // Login to AMI
            fputs($this->ami, "Action: Login\r\n");
            fputs($this->ami, "Username: " . AMI_USERNAME . "\r\n");
            fputs($this->ami, "Secret: " . AMI_SECRET . "\r\n\r\n");

            // Read response
            $response = '';
            while (($line = fgets($this->ami)) !== false) {
                $response .= $line;
                if (strpos($line, 'Response: Success') !== false) {
                    break;
                }
                if (strpos($line, 'Response: Error') !== false) {
                    throw new Exception("AMI Authentication failed");
                }
            }
        } catch (Exception $e) {
            error_log("AMI Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function getQueueStatus() {
        try {
            // Send QueueStatus command
            fputs($this->ami, "Action: QueueStatus\r\n\r\n");
            
            $response = '';
            $queues = [];
            $currentQueue = null;
            
            while (($line = fgets($this->ami)) !== false) {
                $response .= $line;
                
                if (strpos($line, 'Event: QueueParams') !== false) {
                    // New queue information
                    $currentQueue = [];
                } elseif (strpos($line, 'Queue: ') !== false) {
                    $currentQueue['name'] = trim(substr($line, 7));
                } elseif (strpos($line, 'Calls: ') !== false) {
                    $currentQueue['calls'] = (int)trim(substr($line, 7));
                } elseif (strpos($line, 'Completed: ') !== false) {
                    $currentQueue['completed'] = (int)trim(substr($line, 11));
                } elseif (strpos($line, 'Abandoned: ') !== false) {
                    $currentQueue['abandoned'] = (int)trim(substr($line, 11));
                } elseif (strpos($line, 'ServiceLevel: ') !== false) {
                    $currentQueue['service_level'] = (float)trim(substr($line, 14));
                } elseif (strpos($line, 'Event: QueueStatusComplete') !== false) {
                    break;
                } elseif (strpos($line, '--END COMMAND--') !== false) {
                    if ($currentQueue) {
                        $queues[] = $currentQueue;
                    }
                    break;
                }
            }

            return $queues;
        } catch (Exception $e) {
            error_log("Queue Status Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function getAgentStatus() {
        try {
            // Get current agent status from queue_log
            $sql = "
                WITH latest_events AS (
                    SELECT 
                        agent,
                        event,
                        time,
                        data1,
                        ROW_NUMBER() OVER (PARTITION BY agent ORDER BY time DESC) as rn
                    FROM queue_log
                    WHERE 
                        event IN ('ADDMEMBER', 'REMOVEMEMBER', 'PAUSE', 'UNPAUSE', 'CONNECT')
                        AND time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                )
                SELECT 
                    agent,
                    event as last_event,
                    time as last_event_time,
                    data1 as additional_data
                FROM latest_events
                WHERE rn = 1
                ORDER BY time DESC
            ";

            return $this->db->fetchAll($sql);
        } catch (Exception $e) {
            error_log("Agent Status Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function getCurrentCalls() {
        try {
            fputs($this->ami, "Action: CoreShowChannels\r\n\r\n");
            
            $response = '';
            $calls = [];
            
            while (($line = fgets($this->ami)) !== false) {
                $response .= $line;
                
                if (strpos($line, 'Event: CoreShowChannel') !== false) {
                    $call = [];
                } elseif (strpos($line, 'Channel: ') !== false) {
                    $call['channel'] = trim(substr($line, 9));
                } elseif (strpos($line, 'CallerIDNum: ') !== false) {
                    $call['caller_id'] = trim(substr($line, 13));
                } elseif (strpos($line, 'Duration: ') !== false) {
                    $call['duration'] = trim(substr($line, 10));
                } elseif (strpos($line, 'Application: ') !== false) {
                    $call['application'] = trim(substr($line, 13));
                    $calls[] = $call;
                } elseif (strpos($line, '--END COMMAND--') !== false) {
                    break;
                }
            }

            return $calls;
        } catch (Exception $e) {
            error_log("Current Calls Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function performAgentAction($action, $agent, $queue = null) {
        try {
            switch ($action) {
                case 'pause':
                    fputs($this->ami, "Action: QueuePause\r\n");
                    fputs($this->ami, "Interface: " . $agent . "\r\n");
                    if ($queue) {
                        fputs($this->ami, "Queue: " . $queue . "\r\n");
                    }
                    fputs($this->ami, "Paused: true\r\n\r\n");
                    break;

                case 'unpause':
                    fputs($this->ami, "Action: QueuePause\r\n");
                    fputs($this->ami, "Interface: " . $agent . "\r\n");
                    if ($queue) {
                        fputs($this->ami, "Queue: " . $queue . "\r\n");
                    }
                    fputs($this->ami, "Paused: false\r\n\r\n");
                    break;

                case 'remove':
                    fputs($this->ami, "Action: QueueRemove\r\n");
                    fputs($this->ami, "Interface: " . $agent . "\r\n");
                    if ($queue) {
                        fputs($this->ami, "Queue: " . $queue . "\r\n");
                    }
                    fputs($this->ami, "\r\n");
                    break;

                default:
                    throw new Exception("Invalid agent action");
            }

            // Read response
            $response = '';
            while (($line = fgets($this->ami)) !== false) {
                $response .= $line;
                if (strpos($line, 'Response: Success') !== false) {
                    return true;
                }
                if (strpos($line, 'Response: Error') !== false) {
                    throw new Exception("Action failed: " . $response);
                }
            }

            return false;
        } catch (Exception $e) {
            error_log("Agent Action Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function __destruct() {
        if ($this->ami) {
            fputs($this->ami, "Action: Logoff\r\n\r\n");
            fclose($this->ami);
        }
    }
}

// Handle API requests
try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $monitor = new RealtimeMonitor();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = [
            'queues' => $monitor->getQueueStatus(),
            'agents' => $monitor->getAgentStatus(),
            'calls' => $monitor->getCurrentCalls(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        sendResponse($data);
    } 
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['action']) || !isset($input['agent'])) {
            throw new Exception("Missing required parameters");
        }

        $result = $monitor->performAgentAction(
            $input['action'],
            $input['agent'],
            $input['queue'] ?? null
        );

        sendResponse([
            'success' => $result,
            'message' => 'Action performed successfully'
        ]);
    }
    else {
        throw new Exception("Invalid request method");
    }

} catch (Exception $e) {
    sendError($e->getMessage());
}
