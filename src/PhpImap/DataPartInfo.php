<?php

namespace PhpImap;

/**
 * @see https://github.com/barbushin/php-imap
 *
 * @author nickl- http://github.com/nickl-
 */
class DataPartInfo
{
    const TEXT_PLAIN = 0;
    const TEXT_HTML = 1;

    /**
     * @var string|int
     *
     * @readonly
     */
    public $id;

    /**
     * @var int|mixed
     *
     * @readonly
     */
    public $encoding;

    /** @var string|null */
    public $charset;

    /**
     * @var string|int
     *
     * @readonly
     */
    public $part;

    /**
     * @var Mailbox
     *
     * @readonly
     */
    public $mail;

    /**
     * @var int
     *
     * @readonly
     */
    public $options;

    /** @var string|null */
    private $data;

    /**
     * @param string|int $id
     * @param string|int $part
     * @param int|mixed  $encoding
     * @param int        $options
     */
    public function __construct(Mailbox $mail, $id, $part, $encoding, $options)
    {
        $this->mail = $mail;
        $this->id = $id;
        $this->part = $part;
        $this->encoding = $encoding;
        $this->options = $options;
    }

    /**
     * @return string|null
     */
    public function fetch()
    {
        if (0 == $this->part) {
            /** @var string */
            $this->data = $this->mail->imap('body', [$this->id, $this->options]);
        } else {
            /** @var string */
            $this->data = $this->mail->imap('fetchbody', [$this->id, $this->part, $this->options]);
        }

        switch ($this->encoding) {
            case ENC7BIT:
                /** @var string|null */
                $this->data = $this->data;
                break;
            case ENC8BIT:
                $this->data = imap_utf8($this->data);
                break;
            case ENCBINARY:
                $this->data = imap_binary($this->data);
                break;
            case ENCBASE64:
                $this->data = preg_replace('~[^a-zA-Z0-9+=/]+~s', '', $this->data); // https://github.com/barbushin/php-imap/issues/88
                $this->data = imap_base64($this->data);
                break;
            case ENCQUOTEDPRINTABLE:
                $this->data = quoted_printable_decode($this->data);
                break;
            case ENCOTHER:
                /** @var string|null */
                $this->data = $this->data;
                break;
            default:
                /** @var string|null */
                $this->data = $this->data;
                break;
        }

        if (isset($this->charset) and !empty(trim($this->charset))) {
            $this->data = $this->mail->convertStringEncoding(
                (string) $this->data, // Data to convert
                $this->charset, // FROM-Encoding (Charset)
                $this->mail->getServerEncoding() // TO-Encoding (Charset)
            );
        }

        return $this->data;
    }
}
