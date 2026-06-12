<?php
/**
 * AJAX backend handler for Newt plugin configuration.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/common.php';

// CSRF check
$varFile = '/var/local/emhttp/var.ini';
$csrfToken = '';
if (is_readable($varFile)) {
    $ini = parse_ini_file($varFile) ?: [];
    $csrfToken = (string) ($ini['csrf_token'] ?? '');
}

$postToken = (string) ($_POST['csrf_token'] ?? '');
if ($csrfToken !== '' && $postToken !== $csrfToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'save':
        $updates = [
            'ENABLE_NEWT'       => (string) ($_POST['ENABLE_NEWT'] ?? '0'),
            'PANGOLIN_ENDPOINT' => trim((string) ($_POST['PANGOLIN_ENDPOINT'] ?? '')),
            'NEWT_ID'           => trim((string) ($_POST['NEWT_ID'] ?? '')),
            'NEWT_SECRET'       => trim((string) ($_POST['NEWT_SECRET'] ?? '')),
            'INTERFACE'         => trim((string) ($_POST['INTERFACE'] ?? 'newt')),
            'MTU'               => trim((string) ($_POST['MTU'] ?? '1280')),
            'DNS'               => trim((string) ($_POST['DNS'] ?? '9.9.9.9')),
            'LOG_LEVEL'         => trim((string) ($_POST['LOG_LEVEL'] ?? 'INFO')),
            'DISABLE_SSH'       => (string) ($_POST['DISABLE_SSH'] ?? '1'),
        ];

        // Basic validation
        if ($updates['ENABLE_NEWT'] === '1') {
            if ($updates['PANGOLIN_ENDPOINT'] === '' || $updates['NEWT_ID'] === '' || $updates['NEWT_SECRET'] === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Endpoint, ID, and Secret are required when enabling Newt.']);
                exit;
            }
        }

        if (!\Newt\writeCfg($updates)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save configuration to flash.']);
            exit;
        }

        // Run apply.sh in the background
        $applyScript = '/usr/local/emhttp/plugins/newt/scripts/apply.sh';
        if (is_executable($applyScript)) {
            $cmd = escapeshellcmd($applyScript) . ' >/dev/null 2>&1 &';
            exec($cmd);
            echo json_encode([
                'pending' => true,
                'message' => 'Applying settings in background...',
                'since'   => time()
            ]);
        } else {
            // Sudo/fallback command
            http_response_code(500);
            echo json_encode(['error' => 'Apply script not executable or not found.']);
        }
        break;

    case 'apply-status':
        $res = \Newt\readApplyResult();
        if ($res) {
            echo json_encode($res);
        } else {
            echo json_encode(['pending' => true, 'message' => 'Waiting for apply script result...']);
        }
        break;

    case 'get-status':
        $cfg = \Newt\readCfg();
        $ifname = $cfg['INTERFACE'] ?? 'newt';
        $running = \Newt\daemonRunning();
        $connected = $running && \Newt\interfaceExists($ifname);
        $logs = \Newt\readLogs(40);
        
        echo json_encode([
            'daemonRunning' => $running,
            'interfaceUp'   => \Newt\interfaceExists($ifname),
            'connected'     => $connected,
            'interfaceName' => $ifname,
            'logs'          => $logs
        ]);
        break;

    case 'stop':
        $rcScript = \Newt\RC_SCRIPT;
        exec(escapeshellcmd($rcScript) . " stop 2>&1", $out, $rc);
        
        // Disable Newt in config so it doesn't auto-start
        \Newt\writeCfg(['ENABLE_NEWT' => '0']);
        
        echo json_encode([
            'success' => ($rc === 0),
            'message' => implode("\n", $out)
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
