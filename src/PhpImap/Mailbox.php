<?php

namespace PhpImap;

use DateTime;
use Exception;
use function iconv;
use function mb_list_encodings;
use PhpImap\Exceptions\ConnectionException;
use PhpImap\Exceptions\InvalidParameterException;
use stdClass;

/**
 * @see https://github.com/barbushin/php-imap
 *
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 */
class Mailbox
{
    protected $imapPath;
    protected $imapLogin;
    protected $imapPassword;
    protected $imapOAuthAccessToken = null;
    protected $imapSearchOption = SE_UID;
    protected $connectionRetry = 0;
    protected $connectionRetryDelay = 100;
    protected $imapOptions = 0;
    protected $imapRetriesNum = 0;
    protected $imapParams = [];
    protected $serverEncoding = 'UTF-8';
    protected $attachmentsDir = null;
    protected $expungeOnDisconnect = true;
    protected $timeouts = [];
    protected $attachmentsIgnore = false;
    protected $pathDelimiter = '.';
    private $imapStream;

    /**
     * @param string $imapPath
     * @param string $login
     * @param string $password
     * @param string $attachmentsDir
     * @param string $serverEncoding
     *
     * @throws InvalidParameterException
     */
    public function __construct($imapPath, $login, $password, $attachmentsDir = null, $serverEncoding = 'UTF-8')
    {
        $this->imapPath = trim($imapPath);
        $this->imapLogin = trim($login);
        $this->imapPassword = $password;
        $this->setServerEncoding($serverEncoding);
        if (null != $attachmentsDir) {
            $this->setAttachmentsDir($attachmentsDir);
        }
    }

    /**
     * Builds an OAuth2 authentication string for the given email address and access token.
     *
     * @return string $access_token Formatted OAuth access token
     */
    protected function _constructAuthString()
    {
        return base64_encode("user=$this->imapLogin\1auth=Bearer $this->imapOAuthAccessToken\1\1");
    }

    /**
     * Authenticates the IMAP client with the OAuth access token.
     *
     * @return void
     *
     * @throws Exception If any error occured
     */
    protected function _oauthAuthentication()
    {
        $oauth_command = 'A AUTHENTICATE XOAUTH2 '.$this->_constructAuthString();

        $oauth_result = fwrite($this->imapStream, $oauth_command);

        if (false === $oauth_result) {
            throw new Exception('Could not authenticate using OAuth!');
        }

        try {
            $mailbox_info = $this->imap('check');
        } catch (Exception $ex) {
            throw new Exception('OAuth authentication failed! IMAP Error: '.$ex->getMessage());
        }

        if (false === $mailbox_info) {
            throw new Exception('OAuth authentication failed! imap_check() could not gather any mailbox information.');
        }
    }

    /**
     * Sets / Changes the OAuth Token for the authentication.
     *
     * @param string $access_token OAuth token from your application (eg. Google Mail)
     *
     * @return void
     *
     * @throws InvalidArgumentException If no access token is provided
     * @throws Exception                If OAuth authentication was unsuccessful
     */
    public function setOAuthToken($access_token)
    {
        if (empty(trim($access_token))) {
            throw new InvalidParameterException('setOAuthToken() requires an access token as parameter!');
        }

        $this->imapOAuthAccessToken = trim($access_token);

        try {
            $this->_oauthAuthentication();
        } catch (Exception $ex) {
            throw new Exception('Invalid OAuth token provided. Error: '.$ex->getMessage());
        }
    }

    /**
     * Gets the OAuth Token for the authentication.
     *
     * @return string $access_token OAuth Access Token
     */
    public function getOAuthToken()
    {
        return $this->imapOAuthAccessToken;
    }

    /**
     * Sets / Changes the path delimiter character (Supported values: '.', '/').
     *
     * @param string $delimiter Path delimiter
     *
     * @throws InvalidParameterException
     */
    public function setPathDelimiter($delimiter)
    {
        if (!$this->validatePathDelimiter($delimiter)) {
            throw new InvalidParameterException('setPathDelimiter() can only set the delimiter to these characters: ".", "/"');
        }

        $this->pathDelimiter = $delimiter;
    }

    /**
     * Returns the current set path delimiter character.
     *
     * @return string Path delimiter
     */
    public function getPathDelimiter()
    {
        return $this->pathDelimiter;
    }

    /**
     * Validates the given path delimiter character.
     *
     * @param string Path delimiter
     *
     * @return bool true (supported) or false (unsupported)
     */
    public function validatePathDelimiter($delimiter)
    {
        $supported_delimiters = ['.', '/'];

        if (!\in_array($delimiter, $supported_delimiters)) {
            return false;
        }

        return true;
    }

    /**
     * Returns the current set server encoding.
     *
     * @return string Server encoding (eg. 'UTF-8')
     */
    public function getServerEncoding()
    {
        return $this->serverEncoding;
    }

    /**
     * Sets / Changes the server encoding.
     *
     * @param string Server encoding (eg. 'UTF-8')
     *
     * @throws InvalidParameterException
     */
    public function setServerEncoding($serverEncoding)
    {
        $serverEncoding = strtoupper(trim($serverEncoding));

        $supported_encodings = mb_list_encodings();

        if (!\in_array($serverEncoding, $supported_encodings) && 'US-ASCII' != $serverEncoding) {
            throw new InvalidParameterException('"'.$serverEncoding.'" is not supported by setServerEncoding(). Your system only supports these encodings: US-ASCII, '.implode(', ', $supported_encodings));
        }

        $this->serverEncoding = $serverEncoding;
    }

    /**
     * Returns the current set IMAP search option.
     *
     * @return string IMAP search option (eg. 'SE_UID')
     */
    public function getImapSearchOption()
    {
        return $this->imapSearchOption;
    }

    /**
     * Sets / Changes the IMAP search option.
     *
     * @param string IMAP search option (eg. 'SE_UID')
     *
     * @throws InvalidParameterException
     */
    public function setImapSearchOption($imapSearchOption)
    {
        $imapSearchOption = strtoupper(trim($imapSearchOption));

        $supported_options = [SE_FREE, SE_UID];

        if (!\in_array($imapSearchOption, $supported_options)) {
            throw new InvalidParameterException('"'.$imapSearchOption.'" is not supported by setImapSearchOption(). Supported options are SE_FREE and SE_UID.');
        }

        $this->imapSearchOption = $imapSearchOption;
    }

