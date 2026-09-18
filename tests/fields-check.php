<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

/**
 * Runs MessageFields::collect() - the one walk over the fields that both the mail and the Telegram
 * message are built from - against a real Joomla Form, and checks what each kind of field turns into.
 *
 * This is the only check there is on the contents of a notification. The suite next door drives the
 * site over HTTP and never sees a mail body or a chat: that a field with options reaches the
 * recipient as its wording rather than as "phone", and a checkbox list as its ticked options rather
 * than as the word "Array", was found by hand on a live send, twice.
 *
 * Nothing here touches the site. Joomla is loaded far enough to build a form, with a language that
 * answers with whatever it is asked for: what is being judged is which string is chosen, not its
 * translation.
 *
 * Usage:
 *   php tests/fields-check.php [--site=<path to a Joomla 6 site>]
 *
 * Exit code 0 when every field turns into what it should, 1 otherwise.
 */

$options = getopt('', ['site:']);
$site    = rtrim($options['site'] ?? 'D:/OSPanel1/home/joomla6', '/\\');

if (!is_file($site . '/libraries/loader.php')) {
    fwrite(STDERR, "no Joomla at: $site\n");
    fwrite(STDERR, "pass --site=<path to a Joomla 6 site>\n");
    exit(1);
}

\define('_JEXEC', 1);
\define('JPATH_BASE', $site);
\define('JPATH_ROOT', JPATH_BASE);
\define('JPATH_SITE', JPATH_BASE);
\define('JPATH_ADMINISTRATOR', JPATH_BASE . '/administrator');
\define('JPATH_LIBRARIES', JPATH_BASE . '/libraries');
\define('JPATH_CONFIGURATION', JPATH_BASE);
\define('JPATH_PLUGINS', JPATH_BASE . '/plugins');
\define('JPATH_THEMES', JPATH_BASE . '/templates');
\define('JPATH_CACHE', JPATH_BASE . '/cache');
\define('JPATH_MANIFESTS', JPATH_ADMINISTRATOR . '/manifests');
\define('JDEBUG', false);

require JPATH_LIBRARIES . '/vendor/autoload.php';
require JPATH_LIBRARIES . '/loader.php';

JLoader::setup();

require \dirname(__DIR__) . '/src/Helper/MessageFields.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Mucronix\Module\MucronixContact\Site\Helper\MessageFields;

// Enough of an application for the form to build its fields, and no more
Factory::$application = new class () {
    public function getIdentity()
    {
        return new User();
    }
};

Factory::$language = new class () {
    public function _($string, $jsSafe = false, $interpretBackSlashes = true)
    {
        return $string;
    }

    public function getTag()
    {
        return 'en-GB';
    }
};

$xml = <<<'XML'
<form>
    <fieldset name="contact">
        <field name="name" type="text" label="Name" />
        <field name="message" type="textarea" label="Message" />
        <field name="consent" type="checkbox" label="Consent" required="true" />
        <field name="mcx_test_opt" type="checkbox" label="Single checkbox" />
        <field name="mcx_test_list" type="list" label="How to reach you">
            <option value="">— choose —</option>
            <option value="email">By mail</option>
            <option value="phone">By phone</option>
        </field>
        <field name="mcx_test_radio" type="radio" label="Best time">
            <option value="day">During the day</option>
            <option value="evening">In the evening</option>
        </field>
        <field name="mcx_test_boxes" type="checkboxes" label="Interested in">
            <option value="price">The price</option>
            <option value="terms">The terms</option>
            <option value="demo">A demonstration</option>
        </field>
        <field name="captcha" type="text" label="Captcha" />
        <field name="mcx_hp" type="text" label="Trap" />
    </fieldset>
</form>
XML;

$form = new Form('mcx.check', ['control' => 'mcx_9']);
$form->load($xml);

$data = [
    'name'           => 'Peter',
    'message'        => 'A line of text',
    'consent'        => 1,
    'mcx_test_opt'   => 0,
    'mcx_test_list'  => 'phone',
    'mcx_test_radio' => 'day',
    'mcx_test_boxes' => ['price', 'terms'],
    'captcha'        => 'solved',
    'mcx_hp'         => '',
];

$failures = 0;

/**
 * Collects the walk into label => value, the way a notification reads it.
 */
$collect = static function (array $data) use ($form): array {
    $lines = [];

    foreach (MessageFields::collect($form, $data) as $line) {
        $lines[rtrim($line['label'], ': ')] = $line['value'];
    }

    return $lines;
};

$say = static function (string $what, $got, $want) use (&$failures): void {
    $ok = $got === $want;
    $failures += $ok ? 0 : 1;

    printf("%-4s %-24s %s\n", $ok ? 'ok' : 'FAIL', $what, $ok ? (string) $got : var_export($got, true) . '   want: ' . var_export($want, true));
};

$lines = $collect($data);

$say('plain text', $lines['Name'] ?? null, 'Peter');
$say('textarea', $lines['Message'] ?? null, 'A line of text');
$say('list by its wording', $lines['How to reach you'] ?? null, 'By phone');
$say('radio by its wording', $lines['Best time'] ?? null, 'During the day');
$say('checkbox list', $lines['Interested in'] ?? null, 'The price, The terms');
$say('unticked checkbox', $lines['Single checkbox'] ?? null, 'JNO');

// A required single checkbox says nothing a recipient does not already know
$say('required checkbox left out', isset($lines['Consent']), false);
$say('captcha left out', isset($lines['Captcha']), false);
$say('trap left out', isset($lines['Trap']), false);

// A value with no option to match is written as it came: saying nothing would be worse
$lines = $collect(array_merge($data, ['mcx_test_list' => 'carrier-pigeon']));
$say('unknown value kept', $lines['How to reach you'] ?? null, 'carrier-pigeon');

// An empty field is left out altogether, an empty checkbox list included
$lines = $collect(array_merge($data, ['name' => '', 'mcx_test_boxes' => []]));
$say('empty text left out', isset($lines['Name']), false);
$say('nothing ticked left out', isset($lines['Interested in']), false);

// A ticked checkbox is a "yes" of its own
$lines = $collect(array_merge($data, ['mcx_test_opt' => 1]));
$say('ticked checkbox', $lines['Single checkbox'] ?? null, 'JYES');

echo "\n", $failures === 0
    ? "OK: every field turns into what a recipient should read\n"
    : "FAILED: $failures of the checks\n";

exit($failures === 0 ? 0 : 1);
