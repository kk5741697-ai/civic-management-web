<?php
// cron-job.php - improved lead importer & flexible distributor
// - Batch-safe: updates in-memory state while assigning to avoid repeating same user
// - Multiple strategies: balanced, round_robin, weighted_random
// - Phone override (recent), punish list, join-date windowing
// - Per-lead transaction and notifications
// - Logging to debug2.log
//
// Place this file where your other app bootstrap (db and constants) are available.

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Dhaka');

$show_import_status = true; // set false in production for silence

require_once ROOT_DIR . "assets/libraries/web-push/vendor/autoload.php";
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;

// ----------------- CONFIG -----------------
if (!defined('BALANCING_LOOKBACK_DAYS')) define('BALANCING_LOOKBACK_DAYS', 90);
if (!defined('PHONE_OVERRIDE_MAX_DAYS')) define('PHONE_OVERRIDE_MAX_DAYS', 30);
// Maximum number of leads a single user can receive in a single cron batch (hard cap)
// Set 0 to disable.
if (!defined('MAX_ASSIGN_PER_USER_PER_BATCH')) define('MAX_ASSIGN_PER_USER_PER_BATCH', 1);

// Distribution strategy: 'balanced' (recommended), 'round_robin', 'weighted_random'
if (!defined('DISTRIBUTION_STRATEGY')) define('DISTRIBUTION_STRATEGY', 'balanced');

$vapidKeysFile = ROOT_DIR . '/vapid_keys.json';
if (!file_exists($vapidKeysFile)) {
    $vapid_keys = VAPID::createVapidKeys();
    file_put_contents($vapidKeysFile, json_encode($vapid_keys));
} else {
    $vapid_keys = json_decode(file_get_contents($vapidKeysFile), true);
}
$wo = $wo ?? [];
$wo['config']['vapid_public_key'] = $vapid_keys['publicKey'] ?? '';
$wo['config']['vapid_private_key'] = $vapid_keys['privateKey'] ?? '';

// ----------------- UTIL, NOTIFICATIONS & LOGGING -----------------

function logDebug($message) {
    $file = ROOT_DIR . '/debug2.log';
    file_put_contents($file, "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL, FILE_APPEND);
}

function get_user_subscription($user_id) {
    $subscriptionFile = ROOT_DIR . '/subscriptions.json';
    if (!file_exists($subscriptionFile)) {
        throw new Exception("Subscription file not found: {$subscriptionFile}");
    }
    $data = json_decode(file_get_contents($subscriptionFile), true);
    return $data[$user_id] ?? [];
}

function sendWebNotification($user_id, $title, $message, $url = '', $image = '') {
    global $vapid_keys;
    try {
        $subscriptions = get_user_subscription($user_id);
        if (empty($subscriptions)) {
            logDebug("No subscriptions for user {$user_id}");
            return false;
        }
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => 'mailto:admin@civicgroupbd.com',
                'publicKey' => $vapid_keys['publicKey'],
                'privateKey' => $vapid_keys['privateKey'],
            ],
        ]);
        $payload = json_encode([
            'title' => $title,
            'body' => $message,
            'icon' => 'https://civicgroupbd.com/manage/assets/images/logo-icon-2.png',
            'badge' => 'https://civicgroupbd.com/manage/assets/images/logo-icon-2.png',
            'onclic_url' => $url ?: 'https://civicgroupbd.com/management',
            'image' => $image ?: null,
        ]);
        $success = 0;
        foreach ($subscriptions as $s) {
            try {
                $sub = Subscription::create([
                    'endpoint' => $s['endpoint'],
                    'publicKey' => $s['keys']['p256dh'],
                    'authToken' => $s['keys']['auth'],
                ]);
                $result = $webPush->sendOneNotification($sub, $payload);
                if ($result->isSuccess()) $success++;
            } catch (Exception $e) {
                logDebug("sendWebNotification inner: " . $e->getMessage());
            }
        }
        return $success > 0;
    } catch (Exception $e) {
        logDebug("sendWebNotification error: " . $e->getMessage());
        return false;
    }
}

