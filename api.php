<?php
/**
 * Backend API - Dashboard Presenze Universitarie
 * Gestione Autenticazione, Profilo, Sessioni, Dati Utente e Web Push Notifications
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$dataDir = __DIR__ . '/data';
$usersDir = $dataDir . '/users';
$vapidFile = $dataDir . '/vapid.json';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
    @file_put_contents($dataDir . '/.htaccess', "Deny from all\n");
}
if (!is_dir($usersDir)) {
    @mkdir($usersDir, 0755, true);
}

function sendResponse($status, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(array_merge(['status' => $status, 'app_version' => '2026.11.18-1'], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function sanitizeUsername($username) {
    $clean = strtolower(trim($username));
    $clean = preg_replace('/[^a-z0-9_.-]/', '', $clean);
    return $clean;
}

function getUserFilePath($username) {
    global $usersDir;
    $clean = sanitizeUsername($username);
    if (empty($clean)) return null;
    return $usersDir . '/' . $clean . '.json';
}

function generateToken($username) {
    $bytes = random_bytes(24);
    $rawToken = bin2hex($bytes);
    $timestamp = time();
    return $username . ':' . $timestamp . ':' . $rawToken;
}

function verifyUserAuthToken($token) {
    if (empty($token) || !is_string($token)) return false;
    $parts = explode(':', $token);
    if (count($parts) < 3) return false;

    $username = sanitizeUsername($parts[0]);
    $filePath = getUserFilePath($username);
    if (!$filePath || !file_exists($filePath)) return false;

    $userRaw = @file_get_contents($filePath);
    $userData = @json_decode($userRaw, true);
    if (!$userData || !isset($userData['session_tokens'])) return false;

    if (in_array($token, $userData['session_tokens'])) {
        return ['username' => $username, 'data' => $userData, 'path' => $filePath];
    }
    return false;
}

function getPushDeviceSummaries($userData) {
    $subscriptions = isset($userData['push_subscriptions']) && is_array($userData['push_subscriptions']) ? $userData['push_subscriptions'] : [];
    if (!empty($userData['push_subscription']) && is_array($userData['push_subscription'])) $subscriptions[] = $userData['push_subscription'];
    $unique = [];
    foreach ($subscriptions as $subscription) {
        if (is_array($subscription) && !empty($subscription['endpoint'])) $unique[$subscription['endpoint']] = $subscription;
    }
    $defaults = $userData['settings'] ?? [];
    $devices = [];
    foreach ($unique as $endpoint => $subscription) {
        $deviceId = substr(hash('sha256', $endpoint), 0, 16);
        $deviceSettings = isset($subscription['notification_settings']) && is_array($subscription['notification_settings']) ? $subscription['notification_settings'] : [];
        $endpointHost = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
        $fallbackBrowser = str_contains($endpointHost, 'push.apple.com') ? 'Safari' : (str_contains($endpointHost, 'fcm.googleapis.com') ? 'Chrome' : 'Browser');
        $fallbackPlatform = str_contains($endpointHost, 'push.apple.com') ? 'iOS' : '';
        $fallbackName = $fallbackBrowser === 'Safari' ? 'Safari · iOS' : ($fallbackBrowser === 'Chrome' ? 'Chrome · dispositivo' : 'Dispositivo registrato');
        $lastReceipt = null;
        if (isset($userData['push_receipts']) && is_array($userData['push_receipts'])) {
            foreach (array_reverse($userData['push_receipts']) as $receipt) {
                if (($receipt['device_id'] ?? '') === $deviceId) { $lastReceipt = $receipt; break; }
            }
        }
        $devices[] = [
            'id' => $deviceId,
            'name' => $subscription['device_name'] ?? $fallbackName,
            'browser' => $subscription['device_browser'] ?? $fallbackBrowser,
            'platform' => $subscription['device_platform'] ?? $fallbackPlatform,
            'last_receipt' => $lastReceipt ? ['stage' => $lastReceipt['stage'] ?? '', 'received_at' => $lastReceipt['received_at'] ?? '', 'error' => $lastReceipt['error'] ?? ''] : null,
            'notification_settings' => [
                'notify_attendance_start' => array_key_exists('notify_attendance_start', $deviceSettings) ? (bool)$deviceSettings['notify_attendance_start'] : (($defaults['notify_attendance_start'] ?? true) !== false),
                'notify_pre_lecture' => array_key_exists('notify_pre_lecture', $deviceSettings) ? (bool)$deviceSettings['notify_pre_lecture'] : (($defaults['notify_pre_lecture'] ?? true) !== false),
                'pre_lecture_minutes' => (int)($deviceSettings['pre_lecture_minutes'] ?? $defaults['pre_lecture_minutes'] ?? 15)
            ],
            'last_seen' => $subscription['device_last_seen'] ?? null
        ];
    }
    return $devices;
}

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];

$action = isset($_GET['action']) ? trim($_GET['action']) : (isset($jsonInput['action']) ? trim($jsonInput['action']) : '');

$authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? trim($_SERVER['HTTP_AUTHORIZATION']) : '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $tokenFromHeader = substr($authHeader, 7);
} else {
    $tokenFromHeader = isset($jsonInput['token']) ? trim($jsonInput['token']) : (isset($_GET['token']) ? trim($_GET['token']) : '');
}

switch ($action) {
    case 'get_vapid_public_key':
        if (!file_exists($vapidFile)) {
            sendResponse('error', ['message' => 'Configurazione VAPID non presente sul server'], 500);
        }
        $vapidData = @json_decode(file_get_contents($vapidFile), true);
        if (!$vapidData || empty($vapidData['public_key'])) {
            sendResponse('error', ['message' => 'Chiave pubblica VAPID non valida'], 500);
        }
        sendResponse('success', ['publicKey' => $vapidData['public_key']]);
        break;

    case 'register':
        $username = isset($jsonInput['username']) ? sanitizeUsername($jsonInput['username']) : '';
        $password = isset($jsonInput['password']) ? (string)$jsonInput['password'] : '';
        $displayName = isset($jsonInput['displayName']) ? trim(strip_tags($jsonInput['displayName'])) : '';

        if (strlen($username) < 3) {
            sendResponse('error', ['message' => 'Username troppo breve (minimo 3 caratteri alfanumerici)'], 400);
        }
        if (strlen($password) < 6) {
            sendResponse('error', ['message' => 'Password troppo debole (minimo 6 caratteri)'], 400);
        }

        $filePath = getUserFilePath($username);
        if (file_exists($filePath)) {
            sendResponse('error', ['message' => 'Questo username è già in uso. Prova ad effettuare il login.'], 409);
        }

        $token = generateToken($username);
        $userData = [
            'username' => $username,
            'displayName' => $displayName ?: $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'session_tokens' => [$token],
            'courses' => [],
            'counted_ics_events' => [],
            'attended_ics_events' => [],
            'lesson_responses' => [],
            'settings' => [
                'auto_sync_ics' => true,
                'notify_attendance_start' => true,
                'notify_pre_lecture' => true,
                'pre_lecture_minutes' => 15,
                'pending_lesson_reminder_enabled' => true,
                'pending_lesson_reminder_days' => [1, 2, 3, 4, 5],
                'pending_lesson_reminder_time' => '18:00'
            ],
            'push_subscription' => null
        ];

        file_put_contents($filePath, json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        sendResponse('success', [
            'message' => 'Registrazione completata con successo!',
            'token' => $token,
            'user' => [
                'username' => $username,
                'displayName' => $userData['displayName'],
                'courses' => [],
                'counted_ics_events' => [],
                'attended_ics_events' => [],
                'lesson_responses' => [],
                'settings' => $userData['settings'],
                'hasPush' => false,
                'isNewUser' => true
            ]
        ]);
        break;

    case 'login':
        $username = isset($jsonInput['username']) ? sanitizeUsername($jsonInput['username']) : '';
        $password = isset($jsonInput['password']) ? (string)$jsonInput['password'] : '';

        if (empty($username) || empty($password)) {
            sendResponse('error', ['message' => 'Inserisci username e password'], 400);
        }

        $filePath = getUserFilePath($username);
        if (!$filePath || !file_exists($filePath)) {
            sendResponse('error', ['message' => 'Credenziali non valide o utente inesistente'], 401);
        }

        $userRaw = @file_get_contents($filePath);
        $userData = @json_decode($userRaw, true);
        if (!$userData || !isset($userData['password_hash']) || !password_verify($password, $userData['password_hash'])) {
            sendResponse('error', ['message' => 'Credenziali errate'], 401);
        }

        $token = generateToken($username);
        if (!isset($userData['session_tokens']) || !is_array($userData['session_tokens'])) {
            $userData['session_tokens'] = [];
        }
        if (count($userData['session_tokens']) > 10) {
            array_shift($userData['session_tokens']);
        }
        $userData['session_tokens'][] = $token;
        $userData['last_login'] = date('c');

        file_put_contents($filePath, json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $courses = isset($userData['courses']) && is_array($userData['courses']) ? $userData['courses'] : [];
        $settings = isset($userData['settings']) && is_array($userData['settings']) ? $userData['settings'] : [
            'auto_sync_ics' => true,
            'notify_attendance_start' => true,
            'notify_pre_lecture' => true,
                'pre_lecture_minutes' => 15,
                'pending_lesson_reminder_enabled' => true,
                'pending_lesson_reminder_days' => [1,2,3,4,5],
                'pending_lesson_reminder_time' => '18:00'
        ];

        sendResponse('success', [
            'message' => 'Login effettuato!',
            'token' => $token,
            'user' => [
                'username' => $userData['username'],
                'displayName' => $userData['displayName'] ?? $userData['username'],
                'courses' => $courses,
                'counted_ics_events' => isset($userData['counted_ics_events']) && is_array($userData['counted_ics_events']) ? array_values(array_unique($userData['counted_ics_events'])) : [],
                'attended_ics_events' => isset($userData['attended_ics_events']) && is_array($userData['attended_ics_events']) ? array_values(array_unique($userData['attended_ics_events'])) : [],
                'lesson_responses' => isset($userData['lesson_responses']) && is_array($userData['lesson_responses']) ? $userData['lesson_responses'] : (object)[],
                'settings' => $settings,
                'hasPush' => !empty($userData['push_subscription']) || !empty($userData['push_subscriptions']),
                'push_devices' => getPushDeviceSummaries($userData),
                'isNewUser' => empty($courses)
            ]
        ]);
        break;

    case 'logout':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if ($auth) {
            $userData = $auth['data'];
            $userData['session_tokens'] = array_values(array_filter($userData['session_tokens'], function($t) use ($tokenFromHeader) {
                return $t !== $tokenFromHeader;
            }));
            file_put_contents($auth['path'], json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        sendResponse('success', ['message' => 'Disconnesso con successo']);
        break;

    case 'get_data':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) {
            sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        }

        $userData = $auth['data'];
        $courses = isset($userData['courses']) && is_array($userData['courses']) ? $userData['courses'] : [];
        $settings = isset($userData['settings']) && is_array($userData['settings']) ? $userData['settings'] : [
            'auto_sync_ics' => true,
            'notify_attendance_start' => true,
            'notify_pre_lecture' => true,
            'pre_lecture_minutes' => 15
        ];

        sendResponse('success', [
            'user' => [
                'username' => $userData['username'],
                'displayName' => $userData['displayName'] ?? $userData['username'],
                'courses' => $courses,
                'counted_ics_events' => isset($userData['counted_ics_events']) && is_array($userData['counted_ics_events']) ? array_values(array_unique($userData['counted_ics_events'])) : [],
                'attended_ics_events' => isset($userData['attended_ics_events']) && is_array($userData['attended_ics_events']) ? array_values(array_unique($userData['attended_ics_events'])) : [],
                'lesson_responses' => isset($userData['lesson_responses']) && is_array($userData['lesson_responses']) ? $userData['lesson_responses'] : (object)[],
                'settings' => $settings,
                'hasPush' => !empty($userData['push_subscription']) || !empty($userData['push_subscriptions']),
                'push_devices' => getPushDeviceSummaries($userData),
                'updated_at' => $userData['updated_at'] ?? $userData['created_at'] ?? date('c'),
                'isNewUser' => empty($courses)
            ]
        ]);
        break;

    case 'save_data':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) {
            sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        }

        $userData = $auth['data'];
        $courses = isset($jsonInput['courses']) && is_array($jsonInput['courses']) ? $jsonInput['courses'] : null;
        $settings = isset($jsonInput['settings']) && is_array($jsonInput['settings']) ? $jsonInput['settings'] : null;
        $countedIcsEvents = isset($jsonInput['counted_ics_events']) && is_array($jsonInput['counted_ics_events']) ? array_values(array_unique(array_filter($jsonInput['counted_ics_events'], 'is_string'))) : null;
        $attendedIcsEvents = isset($jsonInput['attended_ics_events']) && is_array($jsonInput['attended_ics_events']) ? array_values(array_unique(array_filter($jsonInput['attended_ics_events'], 'is_string'))) : null;
        $lessonResponses = isset($jsonInput['lesson_responses']) && is_array($jsonInput['lesson_responses']) ? $jsonInput['lesson_responses'] : null;

        if ($courses !== null) {
            $userData['courses'] = $courses;
        }
        if ($settings !== null) {
            $userData['settings'] = array_merge($userData['settings'] ?? [], $settings);
        }
        if ($countedIcsEvents !== null) {
            $existingCounted = isset($userData['counted_ics_events']) && is_array($userData['counted_ics_events']) ? $userData['counted_ics_events'] : [];
            $userData['counted_ics_events'] = array_values(array_unique(array_merge($existingCounted, $countedIcsEvents)));
        } elseif (!isset($userData['counted_ics_events']) || !is_array($userData['counted_ics_events'])) {
            $userData['counted_ics_events'] = [];
        }
        if ($attendedIcsEvents !== null) {
            $existingAttended = isset($userData['attended_ics_events']) && is_array($userData['attended_ics_events']) ? $userData['attended_ics_events'] : [];
            $userData['attended_ics_events'] = array_values(array_unique(array_merge($existingAttended, $attendedIcsEvents)));
        } elseif (!isset($userData['attended_ics_events']) || !is_array($userData['attended_ics_events'])) {
            $userData['attended_ics_events'] = [];
        }
        if ($lessonResponses !== null) {
            $userData['lesson_responses'] = array_merge($userData['lesson_responses'] ?? [], $lessonResponses);
        } elseif (!isset($userData['lesson_responses']) || !is_array($userData['lesson_responses'])) {
            $userData['lesson_responses'] = [];
        }
        $userData['updated_at'] = date('c');

        file_put_contents($auth['path'], json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        sendResponse('success', [
            'message' => 'Dati sincronizzati sul server',
            'updated_at' => $userData['updated_at']
        ]);
        break;

    case 'update_profile':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) {
            sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        }

        $userData = $auth['data'];
        $oldPath = $auth['path'];
        $currentUsername = $auth['username'];

        $newDisplayName = isset($jsonInput['displayName']) ? trim(strip_tags($jsonInput['displayName'])) : null;
        $newUsername = isset($jsonInput['username']) ? sanitizeUsername($jsonInput['username']) : null;
        $newPassword = isset($jsonInput['password']) ? (string)$jsonInput['password'] : null;
        $settingsInput = isset($jsonInput['settings']) && is_array($jsonInput['settings']) ? $jsonInput['settings'] : null;

        if ($newDisplayName !== null && strlen($newDisplayName) > 0) {
            $userData['displayName'] = $newDisplayName;
        }

        if ($settingsInput !== null) {
            $userData['settings'] = array_merge($userData['settings'] ?? [], $settingsInput);
        }

        if (!empty($newPassword)) {
            if (strlen($newPassword) < 6) {
                sendResponse('error', ['message' => 'La nuova password deve contenere almeno 6 caratteri'], 400);
            }
            $userData['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $newToken = $tokenFromHeader;

        if (!empty($newUsername) && $newUsername !== $currentUsername) {
            if (strlen($newUsername) < 3) {
                sendResponse('error', ['message' => 'Il nuovo username deve contenere almeno 3 caratteri'], 400);
            }
            $newPath = getUserFilePath($newUsername);
            if (file_exists($newPath)) {
                sendResponse('error', ['message' => 'Lo username scelto è già occupato da un altro account'], 409);
            }

            $userData['username'] = $newUsername;
            $newToken = generateToken($newUsername);
            $userData['session_tokens'] = [$newToken];
            $userData['updated_at'] = date('c');

            file_put_contents($newPath, json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            @unlink($oldPath);
        } else {
            $userData['updated_at'] = date('c');
            file_put_contents($oldPath, json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        sendResponse('success', [
            'message' => 'Profilo aggiornato con successo!',
            'token' => $newToken,
            'user' => [
                'username' => $userData['username'],
                'displayName' => $userData['displayName'],
                'courses' => $userData['courses'] ?? [],
                'counted_ics_events' => isset($userData['counted_ics_events']) && is_array($userData['counted_ics_events']) ? array_values(array_unique($userData['counted_ics_events'])) : [],
                'attended_ics_events' => isset($userData['attended_ics_events']) && is_array($userData['attended_ics_events']) ? array_values(array_unique($userData['attended_ics_events'])) : [],
                'lesson_responses' => isset($userData['lesson_responses']) && is_array($userData['lesson_responses']) ? $userData['lesson_responses'] : (object)[],
                'settings' => $userData['settings'] ?? [],
                'hasPush' => !empty($userData['push_subscription']) || !empty($userData['push_subscriptions']),
                'push_devices' => getPushDeviceSummaries($userData)
            ]
        ]);
        break;

    case 'save_push_subscription':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) {
            sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        }

        $userData = $auth['data'];
        $subscription = isset($jsonInput['subscription']) ? $jsonInput['subscription'] : null;

        if (!$subscription || !is_array($subscription) || empty($subscription['endpoint'])) {
            sendResponse('error', ['message' => 'Dati sottoscrizione push non validi'], 400);
        }

        $deviceId = substr(hash('sha256', $subscription['endpoint']), 0, 16);
        $disabledDevices = isset($userData['disabled_push_devices']) && is_array($userData['disabled_push_devices']) ? $userData['disabled_push_devices'] : [];
        $reactivate = !empty($jsonInput['reactivate']);
        if (isset($disabledDevices[$deviceId]) && !$reactivate) {
            sendResponse('success', ['message' => 'Questo dispositivo è stato rimosso dal profilo', 'disabled' => true, 'push_devices' => getPushDeviceSummaries($userData)]);
        }
        if ($reactivate) unset($disabledDevices[$deviceId]);
        $userData['disabled_push_devices'] = $disabledDevices;
        $subscriptions = isset($userData['push_subscriptions']) && is_array($userData['push_subscriptions']) ? $userData['push_subscriptions'] : [];
        if (!empty($userData['push_subscription']) && is_array($userData['push_subscription'])) $subscriptions[] = $userData['push_subscription'];
        $existing = null;
        foreach ($subscriptions as $item) {
            if (is_array($item) && ($item['endpoint'] ?? '') === $subscription['endpoint']) { $existing = $item; break; }
        }
        $device = isset($jsonInput['device']) && is_array($jsonInput['device']) ? $jsonInput['device'] : [];
        $subscription['device_name'] = trim(strip_tags((string)($device['name'] ?? ($existing['device_name'] ?? 'Dispositivo registrato'))));
        $subscription['device_browser'] = trim(strip_tags((string)($device['browser'] ?? ($existing['device_browser'] ?? ''))));
        $subscription['device_platform'] = trim(strip_tags((string)($device['platform'] ?? ($existing['device_platform'] ?? ''))));
        $subscription['device_last_seen'] = date('c');
        $defaults = $userData['settings'] ?? [];
        $savedDeviceSettings = isset($existing['notification_settings']) && is_array($existing['notification_settings']) ? $existing['notification_settings'] : [];
        if (!$savedDeviceSettings) {
            $savedDeviceSettings = [
                'notify_attendance_start' => ($defaults['notify_attendance_start'] ?? true) !== false,
                'notify_pre_lecture' => ($defaults['notify_pre_lecture'] ?? true) !== false,
                'pre_lecture_minutes' => (int)($defaults['pre_lecture_minutes'] ?? 15)
            ];
        }
        $subscription['notification_settings'] = $savedDeviceSettings;
        if (isset($jsonInput['device_notifications']) && is_array($jsonInput['device_notifications'])) {
            $incoming = $jsonInput['device_notifications'];
            foreach (['notify_attendance_start', 'notify_pre_lecture', 'notify_evening'] as $key) if (array_key_exists($key, $incoming)) $subscription['notification_settings'][$key] = (bool)$incoming[$key];
            if (isset($incoming['pre_lecture_minutes'])) $subscription['notification_settings']['pre_lecture_minutes'] = max(5, min(180, (int)$incoming['pre_lecture_minutes']));
        }
        $unique = [];
        foreach ($subscriptions as $item) if (is_array($item) && !empty($item['endpoint']) && $item['endpoint'] !== $subscription['endpoint']) $unique[$item['endpoint']] = $item;
        $unique[$subscription['endpoint']] = $subscription;
        $userData['push_subscriptions'] = array_values($unique);
        $userData['push_subscription'] = $subscription; // compatibilità con versioni precedenti
        if (isset($jsonInput['settings']) && is_array($jsonInput['settings'])) {
            $userData['settings'] = array_merge($userData['settings'] ?? [], $jsonInput['settings']);
        }
        $userData['updated_at'] = date('c');

        file_put_contents($auth['path'], json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        sendResponse('success', ['message' => 'Sottoscrizione push attivata sul dispositivo!', 'push_devices' => getPushDeviceSummaries($userData)]);
        break;

    case 'update_push_device':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        $userData = $auth['data'];
        $deviceId = isset($jsonInput['device_id']) ? (string)$jsonInput['device_id'] : '';
        $incoming = isset($jsonInput['notification_settings']) && is_array($jsonInput['notification_settings']) ? $jsonInput['notification_settings'] : [];
        $all = isset($userData['push_subscriptions']) && is_array($userData['push_subscriptions']) ? $userData['push_subscriptions'] : [];
        if (!empty($userData['push_subscription']) && is_array($userData['push_subscription'])) $all[] = $userData['push_subscription'];
        $unique = []; $found = false;
        foreach ($all as $subscription) {
            if (!is_array($subscription) || empty($subscription['endpoint'])) continue;
            $id = substr(hash('sha256', $subscription['endpoint']), 0, 16);
            if ($id === $deviceId) {
                $settings = isset($subscription['notification_settings']) && is_array($subscription['notification_settings']) ? $subscription['notification_settings'] : [];
                foreach (['notify_attendance_start', 'notify_pre_lecture', 'notify_evening'] as $key) if (array_key_exists($key, $incoming)) $settings[$key] = (bool)$incoming[$key];
                if (isset($incoming['pre_lecture_minutes'])) $settings['pre_lecture_minutes'] = max(5, min(180, (int)$incoming['pre_lecture_minutes']));
                $subscription['notification_settings'] = $settings;
                $subscription['device_last_seen'] = date('c');
                $found = true;
            }
            $unique[$subscription['endpoint']] = $subscription;
        }
        if (!$found) sendResponse('error', ['message' => 'Dispositivo non trovato'], 404);
        $userData['push_subscriptions'] = array_values($unique);
        $userData['push_subscription'] = end($userData['push_subscriptions']) ?: null;
        $userData['updated_at'] = date('c');
        file_put_contents($auth['path'], json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        sendResponse('success', ['message' => 'Preferenze del dispositivo salvate', 'push_devices' => getPushDeviceSummaries($userData)]);
        break;

    case 'delete_push_device':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        $userData = $auth['data'];
        $deviceId = isset($jsonInput['device_id']) ? (string)$jsonInput['device_id'] : '';
        $all = isset($userData['push_subscriptions']) && is_array($userData['push_subscriptions']) ? $userData['push_subscriptions'] : [];
        if (!empty($userData['push_subscription']) && is_array($userData['push_subscription'])) $all[] = $userData['push_subscription'];
        $disabledDevices = isset($userData['disabled_push_devices']) && is_array($userData['disabled_push_devices']) ? $userData['disabled_push_devices'] : [];
        $unique = []; $removed = false;
        foreach ($all as $subscription) {
            if (!is_array($subscription) || empty($subscription['endpoint'])) continue;
            $id = substr(hash('sha256', $subscription['endpoint']), 0, 16);
            if ($id === $deviceId) { $removed = true; $disabledDevices[$deviceId] = date('c'); continue; }
            $unique[$subscription['endpoint']] = $subscription;
        }
        if (!$removed) sendResponse('error', ['message' => 'Dispositivo non trovato'], 404);
        $userData['push_subscriptions'] = array_values($unique);
        $userData['push_subscription'] = !empty($userData['push_subscriptions']) ? end($userData['push_subscriptions']) : null;
        $userData['disabled_push_devices'] = $disabledDevices;
        $userData['updated_at'] = date('c');
        file_put_contents($auth['path'], json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        sendResponse('success', ['message' => 'Dispositivo rimosso. Non riceverà più notifiche.', 'push_devices' => getPushDeviceSummaries($userData)]);
        break;

    case 'unsubscribe_push':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) {
            sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        }

        $userData = $auth['data'];
        $endpoint = isset($jsonInput['endpoint']) && is_string($jsonInput['endpoint']) ? $jsonInput['endpoint'] : '';
        if ($endpoint !== '') {
            $subscriptions = isset($userData['push_subscriptions']) && is_array($userData['push_subscriptions']) ? $userData['push_subscriptions'] : [];
            if (!empty($userData['push_subscription']) && is_array($userData['push_subscription'])) $subscriptions[] = $userData['push_subscription'];
            $subscriptions = array_values(array_filter($subscriptions, function($item) use ($endpoint) {
                return is_array($item) && !empty($item['endpoint']) && $item['endpoint'] !== $endpoint;
            }));
            $userData['push_subscriptions'] = $subscriptions;
            $userData['push_subscription'] = !empty($subscriptions) ? end($subscriptions) : null;
        } else {
            $userData['push_subscriptions'] = [];
            $userData['push_subscription'] = null;
        }
        $userData['updated_at'] = date('c');

        file_put_contents($auth['path'], json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        sendResponse('success', ['message' => 'Notifiche push disattivate']);
        break;

    case 'push_receipt':
        $receipt = isset($jsonInput['receipt']) && is_array($jsonInput['receipt']) ? $jsonInput['receipt'] : [];
        $username = isset($receipt['username']) ? sanitizeUsername($receipt['username']) : '';
        $deviceId = isset($receipt['device_id']) ? (string)$receipt['device_id'] : '';
        $deliveryId = isset($receipt['delivery_id']) ? (string)$receipt['delivery_id'] : '';
        $sentAt = isset($receipt['sent_at']) ? (string)$receipt['sent_at'] : '';
        $signature = isset($receipt['signature']) ? (string)$receipt['signature'] : '';
        $stage = isset($jsonInput['stage']) ? (string)$jsonInput['stage'] : '';
        $workerEventAt = isset($jsonInput['worker_event_at']) ? substr((string)$jsonInput['worker_event_at'], 0, 40) : '';
        if (!$username || !$deviceId || !$deliveryId || !ctype_digit($sentAt) || !in_array($stage, ['received', 'shown', 'show_error'], true)) {
            sendResponse('error', ['message' => 'Ricevuta push non valida'], 400);
        }
        $receiptSecret = @file_get_contents(__DIR__ . '/data/push_receipt_secret');
        if (!$receiptSecret) sendResponse('error', ['message' => 'Firma ricevuta non configurata'], 500);
        $message = implode('|', [$username, $deviceId, $deliveryId, $sentAt]);
        $expected = hash_hmac('sha256', $message, trim($receiptSecret));
        if (!hash_equals($expected, $signature)) sendResponse('error', ['message' => 'Firma ricevuta non valida'], 403);
        $userPath = getUserFilePath($username);
        $userData = $userPath && file_exists($userPath) ? @json_decode(@file_get_contents($userPath), true) : null;
        if (!$userData) sendResponse('error', ['message' => 'Profilo non trovato'], 404);
        $activeIds = array_column(getPushDeviceSummaries($userData), 'id');
        if (!in_array($deviceId, $activeIds, true)) sendResponse('error', ['message' => 'Dispositivo non attivo'], 403);
        $receipts = isset($userData['push_receipts']) && is_array($userData['push_receipts']) ? $userData['push_receipts'] : [];
        $record = [
            'device_id' => $deviceId,
            'delivery_id' => $deliveryId,
            'sent_at' => (int)$sentAt,
            'received_at' => date('c'),
            'worker_event_at' => $workerEventAt,
            'stage' => $stage,
            'error' => isset($jsonInput['error']) ? substr(strip_tags((string)$jsonInput['error']), 0, 300) : ''
        ];
        $receipts[] = $record;
        $userData['push_receipts'] = array_slice($receipts, -100);
        @file_put_contents($userPath, json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        sendResponse('success', ['message' => 'Ricevuta registrata']);
        break;

    case 'send_test_push':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) {
            sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        }

        $userData = $auth['data'];
        $subscriptions = isset($userData['push_subscriptions']) && is_array($userData['push_subscriptions']) ? $userData['push_subscriptions'] : [];
        if (!empty($userData['push_subscription']) && is_array($userData['push_subscription'])) $subscriptions[] = $userData['push_subscription'];
        $uniqueSubscriptions = [];
        foreach ($subscriptions as $item) {
            if (is_array($item) && !empty($item['endpoint'])) $uniqueSubscriptions[$item['endpoint']] = $item;
        }
        $subscriptions = array_values($uniqueSubscriptions);
        if (empty($subscriptions)) {
            sendResponse('error', ['message' => 'Nessuna sottoscrizione push attiva per questo account. Attiva prima le notifiche.'], 400);
        }

        $payload = [
            'title' => 'Test Notifiche Push 🔔',
            'body' => 'Complimenti! Le notifiche Web Push per UniPresenze sono configurate correttamente.',
            'url' => 'https://lezioni.rsessa.it/'
        ];

        $pyScript = __DIR__ . '/send_single_push.py';
        $pyVenv = __DIR__ . '/push_venv/bin/python3';
        if (!file_exists($pyVenv) || !file_exists($pyScript)) {
            sendResponse('error', ['message' => 'Ambiente Python per notifiche push non pronto'], 500);
        }
        $sent = 0;
        $errors = [];
        foreach ($subscriptions as $subscription) {
            $cmd = escapeshellcmd($pyVenv) . ' ' . escapeshellarg($pyScript) . ' ' . escapeshellarg(json_encode($subscription)) . ' ' . escapeshellarg(json_encode($payload));
            $output = shell_exec($cmd);
            $res = @json_decode($output, true);
            if ($res && ($res['status'] ?? '') === 'success') $sent++;
            else $errors[] = $res['message'] ?? 'Errore sconosciuto';
        }
        if ($sent > 0) sendResponse('success', ['message' => "Notifica di test inviata a {$sent} dispositivo/i.", 'sent' => $sent]);
        sendResponse('error', ['message' => 'Invio fallito: ' . implode('; ', array_unique($errors))], 500);
        break;

    case 'delete_account':
        $auth = verifyUserAuthToken($tokenFromHeader);
        if (!$auth) {
            sendResponse('error', ['message' => 'Sessione non valida o scaduta'], 401);
        }

        if (file_exists($auth['path'])) {
            unlink($auth['path']);
        }

        sendResponse('success', ['message' => 'Account e dati eliminati definitivamente']);
        break;

    default:
        sendResponse('error', [
            'message' => 'Azione non riconosciuta',
            'available_actions' => ['register', 'login', 'logout', 'get_data', 'save_data', 'update_profile', 'get_vapid_public_key', 'save_push_subscription', 'update_push_device', 'delete_push_device', 'unsubscribe_push', 'send_test_push', 'push_receipt', 'delete_account']
        ], 400);
        break;
}