    /**
     * Set $this->attachmentsIgnore param. Allow to ignore attachments when they are not required and boost performance.
     *
     * @param bool $attachmentsIgnore
     *
     * @throws InvalidParameterException
     */
    public function setAttachmentsIgnore($attachmentsIgnore)
    {
        if (!\is_bool($attachmentsIgnore)) {
            throw new InvalidParameterException('setAttachmentsIgnore() expects a boolean: true or false');
        }
        $this->attachmentsIgnore = $attachmentsIgnore;
    }

    /**
     * Get $this->attachmentsIgnore param.
     *
     * @return bool $attachmentsIgnore
     */
    public function getAttachmentsIgnore()
    {
        return $this->attachmentsIgnore;
    }

    /**
     * Sets the timeout of all or one specific type.
     *
     * @param int   $timeout Timeout in seconds
     * @param array $types   One of the following: IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT
     *
     * @throws InvalidParameterException
     */
    public function setTimeouts($timeout, $types = [IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT])
    {
        $supported_types = [IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT];

        $found_types = array_intersect($types, $supported_types);

        if (\count($types) != \count($found_types)) {
            throw new InvalidParameterException('You have provided at least one unsupported timeout type. Supported types are: IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT');
        }

        $this->timeouts = array_fill_keys($types, $timeout);
    }

    /**
     * Returns the IMAP login (usually an email address).
     *
     * @return string IMAP login
     */
    public function getLogin()
    {
        return $this->imapLogin;
    }

    /**
     * Set custom connection arguments of imap_open method. See http://php.net/imap_open.
     *
     * @param int   $options
     * @param int   $retriesNum
     * @param array $params
     *
     * @throws InvalidParameterException
     */
    public function setConnectionArgs($options = 0, $retriesNum = 0, $params = null)
    {
        if (0 != $options) {
            $supported_options = [OP_READONLY, OP_ANONYMOUS, OP_HALFOPEN, CL_EXPUNGE, OP_DEBUG, OP_SHORTCACHE, OP_SILENT, OP_PROTOTYPE, OP_SECURE];
            if (!\in_array($options, $supported_options)) {
                throw new InvalidParameterException('Please check your option for setConnectionArgs()! Unsupported option "'.$options.'". Available options: https://www.php.net/manual/de/function.imap-open.php');
            }
            $this->imapOptions = $options;
        }

        if (0 != $retriesNum) {
            if (!\is_int($retriesNum) or $retriesNum < 0) {
                throw new InvalidParameterException('Invalid number of retries provided for setConnectionArgs()! It must be a positive integer. (eg. 1 or 3)');
            }
            $this->imapRetriesNum = $retriesNum;
        }

        if (null != $params and !empty($params)) {
            $supported_params = ['DISABLE_AUTHENTICATOR'];
            if (!\is_array($params)) {
                throw new InvalidParameterException('setConnectionArgs() requires $params to be an array!');
            }

            foreach ($params as $key => $value) {
                if (!\in_array($key, $supported_params)) {
                    throw new InvalidParameterException('Invalid array key of params provided for setConnectionArgs()! Only DISABLE_AUTHENTICATOR is currently valid.');
                }
            }
            $this->imapParams = $params;
        }
    }

    /**
     * Set custom folder for attachments in case you want to have tree of folders for each email
     * i.e. a/1 b/1 c/1 where a,b,c - senders, i.e. john@smith.com.
     *
     * @param string $attachmentsDir Folder where to save attachments
     *
     * @throws InvalidParameterException
     */
    public function setAttachmentsDir($attachmentsDir)
    {
        if (empty(trim($attachmentsDir))) {
            throw new InvalidParameterException('setAttachmentsDir() expects a string as first parameter!');
        }
        if (!is_dir($attachmentsDir)) {
            throw new InvalidParameterException('Directory "'.$attachmentsDir.'" not found');
        }
        $this->attachmentsDir = rtrim(realpath($attachmentsDir), '\\/');
    }

    /**
     * Get current saving folder for attachments.
     *
     * @return string Attachments dir
     */
    public function getAttachmentsDir()
    {
        return $this->attachmentsDir;
    }

    /*
     * Sets / Changes the attempts / retries to connect
     * @param int $maxAttempts
     * @return void
    */
    public function setConnectionRetry($maxAttempts)
    {
        $this->connectionRetry = $maxAttempts;
    }

    /*
     * Sets / Changes the delay between each attempt / retry to connect
     * @param int $milliseconds
     * @return void
    */
    public function setConnectionRetryDelay($milliseconds)
    {
        $this->connectionRetryDelay = $milliseconds;
    }

    /**
     * Get IMAP mailbox connection stream.
     *
     * @param bool $forceConnection Initialize connection if it's not initialized
     *
     * @return resource|null
     */
    public function getImapStream($forceConnection = true)
    {
        if ($forceConnection) {
            $this->pingOrDisconnect();
            if (!$this->imapStream) {
                $this->imapStream = $this->initImapStreamWithRetry();
            }
        }

        return $this->imapStream;
    }

    /**
     * Returns the provided string in UTF7-IMAP encoded format.
     *
     * @return string $str UTF-7 encoded string or same as before, when it's no string
     */
    public function encodeStringToUtf7Imap($str)
    {
        if (\is_string($str)) {
            return mb_convert_encoding($str, 'UTF7-IMAP', mb_detect_encoding($str, 'UTF-8, ISO-8859-1, ISO-8859-15', true));
        }

        // Return $str as it is, when it is no string
        return $str;
    }

    /**
     * Returns the provided string in UTF-8 encoded format.
     *
     * @return string $str UTF-7 encoded string or same as before, when it's no string
     */
    public function decodeStringFromUtf7ImapToUtf8($str)
    {
        if (\is_string($str)) {
            return mb_convert_encoding($str, 'UTF-8', 'UTF7-IMAP');
        }

        // Return $str as it is, when it is no string
        return $str;
    }

    /**
     * Switch mailbox without opening a new connection.
     *
     * @param string $imapPath
     *
     * @throws Exception
     */
    public function switchMailbox($imapPath)
    {
        if (strpos($imapPath, '}') > 0) {
            $this->imapPath = $imapPath;
        } else {
            $this->imapPath = $this->getCombinedPath($imapPath, true);
        }

        $this->imap('reopen', $this->imapPath);
    }