if (!function_exists('notifyUser')) {
    function notifyUser($db, $user_id, $subject, $comment, $url) {
        if (intval($user_id) === 1) return true; // skip admin if desired
        $ok = $db->insert(NOTIFICATION, [
            'subject' => $subject,
            'comment' => $comment,
            'type'    => 'leads',
            'url'     => $url,
            'user_id' => $user_id
        ]);
        if ($ok) {
            @sendWebNotification($user_id, $subject, $comment, $url);
            return true;
        }
        throw new Exception("Failed to insert notification for user {$user_id}: " . $db->getLastError());
    }
}

function logActivity($feature, $activityType, $details = null, $userId = false) {
    global $db, $wo;
    if (!$userId) $userId = $wo['user']['user_id'] ?? 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $device = $_SERVER['HTTP_USER_AGENT'] ?? 'cron';
    $windows = ['login'=>300,'view'=>60,'create'=>5,'edit'=>5,'update'=>5,'delete'=>5,'message'=>10,'comment'=>30,'other'=>60,'error'=>300];
    $window = $windows[$activityType] ?? 5;
    $now = time();
    $start = $now - $window;
    $exists = $db->where('user_id', $userId)
                 ->where('feature', $feature)
                 ->where('activity_type', $activityType)
                 ->where('created_at', $start, '>=')
                 ->where('created_at', $now, '<=')
                 ->getOne('activity_logs');
    if (!$exists) {
        $db->insert('activity_logs', [
            'user_id' => $userId,
            'feature' => $feature,
            'activity_type' => $activityType,
            'details' => $details,
            'ip_address' => $ip,
            'device_info' => $device,
            'created_at' => $now
        ]);
        return true;
    }
    return false;
}

// ----------------- FACEBOOK SDK -----------------
include_once(ROOT_DIR . "assets/libraries/facebook-graph/vendor/autoload.php");
use JanuSoftware\Facebook\Facebook;
$fb_connect = new Facebook([
    'app_id' => '539681107316774',
    'app_secret' => '20d756d9f811dd41ba813368f88a4cbb',
    'default_graph_version' => 'v21.0',
]);

function read_fb_api_setup_data() {
    $file_path = ROOT_DIR . '/fb_api_setup.json';
    if (!file_exists($file_path)) return null;
    return json_decode(file_get_contents($file_path), true);
}

// ----------------- PROJECT & QUOTA HELPERS -----------------

function canonicalProjectKey(string $pid): string {
    $k = trim(strtolower($pid));
    $k = str_replace(['-', ' '], '_', $k);
    $pageIdMap = ['259547413906965'=>'hill_town','1932174893479181'=>'moon_hill'];
    if (isset($pageIdMap[$k])) return $pageIdMap[$k];
    $variants = [
        'hilltown'=>'hill_town','hill_town'=>'hill_town','hill-town'=>'hill_town',
        'moonhill'=>'moon_hill','moon_hill'=>'moon_hill','moon-hill'=>'moon_hill',
        'abedin'=>'abedin','civic_abedin'=>'abedin','ashridge'=>'ashridge','civic_ashridge'=>'ashridge'
    ];
    if (isset($variants[$k])) return $variants[$k];
    return preg_replace('/[^a-z0-9_]/','_', $k);
}

function balancingProjects(): array {
    return ['hill_town','moon_hill','ashridge','abedin'];
}

