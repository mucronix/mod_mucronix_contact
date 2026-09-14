<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Helper;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Sends a copy of the message to a Telegram chat.
 *
 * Second in line behind the mail and never in its way: every failure here is thrown back to the
 * caller, which logs it and still reports success to the visitor. The mail is the message; this is
 * a convenience on top of it.
 *
 * @since  1.0.0
 */
class TelegramSender
{
    /**
     * Fields that never belong in the message. The same two the mail leaves out: one is a check,
     * the other a trap, and neither is anything the visitor wrote.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const SKIP_FIELDS = ['captcha', 'mcx_hp'];

    /**
     * Seconds the whole request may take. The visitor is waiting behind it and the mail has already gone.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const TIMEOUT = 5;

    /**
     * Seconds to reach the host. Shorter than the whole request: a host that does not answer at all
     * should not eat the entire budget that a slow answer may still need.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const CONNECT_TIMEOUT = 3;

    /**
     * Longest text sendMessage takes. Documented as "1-4096 characters after entities parsing".
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_TEXT = 4096;

    /**
     * Longest caption sendPhoto and sendDocument take, documented as "0-1024 characters".
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_CAPTION = 1024;

    /**
     * Extensions Telegram is willing to take as a photo rather than as a file.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Sends the message, and the attachment after it when that is switched on.
     *
     * @param   Form        $form        The contact form, used for the field labels.
     * @param   array       $data        The filtered and validated form data.
     * @param   Registry    $params      The module parameters.
     * @param   string      $pageUrl     The page the form was sent from, already checked.
     * @param   array|null  $attachment  ['path' => file on disk, 'name' => the name the visitor gave it]
     *
     * @return  void
     *
     * @since   1.0.0
     * @throws  \RuntimeException  On anything Telegram or the network refuses.
     */
    public function send(Form $form, array $data, Registry $params, string $pageUrl, ?array $attachment = null): void
    {
        $token  = trim((string) $params->get('telegram_token', ''));
        $chatId = trim((string) $params->get('telegram_chat_id', ''));

        if ($token === '' || $chatId === '') {
            throw new \RuntimeException('Telegram is switched on but the token or the chat id is missing');
        }

        $this->call($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text'    => $this->getText($form, $data, $pageUrl, $attachment),
        ]);

        if ($attachment === null || !$params->get('telegram_attach', 0)) {
            return;
        }

