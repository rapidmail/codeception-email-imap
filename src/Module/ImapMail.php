<?php

namespace Codeception\Module;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 TechDivision GmbH <info@techdivision.com>
 *
 *  All rights reserved
 *
 ***************************************************************/

class ImapMail extends \Codeception\Module
{
    use \Codeception\Email\TestsEmails;

    use \Codeception\Email\EmailServiceProvider;

    /**
     * @var \Ddeboer\Imap\Server
     */
    protected $server;

    /**
     * @var \Ddeboer\Imap\Connection
     */
    protected $imapConnection;

    /**
     * @var \Ddeboer\Imap\MailboxInterface
     */
    protected $inbox;

    /**
     * @var array
     */
    protected $currentInbox;

    /**
     * @var array
     */
    protected $unreadInbox;

    /**
     * @var \Ddeboer\Imap\Message
     */
    protected $openedEmail;

    /**
     * The mails from the inbox
     *
     * @var \Ddeboer\Imap\MessageIteratorInterface
     */
    protected $fetchedEmails = [];

    /**
     * Codeception exposed variables
     *
     * @var array
     */
    protected $config = array(
        'imapPort' => 143,
        # set this to /imap/ssl/novalidate-cert to ignore invalid ssl certs
        'imapFlags' => '',
        'deleteEmailsAfterScenario' => false,
        'mailboxMapping' => [],
    );

    /**
     * Codeception required variables
     *
     * @var array
     */
    protected $requiredFields = array('imapUser', 'imapPassword', 'imapServer');


    /**
     * @param \Codeception\TestInterface $test
     * @throws \Zend\Mail\Storage\Exception\RuntimeException
     * @throws \Zend\Mail\Storage\Exception\InvalidArgumentException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function _before(\Codeception\TestInterface $test)
    {
        $this->server = new \Ddeboer\Imap\Server($this->config['imapServer'], $this->config['imapPort'], $this->config['imapFlags']);
        $this->imapConnection = $this->server->authenticate($this->config['imapUser'], $this->config['imapPassword']);
        $this->inbox = $this->imapConnection->getMailbox('INBOX');
        $this->unreadInbox = $this->inbox;
    }

    /**
     * Method executed after each scenario
     */
    public function _after(\Codeception\TestInterface $test)
    {
        if ($this->imapConnection) {

            if(isset($this->config['deleteEmailsAfterScenario']) && $this->config['deleteEmailsAfterScenario'])
            {
                $this->deleteAllEmails();
            }
            $this->imapConnection->close();
        }
    }

    protected function getUnreadInbox()
    {
        $search = new \Ddeboer\Imap\SearchExpression();
        $search->addCondition(new \Ddeboer\Imap\Search\Flag\Unseen());

        return $this->inbox->getMessages($search);
    }

    public function getCurrentInbox()
    {
        return $this->currentInbox;
    }

    /**
     * Delete All Emails
     *
     * Accessible from tests, deletes all emails
     */
    public function deleteAllEmails()
    {
        $messages = $this->inbox->getMessages();
        foreach ($messages as $message) {
            $message->delete();
        }
        $this->imapConnection->expunge();
        $this->imapConnection->getResource()->clearLastMailboxUsedCache();
    }

    /**
     * Fetch Emails
     *
     * Accessible from tests, fetches all emails
     */
    public function fetchEmails()
    {
        $this->fetchedEmails = array();

        try
        {
            // refresh the cache
            $this->imapConnection->getResource()->clearLastMailboxUsedCache();
            $messages = $this->inbox->getMessages();
            if ($messages instanceof \ArrayIterator) {
                $messages = iterator_to_array($messages);
            }

            $this->fetchedEmails = $messages;
        }
        catch(\Exception $e)
        {
            $this->fail('Exception: ' . $e->getMessage());
        }

        // by default, work on all emails
        $this->setCurrentInbox($this->fetchedEmails);
    }

    /**
     * Access Inbox For *
     *
     * Filters emails to only keep those that are received by the provided address
     *
     * @param string $address Recipient address' inbox
     */
    public function accessInboxFor($address)
    {
        $inboxForAddress = array();

        foreach ($this->fetchedEmails as $email) {

            if ($this->isAddressinListOfRecipients($email->getTo(), $address)
                || $this->isAddressinListOfRecipients($email->getCc(), $address)
                || $this->isAddressinListOfRecipients($email->getBcc(), $address)
            ) {
                $inboxForAddress[] = $email;
            }
        }

        $this->setCurrentInbox($inboxForAddress);
    }


    /**
     * checks for spam score
     * could use several methods, e.g. SpamAssassin
     *
     * @return void
     */
    public function seeNoRelevantSpamScore()
    {
        $this->checkSpamAssassinSpamStatus($this->openedEmail->getRawHeaders());
    }