function loadAssignmentQuotas(string $pageId): array {
    global $db;
    switch ($pageId) {
        case 'hill_town':
            $rows = $db->where('project', $pageId)->orderby('user_id', 'DESC')->get('crm_assignment_rules', null, ['user_id','raw_weight','participating']);
            break;
        case 'moon_hill':
            $rows = $db->where('project', $pageId)->orderby('user_id', 'ASC')->get('crm_assignment_rules', null, ['user_id','raw_weight','participating']);
            break;
        case 'ashridge':
            $rows = $db->where('project', $pageId)->orderby('user_id', 'DESC')->get('crm_assignment_rules', null, ['user_id','raw_weight','participating']);
            break;
        case 'abedin':
            $rows = $db->where('project', $pageId)->orderby('user_id', 'ASC')->get('crm_assignment_rules', null, ['user_id','raw_weight','participating']);
            break;
        case '259547413906965':
            $rows = $db->where('project', 'hill_town')->orderby('user_id', 'DESC')->get('crm_assignment_rules', null, ['user_id','raw_weight','participating']);
            break;
        case '1932174893479181':
            $rows = $db->where('project', 'moon_hill')->orderby('user_id', 'ASC')->get('crm_assignment_rules', null, ['user_id','raw_weight','participating']);
            break;
        default:
            $rows = $db->where('project', $pageId)->orderby('user_id', 'ASC')->get('crm_assignment_rules', null, ['user_id','raw_weight','participating']);
    }
    $quotas = [];
    foreach ($rows as $r) {
        // if participating is defined and false, treat weight as 0
        $w = isset($r->participating) && ((int)$r->participating === 0) ? 0 : (int)$r->raw_weight;
        $quotas[(int)$r->user_id] = $w;
    }
    return $quotas;
}

function loadNormalizedQuotas(string $project, array $eligibleUserIds = null): array {
    $raw = loadAssignmentQuotas($project);
    if (is_array($eligibleUserIds)) $raw = array_intersect_key($raw, array_flip($eligibleUserIds));
    $sum = array_sum($raw);
    $out = [];
    foreach ($raw as $uid => $w) $out[(int)$uid] = $sum > 0 ? (100.0 * $w / $sum) : 0.0;
    return $out;
}

function loadAllProjectQuotas(array $eligibleUnion = null): array {
    $result = [];
    foreach (balancingProjects() as $proj) $result[$proj] = loadNormalizedQuotas($proj, $eligibleUnion);
    return $result;
}

function loadPunishedUsers(): array {
    global $db;
    $rows = $db->get('crm_punished_users', null, ['user_id']);
    return array_column($rows, 'user_id');
}

function eligibleUsersForProject(string $project, array $projectQuotas): array {
    global $db;
    $ids = array_keys($projectQuotas);
    if (empty($ids)) return [];
    $punished = loadPunishedUsers();
    $rows = $db->where('user_id', $ids, 'IN')->where('active','1')->where('banned','0')->get(T_USERS, null, ['user_id','leader_id','joining_date']);
    $eligible = [];
    foreach ($rows as $r) {
        if (!in_array((int)$r->user_id, $punished)) {
            $eligible[(int)$r->user_id] = [
                'leader_id' => (int)$r->leader_id,
                'joining_ts' => strtotime($r->joining_date . ' 00:00:00') ?: 0
            ];
        }
    }
    return $eligible;
}

function eligibleUnionAcrossProjects(): array {
    $all = [];
    foreach (balancingProjects() as $proj) {
        $raw = loadAssignmentQuotas($proj);
        $eligible = eligibleUsersForProject($proj, $raw);
        foreach ($eligible as $uid => $meta) $all[(int)$uid] = true;
    }
    return array_keys($all);
}

function windowStartFor(array $eligibleMeta): int {
    if (empty($eligibleMeta)) return strtotime("-".BALANCING_LOOKBACK_DAYS." days");
    $max = 0;
    foreach ($eligibleMeta as $meta) if (!empty($meta['joining_ts']) && $meta['joining_ts'] > $max) $max = $meta['joining_ts'];
    if ($max <= 0) return strtotime("-".BALANCING_LOOKBACK_DAYS." days");
    return (int)$max;
}