        $this->sendAttachment($token, $chatId, $attachment);
    }

    /**
     * Builds the text of the message, in the same order the mail lists its rows.
     *
     * No parse_mode, and that is the decision rather than an omission. With one, every angle
     * bracket and ampersand a visitor types has to be escaped or Telegram refuses the whole
     * message, and a contact form has nothing to gain from bold text.
     *
     * @param   Form        $form        The contact form, used for the field labels.
     * @param   array       $data        The filtered and validated form data.
     * @param   string      $pageUrl     The page the form was sent from.
     * @param   array|null  $attachment  The attached file, when there is one.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getText(Form $form, array $data, string $pageUrl, ?array $attachment = null): string
    {
        $lines = [];

        foreach ($form->getFieldset('contact') as $field) {
            if (\in_array($field->fieldname, self::SKIP_FIELDS, true)) {
                continue;
            }

            $value = $data[$field->fieldname] ?? '';

            if ($field->fieldname === 'consent') {
                $value = $value ? Text::_('JYES') : Text::_('JNO');
            }

            if (trim((string) $value) === '') {
                continue;
            }

            // The consent label can carry a link to the policy article, the text alone belongs here
            $lines[] = trim(strip_tags($field->title)) . ': ' . $value;
        }

        /*
         * Named here as well as in the mail: a mail client or a scanner may drop the file, and in
         * Telegram the upload is a second request that can fail on its own while this one went through.
         */
        if ($attachment !== null) {
            $lines[] = Text::_('MOD_MUCRONIX_CONTACT_MAIL_ATTACHMENT') . ': ' . $attachment['name'];
        }

        $lines[] = '';
        $lines[] = Text::_('MOD_MUCRONIX_CONTACT_MAIL_PAGE') . ': ' . $pageUrl;

        return $this->shorten(implode("\n", $lines), self::MAX_TEXT);
    }

    /**
     * Sends the attached file as a second request.
     *
     * Not sendMediaGroup, which the Wedal module reaches for: its media array "must include 2-10
     * items", and this form takes one file at a time, so that method could never work here.
     *
     * @param   string  $token       The bot token.
     * @param   string  $chatId      The chat the message goes to.
     * @param   array   $attachment  ['path' => file on disk, 'name' => the name the visitor gave it]
     *
     * @return  void
     *
     * @since   1.0.0
     * @throws  \RuntimeException  On anything Telegram or the network refuses.
     */
    private function sendAttachment(string $token, string $chatId, array $attachment): void
    {
        $path = (string) $attachment['path'];

        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('The attachment is gone before Telegram could be given it: %s', $path));
        }

        $extension = strtolower(pathinfo((string) $attachment['name'], \PATHINFO_EXTENSION));
        $isPhoto   = \in_array($extension, self::PHOTO_EXTENSIONS, true);

        /*
         * A photo as a photo, everything else as a file. Telegram takes at most 10 MB for a photo
         * against 50 MB for a file, and turns down a photo whose width and height add up past
         * 10000 or whose sides differ by more than twenty times. Such a refusal is named in the
         * log with the reason Telegram gave, and the mail has long gone either way.
         */
        $method = $isPhoto ? 'sendPhoto' : 'sendDocument';
        $field  = $isPhoto ? 'photo' : 'document';

        $this->call($token, $method, [
            'chat_id' => $chatId,
            // The name the visitor gave it, not the safe one on disk, which means nothing to anybody
            $field    => new \CURLFile($path, '', (string) $attachment['name']),
            'caption' => $this->shorten((string) $attachment['name'], self::MAX_CAPTION),
        ]);
    }

    /**
     * Makes one request to the Bot API and reads what came back.
     *
     * @param   string  $token   The bot token.
     * @param   string  $method  The Bot API method.
     * @param   array   $fields  The parameters of the method.
     *
     * @return  void
     *
     * @since   1.0.0
     * @throws  \RuntimeException  When the request fails or Telegram answers anything but ok.
     */
    private function call(string $token, string $method, array $fields): void
    {
        /*
         * The connect timeout is passed through transport.curl because the Joomla transport sets
         * CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT to the same number, and these options are
         * applied after its own. Nothing here touches certificate verification: the transport
         * brings its own root certificates and the token travels inside this connection.
         */
        $http = HttpFactory::getHttp(['transport.curl' => [\CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT]]);

        $hasFile = false;

        foreach ($fields as $value) {
            if ($value instanceof \CURLFile) {
                $hasFile = true;

                break;
            }
        }

        // Announcing multipart is what makes the transport hand the array to curl unencoded
        $headers = $hasFile ? ['Content-Type' => 'multipart/form-data'] : [];

        try {
            /*
             * Post, not get. The token stands in the path either way, that is how the Bot API is
             * built, but the message the visitor wrote has no business in a request line: it would
             * run into the length limit of a url and into every log along the road.
             */
            $response = $http->post(
                \sprintf('https://api.telegram.org/bot%s/%s', $token, $method),
                $fields,
                $headers,
                self::TIMEOUT
            );
        } catch (\Throwable $e) {
            /*
             * The message of the exception is repeated rather than the exception passed on, because
             * curl puts the whole url into some of its own, and the url holds the token.
             */
            throw new \RuntimeException(\sprintf('Telegram %s: the request failed, %s', $method, $this->clean($e->getMessage(), $token)));
        }

        $body   = (string) $response->body;
        $answer = json_decode($body, true);

        if (!\is_array($answer)) {
            throw new \RuntimeException(\sprintf(
                'Telegram %s: http %d, the answer could not be read, %s',
                $method,
                $response->code,
                $this->clean(substr(trim($body), 0, 200), $token)
            ));
        }

        // Read rather than discarded: an answer of "ok": false is the whole point of asking
        if (empty($answer['ok'])) {
            throw new \RuntimeException(\sprintf(
                'Telegram %s: http %d, %s',
                $method,
                $response->code,
                $this->clean((string) ($answer['description'] ?? 'no reason given'), $token)
            ));
        }
    }

    /**
     * Keeps the token out of anything that goes to the log.
     *
     * @param   string  $message  The text about to be written down.
     * @param   string  $token    The bot token.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function clean(string $message, string $token): string
    {
        return $token === '' ? $message : str_replace($token, '***', $message);
    }

    /**
     * Cuts a string to what Telegram accepts, counting characters rather than bytes.
     *
     * Past the limit Telegram refuses the whole request, so a long message would arrive as nothing
     * at all. The message field of this form allows 5000 characters against the 4096 of sendMessage.
     *
     * @param   string   $text   The text.
     * @param   integer  $limit  The most characters allowed.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function shorten(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // The mark of the cut is part of the budget, so that the result cannot come out one over
        $mark = Text::_('MOD_MUCRONIX_CONTACT_TELEGRAM_TRUNCATED');

        return mb_substr($text, 0, max(0, $limit - mb_strlen($mark))) . $mark;
    }
}