    /**
     * Checks the X-Spam-Status for occurence of "Yes" and returns false if so
     * We have to check the raw headers since the imap library has no
     * proper method for checking any other way
     *
     * @param string $rawHeaders
     * @return bool
     */
    protected function checkSpamAssassinSpamStatus($rawHeaders)
    {
        preg_match_all("/^([^\r\n:]+)\s*[:]\s*([^\r\n:]+(?:[\r]?[\n][ \t][^\r\n]+)*)/m", $rawHeaders, $matches);

        $headerKeys = $matches[1];
        $headerValues = $matches[2];
        $spamAssassinSpamStatusKey = 'X-Spam-Status';
        if (in_array($spamAssassinSpamStatusKey,$headerKeys)) {
            $headerKey = array_search($spamAssassinSpamStatusKey,$headerKeys);
            $this->assertStringStartsWith("No", $headerValues[$headerKey], 'Your mail is liable to end up in spam folder, since X-Spam-Status contains the following: ' . $headerValues[$headerKey]);
        }
    }

    /**
     * Iterates of an array of recipients and checks if the address matches
     *
     * @param $recipients
     * @param $address
     * @return bool
     */
    private function isAddressinListOfRecipients($recipients, $address) {
        /** @var \Ddeboer\Imap\Message\EmailAddress $recipient */
        foreach ($recipients as $recipient) {
            if ($recipient->getAddress() === $address) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string Body
     */
    public function grabBodyFromEmail()
    {
        return $this->getEmailBody($this->openedEmail);
    }

    /**
     * Set Current Inbox
     *
     * Sets the current inbox to work on, also create a copy of it to handle unread emails
     *
     * @param array $inbox Inbox
     */
    protected function setCurrentInbox($inbox)
    {
        $this->currentInbox = $inbox;
        $this->unreadInbox = $inbox;
    }

    /**
     * Open Next Unread Email
     *
     * Pops the most recent unread email and assigns it as the email to conduct tests on
     */
    public function openNextUnreadEmail()
    {
        $this->openedEmail = $this->getMostRecentUnreadEmail();
    }

    /**
     * Get Most Recent Unread Email
     *
     * Pops the most recent unread email, fails if the inbox is empty
     *
     * @return mixed Returns a JSON encoded Email
     */
    protected function getMostRecentUnreadEmail()
    {
        if(empty($this->unreadInbox))
        {
            $this->fail('Unread Inbox is Empty');
        }

        return array_shift($this->unreadInbox);
    }

    /**
     * @return \Ddeboer\Imap\Message
     */
    protected function getOpenedEmail()
    {
        return $this->openedEmail;
    }

    /**
     * The MailHog API returns the plain body, we just concatenate the html and the text version
     *
     * @param \Ddeboer\Imap\Message $email
     * @return null|string
     */
    protected function getEmailBody($email)
    {
        if ($email->getBodyHtml())

        $body = $email->getBodyHtml();
        $body .= "\n\n";
        $body .= $email->getBodyText();

        return $body;
    }

    /**
     * @param \Ddeboer\Imap\Message $email
     * @return null|string
     */
    protected function getEmailSender($email)
    {
        /** @var \Ddeboer\Imap\Message\EmailAddress $sender */
        $sender = $email->getSender()[0];
        return $sender->getAddress();
    }

    /**
     * Get Email Subject
     *
     * Returns the subject of an email
     *
     * @param \Ddeboer\Imap\Message $email Email
     * @return string Subject
     */
    protected function getEmailSubject($email)
    {
        return $email->getSubject();
    }

    /**
     * Get Email Reply To
     *
     * Returns the string containing the address to reply to
     *
     * @param \Ddeboer\Imap\Message $email Email
     * @return array
     */
    protected function getEmailReplyTo($email)
    {
        return json_encode($this->extractAddressesFromArray($email->getReplyTo()));
    }


    /**
     * Get Email To
     *
     * Returns the string containing the persons included in the To field
     *
     * @param \Ddeboer\Imap\Message $email Email
     * @return array
     */
    protected function getEmailTo($email)
    {
        return json_encode($this->extractAddressesFromArray($email->getTo()));
    }

    /**
     * Get Email CC
     *
     * Returns the string containing the persons included in the CC field
     *
     * @param \Ddeboer\Imap\Message $email Email
     * @return array
     */
    protected function getEmailCC($email)
    {
        return json_encode($this->extractAddressesFromArray($email->getCc()));
    }

    /**
     * Get Email BCC
     *
     * Returns the string containing the persons included in the BCC field
     *
     * @param \Ddeboer\Imap\Message $email Email
     * @return array
     */
    protected function getEmailBCC($email)
    {
        return json_encode($this->extractAddressesFromArray($email->getBcc()));
    }

    /**
     * Get Email Recipients
     *
     * Returns the string containing all of the recipients, such as To, CC and if provided BCC
     *
     * @param \Ddeboer\Imap\Message $email Email
     * @return array
     */
    protected function getEmailRecipients($email)
    {
        return json_encode(array_merge(
            $this->extractAddressesFromArray($email->getTo()),
            $this->extractAddressesFromArray($email->getCc()),
            $this->extractAddressesFromArray($email->getBcc())
        ));
    }

    /**
     * @param array $addresses Extracts the addresses from an array of addresses to use for the actual test
     * @return array
     */
    protected function extractAddressesFromArray(array $addresses = [])
    {
        $extractedAddresses = [];
        foreach ($addresses as $address) {
            if ($address instanceof \Ddeboer\Imap\Message\EmailAddress) {
                $extractedAddresses[] = $address->getFullAddress();
            }
        }
        return $extractedAddresses;
    }
}
