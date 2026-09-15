<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

/**
 * Sends the contact form over HTTP, both ways: as a plain post without JavaScript and through
 * com_ajax the way the script in the browser does. Nothing is clicked by hand, so the result
 * does not depend on the browser.
 *
 * The evidence that a message was sent is the module log, administrator/logs/mod_mucronix_contact.php:
 * a response cannot tell the two cases apart, both report success.
 *
 * Requires "Log Sent Messages" to be on in the module settings, Advanced tab. The script cannot
 * switch it on: module parameters live in the database. Without it the sent line never appears
 * and the sending cases cannot be judged.
 *
 * The extra field cases need this in the Extra Fields tab, and skip themselves without it:
 *
 *   <field name="mcx_test_topic" type="text" label="Test topic" />
 *   <field name="captcha" type="text" label="Refused name" />
 *   <field name="mcx_test_sql" type="sql" label="Refused type" query="SELECT 1" />
 *
 * The first has to be accepted, the second and third refused. All three are written as one block
 * on purpose: the presence of the first proves the block was pasted, so the absence of the other
 * two means they were refused rather than never configured.
 *
 * Seven real messages are sent per run, one per sending case, and two more with --success-page.
 *
 * What this cannot check: the captcha widget in the browser. The script asks for a new challenge
 * before every submission, which is what a reset widget does. Whether the widget itself hands out
 * a new solution after a refusal, instead of the one it already used, only a browser can show.
 *
 * Nor the body of the message. The site sends over SMTP to a real server, so nothing readable is
 * left on this machine: that an accepted extra field is really written into the letter can only be
 * seen in the mailbox. What is checked here is that the field is in the form, that a value in it is
 * taken, and that the message goes out.
 *
 * Usage:
 *   php tests/honeypot-test.php [--url=http://joomla6/] [--page=/] [--log=<path>] [--slow] [--telegram]
 *                              [--success-page]
 *
 * --slow adds the expired captcha case, which idles about five minutes.
 *
 * --success-page adds the two thank-you page cases, and says so for the same reason as --telegram:
 * a page that was never chosen and a page that stopped being answered look alike from here, so the
 * setting is asserted on the command line rather than guessed. Choose one in the Basic tab first.
 *
 * --telegram adds the case for sending to Telegram, and says so because nothing in the page shows
 * whether it is switched on. The case asks one thing: with Telegram on, the message still goes out.
 * It has to pass with a working token and with a broken one alike, and the broken one is the run
 * worth doing, because a refused copy may not turn a delivered message into a refusal on screen.
 * Whether the chat received anything is not visible from here.
 *
 * Exit code 0 when every case behaves as it should, 1 otherwise.
 */

$options     = getopt('', ['url::', 'page::', 'log::', 'slow', 'telegram', 'success-page']);
$slow        = isset($options['slow']);
$telegram    = isset($options['telegram']);
$successPage = isset($options['success-page']);
$baseUrl = rtrim($options['url'] ?? 'http://joomla6/', '/') . '/';
$page    = $options['page'] ?? '/';
$logFile = $options['log'] ?? 'administrator/logs/mod_mucronix_contact.php';

/*
 * The log is the only evidence that a message really went out, so a run without it would judge
 * every sending case on nothing. The default is relative to the working directory, which suits a
 * run from the site root; from anywhere else pass --log.
 */
if (!is_file($logFile)) {
    fwrite(STDERR, 'no module log at: ' . $logFile . "\n");
    fwrite(STDERR, "pass --log=<path to administrator/logs/mod_mucronix_contact.php>\n");
    fwrite(STDERR, "the file appears once the module has logged something: switch on \"Log Sent Messages\" and send once\n");
    exit(1);
}

/**
 * Fields the script fills itself, and whose values several cases depend on.
 */
const BASE_FIELDS = ['name', 'email', 'message', 'consent', 'captcha', 'mcx_hp', 'attachment'];

$failures = 0;
$jars     = [];

/**
 * A fresh session: the throttle allows one message per 30 seconds per session.
 */
function newJar(): string
{
    global $jars;

    $jar    = tempnam(sys_get_temp_dir(), 'mcx') ?: die("cannot create a cookie jar\n");
    $jars[] = $jar;

    return $jar;
}

/**
 * One HTTP request in the session of the cookie jar.
 */
function request(string $url, string $jar, ?array $post = null, ?int &$status = null, ?string &$location = null): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        // A redirect after a POST would be replayed as a GET and the form data would be lost,
        // so redirects are only followed for plain page reads
        CURLOPT_FOLLOWLOCATION => $post === null,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => $post !== null,
    ]);

    if ($post !== null) {
        $hasFile = false;

        foreach ($post as $value) {
            if ($value instanceof CURLFile) {
                $hasFile = true;

                break;
            }
        }

        curl_setopt($ch, CURLOPT_POST, true);

        // An array with a CURLFile in it makes curl send multipart, which url encoding cannot carry
        curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $post : http_build_query($post));
    }

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) {
        fwrite(STDERR, 'request failed: ' . curl_error($ch) . "\n");

        /*
         * A sending case waits on the mailer, and an unreachable SMTP host blocks for longer than
         * the thirty seconds allowed here - so a mail outage arrives looking exactly like a dead
         * site. The module writes the real reason to its log either way.
         */
        if (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT && $post !== null) {
            fwrite(STDERR, "  a submission timed out rather than the site being unreachable.\n");
            fwrite(STDERR, "  check the end of the module log for an SMTP error before looking anywhere else.\n");
        }

        exit(1);
    }

    $location = null;

    if ($post !== null) {
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers    = substr((string) $body, 0, $headerSize);
        $body       = substr((string) $body, $headerSize);

        if (preg_match('/^location:\s*(.+)$/im', $headers, $m)) {
            $location = trim($m[1]);
        }
    }

    curl_close($ch);

    return (string) $body;
}

/**
 * Reads the form page and picks out the module id, the action, the CSRF token and whether a captcha is shown.
 */
