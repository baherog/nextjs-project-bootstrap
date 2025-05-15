<?php
require_once '../config.php';
require_once '../db.php';

class DistributionReport {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getQueueDistribution($startDate, $endDate) {
        try {
            $sql = "
                SELECT 
                    queuename,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN event = 'CONNECT' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN event IN ('ABANDON', 'EXITWITHTIMEOUT') THEN 1 ELSE 0 END) as unanswered,
                    AVG(CASE WHEN event = 'CONNECT' THEN duration ELSE NULL END) as avg_talk_time,
                    AVG(wait) as avg_wait_time
                FROM queue_log
                WHERE 
                    event IN ('CONNECT', 'ABANDON', 'EXITWITHTIMEOUT')
                    AND time BETWEEN ? AND ?
                GROUP BY queuename
                ORDER BY total_calls DESC
            ";

            return $this->db->fetchAll($sql, [$startDate, $endDate]);
        } catch (Exception $e) {
            throw new Exception("Error getting queue distribution: " . $e->getMessage());
        }
    }

    public function getMonthlyDistribution($year = null) {
        try {
            $year = $year ?? date('Y');
            $sql = "
                SELECT 
                    MONTH(time) as month,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN event = 'CONNECT' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN event IN ('ABANDON', 'EXITWITHTIMEOUT') THEN 1 ELSE 0 END) as unanswered,
                    COUNT(DISTINCT queuename) as active_queues,
                    COUNT(DISTINCT CASE WHEN event = 'CONNECT' THEN agent ELSE NULL END) as active_agents
                FROM queue_log
                WHERE 
                    YEAR(time) = ?
                    AND event IN ('CONNECT', 'ABANDON', 'EXITWITHTIMEOUT')
                GROUP BY MONTH(time)
                ORDER BY month
            ";

            return $this->db->fetchAll($sql, [$year]);
        } catch (Exception $e) {
            throw new Exception("Error getting monthly distribution: " . $e->getMessage());
        }
    }

    public function getWeeklyDistribution($startDate, $endDate) {
        try {
            $sql = "
                SELECT 
                    YEARWEEK(time, 1) as year_week,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN event = 'CONNECT' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN event IN ('ABANDON', 'EXITWITHTIMEOUT') THEN 1 ELSE 0 END) as unanswered,
                    AVG(CASE WHEN event = 'CONNECT' THEN duration ELSE NULL END) as avg_talk_time
                FROM queue_log
                WHERE 
                    time BETWEEN ? AND ?
                    AND event IN ('CONNECT', 'ABANDON', 'EXITWITHTIMEOUT')
                GROUP BY YEARWEEK(time, 1)
                ORDER BY year_week
            ";

            return $this->db->fetchAll($sql, [$startDate, $endDate]);
        } catch (Exception $e) {
            throw new Exception("Error getting weekly distribution: " . $e->getMessage());
        }
    }

    public function getDailyDistribution($startDate, $endDate, $queue = null) {
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
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN event = 'CONNECT' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN event IN ('ABANDON', 'EXITWITHTIMEOUT') THEN 1 ELSE 0 END) as unanswered,
                    AVG(CASE WHEN event = 'CONNECT' THEN duration ELSE NULL END) as avg_talk_time,
                    AVG(wait) as avg_wait_time
                FROM queue_log
                WHERE 
                    time BETWEEN ? AND ?
                    AND event IN ('CONNECT', 'ABANDON', 'EXITWITHTIMEOUT')
                    $queueFilter
                GROUP BY DATE(time)
                ORDER BY date
            ";

            return $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            throw new Exception("Error getting daily distribution: " . $e->getMessage());
        }
    }

    public function getHourlyDistribution($date = null) {
        try {
            $date = $date ?? date('Y-m-d');
            $sql = "
                SELECT 
                    HOUR(time) as hour,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN event = 'CONNECT' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN event IN ('ABANDON', 'EXITWITHTIMEOUT') THEN 1 ELSE 0 END) as unanswered,
                    COUNT(DISTINCT queuename) as active_queues,
                    COUNT(DISTINCT CASE WHEN event = 'CONNECT' THEN agent ELSE NULL END) as active_agents
                FROM queue_log
                WHERE 
                    DATE(time) = ?
                    AND event IN ('CONNECT', 'ABANDON', 'EXITWITHTIMEOUT')
                GROUP BY HOUR(time)
                ORDER BY hour
            ";

            return $this->db->fetchAll($sql, [$date]);
        } catch (Exception $e) {
            throw new Exception("Error getting hourly distribution: " . $e->getMessage());
        }
    }

    public function getDayOfWeekDistribution($startDate, $endDate) {
        try {
            $sql = "
                SELECT 
                    DAYOFWEEK(time) as day_of_week,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN event = 'CONNECT' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN event IN ('ABANDON', 'EXITWITHTIMEOUT') THEN 1 ELSE 0 END) as unanswered,
                    AVG(CASE WHEN event = 'CONNECT' THEN duration ELSE NULL END) as avg_talk_time,
                    COUNT(DISTINCT queuename) as active_queues
                FROM queue_log
                WHERE 
                    time BETWEEN ? AND ?
                    AND event IN ('CONNECT', 'ABANDON', 'EXITWITHTIMEOUT')
                GROUP BY DAYOFWEEK(time)
                ORDER BY day_of_week
            ";

            return $this->db->fetchAll($sql, [$startDate, $endDate]);
        } catch (Exception $e) {
            throw new Exception("Error getting day of week distribution: " . $e->getMessage());
        }
    }
}

// Handle API requests
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method");
    }

    $report = new DistributionReport();
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $reportType = $_GET['type'] ?? 'queue';
    $queue = $_GET['queue'] ?? null;
    $year = $_GET['year'] ?? date('Y');
    $date = $_GET['date'] ?? date('Y-m-d');

    $result = [];
    switch ($reportType) {
        case 'queue':
            $result = $report->getQueueDistribution($startDate, $endDate);
            break;
        case 'monthly':
            $result = $report->getMonthlyDistribution($year);
            break;
        case 'weekly':
            $result = $report->getWeeklyDistribution($startDate, $endDate);
            break;
        case 'daily':
            $result = $report->getDailyDistribution($startDate, $endDate, $queue);
            break;
        case 'hourly':
            $result = $report->getHourlyDistribution($date);
            break;
        case 'day_of_week':
            $result = $report->getDayOfWeekDistribution($startDate, $endDate);
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
            'year' => $year,
            'date' => $date
        ]
    ]);

} catch (Exception $e) {
    sendError($e->getMessage());
}