function actualCounts(array $userIds, array $projects, int $startTs): array {
    global $db;
    if (empty($userIds) || empty($projects)) return [];
    $rows = $db->where('member', $userIds, 'IN')->where('project', $projects, 'IN')->where('created', $startTs, '>=')->groupBy('member')->get('crm_leads', null, ['member','COUNT(*) AS cnt']);
    $out = array_fill_keys($userIds, 0);
    foreach ($rows as $r) $out[(int)$r->member] = (int)$r->cnt;
    return $out;
}

function actualCountsForProject(array $userIds, string $project, int $startTs): array {
    return actualCounts($userIds, [$project], $startTs);
}

function adjustForRecentDuplicates(array &$actual, array $userIds, array $projects, int $days = 15): void {
    global $db;
    if (empty($userIds) || empty($projects)) return;
    $since = strtotime("-{$days} days");
    $rows = $db->where('created', $since, '>=')->where('member', $userIds, 'IN')->where('project', $projects, 'IN')->get('crm_leads', null, ['member','project','phone']);
    $seen = [];
    foreach ($rows as $row) {
        $key = $row->member . ':' . preg_replace('/[^0-9]/','', (string)$row->phone);
        if (!isset($seen[$key])) { $seen[$key] = $row->project; continue; }
        if ($seen[$key] !== $row->project && isset($actual[(int)$row->member]) && $actual[(int)$row->member] > 0) $actual[(int)$row->member]--;
    }
}

function globalDeficits(array $eligibleMeta, array $allQuotas, int $startTs): array {
    $userIds = array_map('intval', array_keys($eligibleMeta));
    $projects = array_keys($allQuotas);
    $actualGlobal = actualCounts($userIds, $projects, $startTs);
    adjustForRecentDuplicates($actualGlobal, $userIds, $projects, 15);
    foreach ($actualGlobal as $uid => $v) if ($v < 0) $actualGlobal[$uid] = 0;
    $totals = [];
    foreach ($projects as $p) {
        $per = actualCounts($userIds, [$p], $startTs);
        $totals[$p] = array_sum($per);
    }
    $expected = array_fill_keys($userIds, 0.0);
    foreach ($projects as $p) {
        $qp = $allQuotas[$p] ?? [];
        $Tp = $totals[$p] ?? 0;
        if ($Tp <= 0) continue;
        foreach ($userIds as $u) {
            $q = $qp[$u] ?? 0.0;
            $expected[$u] += ($q * $Tp) / 100.0;
        }
    }
    $def = [];
    foreach ($userIds as $u) $def[$u] = ($expected[$u] ?? 0.0) - ($actualGlobal[$u] ?? 0);
    return ['actual_global' => $actualGlobal, 'expected' => $expected, 'deficits' => $def];
}

// ----------------- STATEFUL BATCH ASSIGNER -----------------

