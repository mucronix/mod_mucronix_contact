<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

/**
 * The half of snippet-check.php that reads the notes on extra fields. Included by it, returns the
 * number of problems found. Expects $root, the project directory, and $site, a Joomla 6 site.
 *
 * A snippet on that page is a promise: paste it and it works. So each one is handed to
 * MucronixContactHelper::getExtraFields() - the method the module runs on the setting itself - and
 * has to come back whole, every field of it, with no notice raised. What comes back is then loaded
 * into a Joomla Form, the way the module does it, and every field has to turn into the core class
 * its type names.
 *
 * Nothing here touches the site: Joomla is loaded far enough to build a form, with a language that
 * answers with whatever it is asked for.
 *
 * Not part of the package.
 */

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

require $root . '/src/Helper/MucronixContactHelper.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\User\User;
use Mucronix\Module\MucronixContact\Site\Helper\MucronixContactHelper;

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

$class   = new ReflectionClass(MucronixContactHelper::class);
$helper  = $class->newInstanceWithoutConstructor();
$vet     = $class->getMethod('getExtraFields');
$notices = $class->getProperty('extraFieldNotices');

$allowed  = $class->getConstant('ALLOWED_EXTRA_TYPES');
$reserved = $class->getConstant('RESERVED_FIELDS');
$refused  = $class->getConstant('REFUSED_EXTRA_ATTRIBUTES');

$pages    = glob($root . '/media/docs/extra-fields.*.html');
$problems = 0;
$shapes   = [];

if (\count($pages) < 3) {
    fwrite(STDERR, "fewer than three pages of notes on extra fields in media/docs\n");
    exit(1);
}

foreach ($pages as $file) {
    $html = (string) file_get_contents($file);
    $tag  = explode('.', basename($file))[1];

    preg_match_all('~<pre><code>(.*?)</code></pre>~s', $html, $blocks);

    echo basename($file), ': ', \count($blocks[1]), " snippets\n";

    $say = static function (string $line) use (&$problems): void {
        echo '  ', $line, "\n";
        $problems++;
    };

    $seen  = [];
    $shape = [];

    foreach ($blocks[1] as $i => $snippet) {
        $snippet = html_entity_decode($snippet, ENT_QUOTES, 'UTF-8');
        $number  = 'snippet ' . ($i + 1) . ': ';

        $notices->setValue($helper, []);

        $nodes = $vet->invoke($helper, $snippet);

        foreach ($notices->getValue($helper) as $notice) {
            $say($number . 'the module complains: ' . $notice['log']);
        }

        // A field the module skipped is gone from what it returned, and a snippet has to arrive whole
        if (\count($nodes) !== substr_count($snippet, '<field ')) {
            $say($number . \count($nodes) . ' fields accepted out of ' . substr_count($snippet, '<field '));
        }

        if ($nodes === []) {
            continue;
        }

        // Put in front of the captcha of the module's own form, the way loadDefinition() does it
        $document = new DOMDocument();
        $document->load($root . '/forms/contact.xml');

        $captcha = (new DOMXPath($document))->query('//field[@name="captcha"]')->item(0);

        foreach ($nodes as $node) {
            $captcha->parentNode->insertBefore($document->importNode($node, true), $captcha);
        }

        $form = new Form('mcx.snippet.' . $tag . '.' . $i, ['control' => 'mcx_9']);

        if (!$form->load($document->saveXML())) {
            $say($number . 'the form refused the merged description');

            continue;
        }

        foreach ($nodes as $node) {
            $name    = $node->getAttribute('name');
            $type    = strtolower($node->getAttribute('type'));
            $field   = $form->getField($name);
            $shape[] = $name . ':' . $type;

            $seen[$type] = true;

            if ($field === false) {
                $say($number . "field $name is not in the form after loading");

                continue;
            }

            /*
             * An unknown type does not fail in Joomla, it quietly becomes a text box. tel is exactly
             * that case and is expected: seen on 6.1.3, the core class is TelephoneField, the type
             * telephone, and no class answers to tel. The module accepts tel and not telephone, so
             * a phone field is a text box with a rule, and the snippets say inputmode="tel".
             */
            $expected = $type === 'tel' ? 'Text' : ucfirst($type);

            if (\get_class($field) !== 'Joomla\\CMS\\Form\\Field\\' . $expected . 'Field') {
                $say($number . "field $name of type $type was built as " . \get_class($field));
            }

            if (trim($node->getAttribute('label')) === '') {
                $say($number . "field $name has no label");
            }

            // The core radio layout prints nothing at all for an empty option list
            if (\in_array($type, ['list', 'radio', 'checkboxes'], true) && $node->getElementsByTagName('option')->length < 2) {
                $say($number . "field $name of type $type needs at least two options");
            }

            // A rule that does not exist throws on every send, and the visitor gets "not accepted"
            $rule = $node->getAttribute('validate');

            if ($rule !== '' && FormHelper::loadRuleType($rule) === false) {
                $say($number . "field $name names a validation rule that does not exist: $rule");
            }
        }
    }

    foreach ($allowed as $type) {
        if (!isset($seen[$type])) {
            $say("no snippet for the accepted type $type");
        }
    }

    // What the page lists by hand has to be what the code holds, or the page goes stale unnoticed
    foreach (array_merge($allowed, $reserved, $refused) as $word) {
        if (!str_contains($html, '<code>' . $word . '</code>')) {
            $say("the page does not name $word, which the code does");
        }
    }

    // Every wording quoted from the administration has to be in the language file it is quoted from
    $strings = [];

    foreach (file($root . '/language/' . $tag . '/mod_mucronix_contact.ini') as $line) {
        if (preg_match('/^[A-Z0-9_]+="(.*)"\s*$/', $line, $match)) {
            $strings[] = html_entity_decode(str_replace('\"', '"', $match[1]), ENT_QUOTES, 'UTF-8');
        }
    }

    preg_match_all('~<span class="ui">(.*?)</span>~s', $html, $quotes);

    foreach ($quotes[1] as $quote) {
        $quote = trim(preg_replace('/\s+/u', ' ', html_entity_decode($quote, ENT_QUOTES, 'UTF-8')));

        // The ellipsis stands where the message carries the name of the field, the type or the attribute
        $pattern = '/' . implode('.+?', array_map(static fn ($part) => preg_quote(trim($part), '/'), explode('…', $quote))) . '/u';

        if (preg_grep($pattern, $strings) === []) {
            $say("quoted wording is not in language/$tag: $quote");
        }
    }

    echo '  quoted wordings: ', \count($quotes[1]), "\n";

    $shapes[$tag] = implode(' ', $shape);
}

// The three pages are one text in three languages: the same fields, by the same names, in the same order
if (\count(array_unique($shapes)) !== 1) {
    echo "the pages do not hold the same fields in the same order:\n";

    foreach ($shapes as $tag => $shape) {
        echo '  ', $tag, ': ', $shape, "\n";
    }

    $problems++;
}

return $problems;