function readForm(string $html): array
{
    if (!preg_match('/<form id="mcx-form-(\d+)"[^>]*\saction="([^"]*)"/s', $html, $m)) {
        fwrite(STDERR, "no contact form on the page: is the module published and assigned to it?\n");
        exit(1);
    }

    if (!preg_match('/name="([0-9a-f]{32})" value="1"/', $html, $t)) {
        fwrite(STDERR, "no form token found\n");
        exit(1);
    }

    /*
     * The base address is taken from the page itself, out of the same script options the browser
     * script reads. A wrong scheme in --url then cannot break anything: the site redirects the
     * plain page read to its canonical address, and every following request is built from that.
     */
    $base = '';

    if (preg_match('/joomla-script-options new">(.*?)<\/script>/s', $html, $o)) {
        $options = json_decode(html_entity_decode($o[1], ENT_QUOTES, 'UTF-8'), true);
        $base    = (string) ($options['system.paths']['baseFull'] ?? '');
    }

    $moduleId = (int) $m[1];

    return [
        'moduleId'   => $moduleId,
        'action'     => html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'),
        'token'      => $t[1],
        'base'       => $base !== '' ? rtrim($base, '/') . '/' : '',
        'captcha'    => str_contains($html, '<altcha-widget'),
        'attachment' => str_contains($html, 'mcx_' . $moduleId . '[attachment]'),
        // The extra field cases ask which fields were rendered and which were kept out
        'html'       => $html,
    ];
}

/**
 * Tells whether the form carries an input under this field name.
 */
function hasField(array $form, string $name): bool
{
    return str_contains($form['html'], 'mcx_' . $form['moduleId'] . '[' . $name . ']');
}

/**
 * Asks the site for a proof of work challenge. The key belongs to the session of the cookie jar.
 */
function fetchChallenge(string $base, string $jar, string $token): array
{
    $raw       = request($base . 'index.php?option=com_ajax&plugin=powcaptcha&group=captcha&format=raw&' . $token . '=1', $jar);
    $challenge = json_decode($raw, true);

    if (!is_array($challenge) || empty($challenge['challenge']) || empty($challenge['salt'])) {
        fwrite(STDERR, "could not read a captcha challenge\n");
        exit(1);
    }

    return $challenge;
}

/**
 * Solves a challenge the way the browser widget does: by trying numbers until the hash matches.
 */
function solveChallenge(array $challenge): string
{
    $algorithm = $challenge['algorithm'] ?? 'SHA-512';
    $max       = (int) ($challenge['maxNumber'] ?? $challenge['maxnumber'] ?? 100000);
    $php       = str_replace('-', '', strtolower($algorithm));

    for ($n = 0; $n <= $max; $n++) {
        if (hash($php, $challenge['salt'] . $n) === $challenge['challenge']) {
            return base64_encode(json_encode([
                'algorithm' => $algorithm,
                'challenge' => $challenge['challenge'],
                'number'    => $n,
                'salt'      => $challenge['salt'],
                'signature' => $challenge['signature'],
            ]));
        }
    }

    fwrite(STDERR, "captcha challenge has no solution below $max\n");
    exit(1);
}

/**
 * The moment a challenge stops being valid, carried in the salt as a query string.
 */
function challengeExpiry(array $challenge): int
{
    $parts = explode('?', (string) $challenge['salt']);
    parse_str($parts[1] ?? '', $params);

    return (int) ($params['expires'] ?? 0);
}

/**
 * Fetches a challenge and solves it.
 */
function solveCaptcha(string $baseUrl, string $jar, string $token): string
{
    return solveChallenge(fetchChallenge($baseUrl, $jar, $token));
}

/**
 * Counts the "Message sent" lines in the module log.
 */
function countSent(string $logFile): int
{
    if (!is_file($logFile)) {
        return 0;
    }

    return substr_count((string) file_get_contents($logFile), 'Message sent');
}

/**
 * Builds the post data of one submission.
 */
function fields(array $form, string $captcha, string $honeypot, string $name = 'Automated test', array $extra = []): array
{
    $prefix = 'mcx_' . $form['moduleId'];
    $post   = [
        $prefix . '[name]'    => $name,
        $prefix . '[email]'   => 'test@example.com',
        $prefix . '[message]' => 'Automated check, ' . date('c'),
        $prefix . '[consent]' => '1',
        $prefix . '[captcha]' => $captcha,
        $prefix . '[mcx_hp]'  => $honeypot,
        'mcx_module_id'       => $form['moduleId'],
        $form['token']        => '1',
    ];

    /*
     * Anything else the form insists on. Extra fields come from a module parameter, so the script
     * cannot know them in advance; a required one it did not fill used to sink every sending case
     * at once - twelve reds spread over a run with the reason named in none of them.
     */
    foreach (requiredExtras($form) as $field => $value) {
        $post[$prefix . '[' . $field . ']'] = $value;
    }

    foreach ($extra as $field => $value) {
        $post[$prefix . '[' . $field . ']'] = $value;
    }

    return $post;
}

/**
 * The required fields the script does not already know about, with a value each.
 *
 * The base fields keep their own: several cases turn on what is in them, and one on a value being
 * absent. Only what the extra fields added is filled in here.
 */
function requiredExtras(array $form): array
{
    static $cache = [];

    if (isset($cache[$form['moduleId']])) {
        return $cache[$form['moduleId']];
    }

    $filled = [];

    foreach (describeFields($form) as $field => $about) {
        if (\in_array($field, BASE_FIELDS, true) || !$about['required']) {
            continue;
        }

        $value = fillFor($about);

        if ($value !== null) {
            $filled[$field] = $value;
        }
    }

    return $cache[$form['moduleId']] = $filled;
}

/**
 * Every named control in the form: its tag, its type, whether it is required, and for a list the
 * first option that carries a value.
 */
function describeFields(array $form): array
{
    $prefix = 'mcx_' . $form['moduleId'];
    $found  = [];

    // A select is taken with its body, so a list can be answered with an option it really has
    $pattern = '/<(input|textarea|select)\b([^>]*name="' . preg_quote($prefix, '/')
        . '\[([a-zA-Z0-9_]+)\]"[^>]*)>(?:(.*?)<\/select>)?/s';

    preg_match_all($pattern, $form['html'], $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $tag        = $match[1];
        $attributes = $match[2];
        $field      = $match[3];
        $body       = $match[4] ?? '';

        preg_match('/\btype="([a-z-]+)"/i', $attributes, $type);
        preg_match('/value="([^"]+)"/', $body, $option);

        // A radio or checkbox group repeats one name; one required member makes the group required
        $found[$field] = [
            'tag'         => $tag,
            'type'        => strtolower($type[1] ?? ''),
            'required'    => ($found[$field]['required'] ?? false) || str_contains($attributes, 'required'),
            'firstOption' => $found[$field]['firstOption'] ?? ($option[1] ?? null),
            'calendar'    => str_contains($attributes, 'data-alt-value') || str_contains($match[0], 'field-calendar'),
        ];
    }

    return $found;
}