function prepareAssignmentState(string $project) {
    // snapshot all data once to make multiple picks deterministic and efficient
    $thisQuotasRaw = loadAssignmentQuotas($project);
    if (empty($thisQuotasRaw)) return null;
    $eligibleMeta = eligibleUsersForProject($project, $thisQuotasRaw);
    $eligibleIds = array_map('intval', array_keys($eligibleMeta));
    if (empty($eligibleIds)) return null;
    $eligibleUnion = eligibleUnionAcrossProjects();
    $allQuotas = loadAllProjectQuotas($eligibleUnion);
    $thisQuotas = $allQuotas[$project] ?? [];
    // restrict to eligible
    $thisQuotas = array_intersect_key($thisQuotas, array_flip($eligibleIds));
    $startTs = windowStartFor($eligibleMeta);
    $projects = array_keys($allQuotas);
    $actualGlobal = actualCounts($eligibleIds, $projects, $startTs);
    adjustForRecentDuplicates($actualGlobal, $eligibleIds, $projects, 15);
    foreach ($eligibleIds as $u) {
        $actualGlobal[$u] = $actualGlobal[$u] ?? 0;
    }
    $actualProject = actualCountsForProject($eligibleIds, $project, $startTs);
    foreach ($eligibleIds as $u) $actualProject[$u] = $actualProject[$u] ?? 0;
    $totals = [];
    foreach ($projects as $p) {
        $per = actualCounts($eligibleIds, [$p], $startTs);
        $totals[$p] = array_sum($per);
    }
    $expected = array_fill_keys($eligibleIds, 0.0);
    foreach ($projects as $p) {
        $qp = $allQuotas[$p] ?? [];
        $Tp = $totals[$p] ?? 0;
        if ($Tp <= 0) continue;
        foreach ($eligibleIds as $u) {
            $q = $qp[$u] ?? 0.0;
            $expected[$u] += ($q * $Tp) / 100.0;
        }
    }
    $deficits = [];
    foreach ($eligibleIds as $u) $deficits[$u] = ($expected[$u] ?? 0.0) - ($actualGlobal[$u] ?? 0);

    // batchAssigned: how many leads we've assigned this batch (for enforcing MAX_ASSIGN_PER_USER_PER_BATCH)
    $batchAssigned = array_fill_keys($eligibleIds, 0);

    return [
        'eligibleIds' => $eligibleIds,
        'eligibleMeta' => $eligibleMeta,
        'thisQuotas' => $thisQuotas,
        'allQuotas' => $allQuotas,
        'startTs' => $startTs,
        'actualProject' => $actualProject,
        'actualGlobal' => $actualGlobal,
        'expected' => $expected,
        'deficits' => $deficits,
        'totals' => $totals,
        'batchAssigned' => $batchAssigned,
    ];
}

/**
 * pickFromState: choose a user from prepared state using configured strategy.
 * Returns ['user_id'=>..., 'leader_id'=>...]
 */
function pickFromState(array &$state, string $project) {
    global $show_import_status;
    $strategy = DISTRIBUTION_STRATEGY;
    $eligible = $state['eligibleIds'];
    $thisQuotas = $state['thisQuotas'];
    $deficits = $state['deficits'];
    $actualProject = $state['actualProject'];
    $eligibleMeta = $state['eligibleMeta'];
    $batchAssigned = $state['batchAssigned'];

    // filter out users who reached per-batch cap (if enabled)
    $candidates = [];
    foreach ($eligible as $u) {
        if (MAX_ASSIGN_PER_USER_PER_BATCH > 0 && ($batchAssigned[$u] ?? 0) >= MAX_ASSIGN_PER_USER_PER_BATCH) continue;
        // only candidates with any quota >0 but fallback will allow all
        if (isset($thisQuotas[$u]) && $thisQuotas[$u] > 0.0) $candidates[] = $u;
    }
    if (empty($candidates)) {
        // fallback: allow any eligible user (may happen if quotas are zero or caps reached)
        foreach ($eligible as $u) {
            if (MAX_ASSIGN_PER_USER_PER_BATCH > 0 && ($batchAssigned[$u] ?? 0) >= MAX_ASSIGN_PER_USER_PER_BATCH) continue;
            $candidates[] = $u;
        }
        if (empty($candidates)) {
            // last resort: return admin(1)
            return ['user_id' => 1, 'leader_id' => 0];
        }
    }

    if ($strategy === 'round_robin') {
        // simple round-robin using smallest batchAssigned count then by joining timestamp
        usort($candidates, function($a,$b) use($batchAssigned,$eligibleMeta){
            $ca = $batchAssigned[$a] ?? 0; $cb = $batchAssigned[$b] ?? 0;
            if ($ca !== $cb) return $ca <=> $cb;
            $ja = $eligibleMeta[$a]['joining_ts'] ?? 0; $jb = $eligibleMeta[$b]['joining_ts'] ?? 0;
            return $ja <=> $jb;
        });
        $selected = $candidates[0];
    } elseif ($strategy === 'weighted_random') {
        // weighted random by thisQuotas among candidates
        $weights = [];
        $total = 0.0;
        foreach ($candidates as $u) {
            $w = max(0.0, $thisQuotas[$u] ?? 0.0);
            $weights[$u] = $w;
            $total += $w;
        }
        if ($total <= 0) {
            // fallback to equal random
            $selected = $candidates[array_rand($candidates)];
        } else {
            // pick by quota weights
            $r = mt_rand() / mt_getrandmax() * $total;
            $acc = 0.0; $selected = $candidates[0];
            foreach ($weights as $u => $w) {
                $acc += $w;
                if ($r <= $acc) { $selected = (int)$u; break; }
            }
        }
    } else {
        // 'balanced' (default) — sort by global deficit (desc), project deficit (desc), actualProject (asc), joining_ts asc
        $projectExpectedTotal = array_sum($state['actualProject'] ?? []);
        $projectExpected = [];
        foreach ($state['eligibleIds'] as $u) {
            $q = $state['thisQuotas'][$u] ?? 0.0;
            $projectExpected[$u] = ($projectExpectedTotal * $q) / 100.0;
        }
        $projectDef = [];
        foreach ($state['eligibleIds'] as $u) $projectDef[$u] = ($projectExpected[$u] ?? 0.0) - ($state['actualProject'][$u] ?? 0);

        usort($candidates, function($a,$b) use($deficits,$projectDef,$actualProject,$eligibleMeta){
            $ga = $deficits[$a] ?? 0.0; $gb = $deficits[$b] ?? 0.0;
            if ($ga != $gb) return ($gb <=> $ga); // larger global deficit first
            $pa = $projectDef[$a] ?? 0.0; $pb = $projectDef[$b] ?? 0.0;
            if ($pa != $pb) return ($pb <=> $pa);
            $aa = $actualProject[$a] ?? 0; $ab = $actualProject[$b] ?? 0;
            if ($aa != $ab) return ($aa <=> $ab); // lower actual first
            $ja = $eligibleMeta[$a]['joining_ts'] ?? 0; $jb = $eligibleMeta[$b]['joining_ts'] ?? 0;
            return $ja <=> $jb;
        });

        // prefer candidate with positive global deficit if any
        $selected = $candidates[0];
        foreach ($candidates as $uid) {
            if (($deficits[$uid] ?? 0) > 0) { $selected = $uid; break; }
        }
    }

    $leaderId = $eligibleMeta[$selected]['leader_id'] ?? 0;
    return ['user_id' => intval($selected), 'leader_id' => intval($leaderId)];
}

