<?php
/**
 * Maruf Traders - Global Utility & Financial Business Logic Functions
 */

defined('APP_INIT') or define('APP_INIT', true);

/**
 * Standardized JSON API Response
 */
function jsonResponse(bool $success, string $message = '', array $data = [], int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Fetch a setting from database with caching
 */
function getSetting(string $key, ?string $default = null): ?string {
    static $settingsCache = [];

    if (empty($settingsCache)) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            while ($row = $stmt->fetch()) {
                $settingsCache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            return $default;
        }
    }

    return $settingsCache[$key] ?? $default;
}

/**
 * Update a setting in database
 */
function updateSetting(string $key, string $value): bool {
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) 
                              VALUES (:k, :v) 
                              ON DUPLICATE KEY UPDATE setting_value = :v2");
        return $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Format currency in Bangladeshi Taka (৳ / Tk)
 */
function formatBDT(float|int|string $amount, bool $showSymbol = true): string {
    $num = (float)$amount;
    $formatted = number_format($num, 2, '.', ',');
    return $showSymbol ? '৳ ' . $formatted : $formatted;
}

/**
 * Format date nicely
 */
function formatDate(?string $dateStr, string $format = 'd M, Y'): string {
    if (empty($dateStr) || $dateStr === '0000-00-00') {
        return '—';
    }
    $timestamp = strtotime($dateStr);
    return $timestamp ? date($format, $timestamp) : '—';
}

/**
 * Format datetime nicely
 */
function formatDateTime(?string $dateStr, string $format = 'd M, Y h:i A'): string {
    if (empty($dateStr) || $dateStr === '0000-00-00 00:00:00') {
        return '—';
    }
    $timestamp = strtotime($dateStr);
    return $timestamp ? date($format, $timestamp) : '—';
}

/**
 * Clean & sanitize user input
 */
