<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

/**
 * Builds the installable package.
 *
 * The manifest has to sit in the root of the archive, and nothing belonging to the repository or to
 * testing goes in: tests/, build/, update/, this script, the spec and ENV.md stay behind. LICENSE
 * and README.md do ship - the licence because the GPL and the extensions directory ask for it, the
 * README because the site owner installs from the administrator and never opens the repository.
 *
 * Usage:
 *   php build.php [target.zip]
 *
 * Without an argument the package is written to build/mod_mucronix_contact-<version>.zip.
 */

$root = __DIR__;

/**
 * Refuses to build when the version disagrees anywhere.
 *
 * One release carries the number in five places: the manifest, joomla.asset.json, update.xml, and
 * twice inside the download address - the release tag and the file name. A mismatch is silent:
 * the package installs either way, and the update server then offers a version nobody can
 * download, or offers nothing at all because the numbers never meet. Nothing on a site shows it.
 *
 * @param   string  $root  The project directory.
 *
 * @return  array{0: string, 1: string[]}  The manifest version, and what disagrees with it.
 */
function checkVersions(string $root): array
{
    $manifest = simplexml_load_file("$root/mod_mucronix_contact.xml");
    $asset    = json_decode(file_get_contents("$root/media/joomla.asset.json"), true);
    $update   = simplexml_load_file("$root/update/mod_mucronix_contact.xml");

    $version = (string) $manifest->version;
    $wrong   = [];

    if ($asset['version'] !== $version) {
        $wrong[] = "joomla.asset.json says {$asset['version']}, the manifest says $version";
    }

    if ((string) $update->update->version !== $version) {
        $wrong[] = 'update.xml says ' . $update->update->version . ", the manifest says $version";
    }

    $url = (string) $update->update->downloads->downloadurl;

    if (!str_contains($url, "/v$version/")) {
        $wrong[] = "the release tag in the download address is not v$version: $url";
    }

    if (!str_contains($url, "mod_mucronix_contact-$version.zip")) {
        $wrong[] = "the file name in the download address is not mod_mucronix_contact-$version.zip";
    }

    // The address in the manifest has to be the one the update file is actually published at
    $server = (string) $manifest->updateservers->server;

    if ($server !== 'https://mucronix.com/updates/mod_mucronix_contact.xml') {
        $wrong[] = "the update server address is unexpected: $server";
    }

    return [$version, $wrong];
}

/*
 * Named one by one on purpose. A folder that happens to grow must never add a file to a package
 * without somebody deciding it should be there.
 */
$files = [
    'mod_mucronix_contact.xml',
    'LICENSE',
    'README.md',
    'README.ru.md',
    'script.php',
    'services/provider.php',
    'forms/contact.xml',
    'layouts/mucronix/form/field/file.php',
    'src/Dispatcher/Dispatcher.php',
    'src/Field/CustomcssField.php',
    'src/Field/StylingdocsField.php',
    'src/Helper/MailSender.php',
    'src/Helper/MessageFields.php',
    'src/Helper/MucronixContactHelper.php',
    'src/Helper/TelegramSender.php',
    'tmpl/default.php',
    'tmpl/default_mail.php',
    'media/joomla.asset.json',
    'media/css/form-base.css',
    'media/css/form-theme.css',
    'media/docs/styling.en-GB.html',
    'media/docs/styling.ru-RU.html',
    'media/docs/styling.uk-UA.html',
    'media/js/form.js',
    'language/en-GB/mod_mucronix_contact.ini',
    'language/en-GB/mod_mucronix_contact.sys.ini',
    'language/ru-RU/mod_mucronix_contact.ini',
    'language/ru-RU/mod_mucronix_contact.sys.ini',
    'language/uk-UA/mod_mucronix_contact.ini',
    'language/uk-UA/mod_mucronix_contact.sys.ini',
];

[$version, $wrong] = checkVersions($root);

if ($wrong !== []) {
    fwrite(STDERR, "versions disagree, nothing was built:\n  " . implode("\n  ", $wrong) . "\n");
    exit(1);
}

echo 'version: ', $version, " - agrees in the manifest, joomla.asset.json, update.xml and the download address\n";

$missing = array_values(array_filter($files, fn ($file) => !is_file("$root/$file")));

if ($missing !== []) {
    fwrite(STDERR, "missing from the project:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}

$out = $argv[1] ?? "$root/build/mod_mucronix_contact-$version.zip";

if (!is_dir(\dirname($out))) {
    mkdir(\dirname($out), 0o755, true);
}

@unlink($out);

$zip = new ZipArchive();

if ($zip->open($out, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "could not create $out\n");
    exit(1);
}

foreach ($files as $file) {
    $zip->addFile("$root/$file", $file);
}

$zip->close();

// Read the finished archive back rather than trusting the list: what is in it is what ships
$check = new ZipArchive();
$check->open($out);

echo 'built:   ', $out, "\n";
echo 'files:   ', $check->numFiles, "\n";

for ($i = 0; $i < $check->numFiles; $i++) {
    $stat = $check->statIndex($i);
    printf("  %-45s %7d bytes\n", $stat['name'], $stat['size']);
}

$check->close();

/*
 * The sixth place the version reaches is the checksum in update.xml, and this script cannot check
 * that one: Joomla hashes the file it downloads from the release, and at build time the release
 * does not exist yet. So the sum is printed rather than verified - copy it into <sha256> once the
 * release is up. Hashing the archive here is safe: GitHub hands the asset back byte for byte, and
 * that was checked by downloading 1.0.0 and comparing. The release order is in section 14 of the
 * spec; getting it wrong offers an update that cannot be downloaded or one that is refused.
 */
echo 'sha256:  ', hash_file('sha256', $out), "\n";