function updateStateAfterAssign(array &$state, string $project, int $user) {
    // increment actuals
    $state['actualProject'][$user] = ($state['actualProject'][$user] ?? 0) + 1;
    $state['actualGlobal'][$user] = ($state['actualGlobal'][$user] ?? 0) + 1;
    // update batchAssigned
    $state['batchAssigned'][$user] = ($state['batchAssigned'][$user] ?? 0) + 1;
    // recompute deficit for this user (simple decrement by 1)
    $state['deficits'][$user] = ($state['expected'][$user] ?? 0.0) - ($state['actualGlobal'][$user] ?? 0);
}

// ----------------- BATCH ALLOCATION (MAIN) -----------------

/**
 * allocateLeadsBatch - allocate an array of incoming FB leads (for same form/page group)
 * $incomingLeads: array of lead objects as FB returns them
 * $leadsData: the form/page metadata decoded from FB for context
 */
function allocateLeadsBatch(array $incomingLeads, array $leadsData) {
    global $db, $show_import_status;

    // group leads by canonical project key
    $groups = [];
    foreach ($incomingLeads as $lead) {
        $proj = '';
        if (!empty($lead['field_data'])) {
            foreach ($lead['field_data'] as $f) {
                if (($f['name'] ?? '') === 'project') { $proj = $f['values'][0] ?? ''; break; }
            }
        }
        if (empty($proj)) {
            $pageId = $leadsData['page']['id'] ?? '';
            if ($pageId === '259547413906965') $proj = 'hill_town';
            elseif ($pageId === '1932174893479181') $proj = 'moon_hill';
            else $proj = '';
        }
        $proj = canonicalProjectKey($proj ?: ($leadsData['page']['id'] ?? ''));
        $groups[$proj][] = $lead;
    }

    foreach ($groups as $proj => $leads) {
        if ($show_import_status) echo "--- ALLOCATING BATCH for project {$proj}: " . count($leads) . " leads ---\n";
        logDebug("Starting allocation for project {$proj} with " . count($leads) . " leads");

        $state = prepareAssignmentState($proj);
        if (empty($state)) {
            logDebug("No eligible users for project {$proj}; skipping assignment of " . count($leads) . " leads");
            if ($show_import_status) echo "No eligible users for {$proj}, skipping.\n";
            continue;
        }

        foreach ($leads as $lead) {
            // extract fields
            $phone = null; $name = null; $created = strtotime($lead['created_time'] ?? 'now');
            $additional = [];
            if (!empty($lead['field_data'])) {
                foreach ($lead['field_data'] as $f) {
                    $k = $f['name'] ?? null; $v = $f['values'][0] ?? null;
                    if (!$k) continue;
                    $additional[$k] = $v;
                    if (in_array($k, ['phone','phone_number'])) $phone = $v;
                    if (in_array($k, ['name','full_name'])) $name = $v;
                }
            }
            if (empty($phone) || empty($name)) {
                if ($show_import_status) echo "Skipping lead {$lead['id']}: missing name/phone.\n";
                continue;
            }
            $phone_number = preg_replace('/[^0-9]/','', $phone);
            $threadId = $lead['id'] ?? null;
            if (!$threadId) { if ($show_import_status) echo "Skipping lead (no thread id)\n"; continue; }
            // check duplicate by thread_id
            $exists = $db->where('thread_id', $threadId)->getOne(T_LEADS, ['lead_id']);
            if ($exists) { if ($show_import_status) echo "Lead {$threadId} already exists, skipping.\n"; continue; }

            // phone override: try to give to previous member if recent and still eligible
            $selected_user = null;
            $prev = $db->where('phone', $phone_number)->where('member', '1', '!=')->getOne(T_LEADS, ['assigned','member','time']);
            if ($prev && ($prev->assigned > 0 || $prev->member > 0)) {
                $prev_member = (int)$prev->member;
                $prev_leader = (int)$prev->assigned;
                $prev_time = (int)($prev->time ?? 0);
                $recentThreshold = strtotime('-' . PHONE_OVERRIDE_MAX_DAYS . ' days');
                $thisQuotasRaw = loadAssignmentQuotas($proj);
                $eligiblePrev = eligibleUsersForProject($proj, $thisQuotasRaw);
                $hasQuota = isset($thisQuotasRaw[$prev_member]) && ((int)$thisQuotasRaw[$prev_member] > 0);
                $isRecent = ($prev_time >= $recentThreshold);
                if (isset($eligiblePrev[$prev_member]) && $hasQuota && $isRecent) {
                    $selected_user = ['user_id' => $prev_member, 'leader_id' => $prev_leader];
                }
            }

            if ($selected_user === null) {
                $selected_user = pickFromState($state, $proj);
            }

            // finalize DB row
            $data = [
                'source' => 'Facebook',
                'phone' => $phone_number,
                'name' => $name,
                'profession' => $additional['job_title'] ?? '',
                'company' => $additional['company'] ?? '',
                'email' => $additional['email'] ?? 'N/A',
                'project' => $proj,
                'additional' => json_encode(array_merge($additional, [
                    'form_name' => $leadsData['name'] ?? 'N/A',
                    'page_id' => $leadsData['page']['id'] ?? 'N/A',
                    'page_name' => $leadsData['page']['name'] ?? 'N/A',
                    'thread_id' => $threadId,
                ])),
                'created' => $created,
                'given_date' => $created,
                'thread_id' => $threadId,
                'assigned' => intval($selected_user['leader_id'] ?? 0),
                'member' => intval($selected_user['user_id'] ?? 0),
                'page_id' => $leadsData['page']['id'] ?? '0',
                'time' => time(),
            ];

            // insert and notify in transaction
            $db->startTransaction();
            try {
                $insert_id = $db->insert(T_LEADS, $data);
                if (!$insert_id) throw new Exception("Insert failed: " . $db->getLastError());

                // notification
                $notification_user = $data['member'] > 0 ? $data['member'] : $data['assigned'];
                $leadUrl = "/management/leads?lead_id={$insert_id}";
                $subject = "New Lead: {$data['name']}";
                $comment = "You have a new lead from {$proj}";

                if ($notification_user) notifyUser($db, $notification_user, $subject, $comment, $leadUrl);

                // notify leader if different and leader is set
                if ($notification_user && intval($notification_user) !== intval($data['assigned']) && intval($data['assigned']) > 0) {
                    $leaderId = intval($data['assigned']);
                    $nameRow = $db->where('user_id', $notification_user)->getOne(T_USERS, ['first_name','last_name']);
                    $commentLeader = ($nameRow ? ($nameRow->first_name . ' ' . $nameRow->last_name) : 'A user') . " has a new lead.";
                    notifyUser($db, $leaderId, $subject, $commentLeader, $leadUrl);
                }

                $db->commit();

                // update in-memory state so next pick changes
                updateStateAfterAssign($state, $proj, intval($data['member'] ?? 0));

                if ($show_import_status) {
                    echo "Inserted lead {$insert_id}, assigned member {$data['member']} leader {$data['assigned']}\n";
                }

            } catch (Exception $e) {
                $db->rollback();
                logDebug("allocateLeadsBatch: failed to insert/notify lead {$threadId}: " . $e->getMessage());
                if ($show_import_status) echo "Failed to insert lead {$threadId}: " . $e->getMessage() . "\n";
            }
        } // end leads loop
    } // end groups loop
}