    protected function initImapStreamWithRetry()
    {
        $retry = $this->connectionRetry;

        do {
            try {
                return $this->initImapStream();
            } catch (ConnectionException $exception) {
            }
        } while (--$retry > 0 && (!$this->connectionRetryDelay || !usleep($this->connectionRetryDelay * 1000)));

        throw $exception;
    }

    /**
     * Open an IMAP stream to a mailbox.
     *
     * @return object IMAP stream on success
     *
     * @throws Exception if an error occured
     */
    protected function initImapStream()
    {
        foreach ($this->timeouts as $type => $timeout) {
            $this->imap('timeout', [$type, $timeout], false);
        }

        $imapStream = @imap_open($this->imapPath, $this->imapLogin, $this->imapPassword, $this->imapOptions, $this->imapRetriesNum, $this->imapParams);

        if (!$imapStream) {
            $lastError = imap_last_error();

            // this function is called multiple times and imap keeps errors around.
            // Let's clear them out to avoid it tripping up future calls.
            @imap_errors();

            if (!empty(trim($lastError))) {
                // imap error = report imap error
                throw new Exception('IMAP error: '.$lastError);
            } else {
                // no imap error = connectivity issue
                throw new Exception('Connection error: Unable to connect to '.$this->imapPath);
            }
        }

        return $imapStream;
    }

    /**
     * Disconnects from IMAP server / mailbox.
     */
    public function disconnect()
    {
        $imapStream = $this->getImapStream(false);
        if ($imapStream && \is_resource($imapStream)) {
            $this->imap('close', [$imapStream, $this->expungeOnDisconnect ? CL_EXPUNGE : 0], false, null);
        }
    }