function sanitizeInput(mixed $data): mixed {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
        return $data;
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate sequential unique business code / ID (e.g. SALE-00001, RET-001)
 */
function generateCode(string $table, string $column, string $prefix, int $padLength = 5): string {
    $db = Database::getConnection();
    $sql = "SELECT {$column} FROM {$table} WHERE {$column} LIKE :pattern ORDER BY id DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([':pattern' => $prefix . '-%']);
    $lastCode = $stmt->fetchColumn();

    if ($lastCode) {
        // Extract numeric part
        if (preg_match('/' . preg_quote($prefix, '/') . '-(\d+)/i', $lastCode, $matches)) {
            $nextNum = (int)$matches[1] + 1;
        } else {
            $nextNum = 1;
        }
    } else {
        $nextNum = 1;
    }

    return sprintf("%s-%0" . $padLength . "d", $prefix, $nextNum);
}

/**
 * Check if an accounting month is closed
 */
function isMonthClosed(string $dateStr): bool {
    $timestamp = strtotime($dateStr);
    if (!$timestamp) {
        $timestamp = time();
    }

    $month = (int)date('n', $timestamp);
    $year = (int)date('Y', $timestamp);

    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM monthly_closings 
                              WHERE closing_month = :m AND closing_year = :y AND status = 'closed' LIMIT 1");
        $stmt->execute([':m' => $month, ':y' => $year]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Assert that the month for a transaction date is open. Throws Exception if closed.
 */
function assertMonthOpen(string $dateStr): void {
    if (isMonthClosed($dateStr)) {
        $monthName = date('F Y', strtotime($dateStr));
        throw new Exception("Transaction rejected: The accounting period for {$monthName} is CLOSED. Please contact an Administrator.");
    }
}

/**
 * Record an audit log entry
 */
function logAudit(string $module, string $action, ?string $refId = null, mixed $oldData = null, mixed $newData = null): void {
    try {
        $db = Database::getConnection();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        // Redact any sensitive keys (e.g. passwords)
        $cleanData = function ($data) {
            if (is_array($data)) {
                unset($data['password'], $data['csrf_token']);
                return json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            return is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        };

        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, module, action, reference_id, old_data, new_data, ip_address, user_agent)
                              VALUES (:uid, :mod, :act, :ref, :old, :new, :ip, :ua)");
        $stmt->execute([
            ':uid' => $userId,
            ':mod' => $module,
            ':act' => $action,
            ':ref' => $refId,
            ':old' => $oldData ? $cleanData($oldData) : null,
            ':new' => $newData ? $cleanData($newData) : null,
            ':ip'  => $ip,
            ':ua'  => substr($ua, 0, 500)
        ]);
    } catch (Exception $e) {
        // Silently prevent audit logger from crashing main business operations
        error_log("Audit log failed: " . $e->getMessage());
    }
}

/**
 * Recalculate Monthly Target Achievement and Commission
 *
 * Rules:
 * Mode: 'proportional'
 *   Achievement %   = (actual_sales / target_qty) * 100
 *   Achievement is CAPPED at 100% for rate purposes (matches targets.php display —
 *   previously this function did NOT cap it, causing the stored estimated_commission
 *   to drift above the card's displayed amount once sales passed 100% of target).
 *   Adjusted Rate   = Full Rate * (Capped Achievement % / 100)
 *   At >= 100%      : Adjusted Rate = Full Rate (never exceeds full rate)
 *   Proportional Commission = Actual Sales * Adjusted Rate
 *
 * Mode: 'fixed_per_bag'
 *   Proportional Commission = Actual Sales * Full Rate (flat, no achievement scaling)
 *
 * NEW — Fixed Per-Bag Commission (stacks on top of either mode above):
 *   Fixed Commission = Actual Sales * fixed_commission_per_bag
 *   This is completely independent of achievement % — it is simply
 *   "this many bags sold x flat rate", always added on top.
 *
 * Estimated Commission = Proportional Commission + Fixed Commission
 */
function recalculateTargetAndCommission(PDO $db, int $companyId, ?int $productId, int $month, int $year): void {
    // 1. Calculate actual sales quantity for this company (and optionally product) in this month/year
    $query = "SELECT COALESCE(SUM(si.quantity), 0) AS total_qty
              FROM sale_items si
              JOIN sales s ON si.sale_id = s.id
              WHERE si.company_id = :comp_id
                AND s.status = 'active'
                AND MONTH(s.sale_date) = :m
                AND YEAR(s.sale_date) = :y";

    $params = [':comp_id' => $companyId, ':m' => $month, ':y' => $year];

    if ($productId !== null) {
        $query .= " AND si.product_id = :prod_id";
        $params[':prod_id'] = $productId;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $actualSalesQty = (int)$stmt->fetchColumn();

    // 2. Fetch existing monthly target record if any
    $tQuery = "SELECT id, target_quantity, commission_mode, commission_rate, fixed_commission_per_bag 
               FROM monthly_targets 
               WHERE company_id = :comp_id AND target_month = :m AND target_year = :y";
    if ($productId !== null) {
        $tQuery .= " AND product_id = :prod_id";
    } else {
        $tQuery .= " AND product_id IS NULL";
    }

    $tStmt = $db->prepare($tQuery);
    $tStmt->execute($params);
    $target = $tStmt->fetch();

    if ($target) {
        $targetQty = (int)$target['target_quantity'];
        $commissionRate = (float)$target['commission_rate'];
        $commissionMode = $target['commission_mode'] ?: 'proportional';
        $fixedRatePerBag = (float)($target['fixed_commission_per_bag'] ?? 0.00); // NEW

        // Calculate achievement percentage (uncapped, for display purposes)
        $achievement = $targetQty > 0 ? round(($actualSalesQty / $targetQty) * 100, 2) : 0.00;

        // Cap achievement at 100% for rate/commission purposes — matches targets.php card logic.
        // (Prevents adjustedRate from exceeding commissionRate once sales pass 100% of target.)
        $achievementCapped = min($achievement, 100.00);

        // Proportional / mode-based commission (existing behavior, bug-fixed)
        $proportionalCommission = 0.00;
        if ($commissionMode === 'proportional') {
            if ($achievementCapped >= 100) {
                $adjustedRate = $commissionRate; // Full rate at or above 100%
            } else {
                $adjustedRate = $commissionRate * ($achievementCapped / 100);
            }
            $proportionalCommission = $actualSalesQty * $adjustedRate;
        } elseif ($commissionMode === 'fixed_per_bag') {
            $proportionalCommission = $actualSalesQty * $commissionRate;
        }

        // NEW: Fixed per-bag commission — always flat, independent of achievement %,
        // stacks on top of whichever mode above was used.
        $fixedCommission = $actualSalesQty * $fixedRatePerBag;

        $estimatedCommission = round($proportionalCommission + $fixedCommission, 2);

        // Update target record
        $uStmt = $db->prepare("UPDATE monthly_targets 
                               SET actual_sales_quantity = :act_qty, 
                                   achievement_percentage = :ach, 
                                   estimated_commission = :comm 
                               WHERE id = :id");
        $uStmt->execute([
            ':act_qty' => $actualSalesQty,
            ':ach'     => $achievement,
            ':comm'    => $estimatedCommission,
            ':id'      => $target['id']
        ]);
    }
}

/**
 * Server-Side Anti-Duplicate Submission Guard
 * Prevents identical double-submissions within $timeoutSeconds.
 */
function preventDuplicateSubmission(string $actionKey, array $payload = [], int $timeoutSeconds = 4): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Filter out transient or dynamic fields like csrf_token
    $cleanPayload = $payload;
    unset($cleanPayload['csrf_token'], $cleanPayload['_rnd'], $cleanPayload['request_token']);

    $signature = md5($actionKey . '_' . serialize($cleanPayload));
    $now = microtime(true);

    if (isset($_SESSION['last_submission'][$actionKey])) {
        $last = $_SESSION['last_submission'][$actionKey];
        if ($last['sig'] === $signature && ($now - $last['time']) < $timeoutSeconds) {
            jsonResponse(false, 'Duplicate submission blocked! This transaction was already processed. Please do not double-click.', [
                'duplicate' => true
            ], 429);
        }
    }

    $_SESSION['last_submission'][$actionKey] = [
        'sig'  => $signature,
        'time' => $now
    ];
}

/**
 * Auto-settle conflicting Due and Advance balance for a retailer.
 * If retailer has both current_due > 0 AND advance_balance > 0,
 * automatically settles them against each other so one of them becomes 0.
 */
function autoSettleRetailerAdvanceDue(int $retailerId, ?PDO $db = null): array {
    if (!$db) {
        $db = Database::getConnection();
    }

    $stmt = $db->prepare("SELECT id, name, current_due, advance_balance FROM retailers WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $retailerId]);
    $r = $stmt->fetch();

    if (!$r) return ['due' => 0.0, 'advance' => 0.0, 'settled' => 0.0];

    $due = (float)$r['current_due'];
    $adv = (float)$r['advance_balance'];

    if ($due > 0 && $adv > 0) {
        $settle = min($due, $adv);
        $newDue = round($due - $settle, 2);
        $newAdv = round($adv - $settle, 2);

        $upd = $db->prepare("UPDATE retailers SET current_due = :due, advance_balance = :adv WHERE id = :id");
        $upd->execute([':due' => $newDue, ':adv' => $newAdv, ':id' => $retailerId]);

        // Insert adjustment ledger entry
        $lStmt = $db->prepare("INSERT INTO retailer_ledger 
            (retailer_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
            VALUES (:rid, CURDATE(), 'ADJUSTMENT', :ref, :desc, 0.00, :credit, :run_bal, :uid)");
        $refCode = 'ADJ-AUTO-' . str_pad((string)$retailerId, 4, '0', STR_PAD_LEFT);
        $desc = "স্বয়ংক্রিয় অগ্রিম ও বকেয়া সমন্বয় (Auto Advance ৳" . number_format($settle, 2) . " settled against due)";

        $lStmt->execute([
            ':rid'     => $retailerId,
            ':ref'     => $refCode,
            ':desc'    => $desc,
            ':credit'  => $settle,
            ':run_bal' => $newDue,
            ':uid'     => $_SESSION['user_id'] ?? 1
        ]);

        return ['due' => $newDue, 'advance' => $newAdv, 'settled' => $settle];
    }

    return ['due' => $due, 'advance' => $adv, 'settled' => 0.0];
}

/**
 * Full Ledger Re-synchronization for a Retailer:
 * Recalculates exact Current Due and Advance Balance from all active transactions:
 * Net Position = Opening Due + Total Active Sales (billed) - Total Active Collections (paid) - Total Active Advances
 * If Net Position >= 0: Current Due = Net Position, Advance = 0.
 * If Net Position < 0: Current Due = 0, Advance = |Net Position|.
 */
function recalculateRetailerBalanceFromLedger(int $retailerId, ?PDO $db = null): array {
    if (!$db) {
        $db = Database::getConnection();
    }

    $rStmt = $db->prepare("SELECT id, name, opening_balance, current_due, advance_balance FROM retailers WHERE id = :id FOR UPDATE");
    $rStmt->execute([':id' => $retailerId]);
    $r = $rStmt->fetch();

    if (!$r) return ['due' => 0.0, 'advance' => 0.0];

    $openingDue = max(0, (float)$r['opening_balance']);

    // Total active sales total amount (total bill amount)
    $sStmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE retailer_id = :rid AND status = 'active'");
    $sStmt->execute([':rid' => $retailerId]);
    $totalSales = (float)$sStmt->fetchColumn();

    // Total active collections
    $cStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM collections WHERE retailer_id = :rid AND status = 'active'");
    $cStmt->execute([':rid' => $retailerId]);
    $totalCollections = (float)$cStmt->fetchColumn();

    // Total active standalone advances
    $aStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM advances WHERE retailer_id = :rid AND status = 'active'");
    $aStmt->execute([':rid' => $retailerId]);
    $totalAdvances = (float)$aStmt->fetchColumn();

    // Net financial balance
    $netBalance = round(($openingDue + $totalSales) - ($totalCollections + $totalAdvances), 2);

    if ($netBalance >= 0) {
        $newDue = $netBalance;
        $newAdv = 0.00;
    } else {
        $newDue = 0.00;
        $newAdv = abs($netBalance);
    }

    $upd = $db->prepare("UPDATE retailers SET current_due = :due, advance_balance = :adv WHERE id = :id");
    $upd->execute([':due' => $newDue, ':adv' => $newAdv, ':id' => $retailerId]);

    return ['due' => $newDue, 'advance' => $newAdv];
}