/**
 * A value a field of this shape will accept, or null where the script has no business guessing.
 */
function fillFor(array $about): ?string
{
    if ($about['tag'] === 'select' || $about['type'] === 'radio') {
        return $about['firstOption'];
    }

    if ($about['calendar']) {
        return '2026-01-01 00:00:00';
    }

    return match ($about['type']) {
        'checkbox' => '1',
        'number'   => '1',
        'email'    => 'extra@example.com',
        'url'      => 'https://example.com',
        'tel'      => '+10000000000',
        'text', '' => 'Automated check',
        default    => null,
    };
}

/**
 * Stops the run when the form asks for something the script cannot answer.
 *
 * Once, here, rather than as a spread of failures further down with the cause named in none of
 * them: a required field left empty refuses every submission, so every sending case goes red.
 */
function requireFillable(array $form): void
{
    $filled   = requiredExtras($form);
    $hopeless = [];

    foreach (describeFields($form) as $field => $about) {
        if (\in_array($field, BASE_FIELDS, true) || !$about['required'] || isset($filled[$field])) {
            continue;
        }

        $hopeless[] = $field . ' (' . ($about['type'] ?: $about['tag']) . ')';
    }

    if ($hopeless !== []) {
        fwrite(STDERR, "the form has required fields this script cannot fill:\n  " . implode("\n  ", $hopeless) . "\n");
        fwrite(STDERR, "every sending case would fail on them. Make them optional, or take them out of the Extra Fields tab.\n");
        exit(1);
    }

    if ($filled !== []) {
        echo 'filling required extra fields: ', implode(', ', array_keys($filled)), "\n";
    }
}

/**
 * Every line of the module log, headers aside.
 */
