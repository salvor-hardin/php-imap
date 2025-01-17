<?php

namespace Webklex\PHPIMAP\Support\Masks;

use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

class MessageMask extends Mask
{
    /**
     * @var Message
     */
    protected mixed $parent;

    /**
     * Get the message html body.
     */
    public function getHtmlBody(): ?string
    {
        $bodies = $this->parent->getBodies();

        if (! isset($bodies['html'])) {
            return null;
        }

        if (is_object($bodies['html']) && property_exists($bodies['html'], 'content')) {
            return $bodies['html']->content;
        }

        return $bodies['html'];
    }

    /**
     * Get the Message html body filtered by an optional callback.
     */
    public function getCustomHTMLBody(?callable $callback = null): ?string
    {
        $body = $this->getHtmlBody();

        if ($body === null) {
            return null;
        }

        if ($callback !== null) {
            $aAttachment = $this->parent->getAttachments();

            $aAttachment->each(function (Attachment $oAttachment) use (&$body, $callback) {
                if (is_callable($callback)) {
                    $body = $callback($body, $oAttachment);
                } elseif (is_string($callback)) {
                    call_user_func($callback, [$body, $oAttachment]);
                }
            });
        }

        return $body;
    }

    /**
     * Get the Message html body with embedded base64 images.
     */
    public function getHTMLBodyWithEmbeddedBase64Images(): ?string
    {
        return $this->getCustomHTMLBody(function (string $body, Attachment $oAttachment) {
            if ($oAttachment->id) {
                $body = str_replace('cid:'.$oAttachment->id, 'data:'.$oAttachment->getContentType().';base64, '.base64_encode($oAttachment->getContent()), $body);
            }

            return $body;
        });
    }
}
