<?php
require_once '../config.php';
require_once '../db.php';

class UnansweredCallsReport {
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
                    COUNT(*) as total_abandoned,
                    SUM(CASE WHEN wait <= ? THEN 1 ELSE 0 END) as quick_abandons,
                    AVG(wait) as avg_wait_before_abandon,
                    MAX(wait) as max_wait_before_abandon
                FROM queue_log
                WHERE 
                    event IN ('ABANDON', 'EXITWITHTIMEOUT') 
                    AND time BETWEEN ? AND ?
                    $queueFilter
                GROUP BY queuename
            ";
            array_unshift($params, SLA_THRESHOLD);

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting unanswered service level data: " . $e->getMessage());
        }
    }

    public function getDisconnectionCause($startDate, $endDate, $queue = null) {
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
                    event,
                    COUNT(*) as total_calls,
                    AVG(wait) as avg_wait_time,
                    COUNT(DISTINCT callid) as unique_callers
                FROM queue_log
                WHERE 
                    event IN ('ABANDON', 'EXITWITHTIMEOUT', 'EXITEMPTY', 'EXITFULL') 
                    AND time BETWEEN ? AND ?
                    $queueFilter
                GROUP BY queuename, event
                ORDER BY queuename, total_calls DESC
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting disconnection cause data: " . $e->getMessage());
        }
    }

    public function getQueueAnalysis($startDate, $endDate) {
        try {
            $sql = "
                SELECT 
                    queuename,
                    COUNT(*) as total_unanswered,
                    SUM(CASE WHEN event = 'ABANDON' THEN 1 ELSE 0 END) as abandons,
                    SUM(CASE WHEN event = 'EXITWITHTIMEOUT' THEN 1 ELSE 0 END) as timeouts,
                    SUM(CASE WHEN event = 'EXITEMPTY' THEN 1 ELSE 0 END) as empty_queue,
                    SUM(CASE WHEN event = 'EXITFULL' THEN 1 ELSE 0 END) as full_queue,
                    AVG(wait) as avg_wait_time,
                    MAX(wait) as max_wait_time
                FROM queue_log
                WHERE 
                    event IN ('ABANDON', 'EXITWITHTIMEOUT', 'EXITEMPTY', 'EXITFULL')
                    AND time BETWEEN ? AND ?
                GROUP BY queuename
                ORDER BY total_unanswered DESC
            ";

            return $this->db->fetchAll($sql, [$startDate, $endDate]);
        } catch (Exception $e) {
            throw new Exception("Error getting queue analysis data: " . $e->getMessage());
        }
    }

    public function getDetailedReport($startDate, $endDate, $queue = null) {
        try {
            $params = [$startDate, $endDate];
            $queueFilter = "";
            
            if ($queue) {
                $queueFilter = "AND queuename = ?";
                $params[] = $queue;
            }

            $sql = "
                SELECT 
                    time,
                    queuename,
                    callid,
                    event as disconnect_reason,
                    wait as wait_time,
                    data1 as caller_number,
                    data2 as position_in_queue
                FROM queue_log
                WHERE 
                    event IN ('ABANDON', 'EXITWITHTIMEOUT', 'EXITEMPTY', 'EXITFULL')
                    AND time BETWEEN ? AND ?
                    $queueFilter
                ORDER BY time DESC
                LIMIT " . MAX_RECORDS;

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting detailed unanswered report: " . $e->getMessage());
        }
    }

    public function getAbandonmentTrends($startDate, $endDate, $queue = null) {
        try {
            $params = [$startDate, $endDate];
            $queueFilter = "";
            
            if ($queue) {
                $queueFilter = "AND queuename = ?";
                $params[] = $queue;
            }

            $sql = "
                SELECT 
                    DATE(time) as date,
                    HOUR(time) as hour,
                    COUNT(*) as total_abandons,
                    AVG(wait) as avg_wait_time,
                    queuename
                FROM queue_log
                WHERE 
                    event = 'ABANDON'
                    AND time BETWEEN ? AND ?
                    $queueFilter
                GROUP BY DATE(time), HOUR(time), queuename
                ORDER BY date, hour
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting abandonment trends: " . $e->getMessage());
        }
    }
}

// Handle API requests
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method");
    }

    $report = new UnansweredCallsReport();
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $reportType = $_GET['type'] ?? 'service_level';
    $queue = $_GET['queue'] ?? null;

    $result = [];
    switch ($reportType) {
        case 'service_level':
            $result = $report->getServiceLevel($startDate, $endDate, $queue);
            break;
        case 'disconnection_cause':
            $result = $report->getDisconnectionCause($startDate, $endDate, $queue);
            break;
        case 'queue_analysis':
            $result = $report->getQueueAnalysis($startDate, $endDate);
            break;
        case 'detailed':
            $result = $report->getDetailedReport($startDate, $endDate, $queue);
            break;
        case 'abandonment_trends':
            $result = $report->getAbandonmentTrends($startDate, $endDate, $queue);
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
            'queue' => $queue
        ]
    ]);

} catch (Exception $e) {
    sendError($e->getMessage());
}
