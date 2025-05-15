<?php
require_once '../config.php';
require_once '../db.php';

class AnsweredCallsReport {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getServiceLevel($startDate, $endDate, $queue = null) {
        try {
            $params = [$startDate, $endDate];
            $queueFilter = "";
            
            if ($queue) {
                $queueFilter = "AND queuename = ?";
                $params[] = $queue;
            }

            $sql = "
                SELECT 
                    queuename,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN wait <= ? THEN 1 ELSE 0 END) as within_sla,
                    AVG(wait) as avg_wait_time,
                    MAX(wait) as max_wait_time
                FROM queue_log
                WHERE 
                    event = 'CONNECT' 
                    AND time BETWEEN ? AND ?
                    $queueFilter
                GROUP BY queuename
            ";
            array_unshift($params, SLA_THRESHOLD);

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting service level data: " . $e->getMessage());
        }
    }

    public function getAnsweredByAgent($startDate, $endDate, $agent = null) {
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
                    AVG(duration) as avg_duration,
                    SUM(duration) as total_duration,
                    MIN(duration) as min_duration,
                    MAX(duration) as max_duration
                FROM queue_log
                WHERE 
                    event = 'CONNECT' 
                    AND time BETWEEN ? AND ?
                    $agentFilter
                GROUP BY agent
                ORDER BY total_calls DESC
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting agent data: " . $e->getMessage());
        }
    }

    public function getAnsweredByQueue($startDate, $endDate) {
        try {
            $sql = "
                SELECT 
                    queuename,
                    COUNT(*) as total_calls,
                    AVG(duration) as avg_duration,
                    SUM(duration) as total_duration,
                    COUNT(DISTINCT agent) as unique_agents,
                    AVG(wait) as avg_wait_time
                FROM queue_log
                WHERE 
                    event = 'CONNECT' 
                    AND time BETWEEN ? AND ?
                GROUP BY queuename
                ORDER BY total_calls DESC
            ";

            return $this->db->fetchAll($sql, [$startDate, $endDate]);
        } catch (Exception $e) {
            throw new Exception("Error getting queue data: " . $e->getMessage());
        }
    }

    public function getDetailedReport($startDate, $endDate, $queue = null, $agent = null) {
        try {
            $params = [$startDate, $endDate];
            $filters = [];
            
            if ($queue) {
                $filters[] = "queuename = ?";
                $params[] = $queue;
            }
            if ($agent) {
                $filters[] = "agent = ?";
                $params[] = $agent;
            }

            $whereClause = $filters ? " AND " . implode(" AND ", $filters) : "";

            $sql = "
                SELECT 
                    time,
                    queuename,
                    agent,
                    callid,
                    wait as wait_time,
                    duration,
                    data1 as caller_number,
                    data2 as disposition
                FROM queue_log
                WHERE 
                    event = 'CONNECT' 
                    AND time BETWEEN ? AND ?
                    $whereClause
                ORDER BY time DESC
                LIMIT " . MAX_RECORDS;

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting detailed report: " . $e->getMessage());
        }
    }
}

// Handle API requests
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method");
    }

    $report = new AnsweredCallsReport();
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $reportType = $_GET['type'] ?? 'service_level';
    $queue = $_GET['queue'] ?? null;
    $agent = $_GET['agent'] ?? null;

    $result = [];
    switch ($reportType) {
        case 'service_level':
            $result = $report->getServiceLevel($startDate, $endDate, $queue);
            break;
        case 'by_agent':
            $result = $report->getAnsweredByAgent($startDate, $endDate, $agent);
            break;
        case 'by_queue':
            $result = $report->getAnsweredByQueue($startDate, $endDate);
            break;
        case 'detailed':
            $result = $report->getDetailedReport($startDate, $endDate, $queue, $agent);
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
            'queue' => $queue,
            'agent' => $agent
        ]
    ]);

} catch (Exception $e) {
    sendError($e->getMessage());
}
