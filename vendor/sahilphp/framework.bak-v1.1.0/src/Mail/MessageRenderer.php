<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * Produces both body parts of a message.
 *
 * A message may be authored as text only, HTML only, or both. When one part
 * is missing it is generated: HTML from text is escaped
 * ({@see Html::fromText()}), and text from HTML is a readable tag-stripped
 * alternative ({@see Html::toText()}). Every message therefore reaches the
 * transport as `multipart/alternative`, which is what mail clients expect.
 */
final class MessageRenderer
{
    public static function text(Message $message): string
    {
        $text = $message->textBody();

        if ($text !== null && trim($text) !== '') {
            return $text;
        }

        $html = $message->htmlBody();

        if ($html !== null && trim($html) !== '') {
            return Html::toText($html);
        }

        throw new InvalidMessageException('The message has no text or HTML body.');
    }

    public static function html(Message $message): string
    {
        $html = $message->htmlBody();

        if ($html !== null && trim($html) !== '') {
            return $html;
        }

        $text = $message->textBody();

        if ($text !== null && trim($text) !== '') {
            return Html::fromText($text);
        }

        throw new InvalidMessageException('The message has no text or HTML body.');
    }
}
