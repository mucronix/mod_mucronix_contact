<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

/**
 * Reads the settings of every module instance straight from the site database and says what the
 * update did to them. The suite next door drives the site over HTTP and cannot see a parameter at
 * all, which is exactly what has to be judged here.
 *
 * It only ever reads. Nothing in this file writes to the database, and it is not part of the package.
 *
 * Usage:
 *   php tests/migration-check.php --site=<path to the site> [--snapshot=<file> | --verify=<file>]
 *
 * Without a file it prints what every instance holds now. --snapshot writes that down before the
 * update; --verify reads it back afterwards and judges each instance by what it used to be:
 *
 *   load_css = 1  ->  Own Styles "base",  Field Markup "none", load_css gone
 *   load_css = 0  ->  Own Styles "none",  Field Markup "none", load_css gone
 *   no load_css   ->  not one character changed
 *
 * The last line is the point of running it twice: an instance already on 1.1.0 must survive the same
 * update again untouched.
 *
 * Exit code 0 when the state matches, 1 otherwise.
 */

$options  = getopt('', ['site:', 'snapshot:', 'verify:']);
$site     = rtrim($options['site'] ?? 'D:/OSPanel1/home/joomla6', '/\\');
$snapshot = $options['snapshot'] ?? null;
$verify   = $options['verify'] ?? null;

if ($snapshot !== null && $verify !== null) {
    fwrite(STDERR, "--snapshot and --verify are two different runs, one before the update and one after\n");
    exit(1);
}

$configuration = $site . '/configuration.php';

if (!is_file($configuration)) {
    fwrite(STDERR, "no configuration.php at: $configuration\n");
    fwrite(STDERR, "pass --site=<path to the site>\n");
    exit(1);
}

\define('_JEXEC', 1);
require $configuration;

$config = new JConfig();

// Joomla stores the port in the host, the way it was typed during installation
[$host, $port] = array_pad(explode(':', $config->host, 2), 2, null);

try {
    $dsn = 'mysql:host=' . $host . ($port !== null ? ';port=' . $port : '') . ';dbname=' . $config->db . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $config->user, $config->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, 'cannot read the database: ' . $e->getMessage() . "\n");
    exit(1);
}

$rows = $pdo
    ->query('SELECT id, title, params FROM ' . $config->dbprefix . "modules WHERE module = 'mod_mucronix_contact' ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    fwrite(STDERR, "no instance of the module in this site\n");
    exit(1);
}

$now = [];

foreach ($rows as $row) {
    $params            = json_decode((string) $row['params'], true);
    $now[(int) $row['id']] = [
        'title'  => (string) $row['title'],
        'raw'    => (string) $row['params'],
        'params' => is_array($params) ? $params : null,
    ];
}

printf("%-6s %-28s %-10s %-12s %-8s\n", 'id', 'title', 'load_css', 'own styles', 'markup');

foreach ($now as $id => $instance) {
    $params = $instance['params'];

    printf(
        "%-6d %-28s %-10s %-12s %-8s\n",
        $id,
        mb_strimwidth($instance['title'], 0, 28, '…'),
        $params === null ? '?' : ($params['load_css'] ?? '-'),
        $params === null ? '?' : ($params['style_mode'] ?? '-'),
        $params === null ? '?' : ($params['markup'] ?? '-')
    );
}

if ($snapshot !== null) {
    file_put_contents($snapshot, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nwritten to $snapshot: run the update, then the same command with --verify=$snapshot\n";

    exit(0);
}

if ($verify === null) {
    exit(0);
}

$before = json_decode((string) @file_get_contents($verify), true);

if (!is_array($before)) {
    fwrite(STDERR, "cannot read the snapshot: $verify\n");
    exit(1);
}

$wrong = [];

foreach ($before as $id => $was) {
    $id = (int) $id;

    if (!isset($now[$id])) {
        $wrong[] = "instance $id is gone from the site";

        continue;
    }

    $is  = $now[$id];
    $old = $was['params'];

    if (!is_array($old)) {
        $wrong[] = "instance $id had params this script could not read, so it judges nothing about it";

        continue;
    }

    // Untouched is untouched: the stored text itself has to match, not just the values
    if (!array_key_exists('load_css', $old)) {
        if ($is['raw'] !== $was['raw']) {
            $wrong[] = "instance $id carried no load_css and was changed anyway";
        }

        continue;
    }

    $expected = (int) $old['load_css'] === 1 ? 'base' : 'none';
    $params   = $is['params'];

    if (!is_array($params)) {
        $wrong[] = "instance $id now holds params that are not readable json";

        continue;
    }

    if (array_key_exists('load_css', $params)) {
        $wrong[] = "instance $id still carries load_css";
    }

    if (($params['style_mode'] ?? '') !== $expected) {
        $wrong[] = "instance $id had load_css=" . $old['load_css'] . ', so Own Styles should be "'
            . $expected . '", not "' . ($params['style_mode'] ?? '-') . '"';
    }

    if (($params['markup'] ?? '') !== 'none') {
        $wrong[] = "instance $id should keep the button of 1.0.x with Field Markup \"none\", not \""
            . ($params['markup'] ?? '-') . '"';
    }

    // Everything else has to be the same value it was
    foreach ($old as $name => $value) {
        if ($name === 'load_css') {
            continue;
        }

        if (!array_key_exists($name, $params) || $params[$name] !== $value) {
            $wrong[] = "instance $id lost or changed the parameter \"$name\"";
        }
    }
}

echo "\n";

foreach ($wrong as $line) {
    echo '  ', $line, "\n";
}

echo $wrong === [] ? "OK: every instance was moved as it should have been\n" : 'FAILED: ' . count($wrong) . " findings\n";

exit($wrong === [] ? 0 : 1);