// ----------------- PROCESS FACEBOOK CONFIG & RUN -----------------

$api_config = read_fb_api_setup_data();
if ($show_import_status) echo '<pre style="background:#f6f6f6;padding:10px;border-radius:6px;">';

if (empty($api_config) || empty($api_config['leads'])) {
    if ($show_import_status) echo "No FB API config or leads disabled.\n";
    logDebug("No FB API config or leads disabled.");
} else {
    foreach ($api_config['leads'] as $page_id => $cfg) {
        if (!isset($cfg['status']) || $cfg['status'] !== '1') continue;
        $pageCfg = $api_config['pages'][$page_id] ?? null;
        if (!$pageCfg) {
            logDebug("Missing page config for {$page_id}");
            continue;
        }
        $token = $pageCfg['access_token'] ?? null;
        if (!$token) { logDebug("No access token for page {$page_id}"); continue; }

        $requests = [];
        foreach ($cfg['form_id'] as $form_id) {
            // Use a conservative fields set for stability
            $fields = "created_time,leads_count,page,page_id,organic_leads_count,name,leads.limit(150){ad_name,created_time,field_data,form_id,id,platform},id,status";
            $requests[] = $fb_connect->request('GET', "/{$form_id}?fields={$fields}");
        }

        try {
            $responses = $fb_connect->sendBatchRequest($requests, $token)->getDecodedBody();
            if ($responses) {
                foreach ($responses as $response) {
                    $leadsData = json_decode($response['body'], true);
                    if (!empty($leadsData['leads']['data'])) {
                        $incoming = $leadsData['leads']['data'];
                        // allocate this form's leads as a batch (keeps group by project inside)
                        allocateLeadsBatch($incoming, $leadsData);
                    } else {
                        if ($show_import_status) echo "No leads for one form.\n";
                    }
                }
            }
        } catch (Exception $e) {
            logDebug("Facebook batch error for page {$page_id}: " . $e->getMessage());
            if ($show_import_status) echo "FB error for page {$page_id}: " . $e->getMessage() . "\n";
        }
    }
}

if ($show_import_status) echo '</pre>';
?>
