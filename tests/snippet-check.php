<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

/**
 * Reads every CSS snippet out of the styling notes and checks that it points at something that
 * exists: braces that balance, the placeholder for the selector of the form, classes that are on the
 * real page or in a rule of the module, and variables some rule of the module actually reads.
 *
 * What it cannot say is whether a snippet looks right - that is for eyes on Cassiopeia. What it
 * catches is the snippet that was aimed at a class renamed three versions ago and quietly does
 * nothing on a site that pasted it.
 *
 * Not part of the package.
 *
 * Usage:
 *   php tests/snippet-check.php [--url=https://joomla6/] [--page=/]
 *
 * Exit code 0 when every snippet points at something, 1 otherwise.
 */

$options = getopt('', ['url::', 'page::']);
$baseUrl = rtrim($options['url'] ?? 'https://joomla6/', '/') . '/';
$page    = $options['page'] ?? '/';
$root    = \dirname(__DIR__);

$context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$html    = @file_get_contents($baseUrl . ltrim($page, '/'), false, $context);

if ($html === false) {
    fwrite(STDERR, 'cannot read the page with the form: ' . $baseUrl . ltrim($page, '/') . "\n");
    exit(1);
}

if (!str_contains($html, 'mcx-form-')) {
    fwrite(STDERR, "no contact form on that page: is the module published and assigned to it?\n");
    exit(1);
}

preg_match_all('/class="([^"]*)"/', $html, $found);

$classes = [];

foreach ($found[1] as $list) {
    foreach (preg_split('/\s+/', trim($list)) as $class) {
        $classes[$class] = true;
    }
}

// A form without a select or a radio is still a form: what the page misses, the stylesheets answer for
$css = file_get_contents($root . '/media/css/form-base.css') . file_get_contents($root . '/media/css/form-theme.css');

$pages    = glob($root . '/media/docs/styling.*.html');
$problems = 0;

if ($pages === []) {
    fwrite(STDERR, "no styling notes in media/docs\n");
    exit(1);
}

foreach ($pages as $file) {
    preg_match_all('~<pre><code>(.*?)</code></pre>~s', (string) file_get_contents($file), $blocks);

    echo basename($file), ': ', \count($blocks[1]), " snippets\n";

    foreach ($blocks[1] as $i => $snippet) {
        $snippet = html_entity_decode($snippet, ENT_QUOTES, 'UTF-8');
        $say     = static function (string $line) use (&$problems, $i): void {
            echo '  snippet ', $i + 1, ': ', $line, "\n";
            $problems++;
        };

        // Braces that do not balance take the rest of Custom CSS down with them
        if (substr_count($snippet, '{') !== substr_count($snippet, '}')) {
            $say('the braces do not balance');
        }

        // Without the placeholder the snippet would be pasted as it stands and apply to every form
        if (!preg_match('/YOUR-FORM|ВАША-ФОРМА/u', $snippet)) {
            $say('no placeholder for the selector of the form');
        }

        foreach (array_unique(preg_split('/\s+/', $snippet)) as $token) {
            if (preg_match('/^\.([a-z][a-z0-9-]+)/i', $token, $class)
                && !isset($classes[$class[1]])
                && !str_contains($css, '.' . $class[1])) {
                $say('class .' . $class[1] . ' is on no element of the form and in no rule of the module');
            }
        }

        preg_match_all('/--mcx-[a-z-]+/', $snippet, $variables);

        foreach (array_unique($variables[0]) as $variable) {
            if (!str_contains($css, $variable)) {
                $say('variable ' . $variable . ' is read by no rule of the module');
            }
        }
    }
}

echo "\n", $problems === 0
    ? "OK: every snippet points at something that exists\n"
    : "FAILED: $problems problems\n";

exit($problems === 0 ? 0 : 1);
