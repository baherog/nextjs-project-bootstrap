<?php
require_once '../config.php';
require_once '../db.php';

class AgentReport {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAgentAvailability($startDate, $endDate, $agent = null) {
        try {
            $params = [$startDate, $endDate];
            $agentFilter = "";
            
            if ($agent) {
                $agentFilter = "AND agent = ?";
                $params[] = $agent;
            }

            $sql = "
                SELECT 
                    agent,
                    COUNT(DISTINCT DATE(time)) as total_days,
                    SUM(CASE WHEN event = 'ADDMEMBER' THEN 1 ELSE 0 END) as login_count,
                    SUM(CASE WHEN event = 'REMOVEMEMBER' THEN 1 ELSE 0 END) as logout_count,
                    SUM(CASE WHEN event = 'PAUSE' THEN 1 ELSE 0 END) as pause_count,
                    SUM(CASE WHEN event = 'UNPAUSE' THEN 1 ELSE 0 END) as unpause_count,
                    COUNT(DISTINCT queuename) as assigned_queues
                FROM queue_log
                WHERE 
                    event IN ('ADDMEMBER', 'REMOVEMEMBER', 'PAUSE', 'UNPAUSE')
                    AND time BETWEEN ? AND ?
                    $agentFilter
                GROUP BY agent
                ORDER BY total_days DESC
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting agent availability: " . $e->getMessage());
        }
    }

    public function getSessionDuration($startDate, $endDate, $agent = null) {
        try {
            $params = [$startDate, $endDate];
            $agentFilter = "";
            
            if ($agent) {
                $agentFilter = "AND agent = ?";
                $params[] = $agent;
            }

            $sql = "
                WITH session_times AS (
                    SELECT 
                        agent,
                        time as start_time,
                        LEAD(time) OVER (PARTITION BY agent ORDER BY time) as end_time,
                        event
                    FROM queue_log
                    WHERE 
                        event IN ('ADDMEMBER', 'REMOVEMEMBER')
                        AND time BETWEEN ? AND ?
                        $agentFilter
                )
                SELECT 
                    agent,
                    COUNT(*) as total_sessions,
                    AVG(TIMESTAMPDIFF(SECOND, start_time, end_time)) as avg_session_duration,
                    MAX(TIMESTAMPDIFF(SECOND, start_time, end_time)) as max_session_duration,
                    SUM(TIMESTAMPDIFF(SECOND, start_time, end_time)) as total_session_duration
                FROM session_times
                WHERE 
                    event = 'ADDMEMBER' 
                    AND end_time IS NOT NULL
                GROUP BY agent
                ORDER BY total_session_duration DESC
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting session duration: " . $e->getMessage());
        }
    }

    public function getPauseDetails($startDate, $endDate, $agent = null) {
        try {
            $params = [$startDate, $endDate];
            $agentFilter = "";
            
            if ($agent) {
                $agentFilter = "AND agent = ?";
                $params[] = $agent;
            }

            $sql = "
                WITH pause_times AS (
                    SELECT 
                        agent,
                        time as pause_time,
                        LEAD(time) OVER (PARTITION BY agent ORDER BY time) as unpause_time,
                        data1 as pause_reason,
                        event
                    FROM queue_log
                    WHERE 
                        event IN ('PAUSE', 'UNPAUSE')
                        AND time BETWEEN ? AND ?
                        $agentFilter
                )
                SELECT 
                    agent,
                    pause_reason,
                    COUNT(*) as pause_count,
                    AVG(TIMESTAMPDIFF(SECOND, pause_time, unpause_time)) as avg_pause_duration,
                    SUM(TIMESTAMPDIFF(SECOND, pause_time, unpause_time)) as total_pause_duration
                FROM pause_times
                WHERE 
                    event = 'PAUSE' 
                    AND unpause_time IS NOT NULL
                GROUP BY agent, pause_reason
                ORDER BY agent, total_pause_duration DESC
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting pause details: " . $e->getMessage());
        }
    }

    public function getCallDisposition($startDate, $endDate, $agent = null) {
        try {
            $params = [$startDate, $endDate];
            $agentFilter = "";
            
            if ($agent) {
                $agentFilter = "AND agent = ?";
                $params[] = $agent;
            }

            $sql = "
                SELECT 
                    agent,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN event = 'CONNECT' THEN 1 ELSE 0 END) as answered_calls,
                    SUM(CASE WHEN event = 'TRANSFER' THEN 1 ELSE 0 END) as transferred_calls,
                    AVG(CASE WHEN event = 'CONNECT' THEN duration ELSE NULL END) as avg_talk_time,
                    MAX(CASE WHEN event = 'CONNECT' THEN duration ELSE NULL END) as max_talk_time,
                    COUNT(DISTINCT queuename) as served_queues
                FROM queue_log
                WHERE 
                    event IN ('CONNECT', 'TRANSFER')
                    AND time BETWEEN ? AND ?
                    $agentFilter
                GROUP BY agent
                ORDER BY total_calls DESC
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting call disposition: " . $e->getMessage());
        }
    }

    public function getDetailedReport($startDate, $endDate, $agent = null) {
        try {
            $params = [$startDate, $endDate];
            $agentFilter = "";
            
            if ($agent) {
                $agentFilter = "AND agent = ?";
                $params[] = $agent;
            }

            $sql = "
                SELECT 
                    time,
                    agent,
                    queuename,
                    event,
                    callid,
                    data1,
                    data2,
                    wait,
                    duration
                FROM queue_log
                WHERE 
                    event IN ('ADDMEMBER', 'REMOVEMEMBER', 'PAUSE', 'UNPAUSE', 'CONNECT', 'TRANSFER')
                    AND time BETWEEN ? AND ?
                    $agentFilter
                ORDER BY time DESC
                LIMIT " . MAX_RECORDS;

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting detailed agent report: " . $e->getMessage());
        }
    }
}

// Handle API requests
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method");
    }

    $report = new AgentReport();
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $reportType = $_GET['type'] ?? 'availability';
    $agent = $_GET['agent'] ?? null;

    $result = [];
    switch ($reportType) {
        case 'availability':
            $result = $report->getAgentAvailability($startDate, $endDate, $agent);
            break;
        case 'session_duration':
            $result = $report->getSessionDuration($startDate, $endDate, $agent);
            break;
        case 'pause_details':
            $result = $report->getPauseDetails($startDate, $endDate, $agent);
            break;
        case 'call_disposition':
            $result = $report->getCallDisposition($startDate, $endDate, $agent);
            break;
        case 'detailed':
            $result = $report->getDetailedReport($startDate, $endDate, $agent);
            break;
        default:
            throw new Exception("Invalid report type");
    }

    sendResponse([
        'success' => true,
        'data' => $result,
        'params' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'type' => $reportType,
            'agent' => $agent
        ]
    ]);

} catch (Exception $e) {
    sendError($e->getMessage());
}
