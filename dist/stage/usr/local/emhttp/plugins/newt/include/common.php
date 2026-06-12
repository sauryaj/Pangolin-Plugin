<?php
/**
 * Shared helpers for the Newt Unraid plugin.
 */

namespace Newt;

const PLUGIN       = 'newt';
const NEWT_BIN     = '/usr/local/sbin/newt';
const RC_SCRIPT    = '/etc/rc.d/rc.newt';
const CFG_FILE     = '/boot/config/plugins/newt/newt.cfg';
const DEFAULT_CFG  = '/usr/local/emhttp/plugins/newt/default.cfg';
const LOCK_FILE    = '/var/run/newt-apply.lock';
const RESULT_FILE  = '/var/run/newt-apply-result.json';
const LOG_FILE     = '/var/log/newt.log';

/**
 * True when the daemon is running.
 */
function daemonRunning(): bool
{
    exec('/usr/bin/pgrep -f "^' . NEWT_BIN . '" 2>/dev/null', $out, $rc);
    return $rc === 0;
}

/**
 * True if the specified WireGuard interface is up.
 */
function interfaceExists(string $ifname): bool
{
    if ($ifname === '') {
        $ifname = 'newt';
    }
    return is_dir("/sys/class/net/{$ifname}");
}

/**
 * Read configuration file merging defaults.
 */
function readCfg(): array
{
    $defaults = [];
    if (is_readable(DEFAULT_CFG)) {
        $defaults = parse_ini_file(DEFAULT_CFG) ?: [];
    }
    
    $user = [];
    if (is_readable(CFG_FILE)) {
        $user = parse_ini_file(CFG_FILE) ?: [];
    }
    
    return array_merge($defaults, $user);
}

/**
 * Write configuration updates back to netw.cfg.
 */
function writeCfg(array $updates): bool
{
    $current = [];
    if (is_readable(CFG_FILE)) {
        $current = parse_ini_file(CFG_FILE) ?: [];
    }
    
    $merged = array_merge($current, $updates);
    
    $dir = dirname(CFG_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    
    $lines = '';
    foreach ($merged as $k => $v) {
        $v = str_replace('"', '', (string) $v);
        $lines .= $k . '="' . $v . "\"\n";
    }
    
    return file_put_contents(CFG_FILE, $lines) !== false;
}

/**
 * Read the last apply status.
 */
function readApplyResult(): ?array
{
    if (!is_readable(RESULT_FILE)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents(RESULT_FILE), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Read the last N lines of the log file.
 */
function readLogs(int $lines = 50): string
{
    if (!is_readable(LOG_FILE)) {
        return "Log file not found or not readable.";
    }
    
    $escapedPath = escapeshellarg(LOG_FILE);
    $escapedLines = escapeshellarg((string) $lines);
    
    exec("tail -n {$escapedLines} {$escapedPath} 2>&1", $output, $rc);
    if ($rc !== 0) {
        return "Failed to read logs.";
    }
    
    return implode("\n", $output);
}