    /**
     * Sets 'expunge on disconnect' parameter.
     *
     * @param bool $isEnabled
     */
    public function setExpungeOnDisconnect($isEnabled)
    {
        $this->expungeOnDisconnect = $isEnabled;
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns the information in an object with following properties:
     *  Date - current system time formatted according to RFC2822
     *  Driver - protocol used to access this mailbox: POP3, IMAP, NNTP
     *  Mailbox - the mailbox name
     *  Nmsgs - number of mails in the mailbox
     *  Recent - number of recent mails in the mailbox
     *
     * @return stdClass
     *
     * @see    imap_check
     */
    public function checkMailbox()
    {
        return $this->imap('check');
    }

    /**
     * Creates a new mailbox.
     *
     * @param string $name Name of new mailbox (eg. 'PhpImap')
     *
     * @see   imap_createmailbox()
     */
    public function createMailbox($name)
    {
        $this->imap('createmailbox', $this->getCombinedPath($name));
    }

    /**
     * Deletes a specific mailbox.
     *
     * @param string $name Name of mailbox, which you want to delete (eg. 'PhpImap')
     *
     * @see   imap_deletemailbox()
     */
    public function deleteMailbox($name)
    {
        $this->imap('deletemailbox', $this->getCombinedPath($name));
    }

    /**
     * Rename an existing mailbox from $oldName to $newName.
     *
     * @param string $oldName Current name of mailbox, which you want to rename (eg. 'PhpImap')
     * @param string $newName New name of mailbox, to which you want to rename it (eg. 'PhpImapTests')
     */
    public function renameMailbox($oldName, $newName)
    {
        $this->imap('renamemailbox', [$this->getCombinedPath($oldName), $this->getCombinedPath($newName)]);
    }

    /**
     * Gets status information about the given mailbox.
     *
     * This function returns an object containing status information.
     * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return stdClass
     */
    public function statusMailbox()
    {
        return $this->imap('status', [$this->imapPath, SA_ALL]);
    }

    /**
     * Gets listing the folders.
     *
     * This function returns an object containing listing the folders.
     * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @param string $pattern
     *
     * @return array listing the folders
     */
    public function getListingFolders($pattern = '*')
    {
        $folders = $this->imap('list', [$this->imapPath, $pattern]) ?: [];
        foreach ($folders as &$folder) {
            $folder = $this->decodeStringFromUtf7ImapToUtf8($folder);
        }

        return $folders;
    }

    /**
     * This function uses imap_search() to perform a search on the mailbox currently opened in the given IMAP stream.
     * For example, to match all unanswered mails sent by Mom, you'd use: "UNANSWERED FROM mom".
     *
     * @param string $criteria              See http://php.net/imap_search for a complete list of available criteria
     * @param bool   $disableServerEncoding Disables server encoding while searching for mails (can be useful on Exchange servers)
     *
     * @return array mailsIds (or empty array)
     */
    public function searchMailbox($criteria = 'ALL', $disableServerEncoding = false)
    {
        if ($disableServerEncoding) {
            return $this->imap('search', [$criteria, $this->imapSearchOption]) ?: [];
        }

        return $this->imap('search', [$criteria, $this->imapSearchOption, $this->getServerEncoding()]) ?: [];
    }

    /**
     * Save a specific body section to a file.
     *
     * @param int    $mailId   message number
     * @param string $filename
     *
     * @see   imap_savebody()
     */
    public function saveMail($mailId, $filename = 'email.eml')
    {
        $this->imap('savebody', [$filename, $mailId, '', (SE_UID == $this->imapSearchOption) ? FT_UID : 0]);
    }

    /**
     * Marks mails listed in mailId for deletion.
     *
     * @param int $mailId message number
     *
     * @see   imap_delete()
     */
    public function deleteMail($mailId)
    {
        $this->imap('delete', [$mailId.':'.$mailId, (SE_UID == $this->imapSearchOption) ? FT_UID : 0]);
    }

    /**
     * Moves mails listed in mailId into new mailbox.
     *
     * @param string $mailId  a range or message number
     * @param string $mailBox Mailbox name
     *
     * @see imap_mail_move()
     *
     * @return void
     */
    public function moveMail($mailId, $mailBox)
    {
        $this->imap('mail_move', [$mailId, $mailBox, CP_UID]) && $this->expungeDeletedMails();
    }

    /**
     * Copies mails listed in mailId into new mailbox.
     *
     * @param string $mailId  a range or message number
     * @param string $mailBox Mailbox name
     *
     * @see   imap_mail_copy()
     */
    public function copyMail($mailId, $mailBox)
    {
        $this->imap('mail_copy', [$mailId, $mailBox, CP_UID]) && $this->expungeDeletedMails();
    }

    /**
     * Deletes all the mails marked for deletion by imap_delete(), imap_mail_move(), or imap_setflag_full().
     *
     * @see imap_expunge()
     */
    public function expungeDeletedMails()
    {
        $this->imap('expunge');
    }

    /**
     * Add the flag \Seen to a mail.
     *
     * @param $mailId
     */
    public function markMailAsRead($mailId)
    {
        $this->setFlag([$mailId], '\\Seen');
    }

    /**
     * Remove the flag \Seen from a mail.
     *
     * @param $mailId
     */
    public function markMailAsUnread($mailId)
    {
        $this->clearFlag([$mailId], '\\Seen');
    }

    /**
     * Add the flag \Flagged to a mail.
     *
     * @param $mailId
     */
    public function markMailAsImportant($mailId)
    {
        $this->setFlag([$mailId], '\\Flagged');
    }

    /**
     * Add the flag \Seen to a mails.
     */
    public function markMailsAsRead(array $mailId)
    {
        $this->setFlag($mailId, '\\Seen');
    }

    /**
     * Remove the flag \Seen from some mails.
     */
    public function markMailsAsUnread(array $mailId)
    {
        $this->clearFlag($mailId, '\\Seen');
    }

    /**
     * Add the flag \Flagged to some mails.
     */
    public function markMailsAsImportant(array $mailId)
    {
        $this->setFlag($mailId, '\\Flagged');
    }

    /**
     * Causes a store to add the specified flag to the flags set for the mails in the specified sequence.
     *
     * @param array  $mailsIds Array of mail IDs
     * @param string $flag     Which you can set are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060
     */
    public function setFlag(array $mailsIds, $flag)
    {
        $this->imap('setflag_full', [implode(',', $mailsIds), $flag, ST_UID]);
    }

    /**
     * Causes a store to delete the specified flag to the flags set for the mails in the specified sequence.
     *
     * @param array  $mailsIds Array of mail IDs
     * @param string $flag     Which you can delete are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060
     */
    public function clearFlag(array $mailsIds, $flag)
    {
        $this->imap('clearflag_full', [implode(',', $mailsIds), $flag, ST_UID]);
    }

    /**
     * Fetch mail headers for listed mails ids.
     *
     * Returns an array of objects describing one mail header each. The object will only define a property if it exists. The possible properties are:
     *  subject - the mails subject
     *  from - who sent it
     *  sender - who sent it
     *  to - recipient
     *  date - when was it sent
     *  message_id - Mail-ID
     *  references - is a reference to this mail id
     *  in_reply_to - is a reply to this mail id
     *  size - size in bytes
     *  uid - UID the mail has in the mailbox
     *  msgno - mail sequence number in the mailbox
     *  recent - this mail is flagged as recent
     *  flagged - this mail is flagged
     *  answered - this mail is flagged as answered
     *  deleted - this mail is flagged for deletion
     *  seen - this mail is flagged as already read
     *  draft - this mail is flagged as being a draft
     *
     * @return array $mailsIds Array of mail IDs
     */
    public function getMailsInfo(array $mailsIds)
    {
        $mails = $this->imap('fetch_overview', [implode(',', $mailsIds), (SE_UID == $this->imapSearchOption) ? FT_UID : 0]);
        if (\is_array($mails) && \count($mails)) {
            foreach ($mails as &$mail) {
                if (isset($mail->subject) and !empty(trim($mail->subject))) {
                    $mail->subject = $this->decodeMimeStr($mail->subject, $this->getServerEncoding());
                }
                if (isset($mail->from) and !empty(trim($mail->from))) {
                    $mail->from = $this->decodeMimeStr($mail->from, $this->getServerEncoding());
                }
                if (isset($mail->sender) and !empty(trim($mail->sender))) {
                    $mail->sender = $this->decodeMimeStr($mail->sender, $this->getServerEncoding());
                }
                if (isset($mail->to) and !empty(trim($mail->to))) {
                    $mail->to = $this->decodeMimeStr($mail->to, $this->getServerEncoding());
                }
            }
        }

        return $mails;
    }

    /**
     * Get headers for all messages in the defined mailbox,
     * returns an array of string formatted with header info,
     * one element per mail message.
     *
     * @return array
     *
     * @see    imap_headers()
     */
    public function getMailboxHeaders()
    {
        return $this->imap('headers');
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns an object with following properties:
     *  Date - last change (current datetime)
     *  Driver - driver
     *  Mailbox - name of the mailbox
     *  Nmsgs - number of messages
     *  Recent - number of recent messages
     *  Unread - number of unread messages
     *  Deleted - number of deleted messages
     *  Size - mailbox size
     *
     * @return object Object with info
     *
     * @see    mailboxmsginfo
     */
    public function getMailboxInfo()
    {
        return $this->imap('mailboxmsginfo');
    }

    /**
     * Gets mails ids sorted by some criteria.
     *
     * Criteria can be one (and only one) of the following constants:
     *  SORTDATE - mail Date
     *  SORTARRIVAL - arrival date (default)
     *  SORTFROM - mailbox in first From address
     *  SORTSUBJECT - mail subject
     *  SORTTO - mailbox in first To address
     *  SORTCC - mailbox in first cc address
     *  SORTSIZE - size of mail in octets
     *
     * @param int    $criteria       Sorting criteria (eg. SORTARRIVAL)
     * @param bool   $reverse        Sort reverse or not
     * @param string $searchCriteria See http://php.net/imap_search for a complete list of available criteria
     *
     * @return array Mails ids
     */
    public function sortMails($criteria = SORTARRIVAL, $reverse = true, $searchCriteria = 'ALL')
    {
        return $this->imap('sort', [$criteria, $reverse, $this->imapSearchOption, $searchCriteria]);
    }

    /**
     * Get mails count in mail box.
     *
     * @return int
     *
     * @see    imap_num_msg()
     */
    public function countMails()
    {
        return $this->imap('num_msg');
    }

    /**
     * Retrieve the quota settings per user.
     *
     * @param string $quota_root Should normally be in the form of which mailbox (i.e. INBOX)
     *
     * @return array
     *
     * @see    imap_get_quotaroot()
     */
    protected function getQuota($quota_root = 'INBOX')
    {
        return $this->imap('get_quotaroot', $quota_root);
    }

    /**
     * Return quota limit in KB.
     *
     * @param string $quota_root Should normally be in the form of which mailbox (i.e. INBOX)
     *
     * @return int
     */
    public function getQuotaLimit($quota_root = 'INBOX')
    {
        $quota = $this->getQuota($quota_root);

        return isset($quota['STORAGE']['limit']) ? $quota['STORAGE']['limit'] : 0;
    }

    /**
     * Return quota usage in KB.
     *
     * @param string $quota_root Should normally be in the form of which mailbox (i.e. INBOX)
     *
     * @return int FALSE in the case of call failure
     */
    public function getQuotaUsage($quota_root = 'INBOX')
    {
        $quota = $this->getQuota($quota_root);

        return isset($quota['STORAGE']['usage']) ? $quota['STORAGE']['usage'] : 0;
    }

    /**
     * Get raw mail data.
     *
     * @param int  $msgId      ID of the message
     * @param bool $markAsSeen Mark the email as seen, when set to true
     *
     * @return string Message of the fetched body
     */
    public function getRawMail($msgId, $markAsSeen = true)
    {
        $options = (SE_UID == $this->imapSearchOption) ? FT_UID : 0;
        if (!$markAsSeen) {
            $options |= FT_PEEK;
        }

        return $this->imap('fetchbody', [$msgId, '', $options]);
    }

    /**
     * Get mail header.
     *
     * @param int $mailId ID of the message
     *
     * @return IncomingMailHeader
     *
     * @throws Exception
     */
    public function getMailHeader($mailId)
    {
        $headersRaw = $this->imap('fetchheader', [$mailId, (SE_UID == $this->imapSearchOption) ? FT_UID : 0]);

        if (false === $headersRaw) {
            throw new Exception('Empty mail header - fetchheader failed. Invalid mail ID?');
        }

        $head = imap_rfc822_parse_headers($headersRaw);

        $header = new IncomingMailHeader();
        $header->headersRaw = $headersRaw;
        $header->headers = $head;
        $header->id = $mailId;
        $header->isDraft = (!isset($head->date)) ? true : false;
        $header->priority = (preg_match("/Priority\:(.*)/i", $headersRaw, $matches)) ? trim($matches[1]) : '';
        $header->importance = (preg_match("/Importance\:(.*)/i", $headersRaw, $matches)) ? trim($matches[1]) : '';
        $header->sensitivity = (preg_match("/Sensitivity\:(.*)/i", $headersRaw, $matches)) ? trim($matches[1]) : '';
        $header->autoSubmitted = (preg_match("/Auto-Submitted\:(.*)/i", $headersRaw, $matches)) ? trim($matches[1]) : '';
        $header->precedence = (preg_match("/Precedence\:(.*)/i", $headersRaw, $matches)) ? trim($matches[1]) : '';
        $header->failedRecipients = (preg_match("/Failed-Recipients\:(.*)/i", $headersRaw, $matches)) ? trim($matches[1]) : '';

        if (isset($head->date) and !empty(trim($head->date))) {
            $header->date = self::parseDateTime($head->date);
        } elseif (isset($head->Date) and !empty(trim($head->Date))) {
            $header->date = self::parseDateTime($head->Date);
        } else {
            $now = new DateTime();
            $header->date = self::parseDateTime($now->format('Y-m-d H:i:s'));
        }

        $header->subject = (isset($head->subject) and !empty(trim($head->subject))) ? $this->decodeMimeStr($head->subject, $this->getServerEncoding()) : null;
        if (isset($head->from) and !empty($head->from)) {
            list($header->fromHost, $header->fromName, $header->fromAddress) = $this->possiblyGetHostNameAndAddress($head->from);
        } elseif (preg_match('/smtp.mailfrom=[-0-9a-zA-Z.+_]+@[-0-9a-zA-Z.+_]+.[a-zA-Z]{2,4}/', $headersRaw, $matches)) {
            $header->fromAddress = substr($matches[0], 14);
        }
        if (isset($head->sender) and !empty($head->sender)) {
            list($header->senderHost, $header->senderName, $header->senderAddress) = $this->possiblyGetHostNameAndAddress($head->sender);
        }
        if (isset($head->to)) {
            $toStrings = [];
            foreach ($head->to as $to) {
                $to_parsed = $this->possiblyGetEmailAndNameFromRecipient($to);
                if ($to_parsed) {
                    list($toEmail, $toName) = $to_parsed;
                    $toStrings[] = $toName ? "$toName <$toEmail>" : $toEmail;
                    $header->to[$toEmail] = $toName;
                }
            }
            $header->toString = implode(', ', $toStrings);
        }

        if (isset($head->cc)) {
            foreach ($head->cc as $cc) {
                $cc_parsed = $this->possiblyGetEmailAndNameFromRecipient($cc);
                if ($cc_parsed) {
                    $header->cc[$cc_parsed[0]] = $cc_parsed[1];
                }
            }
        }

        if (isset($head->bcc)) {
            foreach ($head->bcc as $bcc) {
                $bcc_parsed = $this->possiblyGetEmailAndNameFromRecipient($bcc);
                if ($bcc_parsed) {
                    $header->bcc[$bcc_parsed[0]] = $bcc_parsed[1];
                }
            }
        }

        if (isset($head->reply_to)) {
            foreach ($head->reply_to as $replyTo) {
                $replyTo_parsed = $this->possiblyGetEmailAndNameFromRecipient($replyTo);
                if ($replyTo_parsed) {
                    $header->replyTo[$replyTo_parsed[0]] = $replyTo_parsed[1];
                }
            }
        }

        if (isset($head->message_id)) {
            $header->messageId = $head->message_id;
        }

        return $header;
    }

    /**
     * taken from https://www.electrictoolbox.com/php-imap-message-parts/.
     */
    public function flattenParts($messageParts, $flattenedParts = [], $prefix = '', $index = 1, $fullPrefix = true)
    {
        foreach ($messageParts as $part) {
            $flattenedParts[$prefix.$index] = $part;
            if (isset($part->parts)) {
                if (2 == $part->type) {
                    $flattenedParts = $this->flattenParts($part->parts, $flattenedParts, $prefix.$index.'.', 0, false);
                } elseif ($fullPrefix) {
                    $flattenedParts = $this->flattenParts($part->parts, $flattenedParts, $prefix.$index.'.');
                } else {
                    $flattenedParts = $this->flattenParts($part->parts, $flattenedParts, $prefix);
                }
                unset($flattenedParts[$prefix.$index]->parts);
            }
            ++$index;
        }

        return $flattenedParts;
    }

    /**
     * Get mail data.
     *
     * @param int  $mailId     ID of the mail
     * @param bool $markAsSeen Mark the email as seen, when set to true
     *
     * @return IncomingMail
     */
    public function getMail($mailId, $markAsSeen = true)
    {
        $mail = new IncomingMail();
        $mail->setHeader($this->getMailHeader($mailId));

        $mailStructure = $this->imap('fetchstructure', [$mailId, (SE_UID == $this->imapSearchOption) ? FT_UID : 0]);

        if (empty($mailStructure->parts)) {
            $this->initMailPart($mail, $mailStructure, 0, $markAsSeen);
        } else {
            foreach ($this->flattenParts($mailStructure->parts) as $partNum => $partStructure) {
                $this->initMailPart($mail, $partStructure, $partNum, $markAsSeen);
            }
        }

        return $mail;
    }

    protected function initMailPart(IncomingMail $mail, $partStructure, $partNum, $markAsSeen = true, $emlParse = false)
    {
        $options = (SE_UID == $this->imapSearchOption) ? FT_UID : 0;

        if (!$markAsSeen) {
            $options |= FT_PEEK;
        }
        $dataInfo = new DataPartInfo($this, $mail->id, $partNum, $partStructure->encoding, $options);

        $params = [];
        if (!empty($partStructure->parameters)) {
            foreach ($partStructure->parameters as $param) {
                $params[strtolower($param->attribute)] = (!isset($param->value) || empty(trim($param->value))) ? '' : $this->decodeMimeStr($param->value);
            }
        }
        if (!empty($partStructure->dparameters)) {
            foreach ($partStructure->dparameters as $param) {
                $paramName = strtolower(preg_match('~^(.*?)\*~', $param->attribute, $matches) ? $matches[1] : $param->attribute);
                if (isset($params[$paramName])) {
                    $params[$paramName] .= $param->value;
                } else {
                    $params[$paramName] = $param->value;
                }
            }
        }

        $isAttachment = isset($params['filename']) || isset($params['name']);

        // ignore contentId on body when mail isn't multipart (https://github.com/barbushin/php-imap/issues/71)
        if (!$partNum && TYPETEXT === $partStructure->type) {
            $isAttachment = false;
        }

        if ($isAttachment) {
            $mail->setHasAttachments(true);
        }

        // check if the part is a subpart of another attachment part (RFC822)
        if ('RFC822' == $partStructure->subtype && isset($partStructure->disposition) && 'attachment' == $partStructure->disposition) {
            // Although we are downloading each part separately, we are going to download the EML to a single file
            //incase someone wants to process or parse in another process
            $attachment = self::downloadAttachment($dataInfo, $params, $partStructure, $mail->id, false);
            $mail->addAttachment($attachment);
        }

        // If it comes from an EML file it is an attachment
        if ($emlParse) {
            $isAttachment = true;
        }

        // Do NOT parse attachments, when getAttachmentsIgnore() is true
        if ($this->getAttachmentsIgnore()
            && (TYPEMULTIPART !== $partStructure->type
            && (TYPETEXT !== $partStructure->type || !\in_array(strtolower($partStructure->subtype), ['plain', 'html'])))
        ) {
            return false;
        }

        if ($isAttachment) {
            $attachment = self::downloadAttachment($dataInfo, $params, $partStructure, $mail->id, $emlParse);
            $mail->addAttachment($attachment);
        } else {
            if (isset($params['charset']) and !empty(trim($params['charset']))) {
                $dataInfo->charset = $params['charset'];
            }
        }

        if (!empty($partStructure->parts)) {
            foreach ($partStructure->parts as $subPartNum => $subPartStructure) {
                $not_attachment = (!isset($partStructure->disposition) || 'attachment' !== $partStructure->disposition);

                if (TYPEMESSAGE === $partStructure->type && 'RFC822' == $partStructure->subtype && $not_attachment) {
                    $this->initMailPart($mail, $subPartStructure, $partNum, $markAsSeen);
                } elseif (TYPEMULTIPART === $partStructure->type && 'ALTERNATIVE' == $partStructure->subtype && $not_attachment) {
                    // https://github.com/barbushin/php-imap/issues/198
                    $this->initMailPart($mail, $subPartStructure, $partNum, $markAsSeen);
                } elseif ('RFC822' == $partStructure->subtype && isset($partStructure->disposition) && 'attachment' == $partStructure->disposition) {
                    //If it comes from am EML attachment, download each part separately as a file
                    $this->initMailPart($mail, $subPartStructure, $partNum.'.'.($subPartNum + 1), $markAsSeen, true);
                } else {
                    $this->initMailPart($mail, $subPartStructure, $partNum.'.'.($subPartNum + 1), $markAsSeen);
                }
            }
        } else {
            if (TYPETEXT === $partStructure->type) {
                if ('plain' == strtolower($partStructure->subtype)) {
                    $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_PLAIN);
                } elseif (!$partStructure->ifdisposition) {
                    $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_HTML);
                } elseif ('attachment' != strtolower($partStructure->disposition)) {
                    $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_HTML);
                }
            } elseif (TYPEMESSAGE === $partStructure->type) {
                $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_PLAIN);
            }
        }
    }

    /**
     * Download attachment.
     *
     * @param string $dataInfo
     * @param array  $params        Array of params of mail
     * @param object $partStructure Part of mail
     * @param int    $mailId        ID of mail
     * @param bool   $emlOrigin     True, if it indicates, that the attachment comes from an EML (mail) file
     *
     * @return IncomingMailAttachment[] $attachment
     */
    public function downloadAttachment(DataPartInfo $dataInfo, $params, $partStructure, $mailId, $emlOrigin = false)
    {
        if ('RFC822' == $partStructure->subtype && isset($partStructure->disposition) && 'attachment' == $partStructure->disposition) {
            $fileName = strtolower($partStructure->subtype).'.eml';
        } elseif ('ALTERNATIVE' == $partStructure->subtype) {
            $fileName = strtolower($partStructure->subtype).'.eml';
        } elseif ((!isset($params['filename']) or empty(trim($params['filename']))) && (!isset($params['name']) or empty(trim($params['name'])))) {
            $fileName = strtolower($partStructure->subtype);
        } else {
            $fileName = (isset($params['filename']) and !empty(trim($params['filename']))) ? $params['filename'] : $params['name'];
            $fileName = $this->decodeMimeStr($fileName, $this->getServerEncoding());
            $fileName = $this->decodeRFC2231($fileName, $this->getServerEncoding());
        }

        $attachment = new IncomingMailAttachment();
        $attachment->id = sha1($fileName.($partStructure->ifid ? $partStructure->id : ''));
        $attachment->contentId = $partStructure->ifid ? trim($partStructure->id, ' <>') : null;
        $attachment->name = $fileName;
        $attachment->disposition = (isset($partStructure->disposition) ? $partStructure->disposition : null);
        $attachment->charset = (isset($params['charset']) and !empty(trim($params['charset']))) ? $params['charset'] : null;
        $attachment->emlOrigin = $emlOrigin;

        $attachment->addDataPartInfo($dataInfo);

        if (null != $this->getAttachmentsDir()) {
            $replace = [
                '/\s/' => '_',
                '/[^\w\.]/iu' => '',
                '/_+/' => '_',
                '/(^_)|(_$)/' => '',
            ];
            $fileSysName = preg_replace('~[\\\\/]~', '', $mailId.'_'.$attachment->id.'_'.preg_replace(array_keys($replace), $replace, $fileName));
            $filePath = $this->getAttachmentsDir().\DIRECTORY_SEPARATOR.$fileSysName;

            if (\strlen($filePath) > 255) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $filePath = substr($filePath, 0, 255 - 1 - \strlen($ext)).'.'.$ext;
            }
            $attachment->setFilePath($filePath);
            $attachment->saveToDisk();
        }

        return $attachment;
    }

    /**
     * Decodes a mime string.
     *
     * @param string $string MIME string to decode
     *
     * @return string Converted string if conversion was successful, or the original string if not
     *
     * @throws Exception
     */
    public function decodeMimeStr($string, $toCharset = 'utf-8')
    {
        if (empty(trim($string))) {
            throw new Exception('decodeMimeStr() Can not decode an empty string!');
        }

        $newString = '';
        foreach (imap_mime_header_decode($string) as $element) {
            if (isset($element->text)) {
                $fromCharset = !isset($element->charset) ? 'iso-8859-1' : $element->charset;
                // Convert to UTF-8, if string has UTF-8 characters to avoid broken strings. See https://github.com/barbushin/php-imap/issues/232
                $toCharset = isset($element->charset) && preg_match('/(UTF\-8)|(default)/i', $element->charset) ? 'UTF-8' : $toCharset;
                $newString .= $this->convertStringEncoding($element->text, $fromCharset, $toCharset);
            }
        }

        return $newString;
    }

    public function isUrlEncoded($string)
    {
        $hasInvalidChars = preg_match('#[^%a-zA-Z0-9\-_\.\+]#', $string);
        $hasEscapedChars = preg_match('#%[a-zA-Z0-9]{2}#', $string);

        return !$hasInvalidChars && $hasEscapedChars;
    }

    protected function decodeRFC2231($string, $charset = 'utf-8')
    {
        if (preg_match("/^(.*?)'.*?'(.*?)$/", $string, $matches)) {
            $encoding = $matches[1];
            $data = $matches[2];
            if ($this->isUrlEncoded($data)) {
                $string = $this->convertStringEncoding(urldecode($data), $encoding, $charset);
            }
        }

        return $string;
    }

    /**
     * Converts the datetime to a RFC 3339 compliant format.
     *
     * @param string $dateHeader Header datetime
     *
     * @return string RFC 3339 compliant format or original (unchanged) format,
     *                if conversation is not possible
     */
    public function parseDateTime($dateHeader)
    {
        if (empty(trim($dateHeader))) {
            throw new InvalidParameterException('parseDateTime() expects parameter 1 to be a parsable string datetime');
        }

        $dateHeaderUnixtimestamp = strtotime($dateHeader);

        if (!$dateHeaderUnixtimestamp) {
            return $dateHeader;
        }

        $dateHeaderRfc3339 = date(DATE_RFC3339, $dateHeaderUnixtimestamp);

        if (!$dateHeaderRfc3339) {
            return $dateHeader;
        }

        return $dateHeaderRfc3339;
    }

    /**
     * Converts a string from one encoding to another.
     *
     * @param string $string       the string, which you want to convert
     * @param string $fromEncoding the current charset (encoding)
     * @param string $toEncoding   the new charset (encoding)
     *
     * @return string Converted string if conversion was successful, or the original string if not
     */
    public function convertStringEncoding($string, $fromEncoding, $toEncoding)
    {
        if (preg_match('/default|ascii/i', $fromEncoding) || !$string || $fromEncoding == $toEncoding) {
            return $string;
        }
        $convertedString = '';
        $supportedEncodings = array_map('strtolower', mb_list_encodings());
        if (\in_array(strtolower($fromEncoding), $supportedEncodings) && \in_array(strtolower($toEncoding), $supportedEncodings)) {
            $convertedString = mb_convert_encoding($string, $toEncoding, $fromEncoding);
        } else {
            $convertedString = @iconv($fromEncoding, $toEncoding.'//TRANSLIT//IGNORE', $string);
        }
        if (('' == $convertedString) or (false === $convertedString)) {
            return $string;
        }

        return $convertedString;
    }

    /**
     * Disconnects from the IMAP server / mailbox.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Gets IMAP path.
     *
     * @return string
     */
    public function getImapPath()
    {
        return $this->imapPath;
    }

    /**
     * Get message in MBOX format.
     *
     * @param int $mailId message number
     *
     * @return string
     */
    public function getMailMboxFormat($mailId)
    {
        $option = (SE_UID == $this->imapSearchOption) ? FT_UID : 0;

        return imap_fetchheader($this->getImapStream(), $mailId, $option && FT_PREFETCHTEXT).imap_body($this->getImapStream(), $mailId, $option);
    }

    /**
     * Get folders list.
     *
     * @param string $search
     *
     * @return array
     */
    public function getMailboxes($search = '*')
    {
        return $this->possiblyGetMailboxes(imap_getmailboxes($this->getImapStream(), $this->imapPath, $search));
    }

    /**
     * Get folders list.
     *
     * @param string $search
     *
     * @return array
     */
    public function getSubscribedMailboxes($search = '*')
    {
        return $this->possiblyGetMailboxes(imap_getsubscribed($this->getImapStream(), $this->imapPath, $search));
    }

    /**
     * Subscribe to a mailbox.
     *
     * @param string $mailbox
     *
     * @throws Exception
     */
    public function subscribeMailbox($mailbox)
    {
        $this->imap('subscribe', $this->getCombinedPath($mailbox));
    }

    /**
     * Unsubscribe from a mailbox.
     *
     * @param string $mailbox
     *
     * @throws Exception
     */
    public function unsubscribeMailbox($mailbox)
    {
        $this->imap('unsubscribe', $this->getCombinedPath($mailbox));
    }

    /**
     * Call IMAP extension function call wrapped with utf7 args conversion & errors handling.
     *
     * @param string       $methodShortName             Name of PHP imap_ method, but without the 'imap_' prefix. (eg. imap_fetch_overview => fetch_overview)
     * @param array|string $args                        All arguments of the original method, except the 'resource $imap_stream' (eg. imap_fetch_overview => string $sequence [, int $options = 0 ])
     * @param bool         $prependConnectionAsFirstArg Add 'resource $imap_stream' as first argument, if set to true
     * @param string|null  $throwExceptionClass         Name of exception class, which will be thrown in case of errors
     *
     * @throws Exception
     */
    public function imap($methodShortName, $args = [], $prependConnectionAsFirstArg = true, $throwExceptionClass = Exception::class)
    {
        if (!\is_array($args)) {
            $args = [$args];
        }
        // https://github.com/barbushin/php-imap/issues/242
        if (\in_array($methodShortName, ['open'])) {
            // Mailbox names that contain international characters besides those in the printable ASCII space have to be encoded with imap_utf7_encode().
            // https://www.php.net/manual/en/function.imap-open.php
            if (\is_string($args[0])) {
                if (preg_match("/^\{.*\}(.*)$/", $args[0], $matches)) {
                    $mailbox_name = $matches[1];

                    if (!mb_detect_encoding($mailbox_name, 'ASCII', true)) {
                        $args[0] = $this->encodeStringToUtf7Imap($mailbox_name);
                    }
                }
            }
        } else {
            foreach ($args as &$arg) {
                if (\is_string($arg)) {
                    $arg = $this->encodeStringToUtf7Imap($arg);
                }
            }
        }
        if ($prependConnectionAsFirstArg) {
            array_unshift($args, $this->getImapStream());
        }

        imap_errors(); // flush errors
        $result = @\call_user_func_array("imap_$methodShortName", $args);

        if (!$result) {
            $errors = imap_errors();
            if ($errors) {
                if ($throwExceptionClass) {
                    throw new $throwExceptionClass("IMAP method imap_$methodShortName() failed with error: ".implode('. ', $errors));
                } else {
                    return false;
                }
            }
        }

        return $result;
    }

    /**
     * Combine Subfolder or Folder to the connection.
     * Have the imapPath a folder added to the connection info, then will the $folder added as subfolder.
     * If the parameter $absolute TRUE, then will the connection new builded only with this folder as root element.
     *
     * @param string $folder   Folder, the will added to the path
     * @param bool   $absolute Add folder as root element to the connection and remove all other from this
     *
     * @return string Return the new path
     */
    protected function getCombinedPath($folder, $absolute = false)
    {
        if (empty(trim($folder))) {
            return $this->imapPath;
        } elseif ('}' === substr($this->imapPath, -1)) {
            return $this->imapPath.$folder;
        } elseif (true === $absolute) {
            $folder = ('/' === $folder) ? '' : $folder;
            $posConnectionDefinitionEnd = strpos($this->imapPath, '}');

            return substr($this->imapPath, 0, $posConnectionDefinitionEnd + 1).$folder;
        }

        return $this->imapPath.$this->getPathDelimiter().$folder;
    }

    /**
     * @return array|null
     *
     * @psalm-return array{0:string, 1:string}|null
     */
    protected function possiblyGetEmailAndNameFromRecipient(object $recipient)
    {
        if (isset($recipient->mailbox) && !empty(trim($recipient->mailbox)) && isset($recipient->host) && !empty(trim($recipient->host))) {
            $recipientEmail = strtolower($recipient->mailbox.'@'.$recipient->host);
            $recipientName = (isset($recipient->personal) and !empty(trim($recipient->personal))) ? $this->decodeMimeStr($recipient->personal, $this->getServerEncoding()) : null;

            return [
                $recipientEmail,
                $recipientName,
            ];
        }

        return null;
    }

    /**
     * @param array $t
     *
     * @return array
     */
    protected function possiblyGetMailboxes($t)
    {
        $arr = [];
        if ($t) {
            foreach ($t as $item) {
                // https://github.com/barbushin/php-imap/issues/339
                $name = $this->decodeStringFromUtf7ImapToUtf8($item->name);
                $arr[] = [
                    'fullpath' => $name,
                    'attributes' => $item->attributes,
                    'delimiter' => $item->delimiter,
                    'shortpath' => substr($name, strpos($name, '}') + 1),
                ];
            }
        }

        return $arr;
    }

    /**
     * @param array $t
     *
     * @psalm-param non-empty-array $t
     *
     * @return array
     *
     * @psalm-return array{0:string|string, 1:string|null, 2:string}
     */
    protected function possiblyGetHostNameAndAddress($t)
    {
        $out = [];
        $out[] = isset($t[0]->host) ? $t[0]->host : (isset($t[1]->host) ? $t[1]->host : null);
        $out[] = (isset($t[0]->personal) and !empty(trim($t[0]->personal))) ? $this->decodeMimeStr($t[0]->personal, $this->getServerEncoding()) : ((isset($t[1]->personal) and (!empty(trim($t[1]->personal)))) ? $this->decodeMimeStr($t[1]->personal, $this->getServerEncoding()) : null);
        $out[] = strtolower($t[0]->mailbox.'@'.$out[0]);

        return $out;
    }

    /**
     * @return void
     */
    protected function pingOrDisconnect()
    {
        if ($this->imapStream && (!\is_resource($this->imapStream) || !imap_ping($this->imapStream))) {
            $this->disconnect();
            $this->imapStream = null;
        }
    }
}