function countLog(string $logFile): int
{
    if (!is_file($logFile)) {
        return 0;
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return count(array_filter($lines, fn ($line) => $line !== '' && $line[0] !== '#'));
}

/**
 * Drawing the form must not write to the log.
 *
 * It used to: every notice about the settings went in on every view, so a page carrying a module
 * with two mistakes in its field description wrote two lines per visitor, crawlers included. The
 * notices belong on the page, and in the log once, when a message comes in.
 */
function quietRenderCase(string $title, int $views, string $baseUrl, string $page, string $logFile): bool
{
    $jar    = newJar();
    $before = countLog($logFile);

    for ($i = 0; $i < $views; $i++) {
        readForm(request($baseUrl . ltrim($page, '/'), $jar));
    }

    $grew = countLog($logFile) - $before;
    $ok   = $grew === 0;

    report($title, $grew . ' new lines', '0 new lines', 0, false, $ok);

    if (!$ok) {
        echo '  ', $views, " views of the page added $grew lines to the module log\n";
        echo "  a live site would bury its real errors under that within a day\n";
    }

    return $ok;
}

/**
 * Reports one case.
 */
function report(string $title, string $state, string $expectedState, int $sent, bool $expectMail, bool $ok): void
{
    printf(
        "%-34s says: %-11s (expected %-8s) mail sent: %-3s expected: %-3s  %s\n",
        $title,
        $state,
        $expectedState,
        $sent > 0 ? 'yes' : 'no',
        $expectMail ? 'yes' : 'no',
        $ok ? 'PASS' : 'FAIL'
    );

    if (!$ok && $expectMail && $sent === 0 && $state === 'success') {
        echo "  the page reports success but no line was logged. Either \"Log Sent Messages\" is off\n";
        echo "  in the module settings (Advanced tab), or the message was not sent at all.\n";
    }
}

/**
 * A submission without JavaScript: the form posts to its own action.
 */
function postCase(string $title, string $honeypot, bool $expectMail, bool $expectError, string $baseUrl, string $page, string $jar, string $logFile): bool
{
    $form     = readForm(request($baseUrl . ltrim($page, '/'), $jar));
    $before   = countSent($logFile);
    $captcha  = $form['captcha'] ? solveCaptcha($form['base'] ?: $baseUrl, $jar, $form['token']) : '';
    $status   = null;
    $location = null;
    $html     = request(
        $form['action'] !== '' ? $form['action'] : $baseUrl . ltrim($page, '/'),
        $jar,
        fields($form, $captcha, $honeypot),
        $status,
        $location
    );

    /*
     * Without JavaScript the module answers a submission with Post/Redirect/Get, so the outcome
     * is on the page the redirect points at, not in the answer to the post itself.
     *
     * Unless a thank-you page is set, and then a successful submission leaves the form behind
     * entirely: the page it lands on carries no message block, and there is nothing to read. Only
     * success can look like that - a refusal has no address to go to and always comes back to the
     * form, at its own anchor. Judged by the anchor rather than by a setting, so that these cases
     * stay right whichever way the module is configured.
     */
    $state = 'not handled';

    if ($location !== null && !str_contains($location, '#mcx-')) {
        $state = 'sent away';
    } else {
        if ($location !== null) {
            $html = request($location, $jar);
        }

        if (str_contains($html, 'mcx-message--ok')) {
            $state = 'success';
        } elseif (str_contains($html, 'mcx-message--error')) {
            $state = 'error';
        }
    }

    $sent = countSent($logFile) - $before;
    $ok   = $expectError
        ? $state === 'error'
        : \in_array($state, ['success', 'sent away'], true);
    $ok   = $ok && $sent === ($expectMail ? 1 : 0);

    report($title, $state, $expectError ? 'error' : 'success', $sent, $expectMail, $ok);

    return $ok;
}

/**
 * A submission through com_ajax, the same request the browser script sends.
 */
function ajaxCase(string $title, string $honeypot, bool $expectMail, bool $expectError, string $baseUrl, string $page, string $jar, string $logFile, string $name = 'Automated test', string $expectField = '', array $extra = []): bool
{
    $form    = readForm(request($baseUrl . ltrim($page, '/'), $jar));
    $before  = countSent($logFile);
    $captcha = $form['captcha'] ? solveCaptcha($form['base'] ?: $baseUrl, $jar, $form['token']) : '';
    $url     = ($form['base'] ?: $baseUrl) . 'index.php?option=com_ajax&module=mucronix_contact&format=json&method=send';
    $status  = null;
    $body    = request($url, $jar, fields($form, $captcha, $honeypot, $name, $extra), $status);
    $json    = json_decode($body, true);
    $data    = $json['data'] ?? null;

    if (!is_array($data)) {
        report($title, 'no data', $expectError ? 'error' : 'success', 0, $expectMail, false);

        // Joomla answers an escaped exception with its own json: error, code, message
        if (is_array($json) && isset($json['message'])) {
            echo '  http ', $status, ', joomla reported: ', $json['message'], "\n";
        } else {
            echo '  http ', $status, ', response: ', substr(trim($body), 0, 300), "\n";
        }

        return false;
    }

    $state = $data['success'] ? 'success' : 'error';
    $sent  = countSent($logFile) - $before;
    $ok    = $state === ($expectError ? 'error' : 'success') && $sent === ($expectMail ? 1 : 0);

    if ($expectField !== '') {
        $hasField = isset($data['errors'][$expectField]);
        $ok       = $ok && $hasField;
    }

    report($title, $state, $expectError ? 'error' : 'success', $sent, $expectMail, $ok);

    if (!$ok && $expectField !== '') {
        echo '  errors returned: ', $data['errors'] ? implode(', ', array_keys($data['errors'])) : '(none)',
            ', expected one for: ', $expectField, "\n";
    }

    return $ok;
}

/**
 * Writes a file to send, padded to a size when one is asked for.
 */
function makeFixture(string $name, string $content, int $padTo = 0): string
{
    $path = sys_get_temp_dir() . '/' . $name;
    file_put_contents($path, $content);

    if ($padTo > strlen($content)) {
        file_put_contents($path, str_repeat('x', $padTo - strlen($content)), FILE_APPEND);
    }

    return $path;
}

/**
 * Writes a real archive under whatever name is asked for: an honest extension with foreign content
 * is what the map of extension to content type exists for.
 */
function makeArchiveFixture(string $name): string
{
    $path = sys_get_temp_dir() . '/' . $name;
    @unlink($path);

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', "an ordinary archive\n");
    $zip->close();

    return $path;
}

/**
 * A submission while sending to Telegram is switched on.
 *
 * The mail is the message and the chat is a copy of it. Whatever Telegram answers, and whether it
 * answers at all, the visitor has to see the message go through and the log has to show one sent
 * line. Run it with a wrong token on purpose at least once.
 */
function telegramCase(string $title, string $baseUrl, string $page, string $logFile): bool
{
    $jar     = newJar();
    $form    = readForm(request($baseUrl . ltrim($page, '/'), $jar));
    $before  = countSent($logFile);
    $captcha = $form['captcha'] ? solveCaptcha($form['base'] ?: $baseUrl, $jar, $form['token']) : '';
    $url     = ($form['base'] ?: $baseUrl) . 'index.php?option=com_ajax&module=mucronix_contact&format=json&method=send';
    $started = microtime(true);
    $body    = request($url, $jar, fields($form, $captcha, ''));
    $took    = microtime(true) - $started;
    $data    = json_decode($body, true)['data'] ?? null;

    if (!is_array($data)) {
        report($title, 'no data', 'success', 0, true, false);
        echo '  response: ', substr(trim($body), 0, 300), "\n";

        return false;
    }

    $state = $data['success'] ? 'success' : 'error';
    $sent  = countSent($logFile) - $before;
    $ok    = $state === 'success' && $sent === 1;

    report($title, $state, 'success', $sent, true, $ok);

    /*
     * Five seconds for the whole request and three to reach the host, so a Telegram that never
     * answers costs the visitor eight at the very worst. Printed rather than judged: the number
     * depends on the mail server as much as on anything here.
     */
    printf("  the request took %.1f s\n", $took);

    if (!$ok) {
        echo "  a refusal from Telegram must not reach the visitor, and must not stop the mail\n";
    }

    return $ok;
}

/**
 * The hidden input that carries the page the form stands on.
 *
 * Nothing in a com_ajax request says which page it came from, so the address travels in the form.
 * Without it the message names the com_ajax endpoint, the same string whichever form wrote.
 * Checked in the markup: whether the message really carries it shows in the mailbox.
 */
function pageFieldCase(string $title, string $baseUrl, string $page, string $logFile): bool
{
    $html = request($baseUrl . ltrim($page, '/'), newJar());
    $form = readForm($html);
    $wrong = [];

    if (!preg_match('/<input type="hidden" name="mcx_page" value="([^"]*)"/', $form['html'], $m)) {
        $wrong[] = 'no mcx_page input in the form at all';
        $value   = '';
    } else {
        $value = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    if ($value === '') {
        $wrong[] = 'mcx_page is empty';
    }

    // The one thing that must never be in there again
    if (str_contains($value, 'option=com_ajax')) {
        $wrong[] = 'mcx_page holds the com_ajax endpoint: ' . $value;
    }

    /*
     * The same address the form posts to without JavaScript, which is the page itself. The two
     * paths have to name one page, otherwise a message would read differently depending on
     * whether the visitor had JavaScript on.
     */
    if ($value !== '' && $value !== $form['action']) {
        $wrong[] = 'mcx_page is ' . $value . ' but the form posts to ' . $form['action'];
    }

    $ok = $wrong === [];

    report($title, $ok ? 'present' : 'wrong', 'present', 0, false, $ok);

    foreach ($wrong as $line) {
        echo '  ', $line, "\n";
    }

    if ($ok) {
        echo '  mcx_page: ', $value, "\n";
    }

    return $ok;
}

/**
 * Collects one language string in every language the module ships.
 *
 * A case that judges the wording cannot know which language the site is running, so it compares
 * against all of them at once. Read from the project rather than from the site: what is installed
 * may be older than what is being tested.
 */
function languageValues(string $key): array
{
    $values = [];

    foreach (glob(__DIR__ . '/../language/*/mod_mucronix_contact.ini') ?: [] as $file) {
        $parsed = parse_ini_file($file);

        if (isset($parsed[$key])) {
            $values[] = $parsed[$key];
        }
    }

    return $values;
}

/**
 * A submission whose token is no good.
 *
 * $freshSession decides which of the two ways that happens is being tried. With an existing session
 * and a made up token, the core check simply answers false. With no session at all it does not: it
 * enqueues a warning of its own, redirects to the front page and reports success, so the module
 * never gets to refuse - a plain post used to land on the front page with everything typed lost,
 * and through com_ajax fetch followed that redirect, failed to read the front page as json and
 * answered "could not be sent, try again later". Both have to say the form is stale instead.
 *
 * This is not a contrived case. Open a page, get distracted, log in or out in another tab, come
 * back and send: the token is a hash of the user id and the session token, so it is spent.
 */
function staleTokenCase(string $title, bool $freshSession, string $baseUrl, string $page, string $logFile): bool
{
    $reading = newJar();
    $form    = readForm(request($baseUrl . ltrim($page, '/'), $reading));
    $before  = countSent($logFile);

    // A session that never saw the form, against one that did but whose token has been replaced
    $sending = $freshSession ? newJar() : $reading;
    $post    = fields($form, '', '');

    if (!$freshSession) {
        unset($post[$form['token']]);
        $post[str_repeat('a', 32)] = '1';
    }

    $url      = ($form['base'] ?: $baseUrl) . 'index.php?option=com_ajax&module=mucronix_contact&format=json&method=send';
    $status   = null;
    $location = null;
    $body     = request($url, $sending, $post, $status, $location);
    $data     = json_decode($body, true)['data'] ?? null;
    $sent     = countSent($logFile) - $before;

    if (!is_array($data)) {
        report($title, 'not json', 'error', $sent, false, false);
        echo "  the answer is not json, so the request was taken over before the module saw it\n";

        /*
         * A redirect answers with headers and an empty body, so the body says nothing and the
         * status line says everything. This is the shape of the core check sending the visitor to
         * the front page instead of refusing.
         */
        echo '  http ', $status, $location === null ? '' : ', Location: ' . $location, "\n";

        if ($body !== '') {
            echo '  body begins: ', substr(trim(strip_tags($body)), 0, 160), "\n";
        }

        return false;
    }

    $message = (string) ($data['message'] ?? '');
    $wrong   = [];

    if ($data['success']) {
        $wrong[] = 'the submission was accepted with a token that is no good';
    }

    if (in_array($message, languageValues('MOD_MUCRONIX_CONTACT_ERROR_SEND'), true)) {
        $wrong[] = 'the wording is the general sending failure, which tells the visitor to wait'
            . ' when what is needed is a reload';
    } elseif (!in_array($message, languageValues('MOD_MUCRONIX_CONTACT_ERROR_TOKEN'), true)) {
        $wrong[] = 'the wording is neither the stale form message nor the sending failure: ' . $message;
    }

    $ok = $wrong === [] && $sent === 0;

    report($title, $data['success'] ? 'success' : 'error', 'error', $sent, false, $ok);

    foreach ($wrong as $line) {
        echo '  ', $line, "\n";
    }

    if ($wrong === []) {
        echo '  says: ', $message, "\n";
    }

    return $ok;
}

/**
 * The honeypot as it stands in the page.
 *
 * The sending cases post mcx_hp themselves, so they prove what the server does with it and nothing
 * about the markup: were the input to disappear from the form, every one of them would stay green
 * while no browser sent the field at all and the trap quietly caught nobody. Hiding is done with
 * the hidden attribute and a display rule, neither of which keeps a control out of a submission -
 * only disabled does that, so that is what is checked here.
 */
function honeypotFieldCase(string $title, string $baseUrl, string $page, string $logFile): bool
{
    $form  = readForm(request($baseUrl . ltrim($page, '/'), newJar()));
    $wrong = [];

    if (!hasField($form, 'mcx_hp')) {
        $wrong[] = 'no mcx_hp input in the form: nothing is sent, and the trap catches nobody';
    }

    if (preg_match('/<input[^>]*mcx_hp[^>]*\sdisabled/i', $form['html'])) {
        $wrong[] = 'the input is disabled, so a browser leaves it out of the submission entirely';
    }

    // type=hidden is the obvious one a bot skips; the field has to look like an ordinary text box
    if (preg_match('/<input[^>]*type="hidden"[^>]*mcx_hp/i', $form['html'])) {
        $wrong[] = 'the input is type="hidden", which a bot recognises and steps over';
    }

    // Hidden by the markup, so that switching the module CSS off cannot put it back on the page
    if (!preg_match('/<div class="mcx-hp"[^>]*\shidden/i', $form['html'])) {
        $wrong[] = 'the wrapper has no hidden attribute: with load_css off the field becomes visible';
    }

    $ok = $wrong === [];

    report($title, $ok ? 'in the form' : 'wrong', 'in the form', 0, false, $ok);

    foreach ($wrong as $line) {
        echo '  ', $line, "\n";
    }

    return $ok;
}

/**
 * A submission without JavaScript while a thank-you page is set.
 *
 * The answer to the post has to be a 303 pointing at that page rather than back at the form, and
 * the page it points at must not be carrying a message of the module: with a page of its own to
 * say thank you, nothing is put into the session, and a result left lying there would surface on
 * some later visit to the form.
 */
function successPagePostCase(string $title, string $baseUrl, string $page, string $logFile): bool
{
    $jar      = newJar();
    $form     = readForm(request($baseUrl . ltrim($page, '/'), $jar));
    $before   = countSent($logFile);
    $captcha  = $form['captcha'] ? solveCaptcha($form['base'] ?: $baseUrl, $jar, $form['token']) : '';
    $status   = null;
    $location = null;
    $wrong    = [];

    request(
        $form['action'] !== '' ? $form['action'] : $baseUrl . ltrim($page, '/'),
        $jar,
        fields($form, $captcha, ''),
        $status,
        $location
    );

    if ($status !== 303) {
        $wrong[] = 'the answer is http ' . $status . ', expected 303';
    }

    $fellBack = str_contains((string) $location, '#mcx-');

    if ((string) $location === '') {
        $wrong[] = 'no Location header at all';
    } elseif ($fellBack) {
        /*
         * The redirect points back at the form, which is the module rolling the thank-you page
         * back: the chosen menu item is deleted, unpublished or a system link with no page behind
         * it. What has to hold then is that the visitor is not left empty handed - the fallback is
         * an ordinary success on the form, never a silent drop onto the front page. The case still
         * counts as failed, because --success-page asserts a page that works.
         */
        $wrong[] = 'the module fell back to the form: the chosen menu item leads nowhere'
            . ' (deleted, unpublished, or a type with no page of its own). Look for the notice above the form.';

        if (!str_contains(request((string) $location, $jar), 'mcx-message--ok')) {
            $wrong[] = 'and the fallback itself is broken: no success message on the form either';
        }
    } elseif (preg_match('/[?&]Itemid=\d+(&|$)/', (string) $location)) {
        /*
         * The signature of the router having had nothing to route: no component, so no path, and
         * the site root comes back carrying the bare id. Reaching a visitor means the fallback did
         * not fire at all, which is the one outcome this whole branch exists to prevent.
         */
        $wrong[] = 'it goes to the site root carrying a bare Itemid: ' . $location
            . ' - the page could not be routed to and the module did not fall back';
    } elseif (str_contains(request((string) $location, $jar), 'mcx-message--ok')) {
        $wrong[] = 'the thank-you page shows the message block of the module: something was left in the session';
    }

    $sent = countSent($logFile) - $before;
    $ok   = $wrong === [] && $sent === 1;

    report($title, $wrong === [] ? 'redirected' : ($fellBack ? 'fell back' : 'wrong'), 'redirected', $sent, true, $ok);

    foreach ($wrong as $line) {
        echo '  ', $line, "\n";
    }

    if ($wrong === []) {
        echo '  goes to: ', $location, "\n";
    }

    return $ok;
}

/**
 * The same thing through com_ajax: the address travels in the json and the script navigates by it.
 *
 * It has to be absolute, because the script assigns it to window.location, and it must not be the
 * com_ajax endpoint, which is where an address taken from the current request would end up.
 */
function successPageAjaxCase(string $title, string $baseUrl, string $page, string $logFile): bool
{
    $jar     = newJar();
    $form    = readForm(request($baseUrl . ltrim($page, '/'), $jar));
    $before  = countSent($logFile);
    $captcha = $form['captcha'] ? solveCaptcha($form['base'] ?: $baseUrl, $jar, $form['token']) : '';
    $url     = ($form['base'] ?: $baseUrl) . 'index.php?option=com_ajax&module=mucronix_contact&format=json&method=send';
    $body    = request($url, $jar, fields($form, $captcha, ''));
    $data    = json_decode($body, true)['data'] ?? null;

    if (!is_array($data)) {
        report($title, 'no data', 'success', 0, true, false);
        echo '  response: ', substr(trim($body), 0, 300), "\n";

        return false;
    }

    $target = (string) ($data['redirect'] ?? '');
    $wrong  = [];

    if ($target === '') {
        /*
         * Either no page was chosen, or one was and the module rolled it back because the menu
         * item leads nowhere. The answer alone cannot tell them apart; the notice above the form
         * can, and says which.
         */
        $wrong[] = 'the answer carries no address: either no Thank You Page is chosen in the Basic tab,'
            . ' or the chosen one leads nowhere and was rolled back. The notice above the form says which.';
    } elseif (!preg_match('#^https?://#i', $target)) {
        $wrong[] = 'the address is not absolute, window.location would resolve it against the endpoint: ' . $target;
    } elseif (str_contains($target, 'option=com_ajax')) {
        $wrong[] = 'the address points back at the com_ajax endpoint: ' . $target;
    }

    $state = $data['success'] ? 'success' : 'error';
    $sent  = countSent($logFile) - $before;
    $ok    = $wrong === [] && $state === 'success' && $sent === 1;

    report($title, $state, 'success', $sent, true, $ok);

    foreach ($wrong as $line) {
        echo '  ', $line, "\n";
    }

    if ($wrong === []) {
        echo '  goes to: ', $target, "\n";
    }

    return $ok;
}

/**
 * A submission with the extra fields the owner described in the settings.
 *
 * $present are field names that have to be in the form, $absent names that must not be, and
 * $values are posted along with the usual ones. The message still has to go out: an extra field
 * that was refused may not take the form down with it.
 */
function extraFieldCase(string $title, array $present, array $absent, array $values, string $baseUrl, string $page, string $logFile): bool
{
    $jar    = newJar();
    $form   = readForm(request($baseUrl . ltrim($page, '/'), $jar));
    $before = countSent($logFile);
    $wrong  = [];

    foreach ($present as $name) {
        if (!hasField($form, $name)) {
            $wrong[] = 'missing: ' . $name;
        }
    }

    foreach ($absent as $name) {
        if (hasField($form, $name)) {
            $wrong[] = 'should not be there: ' . $name;
        }
    }

    /*
     * A field named captcha would have replaced the captcha itself, and the widget would be gone
     * from the page. Its presence is what proves the reserved name was refused rather than taken.
     */
    if (!$form['captcha']) {
        $wrong[] = 'the captcha widget is gone from the form'
            . ' (or no captcha is configured at all, in which case this case cannot judge the reserved name)';
    }

    $captcha = $form['captcha'] ? solveCaptcha($form['base'] ?: $baseUrl, $jar, $form['token']) : '';
    $url     = ($form['base'] ?: $baseUrl) . 'index.php?option=com_ajax&module=mucronix_contact&format=json&method=send';
    $body    = request($url, $jar, fields($form, $captcha, '', 'Automated test', $values));
    $data    = json_decode($body, true)['data'] ?? null;

    if (!is_array($data)) {
        report($title, 'no data', 'success', 0, true, false);
        echo '  response: ', substr(trim($body), 0, 300), "\n";

        return false;
    }

    $state = $data['success'] ? 'success' : 'error';
    $sent  = countSent($logFile) - $before;
    $ok    = $wrong === [] && $state === 'success' && $sent === 1;

    report($title, $state, 'success', $sent, true, $ok);

    foreach ($wrong as $line) {
        echo '  ', $line, "\n";
    }

    return $ok;
}

/**
 * Sends the form with a file attached, through com_ajax. Each case gets its own session, because
 * a successful one starts the thirty second wait.
 */
function attachmentCase(string $title, string $fixture, bool $expectMail, bool $expectError, string $baseUrl, string $page, string $logFile): bool
{
    $jar  = newJar();
    $form = readForm(request($baseUrl . ltrim($page, '/'), $jar));

    if (!$form['attachment']) {
        printf("%-34s SKIPPED: the form has no attachment field\n", $title);

        return true;
    }

    $before  = countSent($logFile);
    $captcha = $form['captcha'] ? solveCaptcha($form['base'] ?: $baseUrl, $jar, $form['token']) : '';
    $post    = fields($form, $captcha, '');

    // The type curl declares is irrelevant: the module reads the content itself
    $post['mcx_' . $form['moduleId'] . '[attachment]'] = new CURLFile($fixture, 'application/octet-stream', basename($fixture));

    $body = request(($form['base'] ?: $baseUrl) . 'index.php?option=com_ajax&module=mucronix_contact&format=json&method=send', $jar, $post);
    $data = json_decode($body, true)['data'] ?? null;

    if (!is_array($data)) {
        report($title, 'no data', $expectError ? 'error' : 'success', 0, $expectMail, false);
        echo '  response: ', substr(trim($body), 0, 300), "\n";

        return false;
    }

    $state = $data['success'] ? 'success' : 'error';
    $sent  = countSent($logFile) - $before;
    $ok    = $state === ($expectError ? 'error' : 'success') && $sent === ($expectMail ? 1 : 0);

    // A refusal has to name the attachment field, not just complain in general
    if ($expectError) {
        $ok = $ok && isset($data['errors']['attachment']);
    }

    report($title, $state, $expectError ? 'error' : 'success', $sent, $expectMail, $ok);

    if (!$ok && $expectError) {
        echo '  errors returned: ', $data['errors'] ? implode(', ', array_keys($data['errors'])) : '(none)',
            ', expected one for: attachment', "\n";
    }

    return $ok;
}

/**
 * Waits for a captcha solution to go stale and sends it anyway: the server has to refuse it.
 * This is the one case where the browser would need the widget to hand out a new challenge.
 */
function expiredCaptchaCase(string $baseUrl, string $page, string $jar, string $logFile): bool
{
    $form      = readForm(request($baseUrl . ltrim($page, '/'), $jar));
    $base      = $form['base'] ?: $baseUrl;
    $challenge = fetchChallenge($base, $jar, $form['token']);
    $payload   = solveChallenge($challenge);
    $wait      = max(0, challengeExpiry($challenge) - time() + 2);

    printf("  solved a challenge, waiting %d seconds for it to expire\n", $wait);
    sleep($wait);

    $before = countSent($logFile);
    $body   = request(
        $base . 'index.php?option=com_ajax&module=mucronix_contact&format=json&method=send',
        $jar,
        fields($form, $payload, '')
    );
    $data   = json_decode($body, true)['data'] ?? null;

    if (!is_array($data)) {
        report('slow: expired captcha', 'no data', 'error', 0, false, false);

        return false;
    }

    $state = $data['success'] ? 'success' : 'error';
    $sent  = countSent($logFile) - $before;
    $ok    = $state === 'error' && $sent === 0 && isset($data['errors']['captcha']);

    report('slow: expired captcha', $state, 'error', $sent, false, $ok);

    if (!$ok) {
        echo '  errors returned: ', $data['errors'] ? implode(', ', array_keys($data['errors'])) : '(none)',
            ', expected one for: captcha', "\n";
    }

    return $ok;
}

echo "target: ", $baseUrl, ltrim($page, '/'), "\nlog:    $logFile\n";
echo "requires \"Log Sent Messages\" to be on in the module settings, Advanced tab.\n\n";

/*
 * Before anything is sent: a required field the script cannot fill would refuse every
 * submission, and the run would be a spread of reds with the cause named in none of them.
 */
requireFillable(readForm(request($baseUrl . ltrim($page, "/"), newJar())));

// Without JavaScript: the form posts to the page itself
$plain = newJar();
$failures += postCase('plain: honeypot filled', 'i-am-a-bot', false, false, $baseUrl, $page, $plain, $logFile) ? 0 : 1;
$failures += postCase('plain: honeypot empty', '', true, false, $baseUrl, $page, $plain, $logFile) ? 0 : 1;
$failures += postCase('plain: repeat within 30 seconds', '', false, true, $baseUrl, $page, $plain, $logFile) ? 0 : 1;

// Through com_ajax, as the browser script does it
$ajax = newJar();
$failures += ajaxCase('ajax: honeypot filled', 'i-am-a-bot', false, false, $baseUrl, $page, $ajax, $logFile) ? 0 : 1;
$failures += ajaxCase('ajax: honeypot empty', '', true, false, $baseUrl, $page, $ajax, $logFile) ? 0 : 1;

/*
 * A refusal must not spoil the session: straight after it, in the same session, a correct
 * submission with a freshly solved captcha has to go through. A fresh session for the pair,
 * so that the throttle does not answer instead of the validation.
 */
$retry = newJar();
$failures += ajaxCase('ajax: refused, name empty', '', false, true, $baseUrl, $page, $retry, $logFile, '', 'name') ? 0 : 1;
$failures += ajaxCase('ajax: retry right after refusal', '', true, false, $baseUrl, $page, $retry, $logFile) ? 0 : 1;

$total = 7;

/*
 * Attachments. The module must have the attachment field switched on, otherwise the file is
 * ignored and a case would pass for the wrong reason; readForm() checks that and says so.
 */
$fixtures = [
    'good'      => makeFixture('mcx-test-good.txt', "An ordinary attachment.\n"),
    'forbidden' => makeFixture('mcx-test-script.php', "<?php echo 'nothing';\n"),
    'oversized' => makeFixture('mcx-test-big.txt', 'padding ', 3 * 1048576),
    'phpInText' => makeFixture('mcx-test-hidden.txt', "looks harmless\n<?php echo 'surprise';\n"),
    'wrongType' => makeArchiveFixture('mcx-test-archive.png'),
];

$total    += 5;
$failures += attachmentCase('file: allowed type', $fixtures['good'], true, false, $baseUrl, $page, $logFile) ? 0 : 1;
$failures += attachmentCase('file: forbidden extension', $fixtures['forbidden'], false, true, $baseUrl, $page, $logFile) ? 0 : 1;
$failures += attachmentCase('file: over the size limit', $fixtures['oversized'], false, true, $baseUrl, $page, $logFile) ? 0 : 1;
$failures += attachmentCase('file: php hidden in a txt', $fixtures['phpInText'], false, true, $baseUrl, $page, $logFile) ? 0 : 1;
$failures += attachmentCase('file: zip renamed to png', $fixtures['wrongType'], false, true, $baseUrl, $page, $logFile) ? 0 : 1;

foreach ($fixtures as $fixture) {
    @unlink($fixture);
}

/*
 * Extra fields. They come from a module parameter the script cannot set, so the block named in the
 * comment at the top has to be in the Extra Fields tab. Without it the three cases are skipped
 * rather than passed: a missing field would otherwise look exactly like a refused one.
 */
$probe = readForm(request($baseUrl . ltrim($page, '/'), newJar()));

if (!hasField($probe, 'mcx_test_topic')) {
    echo "\nskipped: the extra field cases. Paste this into the Extra Fields tab of the module:\n";
    echo "    <field name=\"mcx_test_topic\" type=\"text\" label=\"Test topic\" />\n";
    echo "    <field name=\"captcha\" type=\"text\" label=\"Refused name\" />\n";
    echo "    <field name=\"mcx_test_sql\" type=\"sql\" label=\"Refused type\" query=\"SELECT 1\" />\n";
} else {
    $total += 3;

    // The value is posted and the message goes out. Whether it reaches the body shows in the mailbox
    $failures += extraFieldCase(
        'extra: allowed field taken, message sent',
        ['mcx_test_topic'],
        [],
        ['mcx_test_topic' => 'Topic from the test run'],
        $baseUrl,
        $page,
        $logFile
    ) ? 0 : 1;

    /*
     * The reserved name. The field called captcha must not be in the form as a text box, the real
     * captcha must still be there, and a message must still go out.
     */
    $failures += extraFieldCase(
        'extra: reserved name refused, form still sends',
        ['mcx_test_topic'],
        [],
        [],
        $baseUrl,
        $page,
        $logFile
    ) ? 0 : 1;

    // The refused type. type="sql" would have rendered a select filled from the database
    $failures += extraFieldCase(
        'extra: refused type kept out, form still sends',
        ['mcx_test_topic'],
        ['mcx_test_sql'],
        [],
        $baseUrl,
        $page,
        $logFile
    ) ? 0 : 1;
}

/*
 * The page the message names. A field of the module rather than of the visitor, so it is read from
 * the markup instead of being sent: what matters is that the form carries the page at all.
 */
$total++;
$failures += pageFieldCase('page: the form carries its own address', $baseUrl, $page, $logFile) ? 0 : 1;

// The trap itself, read from the markup: the sending cases above supply mcx_hp on their own
$total++;
$failures += honeypotFieldCase('honeypot: present and submittable', $baseUrl, $page, $logFile) ? 0 : 1;

/*
 * A spent token, both ways it happens. The second is the one that was answering "could not be
 * sent, try again later": the core check redirects instead of refusing when the session is new,
 * and nothing of ours ran at all. Neither case sends a message, so both are free.
 */
$total    += 2;
$failures += staleTokenCase('token: wrong token, live session', false, $baseUrl, $page, $logFile) ? 0 : 1;
$failures += staleTokenCase('token: no session at all', true, $baseUrl, $page, $logFile) ? 0 : 1;

/*
 * The log must not grow from people looking at the page. Run with the settings as they are:
 * with a mistake in the extra fields it is the case that catches the flood coming back.
 */
$total++;
$failures += quietRenderCase('log: drawing the form writes nothing', 5, $baseUrl, $page, $logFile) ? 0 : 1;

/*
 * The thank-you page, only with --success-page. Each case gets its own session because both send a
 * message, and the plain post runs first: it is the path that would strand a visitor on a repeated
 * post, so it is the one worth seeing fail first.
 */
if ($successPage) {
    $total    += 2;
    $failures += successPagePostCase('thanks: plain post goes there', $baseUrl, $page, $logFile) ? 0 : 1;
    $failures += successPageAjaxCase('thanks: ajax answers with address', $baseUrl, $page, $logFile) ? 0 : 1;
} else {
    echo "\nskipped: the thank-you page cases. Choose a Thank You Page in the Basic tab, then pass --success-page.\n";
}

/*
 * Telegram, only with --telegram: nothing in the page says whether it is switched on, and a case
 * that cannot tell "off" from "broken" would pass for the wrong reason.
 */
if ($telegram) {
    $total++;
    $failures += telegramCase('telegram: switched on, the message still goes out', $baseUrl, $page, $logFile) ? 0 : 1;
} else {
    echo "\nskipped: the Telegram case. Pass --telegram once it is configured, best with a wrong token.\n";
}

/*
 * The slow pair, only with --slow: a challenge is valid for five minutes by default, so the
 * script has to idle that long. It checks the server side of an expired captcha; whether the
 * widget in the browser hands out a new challenge after a refusal stays unchecked either way.
 */
if ($slow) {
    $total += 2;
    $stale  = newJar();

    $failures += expiredCaptchaCase($baseUrl, $page, $stale, $logFile) ? 0 : 1;
    $failures += ajaxCase('slow: retry with a fresh captcha', '', true, false, $baseUrl, $page, $stale, $logFile) ? 0 : 1;
} else {
    echo "\nskipped: the expired captcha case. Pass --slow to run it, it idles about five minutes.\n";
}

foreach ($jars as $jar) {
    @unlink($jar);
}

echo "\n", $failures === 0 ? "OK: every case behaves as it should\n" : "FAILED: $failures of $total\n";

exit($failures === 0 ? 0 : 1);
